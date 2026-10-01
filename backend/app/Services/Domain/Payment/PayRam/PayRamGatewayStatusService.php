<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The gateway's own view of an organizer's project: whether a payout (cold)
 * wallet is configured, what is waiting to sweep, and the last sweep failure.
 *
 * Nothing here is inferred from our database. If the gateway cannot be reached
 * we report `available: false` rather than guessing — an organizer should be
 * told the truth about where their money is, not a reassuring default.
 */
readonly class PayRamGatewayStatusService
{
    private const CACHE_TTL_SECONDS = 20;

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     cold_wallet_configured: bool,
     *     default_cold_wallet_set: bool,
     *     eligible_for_sweep: array<int, array<string, mixed>>,
     *     last_sweep_error: array<string, mixed>|null,
     * }
     */
    public function forProject(?int $projectId): array
    {
        if ($projectId === null || $projectId <= 0) {
            return $this->unavailable();
        }

        return Cache::remember(
            'payram:gateway_status:'.$projectId,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->fetch($projectId),
        );
    }

    /**
     * @return array{
     *     available: bool,
     *     cold_wallet_configured: bool,
     *     default_cold_wallet_set: bool,
     *     eligible_for_sweep: array<int, array<string, mixed>>,
     *     last_sweep_error: array<string, mixed>|null,
     * }
     */
    private function fetch(int $projectId): array
    {
        try {
            $rows = $this->operatorClient->getProjectAddressBalances($projectId);
        } catch (Throwable $exception) {
            logger()->warning('Could not read PayRam gateway status', [
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);

            return $this->unavailable();
        }

        $eligible = [];
        $lastSweepError = null;
        $coldWalletConfigured = null;
        $defaultColdWalletSet = null;

        foreach ($rows as $row) {
            $amount = (string) ($row['eligibleForSweepAmount'] ?? '0');
            if ((float) $amount > 0) {
                $eligible[] = [
                    'wallet_name' => $row['walletName'] ?? null,
                    'blockchain_code' => $row['blockchainCode'] ?? null,
                    'currency_code' => $row['currencyCode'] ?? null,
                    'amount' => $amount,
                    'amount_usd' => $row['eligibleForSweepAmountUSD'] ?? null,
                ];
            }

            if (! empty($row['lastSweepError'])) {
                $lastSweepError = $row['lastSweepError'];
            }

            // A project is only as ready as its least-ready wallet.
            $rowCold = (bool) ($row['coldWalletConfigured'] ?? false);
            $rowDefault = (bool) ($row['defaultColdWalletSet'] ?? false);
            $coldWalletConfigured = $coldWalletConfigured === null ? $rowCold : ($coldWalletConfigured && $rowCold);
            $defaultColdWalletSet = $defaultColdWalletSet === null ? $rowDefault : ($defaultColdWalletSet && $rowDefault);
        }

        return [
            'available' => true,
            'cold_wallet_configured' => (bool) $coldWalletConfigured,
            'default_cold_wallet_set' => (bool) $defaultColdWalletSet,
            'eligible_for_sweep' => $eligible,
            'last_sweep_error' => $lastSweepError,
        ];
    }

    /**
     * @return array{
     *     available: bool,
     *     cold_wallet_configured: bool,
     *     default_cold_wallet_set: bool,
     *     eligible_for_sweep: array<int, array<string, mixed>>,
     *     last_sweep_error: array<string, mixed>|null,
     * }
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'cold_wallet_configured' => false,
            'default_cold_wallet_set' => false,
            'eligible_for_sweep' => [],
            'last_sweep_error' => null,
        ];
    }
}
