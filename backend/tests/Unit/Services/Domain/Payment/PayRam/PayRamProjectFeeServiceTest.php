<?php

namespace Tests\Unit\Services\Domain\Payment\PayRam;

use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Services\Domain\Payment\PayRam\PayRamProjectFeeService;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * The fee is set in PayRam per merchant and per chain. Monno reads it, so these
 * cover resolution (override beats default), the configured fallback, and the
 * minimum ("the higher of a percentage or a floor").
 */
class PayRamProjectFeeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(array $overrides = [], array $defaults = []): PayRamProjectFeeService
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectFees')->andReturn($overrides);
        $client->shouldReceive('getFeeDefaults')->andReturn($defaults);

        return new PayRamProjectFeeService($client);
    }

    private function failingService(): PayRamProjectFeeService
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectFees')->andThrow(new PayRamApiException('down'));
        $client->shouldReceive('getFeeDefaults')->andThrow(new PayRamApiException('down'));

        return new PayRamProjectFeeService($client);
    }

    public function test_a_project_override_wins_over_the_chain_default(): void
    {
        $service = $this->service(
            overrides: [['projectID' => 9, 'feeBps' => 400, 'blockchain' => ['code' => 'ETH']]],
            defaults: [
                ['feeBps' => 250, 'blockchain' => ['code' => 'ETH']],
                ['feeBps' => 300, 'blockchain' => ['code' => 'BTC']],
            ],
        );

        $fees = $service->resolvedFees(9);

        $this->assertSame(400, $fees['ETH']['bps']);
        $this->assertSame('project', $fees['ETH']['source']);
        $this->assertSame(300, $fees['BTC']['bps'], 'No override for BTC — the default applies.');
        $this->assertSame('default', $fees['BTC']['source']);
    }

    public function test_another_projects_override_does_not_leak(): void
    {
        $service = $this->service(
            overrides: [['projectID' => 77, 'feeBps' => 900, 'blockchain' => ['code' => 'ETH']]],
            defaults: [['feeBps' => 250, 'blockchain' => ['code' => 'ETH']]],
        );

        $fees = $service->resolvedFees(9);

        $this->assertSame(250, $fees['ETH']['bps']);
        $this->assertSame('default', $fees['ETH']['source']);
    }

    public function test_the_fee_falls_back_to_config_when_the_gateway_is_unreachable(): void
    {
        config(['services.payram.operator_fee_bps' => 275]);

        $this->assertSame(275, $this->failingService()->operatorFeeBps(9, 'ETH'));
    }

    public function test_the_fee_falls_back_to_config_for_an_unknown_chain(): void
    {
        config(['services.payram.operator_fee_bps' => 275]);

        $service = $this->service(defaults: [['feeBps' => 250, 'blockchain' => ['code' => 'ETH']]]);

        $this->assertSame(275, $service->operatorFeeBps(9, 'TRX'));
    }

    public function test_the_fee_uses_the_chain_rate_read_from_the_gateway(): void
    {
        $service = $this->service(
            overrides: [['projectID' => 9, 'feeBps' => 400, 'blockchain' => ['code' => 'ETH']]],
            defaults: [['feeBps' => 250, 'blockchain' => ['code' => 'ETH']]],
        );

        $this->assertSame(400, $service->operatorFeeBps(9, 'ETH'));
        // 4% of 100.
        $this->assertSame(4.0, $service->operatorFeeUsd(9, 'ETH', 100.0));
    }

    public function test_the_minimum_wins_when_the_percentage_is_smaller(): void
    {
        config(['services.payram.operator_fee_min_usd' => 1.00]);

        $service = $this->service(defaults: [['feeBps' => 250, 'blockchain' => ['code' => 'ETH']]]);

        // 2.5% of 20.00 = 0.50 -> the 1.00 floor applies.
        $this->assertSame(1.00, $service->operatorFeeUsd(9, 'ETH', 20.00));
        // 2.5% of 200.00 = 5.00 -> the percentage wins.
        $this->assertSame(5.00, $service->operatorFeeUsd(9, 'ETH', 200.00));
    }

    public function test_a_waived_fee_is_not_resurrected_by_the_minimum(): void
    {
        config(['services.payram.operator_fee_min_usd' => 1.00]);

        // 0 bps is a deliberate waiver; the floor must not turn it into a charge.
        $service = $this->service(overrides: [['projectID' => 9, 'feeBps' => 0, 'blockchain' => ['code' => 'ETH']]]);

        $this->assertSame(0.0, $service->operatorFeeUsd(9, 'ETH', 20.00));
    }

    public function test_no_project_falls_back_to_config(): void
    {
        config(['services.payram.operator_fee_bps' => 250]);

        $this->assertSame(250, $this->service()->operatorFeeBps(null, 'ETH'));
    }
}
