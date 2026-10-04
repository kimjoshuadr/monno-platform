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

        $key = 'payram:gateway_status:'.$projectId;

        // Caching is an optimisation, never a requirement. A cache store that
        // cannot be written (bad permissions, full disk) must not turn the money-
        // path status into a 500 — read and write best-effort, and always fall
        // back to asking the gateway.
        try {
            if (($cached = Cache::get($key)) !== null) {
                return $cached;
            }
        } catch (Throwable $exception) {
            logger()->warning('Could not read the PayRam gateway status cache', [
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);
        }

        $status = $this->fetch($projectId);

        // Never cache a failure. `available: false` means "we could not ask",
        // which is a transient condition — a stale operator token or a blip.
        // Remembering it for 20s turns one bad moment into the organizer being
        // told their gateway is unreachable, and it also fails the publish gate.
        // Only a real answer is worth keeping.
        if (($status['available'] ?? false) === true) {
            try {
                Cache::put($key, $status, self::CACHE_TTL_SECONDS);
            } catch (Throwable $exception) {
                logger()->warning('Could not write the PayRam gateway status cache', [
                    'project_id' => $projectId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $status;
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

        [$eligibleForSweep, $lastSweepError] = $this->sweepState($projectId);

        return [
            'available' => true,
            'cold_wallet_configured' => $configuredNetworks !== [],
            'default_cold_wallet_set' => $networks !== []
                && count($configuredNetworks) === count($networks),
            'networks' => $networks,
            'configured_networks' => $configuredNetworks,
            'eligible_for_sweep' => $eligibleForSweep,
            'last_sweep_error' => $lastSweepError,
            'recent_settlements' => $this->recentSettlements($projectId),
        ];
    }

    /**
     * The last few sweeps that moved funds to the organizer's cold wallet,
     * newest first. PayRam records several legs per sweep (collect, fee,
     * transfer); only the fund transfer to the cold wallet is the organizer's
     * settlement, so that is what we surface. A failure here only drops the
     * detail — it never affects readiness.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentSettlements(int $projectId): array
    {
        try {
            $sweeps = $this->operatorClient->getProjectSweeps($projectId, 20);
        } catch (Throwable) {
            return [];
        }

        $settlements = [];

        foreach ($sweeps as $sweep) {
            if (! is_array($sweep) || ($sweep['status'] ?? null) !== 'fund_transfer') {
                continue;
            }

            $settlements[] = [
                'amount' => (string) ($sweep['amount'] ?? '0'),
                'currency_code' => $sweep['currencyCode'] ?? null,
                'blockchain_code' => $sweep['blockchainCode'] ?? null,
                'destination' => $sweep['toAddress'] ?? null,
                'transaction_hash' => $sweep['txHash'] ?? null,
                'at' => $sweep['timestamp'] ?? ($sweep['createdAt'] ?? null),
            ];

            if (count($settlements) >= 3) {
                break;
            }
        }

        return $settlements;
    }

    /**
     * What is waiting to sweep, and the last reason a sweep failed.
     *
     * This is the difference between "sales settle to your cold wallet" as a
     * claim and as a fact. It was hardcoded to empty/null, so the card told the
     * organizer everything was fine while their funds sat un-swept and the
     * gateway was failing every sweep (for example, no hot wallet assigned, so
     * the sweep transaction cannot be funded). A balance call that fails must
     * not take down the readiness report — it only removes the sweep detail.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>|null}
     */
    private function sweepState(int $projectId): array
    {
        try {
            $balances = $this->operatorClient->getProjectAddressBalances($projectId);
        } catch (Throwable $exception) {
            logger()->warning('Could not read PayRam sweep state', [
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);

            return [[], null];
        }

        $eligible = [];
        $lastError = null;

        foreach ($balances as $balance) {
            if (! is_array($balance)) {
                continue;
            }

            $eligibleAmount = (float) ($balance['eligibleForSweepAmount'] ?? 0);
            $eligibleCount = (int) ($balance['eligibleForSweepCount'] ?? 0);

            if ($eligibleCount > 0 || $eligibleAmount > 0) {
                $eligible[] = [
                    'wallet_name' => $balance['walletName'] ?? null,
                    'blockchain_code' => $balance['blockchainCode'] ?? null,
                    'currency_code' => $balance['currencyCode'] ?? null,
                    'amount' => (string) ($balance['eligibleForSweepAmount'] ?? '0'),
                    'amount_usd' => $balance['eligibleForSweepAmountUSD'] ?? null,
                ];
            }

            // The first concrete failure is the most useful thing to show; if
            // several wallets are failing they are almost always failing for
            // the same reason.
            $error = $balance['lastSweepError'] ?? null;
            if ($lastError === null && is_array($error) && ($error['reason'] ?? null)) {
                $lastError = [
                    'statusCode' => $error['statusCode'] ?? null,
                    'category' => $error['category'] ?? null,
                    'reason' => $error['reason'],
                    'actionHint' => $error['actionHint'] ?? null,
                ];
            }
        }

        return [$eligible, $lastError];
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
            'recent_settlements' => [],
        ];
    }
}
