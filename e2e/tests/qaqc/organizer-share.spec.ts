import { test, expect, type Page } from '../../fixtures';
import { createLiveEventWithProduct } from '../../api/factory';
import { monnoSiteUrl } from '../../utils/parity';
import { BASE_URL } from '../../utils/env';
import { uniqueName } from '../../utils/unique';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';

const shareUrl = async (authedPage: Page, organizerId: number) => {
  await authedPage.goto(`${BASE_URL}/manage/organizer/${organizerId}`);
  await authedPage.getByRole('button', { name: 'Share Organizer Page' }).first().click();
  await authedPage.getByRole('tab', { name: 'Copy Link' }).click();
  return authedPage.getByLabel('Page URL');
};

/**
 * The organizer share link is the canonical website room once the website has
 * one. The website lists a room as soon as the organizer has a LIVE event (the
 * organizer's own status does not gate it), so before that there is nothing to
 * point at and the app's own page — the only copy — is shared instead.
 */
test.describe('organizer share links', () => {
  test('a LIVE event makes the share point at the website room', async ({ api, authedPage, page }) => {
    test.setTimeout(120_000);

    const organizer = await api.createOrganizer(uniqueName('E2E Share Live'));
    await createLiveEventWithProduct(api, { organizerId: organizer.id, price: 25, quantityAvailable: 50 });

    await expect(await shareUrl(authedPage, organizer.id)).toHaveValue(`${monnoSiteUrl()}/o/${organizer.id}/`);

    // The website's room exists — retry, the directory feed revalidates on a short interval.
    await expect(async () => {
      const response = await page.request.get(`${MONNO_URL}/o/${organizer.id}/`);
      expect(response.status()).toBe(200);
    }).toPass({ timeout: 30_000 });
  });

  test('with no LIVE event the link stays on the app and the website has no room', async ({
    api,
    authedPage,
    page,
  }) => {
    test.setTimeout(120_000);

    const organizer = await api.createOrganizer(uniqueName('E2E Share Empty'));

    await expect(await shareUrl(authedPage, organizer.id)).toHaveValue(
      `${BASE_URL}/events/${organizer.id}/${organizer.slug}`,
    );

    const response = await page.request.get(`${MONNO_URL}/o/${organizer.id}/`);
    expect(response.status()).toBe(404);
  });
});
