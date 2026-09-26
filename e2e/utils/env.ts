export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8123';
export const API_BASE_URL = process.env.E2E_API_URL ?? `${BASE_URL}/api/`;
export const MAILPIT_URL = process.env.MAILPIT_URL ?? 'http://localhost:8225';
export const IS_SAAS_MODE = (process.env.E2E_SAAS_MODE ?? 'false') === 'true';
export const STRIPE_PUBLIC_KEY = process.env.STRIPE_PUBLIC_KEY ?? '';
export const STRIPE_SECRET_KEY = process.env.STRIPE_SECRET_KEY ?? '';
export const STRIPE_WEBHOOK_SECRET = process.env.STRIPE_WEBHOOK_SECRET ?? 'whsec_e2e_local_secret';

/**
 * Stripe is disabled in the frontend for this deployment (Philippines) — see
 * frontend/src/utilites/paymentProviders.ts. The @stripe specs need the frontend flag
 * flipped back on *and* test keys configured, otherwise they'd assert against a UI that
 * no longer offers Stripe.
 */
export const STRIPE_UI_ENABLED = (process.env.E2E_STRIPE_UI_ENABLED ?? 'false') === 'true';

/** Shared skip condition for every @stripe spec. */
export const STRIPE_SPEC_SKIP_REASON = 'Requires STRIPE_PUBLIC_KEY and E2E_STRIPE_UI_ENABLED=true — Stripe is disabled in this deployment.';
export const skipStripeSpecs = !STRIPE_PUBLIC_KEY || !STRIPE_UI_ENABLED;
export const SUPERADMIN_EMAIL = process.env.E2E_SUPERADMIN_EMAIL ?? 'superadmin@e2e.test';
export const SUPERADMIN_PASSWORD = process.env.E2E_SUPERADMIN_PASSWORD ?? 'SuperAdminPass123!';

export const cookieDomain = (): string => new URL(BASE_URL).hostname;
