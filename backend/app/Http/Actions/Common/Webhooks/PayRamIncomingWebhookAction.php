<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\DTO\PayRamWebhookDTO;
use HiEvents\Services\Domain\Payment\PayRam\PayRamIncomingWebhookHandler;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * PayRam payment/payout webhooks.
 *
 * The signature is checked here, before we acknowledge the delivery, so a
 * forged body is rejected with 401 rather than being accepted and dropped.
 * Actual processing happens on the queue (PayRam waits up to 60s for a 2xx).
 */
class PayRamIncomingWebhookAction extends BaseAction
{
    public function __construct(
        private readonly PayRamWebhookVerifier $webhookVerifier,
    ) {}

    public function __invoke(Request $request): Response
    {
        $rawBody = (string) $request->getContent();
        $signature = $request->header('X-Payram-Signature');
        $apiKeyHeader = $request->header('API-KEY');

        if (! $this->webhookVerifier->verify($rawBody, $signature, $apiKeyHeader)) {
            logger()->warning('Rejected PayRam webhook with an invalid signature');

            return $this->noContentResponse(ResponseCodes::HTTP_UNAUTHORIZED);
        }

        try {
            $dto = new PayRamWebhookDTO(
                rawBody: $rawBody,
                signature: $signature,
            );

            dispatch(static function (PayRamIncomingWebhookHandler $handler) use ($dto) {
                $handler->handle($dto);
            })->catch(function (Throwable $exception) use ($rawBody) {
                logger()->error(__('Failed to handle incoming PayRam webhook'), [
                    'exception' => $exception->getMessage(),
                    'payload' => $rawBody,
                ]);
            });
        } catch (Throwable $exception) {
            logger()->error($exception->getMessage(), $exception->getTrace());

            return $this->noContentResponse(ResponseCodes::HTTP_BAD_REQUEST);
        }

        return $this->noContentResponse();
    }
}
