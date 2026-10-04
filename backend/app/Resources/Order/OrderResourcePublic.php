<?php

namespace HiEvents\Resources\Order;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Resources\Attendee\AttendeeResourcePublic;
use HiEvents\Resources\BaseResource;
use HiEvents\Resources\Event\EventResourcePublic;
use HiEvents\Resources\Order\Invoice\InvoiceResourcePublic;
use Illuminate\Http\Request;

/**
 * @mixin OrderDomainObject
 */
class OrderResourcePublic extends BaseResource
{
    public function toArray(Request $request): array
    {
        $includePostCheckoutData = $this->getStatus() === OrderStatus::COMPLETED->name;

        return [
            'short_id' => $this->getShortId(),
            'total_before_additions' => $this->getTotalBeforeAdditions(),
            'total_tax' => $this->getTotalTax(),
            'total_gross' => $this->getTotalGross(),
            'total_fee' => $this->getTotalFee(),
            /** @var 'RESERVED'|'CANCELLED'|'COMPLETED'|'AWAITING_OFFLINE_PAYMENT'|'ABANDONED' */
            'status' => $this->getStatus(),
            /** @var 'REFUND_PENDING'|'REFUND_FAILED'|'REFUNDED'|'PARTIALLY_REFUNDED'|null */
            'refund_status' => $this->getRefundStatus(),
            /** @var 'NO_PAYMENT_REQUIRED'|'AWAITING_PAYMENT'|'AWAITING_OFFLINE_PAYMENT'|'PAYMENT_FAILED'|'PAYMENT_RECEIVED'|null */
            'payment_status' => $this->getPaymentStatus(),
            // The checkout return page must know which provider took the
            // payment so it does not ask Stripe to confirm a crypto order —
            // Stripe can never confirm those and the page would show a false
            // failure over a settled payment.
            'payment_provider' => $this->getPaymentProvider(),
            // The crypto payment's own state, so the return page can wait for
            // completion and, if a payment came up short, say so honestly.
            'payment' => $this->when(
                $this->getPaymentProvider() === PaymentProviders::PAYRAM->value && $this->getPayramPayment() !== null,
                fn () => $this->payramPaymentPayload(),
            ),
            'currency' => $this->getCurrency(),
            'reserved_until' => $this->getReservedUntil(),
            'is_expired' => $this->when(
                ! is_null($this->getReservedUntil()),
                fn () => Carbon::createFromTimeString($this->getReservedUntil())->isPast(),
            ),
            'first_name' => $this->getFirstName(),
            'last_name' => $this->getLastName(),
            'email' => $this->getEmail(),
            'public_id' => $this->getPublicId(),
            'is_payment_required' => $this->isPaymentRequired(),
            'promo_code' => $this->getPromoCode(),
            'taxes_and_fees_rollup' => $this->getTaxesAndFeesRollup(),
            'event' => $this->when(
                ! is_null($this->getEvent()),
                fn () => new EventResourcePublic(
                    resource: $this->getEvent(),
                    includePostCheckoutData: $includePostCheckoutData,
                ),
            ),
            'latest_invoice' => $this->when(
                ! is_null($this->getLatestInvoice()),
                fn () => (new InvoiceResourcePublic($this->getLatestInvoice()))->toArray($request),
            ),
            'address' => $this->when(
                ! is_null($this->getAddress()),
                fn () => $this->getAddress()
            ),
            'order_items' => $this->when(
                ! is_null($this->getOrderItems()),
                fn () => $this->getOrderItems()->map(
                    fn ($orderItem) => new OrderItemResourcePublic($orderItem, $includePostCheckoutData),
                )
            ),
            'attendees' => $this->when(
                ! is_null($this->getAttendees()),
                fn () => $this->getAttendees()->map(
                    fn ($attendee) => new AttendeeResourcePublic($attendee, $includePostCheckoutData),
                )
            ),
            $this->mergeWhen($this->getSessionIdentifier() !== null, fn () => [
                'session_identifier' => $this->getSessionIdentifier(),
            ]),
        ];
    }

    /**
     * The crypto payment as the checkout return page needs it: what was asked
     * for, what actually arrived, and whether that is short or over. `state` is
     * PayRam's own value (open / partially_filled / filled / over_filled /
     * cancelled) so the page can wait until it completes.
     *
     * @return array<string, mixed>
     */
    private function payramPaymentPayload(): array
    {
        $payment = $this->getPayramPayment();
        $expected = (float) $payment->getAmountInUsd();
        $received = $payment->getFilledAmountInUsd();

        return [
            'provider' => PaymentProviders::PAYRAM->value,
            'state' => $payment->getStatus(),
            'expected_usd' => $payment->getAmountInUsd(),
            'expected_amount' => $payment->getAmountInUsd(),
            'received_amount' => $payment->getFilledAmount(),
            'received_usd' => $received,
            'currency' => $payment->getCurrency(),
            'reference_id' => $payment->getReferenceId(),
            'underpaid' => $received !== null && (float) $received < $expected,
            'overpaid' => $received !== null && (float) $received > $expected,
        ];
    }
}
