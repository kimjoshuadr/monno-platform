<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves what PayRam actually charges a merchant, per chain.
 *
 * The fee is not ours to configure: the operator sets it in PayRam, per project
 * and per chain (defaults per chain, overrides per project). Monno reads the
 * resolved rate so the quote, the recorded revenue and the card all match what
 * will really be taken on-chain — rather than a single env value guessed for
 * every merchant.
 *
 * Resolution order matches PayRam's: a project override for that chain wins,
 * otherwise the chain default. Anything we cannot read falls back to the
 * configured operator rate, and a gateway failure is never cached.
 */
readonly class PayRamProjectFeeService
{
    private const CACHE_KEY = 'payram_operator_fees';

    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
    ) {}

    /**
     * The effective operator fee per chain for a project, keyed by chain code.
     *
     * @return array<string, array{bps: int, source: string}>
     */
    public function resolvedFees(int $projectId): array
    {
        try {
            $fees = $this->feeData();
        } catch (Throwable $exception) {
            // A gateway we cannot reach must not invent a fee; callers fall
            // back to the configured rate explicitly.
            logger()->warning('Could not read the PayRam fee configuration', [
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $resolved = [];

        foreach ($fees['defaults'] as $row) {
            $code = $this->chainCode($row);
            if ($code !== null) {
                $resolved[$code] = ['bps' => (int) ($row['feeBps'] ?? 0), 'source' => 'default'];
            }
        }

        // Project overrides win over the chain default.
        foreach ($fees['overrides'] as $row) {
            if ((int) ($row['projectID'] ?? 0) !== $projectId) {
                continue;
            }

            $code = $this->chainCode($row);
            if ($code !== null) {
                $resolved[$code] = ['bps' => (int) ($row['feeBps'] ?? 0), 'source' => 'project'];
            }
        }

        return $resolved;
    }

    /**
     * Monno's operator fee for a merchant on a chain, in basis points.
     *
     * Falls back to the configured operator rate when the project is unknown or
     * the gateway has no answer for the chain (or cannot be reached at all).
     */
    public function operatorFeeBps(?int $projectId, ?string $chainCode): int
    {
        if ($projectId === null || $projectId <= 0) {
            return max(0, (int) config('services.payram.operator_fee_bps', 0));
        }

        $resolved = $this->resolvedFees($projectId);
        $code = $chainCode !== null ? strtoupper(trim($chainCode)) : null;

        if ($code !== null && isset($resolved[$code])) {
            return max(0, $resolved[$code]['bps']);
        }

        return max(0, (int) config('services.payram.operator_fee_bps', 0));
    }

    /**
     * Monno's operator fee in USD for an amount.
     *
     * The fee is the greater of the percentage and the configured minimum.
     * PayRam can only take a percentage on-chain, so any part of the minimum
     * that exceeds the percentage is Monno's to collect outside the sweep; this
     * method returns the fee Monno intends to charge, not what the chain takes.
     */
    public function operatorFeeUsd(?int $projectId, ?string $chainCode, float $amountUsd): float
    {
        $bps = $this->operatorFeeBps($projectId, $chainCode);

        // A waived fee (0 bps) is not resurrected by the minimum.
        if ($bps <= 0 || $amountUsd <= 0) {
            return 0.0;
        }

        $percentage = $amountUsd * $bps / 10000;
        $minimum = max(0.0, (float) config('services.payram.operator_fee_min_usd', 0));

        return round(max($percentage, $minimum), 2);
    }

    /**
     * @return array{overrides: array<int, array<string, mixed>>, defaults: array<int, array<string, mixed>>}
     */
    private function feeData(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['overrides'], $cached['defaults'])) {
            return $cached;
        }

        // One operator-wide fetch serves every project. A thrown error is
        // deliberately not cached, so a gateway blip does not pin a stale fee.
        $data = [
            'overrides' => $this->operatorClient->getProjectFees(),
            'defaults' => $this->operatorClient->getFeeDefaults(),
        ];

        Cache::put(self::CACHE_KEY, $data, self::CACHE_TTL_SECONDS);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function chainCode(array $row): ?string
    {
        $code = $row['blockchain']['code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return strtoupper(trim($code));
    }
}
