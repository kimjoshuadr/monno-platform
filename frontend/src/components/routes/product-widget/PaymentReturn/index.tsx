import {usePollGetOrderPublic} from "../../../../queries/usePollGetOrderPublic.ts";
import {useNavigate, useParams} from "react-router";
import {useEffect, useRef, useState} from "react";
import classes from './PaymentReturn.module.scss';
import {t} from "@lingui/macro";
import {useGetOrderStripePaymentIntentPublic} from "../../../../queries/useGetOrderStripePaymentIntentPublic.ts";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {eventCheckoutPath} from "../../../../utilites/urlHelper.ts";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {isSsr} from "../../../../utilites/helpers.ts";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";

/**
 * Handles the return from the payment provider.
 *
 * Stripe sends a webhook that marks the order COMPLETED, but the return can
 * land first, so we poll the order and (for Stripe) fall back to reading the
 * PaymentIntent directly.
 *
 * Crypto is different: PayRam has no "intent" to read, and an on-chain transfer
 * takes real time to confirm, so the only thing worth polling is the order
 * itself. Asking Stripe for a PayRam order can never succeed, and used to show
 * a false failure after ten seconds.
 *
 * The page now waits for the payment to actually complete and then sends the
 * buyer to their ticket. If the crypto payment arrived short, it says so
 * plainly instead of pretending it is still merely "processing".
 **/
const STRIPE_CONFIRM_WINDOW_MS = 10000;

// Crypto can take a while — confirmations plus the sweep. Keep waiting for half
// an hour before falling back to "still pending, we'll email your ticket", and
// let a reload resume the wait. The order completes server-side either way.
const PAYRAM_CONFIRM_WINDOW_MS = 30 * 60 * 1000;

