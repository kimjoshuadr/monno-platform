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

    /**
     * Cap on the decimal places we render a summed amount to. Coins go to 18
     * decimals; beyond that there is nothing economically meaningful, and the
     * gateway never sends more.
     */
    private const MAX_AMOUNT_DECIMALS = 18;

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
        private readonly PayRamProjectFeeService $feeService,
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
            // What PayRam will actually take from this merchant, per chain.
            // Read, not configured: the operator sets it in PayRam.
            'fees' => $this->feeService->resolvedFees($projectId),
        ];
    }

    /**
     * The recent sweeps that reached the organizer's cold wallet, newest first.
     *
     * PayRam records one row per leg of a single sweep transaction: the collect
     * from the deposit address, PayRam's fee transfer, Monno's operator-fee
     * transfer, and the fund transfer to the cold wallet. Grouping the legs by
     * transaction hash turns four rows into the one thing the organizer cares
     * about — what was collected, what each fee took, and what actually
     * arrived — instead of a bare "last settled" figure they cannot check.
     *
     * A failure here only drops the detail — it never affects readiness.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentSettlements(int $projectId): array
    {
        try {
            // 40 rows covers a handful of sweeps; each sweep is several rows.
            $sweeps = $this->operatorClient->getProjectSweeps($projectId, 40);

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

            $settlements = [];
            $seen = [];

            foreach ($sweeps as $sweep) {
                if (! is_array($sweep)) {
                    continue;
                }

                $txHash = (string) ($sweep['txHash'] ?? '');
                if ($txHash === '' || isset($seen[$txHash])) {
                    continue;
                }
                $seen[$txHash] = true;

                $settlement = $this->summariseSweep($txHash, $legsByTx[$txHash] ?? []);
                if ($settlement !== null) {
                    $settlements[] = $settlement;
                }

                if (count($settlements) >= 3) {
                    break;
                }
            }

            return $settlements;
        } catch (Throwable $exception) {
            // Readiness must not depend on being able to describe a sweep. Drop
            // the detail rather than turn the money-path status into a 500.
            logger()->warning('Could not summarise PayRam settlements', [
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Collapse the legs of one sweep into a single settlement record.
     *
     * @param  array<int, array<string, mixed>>  $legs
     * @return array<string, mixed>|null
     */
    private function summariseSweep(string $txHash, array $legs): ?array
    {
        $gross = 0.0;
        $payramFee = 0.0;
        $operatorFee = 0.0;
        $net = 0.0;
        $hasGross = false;
        $hasPayramFee = false;
        $hasOperatorFee = false;
        $hasNet = false;
        $decimals = 0;
        $destination = null;
        $currency = null;
        $blockchain = null;
        $at = null;

        foreach ($legs as $leg) {
            $status = (string) ($leg['status'] ?? '');
            $amountString = $this->toAmountString($leg['amount'] ?? null);
            $amount = (float) $amountString;

            // Render to the precision the gateway actually reported, so a float
            // sum cannot invent trailing digits (and 0.1 + 0.2 reads as 0.3).
            $decimals = max($decimals, $this->decimalPlaces($amountString));

            $currency ??= $leg['currencyCode'] ?? null;
            $blockchain ??= $leg['blockchainCode'] ?? null;
            $at ??= $leg['timestamp'] ?? ($leg['createdAt'] ?? null);

            // A sweep can batch several deposit addresses into one transaction,
            // so there may be more than one leg of a given kind. Sum them; never
            // keep only the last, or the breakdown will not add up (and the rate
            // will read as nonsense).
            switch ($status) {
                case 'fund_collect':
                case 'fund_collect_processed':
                    $gross += $amount;
                    $hasGross = true;
                    break;
                case 'fee_transfer':
                case 'fee_transfer_processed':
                    $payramFee += $amount;
                    $hasPayramFee = true;
                    break;
                case 'operator_fee_transfer':
                case 'operator_fee_transfer_processed':
                    $operatorFee += $amount;
                    $hasOperatorFee = true;
                    break;
                case 'fund_transfer':
                    $net += $amount;
                    $hasNet = true;
                    $destination ??= $leg['toAddress'] ?? null;
                    break;
            }
        }

        // A sweep only counts as a settlement once the fund transfer to the
        // cold wallet exists; the fee legs alone can also belong to an
        // in-flight sweep that has not landed yet.
        if (! $hasNet) {
            return null;
        }

        $realisedRateBps = null;
        if ($hasGross && $gross > 0 && $hasPayramFee) {
            $realisedRateBps = (int) round(($payramFee / $gross) * 10000);
        }

        return [
            'gross' => $hasGross ? $this->formatAmount($gross, $decimals) : null,
            'payram_fee' => $hasPayramFee ? $this->formatAmount($payramFee, $decimals) : null,
            'operator_fee' => $hasOperatorFee ? $this->formatAmount($operatorFee, $decimals) : null,
            'net' => $this->formatAmount($net, $decimals),
            'realised_rate_bps' => $realisedRateBps,
            'currency_code' => $currency,
            'blockchain_code' => $blockchain,
            'destination' => $destination,
            'transaction_hash' => $txHash,
            'at' => $at,
        ];
    }

    /**
     * Normalise a leg amount to a plain decimal string. The gateway sends them
     * as strings, but a float would arrive in exponent form.
     */
    private function toAmountString(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('%.18F', (float) $value);
        }

        return '0';
    }

    private function decimalPlaces(string $amount): int
    {
        $point = strpos($amount, '.');

        if ($point === false) {
            return 0;
        }

        return min(self::MAX_AMOUNT_DECIMALS, strlen($amount) - $point - 1);
    }

    /** "0.001320000000000000" -> "0.00132". */
    private function formatAmount(float $amount, int $decimals): string
    {
        $formatted = number_format($amount, min($decimals, self::MAX_AMOUNT_DECIMALS), '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
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
            $heldAmount = (float) ($balance['amount'] ?? 0);

            if ($eligibleCount > 0 || $eligibleAmount > 0) {
                $eligible[] = [
                    'wallet_name' => $balance['walletName'] ?? null,
                    'blockchain_code' => $balance['blockchainCode'] ?? null,
                    'currency_code' => $balance['currencyCode'] ?? null,
                    'amount' => (string) ($balance['eligibleForSweepAmount'] ?? '0'),
                    'amount_usd' => $balance['eligibleForSweepAmountUSD'] ?? null,
                ];
            }

            // PayRam keeps the last sweep error on a wallet even after the
            // problem is resolved, so a stale "can't deploy / low gas" lingers
            // forever and sends organizers chasing a fixed issue. An error only
            // means something when there is money waiting to move — eligible to
            // sweep, or actually held. With nothing to act on it is history.
            $hasSomethingToActOn = $eligibleCount > 0 || $eligibleAmount > 0 || $heldAmount > 0;

            // The first concrete failure is the most useful thing to show; if
            // several wallets are failing they are almost always failing for
            // the same reason.
            $error = $balance['lastSweepError'] ?? null;
            if ($hasSomethingToActOn && $lastError === null && is_array($error) && ($error['reason'] ?? null)) {
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
            'fees' => [],
        ];
    }
}
