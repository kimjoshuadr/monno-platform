<?php

namespace HiEvents\Http\Actions\Orders\Payment\PayRam;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\PayramPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\DTO\PayRamWebhookDTO;
use HiEvents\Services\Domain\Payment\PayRam\PayRamCredentialResolver;
use HiEvents\Services\Domain\Payment\PayRam\PayRamIncomingWebhookHandler;
use HiEvents\Services\Domain\Payment\PayRam\PayRamStatusPayloadMapper;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class PayRamReturnAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PayRamPaymentsRepositoryInterface $payramPaymentsRepository,
        private readonly PayRamClient $payRamClient,
        private readonly PayRamIncomingWebhookHandler $webhookHandler,
        private readonly PayRamStatusPayloadMapper $mapper,
        private readonly PayRamCredentialResolver $credentialResolver,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $invoiceId = $request->query('invoice_id')
            ?? $request->query('invoiceId')
            ?? $request->query('order_short_id')
            ?? $request->query('short_id');

        $referenceId = $request->query('reference_id')
            ?? $request->query('referenceId')
            ?? $request->query('reference');

        $order = null;
        $payment = null;

        if ($referenceId) {
            $payment = $this->payramPaymentsRepository->findFirstWhere([
                PayramPaymentDomainObjectAbstract::REFERENCE_ID => (string) $referenceId,
            ]);

            if ($payment && $payment->getOrderId()) {
                $order = $this->orderRepository
                    ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
                    ->findById($payment->getOrderId());
            }
        }

        if ($order === null && $invoiceId) {
            $order = $this->orderRepository
                ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
                ->findByShortId((string) $invoiceId);

            if ($order && $payment === null) {
                $payment = $this->payramPaymentsRepository->findFirstWhere([
                    PayramPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
                ]);
            }
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost'), '/');

        if ($order === null) {
            return redirect()->to($frontendUrl);
        }

        // Fast-path settlement: if PayRam reports the transaction is settled,
        // trigger handler immediately so the user doesn't wait for polling or webhooks.
        if ($payment && $order->getStatus() !== OrderStatus::COMPLETED->name) {
            try {
                $organizerId = $order->getEvent()?->getOrganizerId();
                $apiKey = $this->credentialResolver->forOrganizer($organizerId);

                $status = $this->payRamClient->getPaymentStatus($payment->getReferenceId(), $apiKey);
                $payload = $this->mapper->toWebhookPayload($status);

                if ($payload !== null) {
                    $this->webhookHandler->handle(new PayRamWebhookDTO($payload));
                    $order = $this->orderRepository->findById($order->getId());
                }
            } catch (Throwable $exception) {
                logger()->warning('PayRam return action failed to poll status on-demand', [
                    'order_id' => $order->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($order->getStatus() === OrderStatus::COMPLETED->name) {
            return redirect()->to(sprintf(
                '%s/checkout/%d/%s/summary',
                $frontendUrl,
                $order->getEventId(),
                $order->getShortId(),
            ));
        }

        return redirect()->to(sprintf(
            '%s/checkout/%d/%s/payment_return',
            $frontendUrl,
            $order->getEventId(),
            $order->getShortId(),
        ));
    }
}
