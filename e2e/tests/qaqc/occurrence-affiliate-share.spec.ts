import { test, expect } from '../../fixtures';
import { createRecurringLiveEvent } from '../../api/factory';
import { OccurrencePage } from '../../pages/occurrence.page';
import { monnoSiteUrl } from '../../utils/parity';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';

/**
 * The website is the canonical place a share link lands, so the parameters an
 * organizer shares — a specific date (`?occurrence_id=`) and an affiliate code
 * (`?aff=`) — have to be honoured here, not only on the ticket surface. Both
 * ride into the order the website creates before it hands off to checkout.
 */
test.describe('website share links: occurrence and affiliate', () => {
  test('a shared date and affiliate code are honoured at checkout', async ({ api, account, page }) => {
    test.setTimeout(120_000);

    const event = await createRecurringLiveEvent(api, account.organizerId, { count: 3, price: 0 });
    // A date other than the first, so the link — not the default — is what selects.
    const occurrence = event.occurrences[1];

    await page.goto(
      `${MONNO_URL}/event/${event.eventId}/?occurrence_id=${occurrence.id}&aff=E2EAFFCODE`,
    );

    const picker = page.locator('[data-od-id="event-occurrence-picker"]');
    await expect(picker).toBeVisible({ timeout: 20_000 });
    await expect(picker.locator('.occurrence-chip[aria-pressed="true"]')).toHaveAttribute(
      'data-occurrence-id',
      String(occurrence.id),
    );

    const firstRow = page.locator('.register-rail [data-testid="event-ticket-row"]').first();
    await expect(firstRow).toBeVisible({ timeout: 20_000 });
    await firstRow.getByRole('button', { name: /Increase/ }).first().click();

    const orderRequest = page.waitForRequest(
      (request) =>
        request.url().includes(`/public/events/${event.eventId}/order`) && request.method() === 'POST',
    );
    await page.getByTestId('checkout-continue-button').click();

    const body = JSON.parse((await orderRequest).postData() ?? '{}');
    expect(body.event_occurrence_id).toBe(occurrence.id);
    expect(body.affiliate_code).toBe('E2EAFFCODE');
  });

  test('the builder shares a date as the website page, not the ticket surface', async ({
    api,
    account,
    authedPage,
  }) => {
    test.setTimeout(120_000);

    const event = await createRecurringLiveEvent(api, account.organizerId, { count: 3, price: 0 });

    const occurrences = new OccurrencePage(authedPage);
    await occurrences.goto(event.eventId);
    await occurrences.chooseRowAction(occurrences.occurrenceRows().first(), 'Share');

    await authedPage.getByRole('tab', { name: 'Copy Link' }).click();
    await expect(authedPage.getByLabel('Page URL')).toHaveValue(
      new RegExp(`^${monnoSiteUrl()}/event/${event.eventId}/\\?occurrence_id=\\d+$`),
    );
  });
});
