<?php

namespace HiEvents\Console\Commands;

use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Watches every merchant project for the things that silently strand money.
 *
 * Two failures matter operationally:
 *   - a project whose hot wallet is not the shared operator wallet (it cannot
 *     sweep, or it sweeps under the wrong gas), and
 *   - a project whose last sweep failed (that is how "low gas", "no hot wallet"
 *     and "deposit not deployed" surface).
 *
 * Exits non-zero when anything is wrong, so the scheduler is the alarm. It does
 * not read on-chain balances — PayRam reports the failures itself.
 */
class PayRamHealthCommand extends Command
{
    protected $signature = 'monno:payram-health
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report PayRam projects whose sweeps are broken (hot-wallet deviation, sweep failures).';

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sharedHotWalletId = (int) config('services.payram.hot_wallet_id', 0);
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

            $hotWalletIds = $this->hotWalletIdsForProject($wallets, $projectId);

            if ($sharedHotWalletId > 0 && ! in_array($sharedHotWalletId, $hotWalletIds, true)) {
                $issues[] = [
                    'project' => $projectId,
                    'label' => $label,
                    'type' => 'hot_wallet_deviation',
                    'detail' => sprintf('assigned hot wallet(s) [%s], expected %d', implode(', ', $hotWalletIds), $sharedHotWalletId),
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
                            'detail' => sprintf(
                                '%s (%s)',
                                $error['reason'],
                                $error['statusCode'] ?? 'unknown',
                            ),
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
     * The hot wallets attached to this project (the wallet list is shared across
     * projects, so a row only counts when the project is in its assignments).
     *
     * @param  array<int, array<string, mixed>>  $wallets
     * @return array<int, int>
     */
    private function hotWalletIdsForProject(array $wallets, int $projectId): array
    {
        $ids = [];

        foreach ($wallets as $wallet) {
            if (! is_array($wallet) || ($wallet['walletType'] ?? null) !== 'hot_wallet') {
                continue;
            }

            foreach (($wallet['externalPlatformWallets'] ?? []) as $assignment) {
                if (is_array($assignment) && (int) ($assignment['externalPlatformID'] ?? 0) === $projectId) {
                    $ids[] = (int) $wallet['id'];
                    break;
                }
            }
        }

        return $ids;
    }
}
