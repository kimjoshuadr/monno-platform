<?php

namespace Tests\Unit\Services\Infrastructure\Payment\PayRam;

use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamConfigurationService;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamWebhookVerifier;
use Mockery as m;
use Tests\TestCase;

class PayRamWebhookVerifierTest extends TestCase
{
    private const INSTANCE_KEY = 'monno-shared-key';
    private const ORGANIZER_KEY = 'organizer-own-key-12345';

    private PayRamWebhookVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payram.enabled' => true,
            'services.payram.base_url' => 'https://pay.monno.io',
            'services.payram.api_key' => self::INSTANCE_KEY,
            'services.payram.webhook_secret' => self::INSTANCE_KEY,
        ]);

        $account = m::mock(OrganizerPayramAccountDomainObject::class);
        $account->shouldReceive('getApiKey')->andReturn(self::ORGANIZER_KEY);

        $accounts = m::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $accounts->shouldReceive('findWhereIn')->andReturn(collect([$account]));

        $this->verifier = new PayRamWebhookVerifier(new PayRamConfigurationService, $accounts);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function sign(string $body, string $key): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $key);
    }

    public function test_it_accepts_a_delivery_signed_with_the_shared_instance_key(): void
    {
        $body = '{"reference_id":"ref-1","status":"FILLED"}';

        $this->assertTrue($this->verifier->verify($body, $this->sign($body, self::INSTANCE_KEY)));
    }

    public function test_it_accepts_a_delivery_signed_with_the_organizers_own_key(): void
    {
        $body = '{"reference_id":"ref-2","status":"FILLED"}';

        $this->assertTrue(
            $this->verifier->verify($body, $this->sign($body, self::ORGANIZER_KEY)),
            'Payments created under an organizer project are signed with their key, not ours.',
        );
    }

    public function test_it_rejects_a_signature_from_a_key_we_do_not_know(): void
    {
        $body = '{"reference_id":"ref-3","status":"FILLED"}';

        $this->assertFalse($this->verifier->verify($body, $this->sign($body, 'some-other-key')));
    }

    public function test_it_rejects_a_tampered_body(): void
    {
        $signature = $this->sign('{"status":"FILLED"}', self::ORGANIZER_KEY);

        $this->assertFalse($this->verifier->verify('{"status":"CANCELLED"}', $signature));
    }

    public function test_it_accepts_the_legacy_verbatim_api_key_header(): void
    {
        $body = '{"reference_id":"ref-4"}';

        $this->assertTrue($this->verifier->verify($body, null, self::ORGANIZER_KEY));
        $this->assertFalse($this->verifier->verify($body, null, 'not-a-key-we-know'));
    }

    public function test_it_rejects_when_there_is_nothing_to_check_against(): void
    {
        $this->assertFalse($this->verifier->verify(null, 'sha256=abc'));
        $this->assertFalse($this->verifier->verify('', 'sha256=abc'));
        $this->assertFalse($this->verifier->verify('{"a":1}', null));
    }
}
