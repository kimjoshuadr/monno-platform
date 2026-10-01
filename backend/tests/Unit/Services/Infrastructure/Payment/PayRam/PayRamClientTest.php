<?php

namespace Tests\Unit\Services\Infrastructure\Payment\PayRam;

use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamClient;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamConfigurationService;
use Mockery as m;
use Tests\TestCase;

class PayRamClientTest extends TestCase
{
    private const API_KEY = 'test-project-api-key';

    private PayRamClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payram.enabled' => true,
            'services.payram.base_url' => 'https://pay.monno.io',
            'services.payram.api_key' => self::API_KEY,
            'services.payram.webhook_secret' => self::API_KEY,
        ]);

        $this->client = new PayRamClient(new PayRamConfigurationService);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_it_accepts_a_correct_hmac_signature(): void
    {
        $body = '{"reference_id":"abc","status":"FILLED"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, self::API_KEY);

        $this->assertTrue($this->client->verifyWebhookSignature($body, $signature));
    }

    public function test_it_rejects_a_signature_computed_with_the_wrong_key(): void
    {
        $body = '{"reference_id":"abc","status":"FILLED"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'not-our-key');

        $this->assertFalse($this->client->verifyWebhookSignature($body, $signature));
    }

    public function test_it_rejects_a_body_tampered_with_after_signing(): void
    {
        $signature = 'sha256=' . hash_hmac('sha256', '{"status":"FILLED"}', self::API_KEY);

        $this->assertFalse($this->client->verifyWebhookSignature('{"status":"CANCELLED"}', $signature));
    }

    public function test_it_rejects_a_plain_sha256_digest_that_is_not_hmac(): void
    {
        $body = '{"status":"FILLED"}';

        $this->assertFalse($this->client->verifyWebhookSignature($body, 'sha256=' . hash('sha256', $body)));
    }

    public function test_it_accepts_the_legacy_verbatim_api_key_header(): void
    {
        $this->assertTrue($this->client->verifyWebhookSignature('{"status":"FILLED"}', null, self::API_KEY));
    }

    public function test_it_rejects_a_wrong_legacy_api_key_header(): void
    {
        $this->assertFalse($this->client->verifyWebhookSignature('{"status":"FILLED"}', null, 'wrong'));
    }

    public function test_it_rejects_missing_signature_and_missing_api_key_header(): void
    {
        $this->assertFalse($this->client->verifyWebhookSignature('{"status":"FILLED"}', null, null));
    }

    public function test_it_rejects_an_empty_body(): void
    {
        $signature = 'sha256=' . hash_hmac('sha256', '', self::API_KEY);

        $this->assertFalse($this->client->verifyWebhookSignature('', $signature));
    }

    public function test_it_rejects_when_no_secret_is_configured(): void
    {
        config(['services.payram.webhook_secret' => '', 'services.payram.api_key' => '']);

        $body = '{"status":"FILLED"}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, '');

        $this->assertFalse($this->client->verifyWebhookSignature($body, $signature));
    }
}
