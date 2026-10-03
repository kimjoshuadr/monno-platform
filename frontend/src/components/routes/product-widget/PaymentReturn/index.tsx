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
 **/
const STRIPE_CONFIRM_WINDOW_MS = 10000;

// On-chain confirmation is not instant. PayRam asks for a dozen block
// confirmations, so give the reference a realistic window before we stop
// waiting and let the buyer know it is still pending rather than failed.
const PAYRAM_CONFIRM_WINDOW_MS = 120000;

export const PaymentReturn = () => {
    const {eventId, orderShortId} = useParams();
    const [shouldPoll, setShouldPoll] = useState(true);
    const {data: order} = usePollGetOrderPublic(eventId, orderShortId, shouldPoll, ['event']);
    const navigate = useNavigate();

    const isPayRam = order?.payment_provider === 'PAYRAM';

    // Never ask Stripe about a crypto order: the endpoint is meaningless for it
    // and its failure is what produced the false "unable to confirm" screen.
    const [attemptManualConfirmation, setAttemptManualConfirmation] = useState(false);
    const paymentIntentQuery = useGetOrderStripePaymentIntentPublic(
        eventId,
        orderShortId,
        attemptManualConfirmation && !isPayRam,
    );

    const [cannotConfirmPayment, setCannotConfirmPayment] = useState(false);
    const [stillPending, setStillPending] = useState(false);
    const hasTrackedPurchase = useRef(false);

    useEffect(
        () => {
            // Wait for the provider before choosing a window: the order arrives
            // in the first poll, and a PayRam order must not be judged by the
            // Stripe clock.
            if (order === undefined) {
                return;
            }

            const window = isPayRam ? PAYRAM_CONFIRM_WINDOW_MS : STRIPE_CONFIRM_WINDOW_MS;

            const timeout = setTimeout(() => {
                setShouldPoll(false);
                if (isPayRam) {
                    setStillPending(true);
                } else {
                    setAttemptManualConfirmation(true);
                }
            }, window);

            return () => {
                clearTimeout(timeout);
            };
        },
        [order === undefined, isPayRam]
    );

    useEffect(() => {
        if (!attemptManualConfirmation || !paymentIntentQuery.isFetched) {
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
    }, [paymentIntentQuery.isFetched, attemptManualConfirmation]);

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
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
        }
        if (order.payment_status === 'PAYMENT_FAILED' || (typeof window !== 'undefined' && window?.location.search.includes('failed'))) {
            navigate(eventCheckoutPath(eventId, orderShortId, 'payment') + '?payment_failed=true');
        }
    }, [order]);

    const showError = cannotConfirmPayment;

    return (
        <CheckoutContent>
            <div className={classes.container}>
                {!showError && !stillPending && (
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

                {!showError && stillPending && (
                    <HomepageInfoMessage
                        status="processing"
                        message={t`We're still waiting for your crypto payment to be confirmed on-chain. This can take a few minutes. You can safely close this page — your ticket will be emailed to you once it is confirmed.`}
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
