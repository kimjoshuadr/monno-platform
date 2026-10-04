<?php

namespace HiEvents\Console\Commands;

use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Watches every merchant project for the things that silently strand money.
 *
 * A project can accept payments as soon as it has a deposit wallet, but it can
 * only *sweep* once it has a hot wallet to pay gas. Funds arriving on a project
 * with no hot wallet are stuck, so that is the headline check here. The second
 * check is the last sweep failure — which is how a dry hot wallet, an undeployed
 * deposit contract or a wrong hot-wallet key surface.
 *
 * Exits non-zero when anything is wrong, so the scheduler is the alarm. It does
 * not read on-chain balances — PayRam reports the failures itself.
 */
class PayRamHealthCommand extends Command
{
    protected $signature = 'monno:payram-health
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report PayRam projects whose sweeps are broken (no hot wallet, sweep failures).';

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $issues = [];

        foreach ($this->accountsRepository->all() as $account) {
            $projectId = $account->getExternalPlatformId();
            if ($projectId === null) {
                continue;
            }

            $label = sprintf('#%d %s', $projectId, (string) ($account->getProjectName() ?? ''));

            try {
                $wallets = $this->operatorClient->getProjectWallets($projectId);
            } catch (Throwable $exception) {
                $issues[] = ['project' => $projectId, 'label' => $label, 'type' => 'gateway_unreachable', 'detail' => $exception->getMessage()];

                continue;
            }

            $mine = $this->projectWallets($wallets, $projectId);
            $hasDepositWallet = $this->hasConfiguredDepositWallet($mine);
            $hasHotWallet = $this->hasHotWallet($mine);

            // A project that can take money but cannot move it. Before a deposit
            // wallet exists there is nothing to sweep yet, so this is not an error.
            if ($hasDepositWallet && ! $hasHotWallet) {
                $issues[] = [
                    'project' => $projectId,
                    'label' => $label,
                    'type' => 'missing_hot_wallet',
                    'detail' => 'has a deposit wallet but no hot wallet — funds cannot sweep',
                ];
            }

            try {
                foreach ($this->operatorClient->getProjectAddressBalances($projectId) as $balance) {
                    $error = is_array($balance) ? ($balance['lastSweepError'] ?? null) : null;
                    if (is_array($error) && ($error['reason'] ?? null)) {
                        $issues[] = [
                            'project' => $projectId,
                            'label' => $label,
                            'type' => 'sweep_failed',
                            'detail' => sprintf('%s (%s)', $error['reason'], $error['statusCode'] ?? 'unknown'),
                        ];
                    }
                }
            } catch (Throwable $exception) {
                $issues[] = ['project' => $projectId, 'label' => $label, 'type' => 'balance_unreadable', 'detail' => $exception->getMessage()];
            }
        }

        if ($issues === []) {
            $this->info('PayRam health: all projects healthy.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($issues, JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $this->error(sprintf('%d PayRam issue(s) need attention.', count($issues)));
        foreach ($issues as $issue) {
            $this->error(sprintf('  [%s] %s: %s', $issue['type'], $issue['label'], $issue['detail']));
        }

        return self::FAILURE;
    }

    /**
     * Wallets attached to this project (the list is shared across projects, so a
     * row only counts when the project is in its assignments).
     *
     * @param  array<int, array<string, mixed>>  $wallets
     * @return array<int, array<string, mixed>>
     */
    private function projectWallets(array $wallets, int $projectId): array
    {
        return array_values(array_filter($wallets, function ($wallet) use ($projectId) {
            if (! is_array($wallet)) {
                return false;
            }
            foreach (($wallet['externalPlatformWallets'] ?? []) as $assignment) {
                if (is_array($assignment) && (int) ($assignment['externalPlatformID'] ?? 0) === $projectId) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $wallets
     */
    private function hasHotWallet(array $wallets): bool
    {
        foreach ($wallets as $wallet) {
            if (($wallet['walletType'] ?? null) === 'hot_wallet') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $wallets
     */
    private function hasConfiguredDepositWallet(array $wallets): bool
    {
        foreach ($wallets as $wallet) {
            if (($wallet['walletType'] ?? null) !== 'deposit_wallet') {
                continue;
            }
            foreach (($wallet['walletScws'] ?? []) as $scw) {
                if (is_array($scw) && (string) ($scw['fundCollectorAddress'] ?? '') !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
