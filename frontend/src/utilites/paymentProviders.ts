import {PaymentProvider} from "../types.ts";

/**
 * Stripe does not support businesses based in the Philippines, so online card payments
 * are disabled for this deployment. Every Stripe code path is kept intact — flip this
 * flag (and configure the STRIPE_* keys) to offer Stripe again.
 */
export const STRIPE_ENABLED = false;

/**
 * The payment methods an event can actually offer. With Stripe disabled, offline
 * payments are the only method, so they are always available — events stored as
 * ['STRIPE'] or [] (every event before Stripe was disabled) stay payable.
 */
export const resolvePaymentProviders = (providers?: PaymentProvider[] | null): PaymentProvider[] => {
    if (STRIPE_ENABLED) {
        return providers ?? [];
    }

    return ['OFFLINE'];
};
