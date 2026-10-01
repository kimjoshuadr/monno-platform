import {PaymentProvider} from "../types.ts";

/**
 * Stripe does not support businesses based in the Philippines, so online card payments
 * are disabled for this deployment. Every Stripe code path is kept intact — flip this
 * flag (and configure the STRIPE_* keys) to offer Stripe again.
 */
export const STRIPE_ENABLED = false;

/**
 * Crypto checkout via the self-hosted PayRam gateway (pay.monno.io). Buyers pay in
 * stablecoins; the organizer's money settles to their own wallet on-chain.
 */
export const PAYRAM_ENABLED = true;

/**
 * The payment methods an event can actually offer.
 *
 * With Stripe disabled, offline payments are the fallback and are always available —
 * events stored as ['STRIPE'] or [] (every event before Stripe was disabled) stay
 * payable. Crypto is offered when the event has it switched on.
 */
export const resolvePaymentProviders = (providers?: PaymentProvider[] | null): PaymentProvider[] => {
    if (STRIPE_ENABLED) {
        return providers ?? [];
    }

    const resolved = new Set<PaymentProvider>(['OFFLINE']);

    if (PAYRAM_ENABLED) {
        for (const provider of providers ?? []) {
            if (provider === 'PAYRAM') {
                resolved.add('PAYRAM');
            }
        }
    }

    return Array.from(resolved);
};
