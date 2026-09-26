import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '../../fixtures';
import { createLiveEventWithProduct } from '../../api/factory';
import { BASE_URL } from '../../utils/env';
import { findSeededEvent } from '../../utils/parity';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';
const OUT = path.join(process.cwd(), 'test-results', 'parity');

/** The website's event page, section for section. */
const SECTION_TITLES = ['About this event', 'Running order', 'Details', 'Hosted by'];

const VIEWPORTS = {
  desktop: { width: 1440, height: 1200 },
  tablet: { width: 834, height: 1112 },
  mobile: { width: 390, height: 844 },
};

/**
 * The acceptance check: the platform's event page — and therefore the builder's preview,
 * which renders it — is the website's event page, not a lookalike.
 *
 * Needs the monno site on :3000 and the seeded platform data (`php artisan monno:seed`).
 * The event is resolved from the public feed so a reseed cannot desync the two sides.
 */
test.describe('website parity', () => {
  test('the platform event page mirrors the website event page', async ({ page, browser, publicApi }) => {
    test.setTimeout(180_000);
    await mkdir(OUT, { recursive: true });
    const seeded = await findSeededEvent(publicApi);

    const website = await browser.newPage({ viewport: VIEWPORTS.desktop, ignoreHTTPSErrors: true });
    await website.goto(`${MONNO_URL}/event/${seeded.id}/`, { waitUntil: 'networkidle' });
    await expect(website.locator('[data-testid="event-ticket-row"]').first()).toBeVisible({ timeout: 20_000 });

    await page.goto(`${BASE_URL}/event/${seeded.id}/${seeded.slug}`, { waitUntil: 'networkidle' });
    await expect(page.locator('.register-rail .hi-product-row').first()).toBeVisible({ timeout: 20_000 });

    // The same sections, in the same order.
    await expect(page.locator('.event-main .block-title')).toHaveText(SECTION_TITLES);
    await expect(website.locator('.event-main .block-title')).toHaveText(SECTION_TITLES);

    // The same frame: chrome, cover column with its host card, badges, the rail card.
    for (const selector of [
      '[data-od-id="site-header"]',
      '[data-od-id="site-footer"]',
      '.event-layout',
      '.event-crumbs',
      '[data-od-id="event-cover"]',
      '[data-od-id="event-host-card"]',
      '[data-od-id="event-follow"]',
      '.event-main .meta-row .badge',
      '[data-od-id="event-ticket-card"]',
      '.register-rail .price-tag',
      '.register-rail .progress',
      '.register-rail .hi-product-row',
      '[data-od-id="event-save"]',
      '[data-od-id="event-share"]',
      '[data-od-id="event-calendar"]',
      '[data-od-id="event-who-coming"]',
    ]) {
      await expect(page.locator(selector).first(), `${selector} should render on the platform page`).toBeVisible();
    }

    // Same chrome copy, and the same rail figures as the website (both read the platform).
    await expect(page.locator('[data-od-id="site-header"] .nav-link')).toHaveText([
      'Discover',
      "Calendars",
      "What's on",
    ]);
    const collapse = (value: string) => value.replace(/\s+/g, ' ').trim();
    const platformText = async (selector: string) => collapse(await page.locator(selector).innerText());
    const websiteText = async (selector: string) => collapse(await website.locator(selector).innerText());

    // Both read the same platform event, so the rail's figures and the page's own labels
    // must agree — rendered text included, not just markup.
    expect(await platformText('.register-rail .price-tag')).toBe(await websiteText('.register-rail .price-tag'));
    expect(await platformText('.register-rail .progress-note')).toBe(
      await websiteText('.register-rail .progress-note'),
    );
    // The website's dates are the event's own timezone — the platform must agree.
    expect(await platformText('.event-kicker .eyebrow')).toBe(await websiteText('.event-kicker .eyebrow'));
    expect(await platformText('.event-main .meta-row .badge')).toBe(
      await websiteText('.event-main .meta-row .badge'),
    );

    for (const [name, viewport] of Object.entries(VIEWPORTS)) {
      await page.setViewportSize(viewport);
      await website.setViewportSize(viewport);

      const platformRail = await page.locator('.register-rail').boundingBox();
      const websiteRail = await website.locator('.register-rail').boundingBox();
      // Both stack the rail above the content below the desktop breakpoint.
      expect(Math.sign(platformRail!.y - (await page.locator('.event-main').boundingBox())!.y)).toBe(
        Math.sign(websiteRail!.y - (await website.locator('.event-main').boundingBox())!.y),
      );

      await page.screenshot({ path: path.join(OUT, `platform-${name}.png`), fullPage: name !== 'desktop' });
      await website.screenshot({ path: path.join(OUT, `website-${name}.png`), fullPage: name !== 'desktop' });
    }

    await website.close();
  });

  test('the builder preview is the same page as the public event page', async ({ authedPage, api, account, browser }) => {
    test.setTimeout(180_000);

    // The preview is owner-only, so this one uses an event this worker owns.
    const event = await createLiveEventWithProduct(api, {
      organizerId: account.organizerId,
      price: 25,
      quantityAvailable: 50,
    });

    const context = await browser.newContext({
      baseURL: BASE_URL,
      ignoreHTTPSErrors: true,
      viewport: VIEWPORTS.desktop,
      storageState: await authedPage.context().storageState(),
    });

    const publicPage = await context.newPage();
    await publicPage.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
    await expect(publicPage.locator('.register-rail .hi-product-row').first()).toBeVisible({ timeout: 20_000 });

    const page = await context.newPage();
    await page.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
    const frame = page.frameLocator('iframe[title="Event Preview"]');

    await expect(frame.locator('#event-title')).toBeVisible({ timeout: 20_000 });

    // Section for section, the preview renders the page the visitor gets.
    expect(await frame.locator('.event-main .block-title').allTextContents()).toEqual(
      await publicPage.locator('.event-main .block-title').allTextContents(),
    );
    await expect(frame.locator('[data-od-id="site-header"]')).toBeVisible();
    await expect(frame.locator('[data-od-id="site-footer"]')).toBeVisible();
    await expect(frame.locator('.register-rail .hi-product-row').first()).toBeVisible();
    await expect(frame.locator('.event-main #tickets')).toHaveCount(0);
    await expect(frame.locator('[data-od-id="event-who-coming"]')).toBeVisible();

    await page.screenshot({ path: path.join(OUT, 'designer-preview.png') });
    await context.close();
  });
});
