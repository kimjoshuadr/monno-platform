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

    protected $description = 'Report PayRam projects whose sweeps are broken (no hot wallet, sweep failures, fee drift).';

    /**
     * How far the rate PayRam actually charged may drift from the rate we quote
     * before it is worth flagging. Coin amounts are rounded, so a little slack
     * is expected; a real rate change is not.
     */
    private const FEE_DRIFT_TOLERANCE_BPS = 50;

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
                    if (! is_array($balance)) {
                        continue;
                    }

                    // The sneaky failure: a hot wallet is attached, the console
                    // lists it, but it is not active for this chain — so nothing
                    // can pay the gas and funds sit still. Only meaningful when a
                    // deposit wallet can actually take money.
                    if (
                        $hasHotWallet
                        && ($balance['coldWalletConfigured'] ?? false) === true
                        && ($balance['hotWalletActive'] ?? false) !== true
                    ) {
                        $issues[] = [
                            'project' => $projectId,
                            'label' => $label,
                            'type' => 'inactive_hot_wallet',
                            'detail' => sprintf(
                                '%s: the hot wallet is attached but not active — funds cannot sweep',
                                $balance['blockchainCode'] ?? 'a chain',
                            ),
                        ];
                    }

                    $error = $balance['lastSweepError'] ?? null;
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

            $this->checkSettlementFeeDrift($projectId, $label, $issues);
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
     * The rate we quote the buyer against is our estimate of what PayRam's sweep
     * will take. PayRam can move that rate (it is capped, not fixed), and if it
     * moves the quote no longer matches what leaves — the buyer's markup stops
     * covering the fee, or the organizer silently eats the difference. Compare
     * the rate the last settled sweep actually charged against what we quote.
     *
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function checkSettlementFeeDrift(int $projectId, string $label, array &$issues): void
    {
        $configured = max(0, (int) config('services.payram.settlement_fee_bps', 0));
        if ($configured === 0) {
            return;
        }

        try {
            $sweeps = $this->operatorClient->getProjectSweeps($projectId, 40);
        } catch (Throwable) {
            // An unreachable gateway is already reported by the wallet check.
            return;
        }

        $legsByTx = [];
        foreach ($sweeps as $sweep) {
            if (! is_array($sweep)) {
                continue;
            }
            $txHash = (string) ($sweep['txHash'] ?? '');
            if ($txHash !== '') {
                $legsByTx[$txHash][] = $sweep;
            }
        }

        foreach ($sweeps as $sweep) {
            if (! is_array($sweep)) {
                continue;
            }

            $txHash = (string) ($sweep['txHash'] ?? '');
            if ($txHash === '' || ! isset($legsByTx[$txHash])) {
                continue;
            }

            $gross = 0.0;
            $payramFee = 0.0;
            $hasGross = false;
            $hasPayramFee = false;
            foreach ($legsByTx[$txHash] as $leg) {
                $status = (string) ($leg['status'] ?? '');
                $amount = (float) ($leg['amount'] ?? 0);

                // A batched sweep has more than one collect leg; sum them.
                if (in_array($status, ['fund_collect', 'fund_collect_processed'], true)) {
                    $gross += $amount;
                    $hasGross = true;
                }
                if (in_array($status, ['fee_transfer', 'fee_transfer_processed'], true)) {
                    $payramFee += $amount;
                    $hasPayramFee = true;
                }
            }

            unset($legsByTx[$txHash]);

            if (! $hasGross || ! $hasPayramFee || $gross <= 0) {
                continue;
            }

            $realised = (int) round(($payramFee / $gross) * 10000);

            if (abs($realised - $configured) > self::FEE_DRIFT_TOLERANCE_BPS) {
                $issues[] = [
                    'project' => $projectId,
                    'label' => $label,
                    'type' => 'settlement_fee_drift',
                    'detail' => sprintf(
                        'PayRam took %.2f%% on the last sweep but we quote %.2f%% — update PAYRAM_SETTLEMENT_FEE_BPS',
                        $realised / 100,
                        $configured / 100,
                    ),
                ];
            }

            // Only the newest settled sweep holds the current rate.
            return;
        }
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
