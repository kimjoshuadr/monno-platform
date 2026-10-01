<?php

namespace HiEvents\Services\Infrastructure\CurrencyConversion;

use Brick\Money\Currency;
use HiEvents\Services\Infrastructure\CurrencyConversion\Exception\CurrencyConversionErrorException;
use HiEvents\Values\MoneyValue;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Fallback exchange-rate provider for deployments without an Open Exchange
 * Rates key.
 *
 * Uses ExchangeRate-API's keyless, USD-based endpoint, so the math is the same
 * as the paid client: every rate is quoted against USD. Failures throw rather
 * than pass a local amount through as if it were dollars.
 */
class KeylessCurrencyConversionClient implements CurrencyConversionClientInterface
{
    private const CACHE_TTL = 43200; // 12 hours in seconds

    private const CACHE_KEY = 'keyless_currency_rates_usd_base';

    public function __construct(
        private readonly string $baseUrl,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws CurrencyConversionErrorException
     */
    public function convert(Currency $fromCurrency, Currency $toCurrency, float $amount): MoneyValue
    {
        if ($fromCurrency->getCurrencyCode() === $toCurrency->getCurrencyCode()) {
            return MoneyValue::fromFloat($amount, $toCurrency->getCurrencyCode());
        }

        $rates = $this->getRates();

        $fromCurrencyCode = $fromCurrency->getCurrencyCode();
        $toCurrencyCode = $toCurrency->getCurrencyCode();

        if (! isset($rates[$fromCurrencyCode], $rates[$toCurrencyCode])) {
            throw new CurrencyConversionErrorException("Invalid currency conversion: $fromCurrencyCode to $toCurrencyCode");
        }

        // Rates are quoted against USD:
        // amount in USD = amount / rate[from]
        // target amount = amount in USD * rate[to]
        $amountInUsd = $amount / $rates[$fromCurrencyCode];
        $convertedAmount = $amountInUsd * $rates[$toCurrencyCode];

        return MoneyValue::fromFloat($convertedAmount, $toCurrencyCode);
    }

    /**
     * @return array<string, float>
     *
     * @throws CurrencyConversionErrorException
     */
    private function getRates(): array
    {
        $rates = $this->cache->get(self::CACHE_KEY);

        if (is_array($rates) && $rates !== []) {
            return $rates;
        }

        try {
            $response = Http::timeout(8)->get($this->baseUrl);
        } catch (\Throwable $exception) {
            $this->logger->error('Keyless exchange-rate request failed', [
                'url' => $this->baseUrl,
                'error' => $exception->getMessage(),
            ]);

            throw new CurrencyConversionErrorException('Failed to fetch exchange rates.');
        }

        if ($response->failed()) {
            $this->logger->error('Keyless exchange-rate endpoint returned an error', [
                'url' => $this->baseUrl,
                'status' => $response->status(),
            ]);

            throw new CurrencyConversionErrorException('Failed to fetch exchange rates.');
        }

        $payload = $response->json();
        $rates = is_array($payload['rates'] ?? null) ? $payload['rates'] : null;

        if ($rates === null || ! isset($rates['USD'])) {
            throw new CurrencyConversionErrorException('Invalid response from exchange-rate endpoint.');
        }

        $rates['USD'] = 1.0;
        $this->cache->set(self::CACHE_KEY, $rates, self::CACHE_TTL);
        $this->logger->info('Keyless exchange rates cached.');

        return $rates;
    }
}