export const PaymentReturn = () => {
    const {eventId, orderShortId} = useParams();
    const [shouldPoll, setShouldPoll] = useState(true);
    const {data: order} = usePollGetOrderPublic(eventId, orderShortId, shouldPoll, ['event']);
    const navigate = useNavigate();

    // Only a Stripe order may use the Stripe confirmation fallback. Crypto and
    // offline orders settle by webhook/poll, and asking Stripe about them can
    // never succeed — that is what showed a false failure over a settled
    // payment. Treat any non-Stripe provider, and a provider we do not know
    // yet, as "wait" rather than "failed".
    const isStripe = order?.payment_provider === 'STRIPE';

    // Never ask Stripe about a crypto order: the endpoint is meaningless for it
    // and its failure is what produced the false "unable to confirm" screen.
    const [attemptManualConfirmation, setAttemptManualConfirmation] = useState(false);
    const paymentIntentQuery = useGetOrderStripePaymentIntentPublic(
        eventId,
        orderShortId,
        attemptManualConfirmation && isStripe,
    );

    const [cannotConfirmPayment, setCannotConfirmPayment] = useState(false);
    const [stillPending, setStillPending] = useState(false);
    const hasTrackedPurchase = useRef(false);

    // The crypto payment, when this is a PayRam order: what was asked for, what
    // arrived, and whether it came up short.
    const payment = order?.payment;
    const isUnderpaid = payment?.underpaid === true;
    const isOverpaid = payment?.overpaid === true;

    // A short crypto payment can outlive its reservation. The order stays
    // RESERVED after it expires (only a non-expired order can be abandoned),
    // so `is_expired` is what tells us the ticket will not come automatically.
    const orderExpired =
        order?.status === 'ABANDONED' || order?.status === 'CANCELLED' || order?.is_expired === true;

    useEffect(
        () => {
            // Wait for the provider before choosing a window: the order arrives
            // in the first poll, and a crypto order must not be judged by the
            // Stripe clock.
            if (order === undefined) {
                return;
            }

            const window = isStripe ? STRIPE_CONFIRM_WINDOW_MS : PAYRAM_CONFIRM_WINDOW_MS;

            const timeout = setTimeout(() => {
                setShouldPoll(false);
                if (isStripe) {
                    setAttemptManualConfirmation(true);
                } else {
                    // Crypto and offline orders settle server-side. Waiting is
                    // the honest state — never a failure we cannot actually
                    // determine here.
                    setStillPending(true);
                }
            }, window);

            return () => {
                clearTimeout(timeout);
            };
        },
        [order === undefined, isStripe]
    );

    useEffect(() => {
        if (!attemptManualConfirmation || !isStripe || !paymentIntentQuery.isFetched) {
            return;
        }
        if (paymentIntentQuery.data?.status === 'succeeded') {
            if (!hasTrackedPurchase.current && order) {
                hasTrackedPurchase.current = true;
                const totalCents = Math.round((order.total_gross || 0) * 100);
                trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_PAID, { value: totalCents });
            }
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
        } else {
            // We tried repeatedly to confirm a Stripe payment and failed. This
            // could be a network error on our end, or a problem with Stripe.
            setCannotConfirmPayment(true);
        }
    }, [paymentIntentQuery.isFetched, attemptManualConfirmation, isStripe]);

    useEffect(() => {
        if (isSsr() || !order) {
            return;
        }

        if (order.status === 'COMPLETED') {
            if (!hasTrackedPurchase.current) {
                hasTrackedPurchase.current = true;
                const totalCents = Math.round((order.total_gross || 0) * 100);
                trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_PAID, { value: totalCents });
            }
            // Overpayment settles as complete too; the surplus is shown on the
            // summary (and in the PayRam dashboard for the organizer).
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
        }
        if (order.payment_status === 'PAYMENT_FAILED' || (typeof window !== 'undefined' && window?.location.search.includes('failed'))) {
            navigate(eventCheckoutPath(eventId, orderShortId, 'payment') + '?payment_failed=true');
        }
    }, [order]);

    const showError = cannotConfirmPayment;

    const receivedLabel = formatCurrency(Number(payment?.received_usd ?? 0), 'USD');
    const expectedLabel = formatCurrency(Number(payment?.expected_usd ?? 0), 'USD');

    return (
        <CheckoutContent>
            <div className={classes.container}>
                {!showError && !orderExpired && !stillPending && !isUnderpaid && (
                    <HomepageInfoMessage
                        status="processing"
                        message={(
                            <>
                                {(!shouldPoll && paymentIntentQuery.isFetched) && t`We could not process your payment. Please try again or contact support.`}
                                {(!shouldPoll && !paymentIntentQuery.isFetched) && t`Almost there! We're just waiting for your payment to be processed. This should only take a few seconds.`}
                                {shouldPoll && t`We're processing your order. Please wait...`}
                            </>
                        )}
                    />
                )}

                {!showError && isUnderpaid && !orderExpired && (
                    <HomepageInfoMessage
                        status="processing"
                        message={t`We received ${receivedLabel} of ${expectedLabel} for this order. That is less than the total, so it cannot be confirmed automatically yet. We will take you to your ticket as soon as it is resolved — you can safely close this page.`}
                    />
                )}

                {!showError && orderExpired && (
                    <HomepageInfoMessage
                        status="processing"
                        message={isUnderpaid
                            ? t`We received ${receivedLabel} of ${expectedLabel}, a shortfall, and this order has now expired. The organizer has been notified of the shortfall and will be in touch about your ticket.`
                            : t`This order has expired. If a payment left your wallet, it did not reach us in time — contact the organizer with your transaction details.`}
                    />
                )}

                {!showError && !orderExpired && stillPending && !isUnderpaid && (
                    <HomepageInfoMessage
                        status="processing"
                        message={isOverpaid
                            ? t`We're still confirming your crypto payment. You paid more than the total — the extra is shown to the organizer in PayRam to refund or apply. You can safely close this page; your ticket will be emailed once it confirms.`
                            : t`We're still waiting for your crypto payment to be confirmed on-chain. This can take a few minutes. You can safely close this page — your ticket will be emailed to you once it is confirmed.`}
                    />
                )}

                {showError && (
                    <HomepageInfoMessage
                        status="error"
                        message={t`We were unable to confirm your payment. Please try again or contact support.`}
                    />
                )}
            </div>
        </CheckoutContent>
    );
}

export default PaymentReturn;
