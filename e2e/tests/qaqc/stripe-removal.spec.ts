import { test, expect } from '../../fixtures';
import {
  createDraftEventWithTicket,
  createLiveEventWithPaidTicket,
  enableOfflinePayments,
  OFFLINE_PAYMENT_INSTRUCTIONS,
} from '../../api/factory';
import { getPublicOrder } from '../../api/public-client';
import { CheckoutPage } from '../../pages/checkout.page';
import { BASE_URL, STRIPE_UI_ENABLED } from '../../utils/env';
import { uniqueEmail } from '../../utils/unique';

/**
 * Stripe is disabled for this deployment (Philippines) — see
 * frontend/src/utilites/paymentProviders.ts. These specs pin the Stripe-free surfaces;
 * they no longer apply once Stripe is re-enabled, so they skip with the flag.
 */
test.describe('stripe removal', () => {
  test.skip(STRIPE_UI_ENABLED, 'Stripe is re-enabled in this deployment — the Stripe-free UI specs do not apply.');

  test('event payment & invoicing settings offer offline payments only', async ({ api, authedPage, account }) => {
    const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
    // Offline payment is the only method and the API requires instructions for it
    // (an organization address too when invoicing is on) — seed both, then check the
    // screen renders Stripe-free and still saves.
    await api.updateEventSettings(event.eventId, {
      payment_providers: ['OFFLINE'],
      offline_payment_instructions: OFFLINE_PAYMENT_INSTRUCTIONS,
      organization_name: 'Monno Test Org',
      organization_address: '123 Test Street, Manila',
      enable_invoicing: true,
    } as Record<string, unknown>);

    await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/settings#payment-settings`);
    const section = authedPage.locator('#payment-settings');
    await expect(section.getByText('Payment & Invoicing Settings')).toBeVisible();

    // Nothing Stripe on the screen, and the notice explains why card payments are gone.
    await expect(section).not.toContainText(/stripe/i);
    await expect(section.getByText(/online card payments are unavailable/i)).toBeVisible();

    // Offline is the only method and it is locked on.
    const offline = section.getByRole('checkbox', { name: /pay via invoice or bank transfer/i });
    await expect(offline).toBeChecked();
    await expect(offline).toBeDisabled();

    // Invoice settings are provider-agnostic and still render and save.
    await expect(section.getByText('Invoice Settings')).toBeVisible();
    await expect(section.getByRole('heading', { name: 'Invoice Numbering' })).toBeVisible();

    const saveResponsePromise = authedPage.waitForResponse(
      (r) => r.url().includes('/settings') && r.request().method() !== 'GET',
    );
    await section.getByRole('button', { name: 'Save' }).click();
    expect((await saveResponsePromise).status()).toBe(200);
    await expect(authedPage.getByText('Successfully Updated Payment & Invoicing Settings')).toBeVisible();

    await expect
      .poll(async () => {
        const settings = (await api.getEventSettings(event.eventId)) as unknown as {
          payment_providers?: string[];
          enable_invoicing?: boolean;
        };
        return `${settings.payment_providers?.join(',')}|${settings.enable_invoicing}`;
      })
      .toBe('OFFLINE|true');
  });

  test('a paid order completes through offline payment with no Stripe surface', async ({
    api,
    page,
    account,
    publicApi,
  }) => {
    test.setTimeout(120_000);

    const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
    await enableOfflinePayments(api, event.eventId);

    const buyer = { firstName: 'Buyer', lastName: 'Offline', email: uniqueEmail('offlinebuyer') };
    const checkout = new CheckoutPage(page);

    await checkout.gotoPublicEvent(event.eventId, event.slug);
    await checkout.setFirstProductQuantity(1);
    await checkout.continueToCheckout();
    await checkout.fillOrderDetails(buyer);
    await checkout.fillFirstAttendee(buyer);
    await checkout.continueToPayment();

    // Single method: no picker, no Stripe frame, and Stripe is never mentioned.
    await expect(page.getByText('Payment method', { exact: true })).toHaveCount(0);
    await expect(page.locator('iframe[title="Secure payment input frame"]')).toHaveCount(0);
    await expect(page.getByText(/stripe/i)).toHaveCount(0);
    await expect(page.getByText(OFFLINE_PAYMENT_INSTRUCTIONS)).toBeVisible();

    await checkout.chooseOfflinePayment();
    await page.waitForURL(/\/checkout\/\d+\/[^/]+\/summary/, { timeout: 30_000 });
    await expect(page.getByText('Your order is awaiting payment')).toBeVisible();

    // The order is an offline order awaiting payment, not a paid one.
    const orderShortId = page.url().match(/\/checkout\/\d+\/([^/?]+)\/summary/)![1];
    const order = await getPublicOrder(publicApi, event.eventId, orderShortId);
    expect(order.status).toBe('AWAITING_OFFLINE_PAYMENT');
  });

  test('a paid event publishes without a Stripe connection check', async ({ api, authedPage, account }) => {
    const event = await createDraftEventWithTicket(api, account.organizerId, { price: 25 });

    await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/dashboard`);
    await authedPage.waitForLoadState('networkidle');

    await authedPage.getByTestId('event-status-toggle').click();
    await expect(authedPage.getByText('Ready to go live?')).toBeVisible();

    // The publish gate must not ask for Stripe, let alone block on it.
    await expect(authedPage.getByText(/connect stripe/i)).toHaveCount(0);
    await expect(authedPage.getByText(/stripe/i)).toHaveCount(0);

    const confirm = authedPage.getByTestId('publish-event-confirm-button');
    await expect(confirm).toContainText(/Publish (Event|Anyway)/);
    await confirm.click();
    await expect(authedPage.getByText('Your event is live!')).toBeVisible();
  });

  test('organizer settings and reports hide the Stripe Connect surfaces', async ({ authedPage, account }) => {
    await authedPage.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/settings`);
    const body = authedPage.locator('body');
    await expect(body.getByRole('heading', { name: 'Basic Information' })).toBeVisible();
    await expect(body).not.toContainText(/stripe/i);
    await expect(body).not.toContainText(/payouts/i);
    await expect(body).not.toContainText(/platform fees/i);

    // Platform-fee reporting is a Stripe Connect feature too.
    await authedPage.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/reports`);
    await expect(body.getByRole('heading', { name: 'Revenue Summary' })).toBeVisible();
    await expect(body).not.toContainText(/platform fees/i);
  });
});
