<?php

namespace HiEvents\Http\Actions\Orders\Payment\PayRam;

use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Exceptions\PayRam\PayRamConfigurationException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\CreatePayRamPaymentHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CreatePayRamPaymentActionPublic extends BaseAction
{
    public function __construct(
        private readonly CreatePayRamPaymentHandler $createPayRamPaymentHandler,
    ) {}

    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $session = $this->createPayRamPaymentHandler->handle($orderShortId);
        } catch (ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), SymfonyResponse::HTTP_CONFLICT);
        } catch (PayRamConfigurationException|PayRamApiException $exception) {
            logger()->error('PayRam payment session could not be created', [
                'order' => $orderShortId,
                'error' => $exception->getMessage(),
            ]);

            return $this->errorResponse($exception->getMessage(), SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse([
            'reference_id' => $session->referenceId,
            'url' => $session->checkoutUrl,
            'amount_in_usd' => $session->amountInUsd,
            'amount_in_usd_formatted' => $session->amountInUsdFormatted(),
            'ticket_amount_in_usd' => $session->ticketAmountInUsd(),
            'ticket_amount_in_usd_formatted' => $session->ticketAmountInUsdFormatted(),
            // PayRam's settlement fee, which the buyer is paying. Named for what
            // it is: the markup is PayRam's cut, not Monno's.
            'payram_fee_usd' => $session->payramFeeUsd,
            'payram_fee_usd_formatted' => $session->payramFeeUsdFormatted(),
            'fee_rate_bps' => $session->feeRateBps,
            'fee_rate_percent' => $session->feeRatePercent(),
            // Deprecated alias, kept so a client loaded before this change still
            // renders a fee line instead of a blank one.
            'platform_fee_usd' => $session->payramFeeUsd,
            'platform_fee_usd_formatted' => $session->payramFeeUsdFormatted(),
            'order_amount' => $session->orderAmount,
            'order_currency' => $session->orderCurrency,
            'fx_rate' => $session->fxRate,
            'expires_at' => $session->expiresAt,
        ]);
    }
}
