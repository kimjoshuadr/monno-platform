import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '../../fixtures';
import { createLiveEventWithProduct, createSoldOutEvent, enableOfflinePayments } from '../../api/factory';
import { CheckoutPage } from '../../pages/checkout.page';
import { PublicEventPage } from '../../pages/public-event.page';
import { BASE_URL } from '../../utils/env';
import { uniqueEmail } from '../../utils/unique';

const OUT = path.join(process.cwd(), 'test-results', 'rail-purchase');

/**
 * The rail is where the page sells: one picker, no duplicate tickets section, the real
 * order path — on the public page, on the builder's preview, and through the waitlist.
 */
test.describe('rail purchase', () => {
  test('a buyer picks tickets, applies a promo and reaches checkout', async ({ api, account, page }) => {
    test.setTimeout(120_000);
    await mkdir(OUT, { recursive: true });

    const event = await createLiveEventWithProduct(api, {
      organizerId: account.organizerId,
      price: 25,
      quantityAvailable: 50,
      showQuantityRemaining: true,
      // One contact form, so the two-ticket order goes through in one step.
      attendeeDetails: 'PER_ORDER',
    });
    await enableOfflinePayments(api, event.eventId);
    await api.createPromoCode(event.eventId, {
      code: 'RAIL10',
      discount_type: 'FIXED',
      discount: 5,
      discount_applies_to: 'ORDER',
    });

    const checkout = new CheckoutPage(page);
    await checkout.gotoPublicEvent(event.eventId, event.slug);

    // The picker lives in the rail; nothing is sold from the main column.
    const railRow = page.locator('.register-rail .hi-product-row').first();
    await expect(railRow).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('.event-main #tickets')).toHaveCount(0);
    await expect(page.locator('.register-rail .progress-note')).toBeVisible();

    // A stepper that always reads its quantity, rather than appearing on the first click.
    await expect(railRow.getByRole('textbox', { name: 'Quantity' })).toBeVisible();
    await expect(railRow.getByRole('button', { name: 'Decrease quantity' })).toBeDisabled();

    await checkout.setQuantityForProduct(event.productTitle, 2);
    await expect(railRow.getByRole('textbox', { name: 'Quantity' })).toHaveValue('2');

    await checkout.applyPromoCode('RAIL10');
    await expect(page.locator('.hi-promo-code-applied')).toContainText('RAIL10');
    await expect(page.locator('.hi-promo-code-applied')).toContainText('$5.00 off your order');

    await page.screenshot({ path: path.join(OUT, 'rail-selected.png'), fullPage: false });

    await page.locator('[data-testid="checkout-continue-button"]').click();
    await page.waitForURL(/\/checkout\/\d+\/[^/]+\/details/, { timeout: 20_000 });

    await checkout.fillOrderDetails({
      firstName: 'Rail',
      lastName: 'Buyer',
      email: uniqueEmail('rail'),
    });
    await checkout.continueToPayment();
    await checkout.chooseOfflinePayment();
    await expect(page.getByText('Your order is awaiting payment')).toBeVisible();
  });

  test('a sold-out rail offers the real waitlist', async ({ api, account, page, publicApi }) => {
    test.setTimeout(120_000);

    const event = await createSoldOutEvent(api, publicApi, account.organizerId, { waitlist: true });

    await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
    await page.waitForLoadState('networkidle');

    const publicEvent = new PublicEventPage(page);
    await publicEvent.joinWaitlist({ firstName: 'Wanda', email: uniqueEmail('waitlister') });
    await expect(page.getByText("You're on the waitlist!")).toBeVisible();
  });

  test('the designer preview buys through the same rail', async ({ api, account, authedPage }) => {
    test.setTimeout(120_000);

    const event = await createLiveEventWithProduct(api, {
      organizerId: account.organizerId,
      price: 25,
      quantityAvailable: 50,
    });

    await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);

    const frame = authedPage.frameLocator('iframe[title="Event Preview"]');
    const row = frame.locator('.register-rail .hi-product-row').first();
    await expect(row).toBeVisible({ timeout: 20_000 });

    await row.getByLabel('Increase quantity').click();
    const continueButton = frame.locator('[data-testid="checkout-continue-button"]');
    await expect(continueButton).toBeEnabled();
    await continueButton.click();

    // The preview's order path is the public page's: the frame lands in checkout.
    await expect
      .poll(
        async () => authedPage.frames().some((candidate) => /\/checkout\/\d+\/[^/]+\/details/.test(candidate.url())),
        { timeout: 20_000 },
      )
      .toBe(true);
  });
});
