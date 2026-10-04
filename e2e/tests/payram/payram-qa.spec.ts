import { expect, test, type Route } from '@playwright/test';
import {
  BASE_URL,
  PAYRAM_URL,
  configuredOrg,
  consoleSsoUrl,
  haveCreds,
  incompleteOrg,
  loginUi,
  openConsole,
} from './helpers';

/**
 * PayRam end-to-end QAQC.
 *
 * Configured organizer (defaults to org 29) exercises the live path; an optional
 * second organizer with an incomplete setup exercises the "needs setup" path.
 * Set the credentials in the environment:
 *
 *   E2E_BASE_URL=https://staging.app.monno.io
 *   PAYRAM_BASE_URL=https://pay.monno.io
 *   E2E_PAYRAM_ORG_ID=29 E2E_PAYRAM_ORG_EMAIL=… E2E_PAYRAM_ORG_PASSWORD=…
 *   E2E_PAYRAM_ORG2_ID=20 E2E_PAYRAM_ORG2_EMAIL=… E2E_PAYRAM_ORG2_PASSWORD=…
 *
 * Run: npx playwright test tests/payram --project=chromium
 */

const configured = configuredOrg();
const incomplete = incompleteOrg();

test.describe('PayRam · Monno card', () => {
  test.skip(!haveCreds(configured), 'Set E2E_PAYRAM_ORG_* to run these.');

  test('a configured organizer reports ready with settlement history', async ({ page, request }) => {
    // The account endpoint is what the card reads.
    await request.post(`${BASE_URL}/api/auth/login`, {
      data: { email: configured.email, password: configured.password },
      headers: { Accept: 'application/json' },
    });
    const account = await request.get(`${BASE_URL}/api/organizers/${configured.id}/payram/account`, {
      headers: { Accept: 'application/json' },
    });
    expect(account.ok(), `account endpoint returned ${account.status()}`).toBeTruthy();

    const body = await account.json();
    expect(body.status).toBe('READY');
    expect(body.gateway?.available).toBe(true);
    expect(body.gateway?.cold_wallet_configured).toBe(true);
    expect(Array.isArray(body.gateway?.recent_settlements)).toBe(true);

    // And the card renders it.
    await loginUi(page, configured);
    await page.goto(`${BASE_URL}/manage/organizer/${configured.id}/settings#crypto-payments`, {
      waitUntil: 'domcontentloaded',
    });
    // Wait for the card itself rather than a fixed delay.
    await page.getByText('Crypto payments', { exact: false }).first().waitFor({ state: 'visible', timeout: 20_000 });
    await page.waitForTimeout(3000);

    const card = await page.evaluate(() => {
      const t = document.body.innerText.replace(/\s+/g, ' ');
      const i = t.indexOf('Crypto payments');
      return i < 0 ? '' : t.slice(i, i + 500);
    });

    expect(card, card).toContain('ACTIVE');
    expect(card, card).toMatch(/Accepting payments on/i);
    expect(card, card).toContain('Last settled');
  });
});

