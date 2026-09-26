import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '../../fixtures';
import {
  createLiveEventWithPaidTicket,
  createLiveEventWithProduct,
  OFFLINE_PAYMENT_INSTRUCTIONS,
} from '../../api/factory';
import { CheckoutPage } from '../../pages/checkout.page';
import { BASE_URL } from '../../utils/env';
import { findSeededEvent } from '../../utils/parity';
import { uniqueEmail } from '../../utils/unique';

const OUT = path.join(process.cwd(), 'test-results', 'visual');
const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';

const VIEWPORTS = {
  desktop: { width: 1440, height: 900 },
  tablet: { width: 834, height: 1112 },
  mobile: { width: 390, height: 844 },
} as const;

test.describe('visual QA', () => {
  test('builder, themed public page and monno across desktop/tablet/mobile', async ({
    api,
    authedPage,
    browser,
    account,
    publicApi,
  }) => {
    test.setTimeout(180_000);
    await mkdir(OUT, { recursive: true });
    const seededEvent = await findSeededEvent(publicApi);

    const event = await createLiveEventWithProduct(api, {
      organizerId: account.organizerId,
      title: 'QAQC Visual Theme',
    });
    await api.updateEventSettings(event.eventId, {
      homepage_theme_settings: {
        accent: '#7C3AED',
        background: '#0F172A',
        mode: 'dark',
        background_type: 'COLOR',
      },
    } as Record<string, unknown>);

    const authedState = await authedPage.context().storageState();

    // Builder + public event page (custom background applied) per viewport.
    for (const [name, viewport] of Object.entries(VIEWPORTS)) {
      const context = await browser.newContext({
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,
        viewport,
        storageState: authedState,
      });
      const page = await context.newPage();

      await page.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
      await expect(page.getByRole('button', { name: 'Add section' })).toBeVisible();
      await page.screenshot({ path: path.join(OUT, `builder-${name}.png`), fullPage: true });

      // When stacked (below xl), the live preview sits under the panel inside
      // #event-manage-main's inner scroll — bring it into view and capture it.
      const preview = page.locator('iframe[title="Event Preview"]');
      await preview.scrollIntoViewIfNeeded();
      await expect(preview).toBeVisible();
      await page.screenshot({ path: path.join(OUT, `builder-${name}-preview.png`) });

      // Placeholder slug → loader redirects to the canonical one.
      await page.goto(`${BASE_URL}/event/${event.eventId}/theme`);
      await expect(page.locator('h1').filter({ hasText: 'QAQC Visual Theme' })).toBeVisible();
      await page.screenshot({ path: path.join(OUT, `event-page-${name}.png`) });

      await context.close();
    }

    // Organizer designer at mobile only (same responsive pattern as event designer).
    {
      const context = await browser.newContext({
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,
        viewport: VIEWPORTS.mobile,
        storageState: authedState,
      });
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/organizer-homepage-designer`);
      await expect(page.getByRole('heading', { name: 'Homepage Design' })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Add section' })).toHaveCount(0);
      await page.screenshot({ path: path.join(OUT, 'builder-organizer-mobile.png'), fullPage: true });
      await context.close();
    }

    // monno site per viewport.
    for (const [name, viewport] of Object.entries(VIEWPORTS)) {
      const context = await browser.newContext({ viewport });
      const page = await context.newPage();
      await page.goto(`${MONNO_URL}/event/${seededEvent.id}/`);
      await expect(page.locator('h1')).toBeVisible();
      await page.screenshot({ path: path.join(OUT, `monno-event-${name}.png`) });

      await page.goto(`${MONNO_URL}/`);
      await expect(page.locator('a[href^="/discover/category/"]').first()).toBeVisible();
      await page.screenshot({ path: path.join(OUT, `monno-home-${name}.png`) });
      await context.close();
    }
  });

  test('Stripe-free payment settings and checkout across desktop/tablet/mobile', async ({
    api,
    authedPage,
    browser,
    account,
  }) => {
    test.setTimeout(180_000);
    await mkdir(OUT, { recursive: true });

    const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
    await api.updateEventSettings(event.eventId, {
      payment_providers: ['OFFLINE'],
      offline_payment_instructions: OFFLINE_PAYMENT_INSTRUCTIONS,
      organization_name: 'Monno Events PH',
      organization_address: '123 Ayala Avenue, Makati, Metro Manila',
      enable_invoicing: true,
    } as Record<string, unknown>);

    const authedState = await authedPage.context().storageState();

    for (const [name, viewport] of Object.entries(VIEWPORTS)) {
      // Event settings → Payment & Invoicing section.
      const context = await browser.newContext({
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,
        viewport,
        storageState: authedState,
      });
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/manage/event/${event.eventId}/settings#payment-settings`);
      const section = page.locator('#payment-settings');
      await expect(section.getByText('Payment & Invoicing Settings')).toBeVisible();
      await section.scrollIntoViewIfNeeded();
      await section.screenshot({ path: path.join(OUT, `stripe-free-settings-${name}.png`) });

      // The invoice half of the screen (element captures of a section this tall are
      // unreliable, so grab a viewport frame scrolled to it as well).
      await section.getByRole('heading', { name: 'Invoice Numbering' }).scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(OUT, `stripe-free-settings-invoice-${name}.png`) });
      await context.close();

      // Buyer checkout → payment step (offline only).
      const buyerContext = await browser.newContext({
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,
        viewport,
      });
      const buyerPage = await buyerContext.newPage();
      const buyer = { firstName: 'Buyer', lastName: 'Offline', email: uniqueEmail('visualbuyer') };
      const checkout = new CheckoutPage(buyerPage);
      await checkout.gotoPublicEvent(event.eventId, event.slug);
      await checkout.setFirstProductQuantity(1);
      await checkout.continueToCheckout();
      await checkout.fillOrderDetails(buyer);
      await checkout.fillFirstAttendee(buyer);
      await checkout.continueToPayment();
      await expect(buyerPage.getByText('Payment Instructions')).toBeVisible();
      await buyerPage.screenshot({ path: path.join(OUT, `stripe-free-checkout-${name}.png`) });
      await buyerContext.close();
    }
  });
});
