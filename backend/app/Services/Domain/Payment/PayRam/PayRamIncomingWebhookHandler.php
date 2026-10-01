<?php

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Generated\PayramPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\PayRamPaymentStatus;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\DTO\PayRamWebhookDTO;
use Illuminate\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * Routes a PayRam webhook delivery to the right handler.
 *
 * PayRam re-sends deliveries, so everything is keyed off reference_id + status.
 * Payout events (`payout.*`) also arrive here; they are a separate concern and
 * are ignored until payouts ship.
 */
class PayRamIncomingWebhookHandler
{
    public function __construct(
        private readonly PayRamPaymentsRepositoryInterface $payramPaymentsRepository,
        private readonly PayRamPaymentSettlementHandler $settlementHandler,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(PayRamWebhookDTO $dto): void
    {
        $payload = json_decode($dto->rawBody, true);

        if (! is_array($payload)) {
            $this->logger->error('Received a malformed PayRam webhook body');

            return;
        }

        $eventType = (string) ($payload['event_type'] ?? '');
        if (str_starts_with($eventType, 'payout.')) {
            $this->logger->info('PayRam payout webhook received (not yet handled)', [
                'event_type' => $eventType,
                'payout_id' => $payload['payout_id'] ?? null,
            ]);

            return;
        }

        $referenceId = (string) ($payload['reference_id'] ?? '');
        $statusValue = (string) ($payload['status'] ?? '');

        if ($referenceId === '' || $statusValue === '') {
            $this->logger->error('PayRam webhook is missing a reference or status', [
                'payload' => $payload,
            ]);

            return;
        }

        $status = PayRamPaymentStatus::tryFrom($statusValue);
        if ($status === null) {
            $this->logger->warning('Payram webhook carried an unknown status', ['status' => $statusValue]);

            return;
        }

        if ($status->isSettled()) {
            // Settlement does its own idempotency (order-scoped cache key) and
            // must not be short-circuited by a delivery-level marker, otherwise
            // a failed first attempt would never be retried.
            $this->settlementHandler->handle($payload);

            return;
        }

        $deliveryKey = 'payram_webhook_'.$referenceId.'_'.$statusValue;
        if ($this->cache->has($deliveryKey)) {
            return;
        }

        $this->recordProgress($payload, $referenceId, $status);

        $this->cache->put($deliveryKey, true, 3600);
    }

    private function recordProgress(array $payload, string $referenceId, PayRamPaymentStatus $status): void
    {
        $paymentInfo = $payload['payment_info'] ?? null;
        $firstDeposit = is_array($paymentInfo) && isset($paymentInfo[0]) && is_array($paymentInfo[0])
            ? $paymentInfo[0]
            : [];

        $affected = $this->payramPaymentsRepository->updateWhere(
            attributes: array_filter([
                PayramPaymentDomainObjectAbstract::STATUS => $status->value,
                PayramPaymentDomainObjectAbstract::FILLED_AMOUNT => isset($payload['filled_amount']) && $payload['filled_amount'] !== null
                    ? (float) $payload['filled_amount']
                    : null,
                PayramPaymentDomainObjectAbstract::FILLED_AMOUNT_IN_USD => isset($payload['filled_amount_in_usd']) && $payload['filled_amount_in_usd'] !== null
                    ? (float) $payload['filled_amount_in_usd']
                    : null,
                PayramPaymentDomainObjectAbstract::CONFIRMATION_CURRENT => isset($payload['confirmation_current'])
                    ? (int) $payload['confirmation_current']
                    : null,
                PayramPaymentDomainObjectAbstract::CONFIRMATION_REQUIRED => isset($payload['confirmation_required'])
                    ? (int) $payload['confirmation_required']
                    : null,
                PayramPaymentDomainObjectAbstract::SOURCE_ADDRESS => $firstDeposit['source_address'] ?? null,
                PayramPaymentDomainObjectAbstract::TRANSACTION_HASH => $firstDeposit['transaction_hash'] ?? null,
                PayramPaymentDomainObjectAbstract::PAYMENT_INFO => $paymentInfo,
                PayramPaymentDomainObjectAbstract::LAST_WEBHOOK_PAYLOAD => $payload,
            ], static fn ($value) => $value !== null),
            where: [
                PayramPaymentDomainObjectAbstract::REFERENCE_ID => $referenceId,
            ],
        );

        if ($affected === 0) {
            $this->logger->error('PayRam webhook referenced a payment we do not have', [
                'reference_id' => $referenceId,
                'status' => $status->value,
            ]);
        }
    }
}
