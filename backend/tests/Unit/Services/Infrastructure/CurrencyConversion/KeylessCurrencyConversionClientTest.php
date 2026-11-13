<?php

namespace Tests\Unit\Services\Infrastructure\CurrencyConversion;

use Brick\Money\Currency;
use HiEvents\Services\Infrastructure\CurrencyConversion\Exception\CurrencyConversionErrorException;
use HiEvents\Services\Infrastructure\CurrencyConversion\KeylessCurrencyConversionClient;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Tests\TestCase;

class KeylessCurrencyConversionClientTest extends TestCase
{
    private const URL = 'https://rates.test/latest/USD';

    private function client(?CacheInterface $cache = null): KeylessCurrencyConversionClient
    {
        return new KeylessCurrencyConversionClient(
            baseUrl: self::URL,
            cache: $cache ?? new class implements CacheInterface
            {
                private array $items = [];

                public function get(string $key, mixed $default = null): mixed
                {
                    return $this->items[$key] ?? $default;
                }

                public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
                {
                    $this->items[$key] = $value;

                    return true;
                }

                public function delete(string $key): bool
                {
                    unset($this->items[$key]);

                    return true;
                }

                public function clear(): bool
                {
                    $this->items = [];

                    return true;
                }

                public function getMultiple(iterable $keys, mixed $default = null): iterable
                {
                    return [];
                }

                public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
                {
                    return true;
                }

                public function deleteMultiple(iterable $keys): bool
                {
                    return true;
                }

                public function has(string $key): bool
                {
                    return isset($this->items[$key]);
                }
            },
            logger: new NullLogger,
        );
    }

    public function test_it_converts_from_a_local_currency_into_usd_using_the_usd_base_rates(): void
    {
        Http::fake([self::URL => Http::response([
            'result' => 'success',
            'base_code' => 'USD',
            'rates' => ['USD' => 1.0, 'PHP' => 50.0],
        ])]);

        $converted = $this->client()->convert(
            Currency::of('PHP'),
            Currency::of('USD'),
            1000.0,
        );

        // 1000 / 50 = 20
        $this->assertSame(20.0, $converted->toFloat());
    }

    public function test_it_converts_from_usd_into_a_local_currency(): void
    {
        Http::fake([self::URL => Http::response([
            'result' => 'success',
            'base_code' => 'USD',
            'rates' => ['USD' => 1.0, 'PHP' => 50.0],
        ])]);

        $converted = $this->client()->convert(
            Currency::of('USD'),
            Currency::of('PHP'),
            25.0,
        );

        $this->assertSame(1250.0, $converted->toFloat());
    }

    public function test_it_short_circuits_when_both_currencies_match_without_calling_the_api(): void
    {
        Http::fake();

        $converted = $this->client()->convert(
            Currency::of('PHP'),
            Currency::of('PHP'),
            42.5,
        );

        $this->assertSame(42.5, $converted->toFloat());
        Http::assertNothingSent();
    }

    public function test_it_caches_rates_so_the_api_is_hit_once(): void
    {
        Http::fake([self::URL => Http::response([
            'result' => 'success',
            'base_code' => 'USD',
            'rates' => ['USD' => 1.0, 'PHP' => 50.0],
        ])]);

        $client = $this->client();
        $client->convert(Currency::of('PHP'), Currency::of('USD'), 1000.0);
        $client->convert(Currency::of('PHP'), Currency::of('USD'), 2000.0);

        Http::assertSentCount(1);
    }

    public function test_it_throws_when_the_rate_for_a_currency_is_missing(): void
    {
        Http::fake([self::URL => Http::response([
            'result' => 'success',
            'base_code' => 'USD',
            'rates' => ['USD' => 1.0],
        ])]);

        $this->expectException(CurrencyConversionErrorException::class);
        $this->client()->convert(
            Currency::of('PHP'),
            Currency::of('USD'),
            1000.0,
        );
    }

    public function test_it_throws_when_the_endpoint_fails_rather_than_passing_the_amount_through(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->expectException(CurrencyConversionErrorException::class);
        $this->client()->convert(
            Currency::of('PHP'),
            Currency::of('USD'),
            1000.0,
        );
    }

    public function test_it_throws_when_the_response_is_malformed(): void
    {
        Http::fake([self::URL => Http::response(['result' => 'success'])]);

        $this->expectException(CurrencyConversionErrorException::class);
        $this->client()->convert(
            Currency::of('PHP'),
            Currency::of('USD'),
            1000.0,
        );
    }
}
