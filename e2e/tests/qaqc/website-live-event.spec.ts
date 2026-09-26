import { test, expect } from '../../fixtures';
import { createDraftEventWithTicket, createLiveEventWithProduct } from '../../api/factory';
import { monnoSiteUrl } from '../../utils/parity';
import { BASE_URL } from '../../utils/env';
import { uniqueName } from '../../utils/unique';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';

/**
 * The point of the live event feed: an event created and published on the platform
 * appears on the website with no rebuild, and every share hands out the website's
 * page — not the ticket surface.
 */
test.describe('website live events', () => {
  test('an event published on the platform appears on the website immediately', async ({ api, account, page }) => {
    test.setTimeout(120_000);

    const event = await createLiveEventWithProduct(api, {
      organizerId: account.organizerId,
      price: 25,
      quantityAvailable: 50,
    });

    // The website's page for a platform event is keyed by the event id.
    await page.goto(`${MONNO_URL}/event/${event.eventId}/`);
    await expect(page.locator('#event-title')).toContainText(event.title, { timeout: 20_000 });

    // The rail sells the platform's own live products, not a demo state.
    await expect(page.locator('.register-rail .hi-product-row').first()).toBeVisible({ timeout: 20_000 });

    // The page's own share hands out the website URL.
    await page.locator('[data-od-id="event-share"]').click();
    await expect(page.locator('.share-url')).toHaveValue(`${monnoSiteUrl()}/event/${event.eventId}/`);
  });

  test('the organizer share points at the website page, not the ticket surface', async ({
    api,
    account,
    authedPage,
  }) => {
    test.setTimeout(120_000);

    const event = await createLiveEventWithProduct(api, {
      organizerId: account.organizerId,
      price: 25,
      quantityAvailable: 50,
    });

    await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}`);
    await authedPage.getByRole('button', { name: 'Share Event' }).first().click();
    await authedPage.getByRole('tab', { name: 'Copy Link' }).click();

    await expect(authedPage.getByLabel('Page URL')).toHaveValue(`${monnoSiteUrl()}/event/${event.eventId}/`);
  });

  test('the "Your event is live!" modal shows the website link', async ({ api, account, authedPage }) => {
    test.setTimeout(120_000);

    const event = await createDraftEventWithTicket(api, account.organizerId, { price: 25 });

    await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/dashboard`);
    await authedPage.waitForLoadState('networkidle');

    await authedPage.getByTestId('event-status-toggle').click();
    await authedPage.getByTestId('publish-event-confirm-button').click();
    await expect(authedPage.getByText('Your event is live!')).toBeVisible();

    // The link in the celebration is the website's page for the event, not the
    // organizer app's — and the Share Event button hands out the same one.
    await expect(authedPage.getByRole('dialog').getByRole('textbox')).toHaveValue(
      `${monnoSiteUrl()}/event/${event.eventId}/`,
    );
  });

  test('a published event is listed in Discover and has a host room', async ({ api, page }) => {
    test.setTimeout(120_000);

    const organizer = await api.createOrganizer(uniqueName('E2E Live Room'));
    await api.updateOrganizerStatus(organizer.id, 'LIVE');
    const event = await createLiveEventWithProduct(api, {
      organizerId: organizer.id,
      price: 25,
      quantityAvailable: 50,
    });

    // Discover narrows to it through search — the pool holds every live event.
    // The directory feed revalidates on a short interval, so retry until the new
    // event is in it rather than assuming the first load already has it.
    await expect(async () => {
      await page.goto(`${MONNO_URL}/discover/`);
      await page.locator('#discover-search').fill(event.title);
      await expect(page.getByRole('link', { name: event.title })).toBeVisible({ timeout: 2000 });
    }).toPass({ timeout: 30_000 });

    // Its organizer has a real room on the site, listing the event. The card is
    // asserted as attached: the timeline's scroll-reveal leaves off-screen rows at
    // opacity 0, so "visible" is a motion state, not a data one. The room is keyed
    // by organizer id (platform slugs are not unique).
    await expect(async () => {
      await page.goto(`${MONNO_URL}/o/${organizer.id}/`);
      await expect(page.locator('.cal-card-title', { hasText: event.title }).first()).toBeAttached({
        timeout: 2000,
      });
    }).toPass({ timeout: 30_000 });
  });
});
