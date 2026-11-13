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
 * Providers this deployment cannot process are dropped (card payments are off,
 * see STRIPE_ENABLED), but nothing is *added*. The organizer's selection stands
 * on its own.
 *
 * Offline used to be force-enabled here because it was the only fallback once
 * Stripe was disabled — which made "no cards" mean "offline whether you want it
 * or not". Crypto is a real gateway now, so that assumption is gone: an event
 * offers exactly the methods that were chosen for it.
 */
export const resolvePaymentProviders = (providers?: PaymentProvider[] | null): PaymentProvider[] => {
    const resolved = new Set<PaymentProvider>();

    for (const provider of providers ?? []) {
        if (provider === 'STRIPE' && !STRIPE_ENABLED) continue;
        if (provider === 'PAYRAM' && !PAYRAM_ENABLED) continue;
        resolved.add(provider);
    }

    return Array.from(resolved);
};