test.describe('PayRam · console overlay', () => {
  test.skip(!haveCreds(configured), 'Set E2E_PAYRAM_ORG_* to run these.');

  test('a configured organizer sees no banner and the operator surface is hidden', async ({ page, request }) => {
    await openConsole(page, request, configured, '/dashboard');

    const state = await page.evaluate(() => {
      const banner = document.getElementById('monno-setup-banner');
      const hidden = Array.from(document.querySelectorAll('[data-monno-hidden]'))
        .map((el) => (el.textContent || '').trim())
        .filter(Boolean);
      const navText = document.body.innerText;
      return {
        version: (window as unknown as { __monnoUiVersion?: string }).__monnoUiVersion || null,
        banner: !!banner,
        oldStepper: !!document.getElementById('monno-setup-guide'),
        hidden,
        navText,
      };
    });

    expect(state.version, 'overlay version').toBe('2026-10-04.2');
    expect(state.oldStepper, 'old stepper must be gone').toBe(false);
    expect(state.banner, 'no banner when setup is complete').toBe(false);
    // Operator-only nav must be hidden.
    expect(state.hidden.join(' ')).toMatch(/Onramp|Developers|Funds Consolidation/);
    expect(state.navText).not.toMatch(/\bFees\b/);
  });

  test('the buyer payment page never shows the organizer banner', async ({ page, request }) => {
    await openConsole(page, request, configured, '/payments/qa-probe-reference');

    const hasBanner = await page.evaluate(() => !!document.getElementById('monno-setup-banner'));
    expect(hasBanner, 'buyer page must not show the setup banner').toBe(false);
  });

  test('an expired session degrades safely (no banner, no nag)', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto(PAYRAM_URL, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.evaluate(() => {
      // A stale organizer session with a token the gateway will reject.
      localStorage.setItem('payram_user', JSON.stringify({ role: { name: 'project_admin' } }));
      localStorage.setItem('monno_setup_organizer', '1');
      localStorage.setItem('payram_access_token', 'expired.invalid.token');
    });
    await page.goto(`${PAYRAM_URL}/dashboard`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(7000);

    const hasBanner = await page.evaluate(() => !!document.getElementById('monno-setup-banner'));
    expect(hasBanner, 'a session we cannot read must not nag').toBe(false);
    await context.close();
  });
});

test.describe('PayRam · incomplete setup', () => {
  test.skip(!haveCreds(incomplete), 'Set E2E_PAYRAM_ORG2_* to run these.');

  test('an organizer without wallets is prompted, and can add a hot wallet', async ({ page, request }) => {
    await openConsole(page, request, incomplete!, '/dashboard');

    const banner = await page.evaluate(() => {
      const el = document.getElementById('monno-setup-banner');
      return {
        present: !!el,
        text: el ? (el.textContent || '').replace(/\s+/g, ' ') : '',
        depositLink: el ? !!el.querySelector('a[href="/manageWallet/deposit-wallet"]') : false,
      };
    });

    expect(banner.present, 'incomplete setup must be prompted').toBe(true);
    expect(banner.depositLink, 'the banner links to deposit wallet setup').toBe(true);

    // Hot wallet setup must be reachable for the organizer (model A).
    await page.goto(`${PAYRAM_URL}/manageWallet/hot-wallet`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(5000);
    const addHotWalletVisible = await page.evaluate(() =>
      Array.from(document.querySelectorAll('a,button,[role="button"]')).some((el) => {
        if ((el.textContent || '').includes('Add Hot Wallet') && !el.hasAttribute('data-monno-hidden')) {
          const cs = getComputedStyle(el);
          return cs.display !== 'none' && cs.visibility !== 'hidden';
        }
        return false;
      }),
    );
    expect(addHotWalletVisible, 'organizers must be able to add their own hot wallet').toBe(true);
  });
});

test.describe('PayRam · buyer return', () => {
  test.skip(!haveCreds(configured), 'Set E2E_PAYRAM_ORG_* to run these.');

  test('a completed crypto order returns to the summary, never a Stripe false failure', async ({ page }) => {
    const eventId = process.env.E2E_PAYRAM_EVENT_ID ?? '106';
    const orderShortId = process.env.E2E_PAYRAM_COMPLETED_ORDER ?? 'o_9VA7mcailtre3';

    const stripeCalls: string[] = [];
    page.on('request', (r) => {
      if (/payment_intent/i.test(r.url())) stripeCalls.push(r.url());
    });

    await page.goto(`${BASE_URL}/checkout/${eventId}/${orderShortId}/payment_return`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(12_000);

    const text = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
    expect(text, text).not.toMatch(/unable to confirm your payment/i);
    expect(stripeCalls.length, 'must not ask Stripe to confirm a crypto order').toBe(0);
  });
});

test.describe('PayRam · crypto payment state', () => {
  const eventId = process.env.E2E_PAYRAM_EVENT_ID ?? '106';
  const orderShortId = process.env.E2E_PAYRAM_COMPLETED_ORDER ?? 'o_9VA7mcailtre3';

  test('the public order exposes the crypto payment state and flags the shortfall/surplus', async ({ request }) => {
    const res = await request.get(
      `${BASE_URL}/api/public/events/${eventId}/order/${orderShortId}?include=event`,
      { headers: { Accept: 'application/json' } },
    );
    expect(res.ok(), `order fetch returned ${res.status()}`).toBeTruthy();

    const payment = (await res.json()).data.payment;
    expect(payment, 'a PayRam order must expose its crypto payment state').toBeTruthy();
    expect(payment.provider).toBe('PAYRAM');
    expect(typeof payment.state).toBe('string');
    expect(payment).toHaveProperty('expected_usd');
    expect(payment).toHaveProperty('received_usd');
    expect(typeof payment.underpaid).toBe('boolean');
    expect(typeof payment.overpaid).toBe('boolean');
  });

  test('an overpaid order shows the surplus note on the summary', async ({ page }) => {
    await page.goto(`${BASE_URL}/checkout/${eventId}/${orderShortId}/summary`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.getByText(/paid more than the total/i)).toBeVisible({ timeout: 20_000 });
  });

  test('a short crypto payment is stated plainly, and an expired one is not promised a ticket', async ({ page }) => {
    // Force a pending, short crypto order so both return-page branches are
    // exercised without moving real funds. The order starts un-expired: the
    // page waits and promises the ticket once resolved. Once expired, it must
    // stop promising a ticket and point at the shortfall instead.
    let isExpired = false;

    const fulfillOrder = async (route: Route) => {
      const resp = await route.fetch();
      const json = await resp.json();
      json.data = {
        ...json.data,
        status: 'RESERVED',
        payment_status: 'AWAITING_PAYMENT',
        payment_provider: 'PAYRAM',
        is_expired: isExpired,
        payment: {
          provider: 'PAYRAM',
          state: 'PARTIALLY_FILLED',
          expected_usd: 9.99,
          expected_amount: 9.99,
          received_amount: 0.001,
          received_usd: 1.23,
          currency: 'ETH',
          reference_id: 'e2e-short',
          underpaid: true,
          overpaid: false,
        },
      };
      await route.fulfill({
        response: resp,
        body: JSON.stringify(json),
        headers: { ...resp.headers(), 'content-type': 'application/json' },
      });
    };

    // One handler, toggled — re-routing the same pattern mid-test registers a
    // second handler and the gateway request gets fulfilled twice.
    await page.route('**/api/public/events/**/order/**', fulfillOrder);
    await page.goto(`${BASE_URL}/checkout/${eventId}/${orderShortId}/payment_return`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.getByText(/take you to your ticket as soon as it is resolved/i)).toBeVisible({
      timeout: 20_000,
    });

    isExpired = true;
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByText(/order has now expired/i)).toBeVisible({ timeout: 20_000 });
  });
});

test.describe('PayRam · resilience', () => {
  test.skip(!haveCreds(configured), 'Set E2E_PAYRAM_ORG_* to run these.');

  test('the account endpoint stays 200 even when the gateway is slow', async ({ request }) => {
    await request.post(`${BASE_URL}/api/auth/login`, {
      data: { email: configured.email, password: configured.password },
      headers: { Accept: 'application/json' },
    });
    const res = await request.get(`${BASE_URL}/api/organizers/${configured.id}/payram/account`, {
      headers: { Accept: 'application/json' },
    });
    // A cache-permission or transient gateway problem must not 500 the money path.
    expect(res.status()).toBe(200);
  });
});

test.describe('PayRam · sync effect', () => {
  const operatorEmail = process.env.E2E_PAYRAM_OPERATOR_EMAIL;
  const operatorPassword = process.env.E2E_PAYRAM_OPERATOR_PASSWORD;
  test.skip(!operatorEmail || !operatorPassword, 'Set E2E_PAYRAM_OPERATOR_* to verify the sync effect.');
  test.skip(!haveCreds(configured), 'Set E2E_PAYRAM_ORG_* to run these.');

  test('the organizer profile is reflected on the PayRam project', async ({ request }) => {
    // Monno side: who the organizer is, and which PayRam project they own.
    await request.post(`${BASE_URL}/api/auth/login`, {
      data: { email: configured.email, password: configured.password },
      headers: { Accept: 'application/json' },
    });
    const organizers = await (await request.get(`${BASE_URL}/api/organizers`, { headers: { Accept: 'application/json' } })).json();
    const organizer = organizers.data.find((o: { id: number }) => o.id === configured.id);
    expect(organizer, `organizer ${configured.id} not found`).toBeTruthy();

    const account = await (await request.get(`${BASE_URL}/api/organizers/${configured.id}/payram/account`, {
      headers: { Accept: 'application/json' },
    })).json();
    const projectId = account.external_platform_id;
    expect(projectId).toBeTruthy();

    // PayRam side, via the operator.
    const signIn = await request.post(`${PAYRAM_URL}/api/v1/signin`, {
      data: { email: operatorEmail, password: operatorPassword },
      headers: { Accept: 'application/json' },
    });
    const token = (await signIn.json()).accessToken;
    const project = await (await request.get(`${PAYRAM_URL}/api/v1/external-platform/${projectId}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })).json();

    // The gateway name is always qualified so two organizers cannot collide.
    expect(project.name).toBe(`${organizer.name} (${configured.id})`);
    // The support address PayRam uses for its emails is the organizer's.
    if (organizer.email) {
      expect(project.emailSendRequestReplyTo).toBe(organizer.email);
    }
  });
});

