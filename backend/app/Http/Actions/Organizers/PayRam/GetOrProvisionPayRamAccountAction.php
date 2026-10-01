<?php

namespace HiEvents\Http\Actions\Organizers\PayRam;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\GetOrProvisionPayRamAccountHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST → make sure this organizer has a PayRam merchant account, and report it.
 * Creating it is idempotent; credentials appear in the response only when the
 * account was created in this call.
 */
class GetOrProvisionPayRamAccountAction extends BaseAction
{
    public function __construct(
        private readonly GetOrProvisionPayRamAccountHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class, Role::ADMIN);

        try {
            $payload = $this->handler->handle($organizerId);
        } catch (PayRamApiException $exception) {
            logger()->error('PayRam account provisioning failed', [
                'organizer_id' => $organizerId,
                'error' => $exception->getMessage(),
                'gateway_response' => mb_substr((string) $exception->rawBody, 0, 500),
            ]);

            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse($payload);
    }
}
