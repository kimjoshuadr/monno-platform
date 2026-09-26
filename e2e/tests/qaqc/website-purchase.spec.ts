import { readFileSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '../../fixtures';
import { findSeededEvent, monnoSiteUrl } from '../../utils/parity';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';
const OUT = path.join(process.cwd(), 'test-results', 'website');

/**
 * The website's own ticketing: the rail sells real tickets through the platform,
 * and the page's own actions — saving, sharing, the calendar file — actually do
 * something. Every event on the site is seeded platform data now, so each test
 * resolves the seeded event from the public feed and its page is `/event/{id}/`.
 */
test.describe('website ticketing', () => {
  test('the website sells through the platform checkout, with no fake popup', async ({ page, publicApi }) => {
    test.setTimeout(120_000);
    const event = await findSeededEvent(publicApi);

    await page.goto(`${MONNO_URL}/event/${event.id}/`);
    await expect(page.locator('[data-testid="event-ticket-row"]').first()).toBeVisible({ timeout: 20_000 });

    // The old demo registration dialog is gone for good.
    await expect(page.locator('[data-od-id="register-dialog"]')).toHaveCount(0);
    await expect(page.getByText('You\u2019re on the list')).toHaveCount(0);

    await page.getByLabel('Increase General admission').click();
    const continueButton = page.locator('[data-testid="checkout-continue-button"]');
    await expect(continueButton).toBeEnabled();

    await continueButton.click();
    await page.waitForURL(/\/checkout\/\d+\/[^/]+\/details/, { timeout: 20_000 });

    // The platform's checkout takes over, with a real reserved order behind it.
    await expect(page.getByLabel(/^First Name/)).toBeVisible();
    await expect(page).toHaveURL(/localhost:5678/);
  });

  test('saving and sharing work on the event page', async ({ page, publicApi }) => {
    const event = await findSeededEvent(publicApi);
    const websiteUrl = `${monnoSiteUrl()}/event/${event.id}/`;

    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
    await mkdir(OUT, { recursive: true });
    await page.goto(`${MONNO_URL}/event/${event.id}/`);
    await expect(page.locator('[data-testid="event-ticket-row"]').first()).toBeVisible({ timeout: 20_000 });

    const save = page.locator('[data-od-id="event-save"]');
    await expect(save).toHaveText(/Save for later/);
    await save.click();
    await expect(save).toHaveText(/Saved/);

    await page.reload();
    await expect(page.locator('[data-od-id="event-save"]')).toHaveText(/Saved/);

    await page.locator('[data-od-id="event-share"]').click();
    // The link is the site's own page, not the address bar.
    await expect(page.locator('.share-url')).toHaveValue(websiteUrl);
    await page.screenshot({ path: path.join(OUT, 'share-dialog-website.png'), animations: 'disabled' });
    await page.locator('[data-od-id="share-copy"]').click();
    await expect(page.locator('[data-od-id="share-copy"]')).toHaveText(/Link copied/);
    const clipboard = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboard).toBe(websiteUrl);
  });

  test('the calendar button downloads the event as .ics', async ({ page, publicApi }) => {
    const event = await findSeededEvent(publicApi);
    const websiteUrl = `${monnoSiteUrl()}/event/${event.id}/`;

    await page.goto(`${MONNO_URL}/event/${event.id}/`);
    await expect(page.locator('[data-testid="event-ticket-row"]').first()).toBeVisible({ timeout: 20_000 });

    const download = page.waitForEvent('download');
    await page.locator('[data-od-id="event-calendar"]').click();

    const file = await download;
    expect(file.suggestedFilename()).toContain('.ics');
    // The entry points at the site's own page, not the host the file was built on.
    const body = readFileSync(await file.path(), 'utf8');
    expect(body).toContain(`URL:${websiteUrl}`);
  });

  test('the share link is the site origin, not the host the page was opened on', async ({ page, publicApi }) => {
    const event = await findSeededEvent(publicApi);
    const websiteUrl = `${monnoSiteUrl()}/event/${event.id}/`;

    // The site also gets opened from other hosts (OpenDesign's preview, 127.0.0.1);
    // the link it hands out must still be the site's own origin.
    await page.goto(`http://127.0.0.1:3000/event/${event.id}/`);
    const share = page.locator('[data-od-id="event-share"]');
    await expect(share).toBeVisible({ timeout: 20_000 });

    await share.click();
    await expect(page.locator('.share-url')).toHaveValue(websiteUrl);
  });

  test('following the host works from the event page', async ({ page, publicApi }) => {
    const event = await findSeededEvent(publicApi);

    await page.goto(`${MONNO_URL}/event/${event.id}/`);
    await expect(page.locator('[data-testid="event-ticket-row"]').first()).toBeVisible({ timeout: 20_000 });

    const follow = page.locator('[data-od-id="event-follow"]');
    await expect(follow).toHaveText(/Follow/);

    await follow.click();
    await expect(follow).toHaveText(/Following/);
    await expect(follow).toHaveAttribute('aria-pressed', 'true');

    // The follow lives in the same store the calendar pages read, so it survives a reload.
    await page.reload();
    await expect(page.locator('[data-od-id="event-follow"]')).toHaveText(/Following/);

    await page.locator('[data-od-id="event-follow"]').click();
    await expect(page.locator('[data-od-id="event-follow"]')).toHaveText(/Follow/);
  });
});
