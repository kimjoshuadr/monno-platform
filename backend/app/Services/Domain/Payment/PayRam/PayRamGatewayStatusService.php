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
     *     networks: array<int, array<string, mixed>>,
     *     configured_networks: array<int, array<string, mixed>>,
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
     *     networks: array<int, array<string, mixed>>,
     *     configured_networks: array<int, array<string, mixed>>,
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
        $networks = [];

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

            $networks[] = [
                'wallet_name' => $row['walletName'] ?? null,
                'blockchain_code' => $row['blockchainCode'] ?? null,
                'cold_wallet_configured' => (bool) ($row['coldWalletConfigured'] ?? false),
                'default_cold_wallet_set' => (bool) ($row['defaultColdWalletSet'] ?? false),
            ];
        }

        // A network that is configured can take money today, so ONE of them is
        // enough for the organizer to be live. This used to be an AND across
        // every wallet, which meant adding a second network switched the first
        // one off — Monno reported "not ready" while PayRam was happily
        // accepting payments on the network that was already set up.
        //
        // The networks the organizer has not finished are still reported, so the
        // card can say what is actually left rather than overstating readiness.
        $configuredNetworks = array_values(array_filter(
            $networks,
            static fn (array $network): bool => $network['cold_wallet_configured'],
        ));

        return [
            'available' => true,
            'cold_wallet_configured' => $configuredNetworks !== [],
            'default_cold_wallet_set' => $networks !== []
                && count($configuredNetworks) === count($networks)
                && $configuredNetworks !== [],
            'networks' => $networks,
            'configured_networks' => $configuredNetworks,
            'eligible_for_sweep' => $eligible,
            'last_sweep_error' => $lastSweepError,
        ];
    }

    /**
     * @return array{
     *     available: bool,
     *     cold_wallet_configured: bool,
     *     default_cold_wallet_set: bool,
     *     networks: array<int, array<string, mixed>>,
     *     configured_networks: array<int, array<string, mixed>>,
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
            'networks' => [],
            'configured_networks' => [],
            'eligible_for_sweep' => [],
            'last_sweep_error' => null,
        ];
    }
}
