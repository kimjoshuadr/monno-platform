<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam;

use HiEvents\Exceptions\ValidationException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\PayRamMerchantProvisioningService;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mints a short-lived, single-use code that lets the organizer land in their
 * PayRam console already signed in.
 *
 * We sign in as the member here (the operator holds their credentials), then
 * park the resulting session under a random code. The PayRam app redeems the
 * code server-to-server. Nothing sensitive ever travels in the URL beyond the
 * one-time code, and the organizer never sees a password.
 */
class CreatePayRamSsoTokenHandler
{
    private const CODE_TTL_SECONDS = 120;

    public const CACHE_PREFIX = 'payram:sso:';

    public function __construct(
        private readonly PayRamMerchantProvisioningService $provisioningService,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly PayRamOperatorClient $operatorClient,
    ) {}

    /**
     * @return array{code: string, dashboard_url: string}
     *
     * @throws ValidationException
     */
    public function handle(int $organizerId): array
    {
        $dashboardUrl = rtrim((string) config('services.payram.base_url', 'https://pay.monno.io'), '/');

        $account = $this->provisioningService->findForOrganizer($organizerId);

        if ($account === null || $account->getStatus() === PayRamMerchantProvisioningService::STATUS_FAILED) {
            $organizer = $this->organizerRepository->findById($organizerId);
            $account = $this->provisioningService->provision(
                organizerId: $organizerId,
                organizerName: $organizer->getName(),
                email: $organizer->getEmail(),
            );
        }

        $email = (string) $account->getMemberEmail();
        $password = (string) $account->getProvisionedPassword();

        if ($email === '' || $password === '') {
            throw new ValidationException(__('This organizer does not have gateway console access yet.'));
        }

        try {
            $tokens = $this->operatorClient->signInMember($email, $password);
        } catch (Throwable $exception) {
            logger()->warning('Could not sign the organizer in to PayRam for SSO', [
                'organizer_id' => $organizerId,
                'error' => $exception->getMessage(),
            ]);

            throw new ValidationException(__('The payment gateway could not start a console session. Please try again.'));
        }

        $code = Str::random(64);
        Cache::put(self::CACHE_PREFIX.$code, $tokens, self::CODE_TTL_SECONDS);

        return [
            'code' => $code,
            'dashboard_url' => $dashboardUrl,
        ];
    }
}
