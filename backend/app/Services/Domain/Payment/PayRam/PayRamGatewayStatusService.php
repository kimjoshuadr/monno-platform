<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The gateway's own view of an organizer's project: which networks can accept
 * payments, what is waiting to sweep, and the last sweep failure.
 *
 * Nothing here is inferred from our database. If the gateway cannot be reached
 * we report `available: false` rather than guessing — an organizer should be
 * told the truth about where their money is, not a reassuring default.
 *
 * The source is /project/{id}/wallets, which is what the console itself lists
 * from. An earlier version read /project/{id}/addresses/balance — a *sweep
 * balance* that stays empty until money has actually moved — and inferred wallet
 * configuration from it, so a freshly configured project looked unconfigured.
 *
 * The response shape matters and is not what it first appears:
 *  - the endpoint returns wallets that are *shared*, so each row may belong to
 *    several projects via `externalPlatformWallets[].externalPlatformID`;
 *  - a deposit wallet that is live on a network carries one `walletScws` entry
 *    per network, and the sweep destination is that entry's
 *    `fundCollectorAddress`. No address means no payout wallet, which is what
 *    PayRam refuses to deploy without.
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
            $wallets = $this->operatorClient->getProjectWallets($projectId);
        } catch (Throwable $exception) {
            logger()->warning('Could not read PayRam gateway status', [
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);

            return $this->unavailable();
        }

        $networks = [];

        foreach ($wallets as $wallet) {
            if (! is_array($wallet) || ! $this->belongsToProject($wallet, $projectId)) {
                continue;
            }

            // Only deposit wallets receive buyer payments; hot wallets are the
            // operator's machinery and say nothing about the organizer's setup.
            if (($wallet['walletType'] ?? null) !== 'deposit_wallet') {
                continue;
            }

            foreach (($wallet['walletScws'] ?? []) as $scw) {
                if (! is_array($scw)) {
                    continue;
                }

                $collector = trim((string) ($scw['fundCollectorAddress'] ?? ''));

                $networks[] = [
                    'wallet_name' => $wallet['name'] ?? null,
                    'blockchain_code' => $scw['blockchainCode'] ?? null,
                    'family' => $scw['family'] ?? ($wallet['family'] ?? null),
                    // A sweep destination is exactly what "payout wallet
                    // configured" means, and PayRam will not deploy a contract
                    // without one.
                    'cold_wallet_configured' => $collector !== '',
                    'default_cold_wallet_set' => $collector !== '',
                    'payout_address' => $collector !== '' ? $collector : null,
                ];
            }
        }

        // A network that is configured can take money today, so ONE of them is
        // enough for the organizer to be live. This used to be an AND across
        // every wallet, which meant adding a second network switched the first
        // one off — Monno reported "not ready" while PayRam was happily
        // accepting payments on the network that was already set up.
        $configuredNetworks = array_values(array_filter(
            $networks,
            static fn (array $network): bool => $network['cold_wallet_configured'],
        ));

        return [
            'available' => true,
            'cold_wallet_configured' => $configuredNetworks !== [],
            'default_cold_wallet_set' => $networks !== []
                && count($configuredNetworks) === count($networks),
            'networks' => $networks,
            'configured_networks' => $configuredNetworks,
            'eligible_for_sweep' => [],
            'last_sweep_error' => null,
        ];
    }

    /**
     * Wallets are shared objects: a row only counts for this project when the
     * project appears in its assignment list.
     *
     * @param  array<string, mixed>  $wallet
     */
    private function belongsToProject(array $wallet, int $projectId): bool
    {
        $assignments = $wallet['externalPlatformWallets'] ?? null;

        if (! is_array($assignments) || $assignments === []) {
            // No assignment data: do not claim it.
            return false;
        }

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            if ((int) ($assignment['externalPlatformID'] ?? 0) === $projectId) {
                return true;
            }
        }

        return false;
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
