<?php

namespace HiEvents\Http\Actions\Orders\Payment\PayRam;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\PayramPaymentDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayRamCancelAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PayRamPaymentsRepositoryInterface $payramPaymentsRepository,
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
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost'), '/');

        if ($order === null) {
            return redirect()->to($frontendUrl);
        }

        return redirect()->to(sprintf(
            '%s/checkout/%d/%s/payment?canceled=1',
            $frontendUrl,
            $order->getEventId(),
            $order->getShortId(),
        ));
    }
}
