import { expect, type APIRequestContext, type Page } from '@playwright/test';

/**
 * Helpers for the PayRam end-to-end specs.
 *
 * These run against a deployed Monno (staging) plus the PayRam gateway, so unlike
 * the rest of the suite they do not bootstrap an account through Mailpit — email
 * verification is on there. Credentials come from the environment, and the
 * gateway session is minted through Monno's own SSO endpoint.
 */
export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8123';
export const API_BASE = process.env.E2E_API_URL ?? `${BASE_URL}/api/`;
export const PAYRAM_URL = process.env.PAYRAM_BASE_URL ?? 'https://pay.monno.io';

export type OrgCreds = {
  id: number;
  email: string;
  password: string;
};

/** The organizer used for the "set up and live" assertions. */
export function configuredOrg(): OrgCreds {
  return {
    id: Number(process.env.E2E_PAYRAM_ORG_ID ?? 29),
    email: process.env.E2E_PAYRAM_ORG_EMAIL ?? '',
    password: process.env.E2E_PAYRAM_ORG_PASSWORD ?? '',
  };
}

/** An organizer with a PayRam account but an incomplete setup (no wallets yet). */
export function incompleteOrg(): OrgCreds | null {
  const email = process.env.E2E_PAYRAM_ORG2_EMAIL;
  const password = process.env.E2E_PAYRAM_ORG2_PASSWORD;
  const id = process.env.E2E_PAYRAM_ORG2_ID;

  if (!email || !password || !id) return null;

  return { id: Number(id), email, password };
}

export function haveCreds(org: OrgCreds | null): org is OrgCreds {
  return !!org && !!org.email && !!org.password && org.id > 0;
}

/**
 * Mint a PayRam console session through Monno's SSO. The code is single-use, so
 * each call produces a fresh URL.
 */
export async function consoleSsoUrl(request: APIRequestContext, org: OrgCreds): Promise<string> {
  const login = await request.post(`${API_BASE}auth/login`, {
    data: { email: org.email, password: org.password },
    headers: { Accept: 'application/json' },
  });
  expect(login.ok(), `login failed for ${org.email}: ${login.status()}`).toBeTruthy();

  const sso = await request.post(`${API_BASE}organizers/${org.id}/payram/sso-token`, {
    headers: { Accept: 'application/json' },
  });
  expect(sso.ok(), `sso-token failed for org ${org.id}: ${sso.status()}`).toBeTruthy();

  const body = await sso.json();
  return `${body.dashboard_url}/sso.html#code=${body.code}` +
    `&exchange=${encodeURIComponent(body.exchange_url)}` +
    `&redirect=%2Fdashboard&wallet=needs`;
}

/** Sign into the Monno app in a browser context (for the organizer settings card). */
export async function loginUi(page: Page, org: OrgCreds): Promise<void> {
  await page.goto(`${BASE_URL}/auth/login`, { waitUntil: 'domcontentloaded' });
  // The login form is server-rendered; clicking before React hydrates does
  // nothing (no request, no navigation). Let the page settle first.
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);

  await page.getByRole('textbox', { name: /email/i }).fill(org.email);
  await page.getByRole('textbox', { name: /password/i }).fill(org.password);
  await page.getByRole('button', { name: /^log in$/i }).click();
  await page
    .waitForURL((url) => !/\/auth\/login/.test(url.pathname), { timeout: 30_000 })
    .catch(() => {});
  await page.waitForTimeout(1500);
}

/** Open the PayRam console on `path` as this organizer. */
export async function openConsole(page: Page, request: APIRequestContext, org: OrgCreds, path = '/dashboard'): Promise<void> {
  const url = await consoleSsoUrl(request, org);
  await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => {});

  // The SSO page exchanges the code and stores the console token, then redirects.
  for (let i = 0; i < 20; i++) {
    if (await page.evaluate(() => !!localStorage.getItem('payram_access_token')).catch(() => false)) break;
    await page.waitForTimeout(500);
  }

  await page.goto(`${PAYRAM_URL}${path}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(4000);
}
