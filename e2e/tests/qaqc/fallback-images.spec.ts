import { test, expect, type Page, type Route } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';

/**
 * Default event / organizer image fallbacks.
 *
 * When an event has no uploaded images the UI must show the curated category
 * photo (categorized) or a generated typographic poster (uncategorized), and an
 * organizer without a logo must get a deterministic brand gradient with
 * initials — never an empty box or a bare icon.
 *
 * Fixtures (real rows, no images):
 *   - token: /tmp/qa-token (registered account)
 *   - organizer without logo: /tmp/qa-org
 *   - event (MUSIC, no images): /tmp/qa-ev
 * Public events are stripped at the network layer so the check is seed-independent.
 */

const FALLBACK_SRC = /\/images\/categories\/.+|data:image\/svg\+xml/;

const readTmp = (p: string) => (existsSync(p) ? readFileSync(p, 'utf8').trim() : '');

async function stripEventImages(route: Route, { removeCategory = false } = {}) {
  const response = await route.fetch();
  const body = await response.json();
  const strip = (e: any) => ({
    ...e,
    images: [],
    category: removeCategory ? null : e.category,
    organizer: e.organizer ? { ...e.organizer, images: [] } : e.organizer,
  });
  if (Array.isArray(body?.data)) body.data = body.data.map(strip);
  else if (body?.data) body.data = strip(body.data);
  await route.fulfill({ response, json: body });
}

async function firstPublicEvent(page: Page) {
  // page.request shares cookies/state and resolves against baseURL's origin…
  // but the browser talks to the API directly, so use the absolute API URL.
  const res = await page.request.get('http://localhost:8080/public/events?view=card');
  const events = (await res.json()).data;
  expect(events.length).toBeGreaterThan(0);
  return events;
}

test.describe('default event / organizer image fallbacks', () => {
  test('event room shows the category-photo cover when the event has no images', async ({ page }) => {
    await page.route('**/public/events**', (route) => stripEventImages(route));

    const events = await firstPublicEvent(page);
    const ev = events.find((e: any) => e.category) ?? events[0];

    await page.goto(`/event/${ev.id}/${ev.slug}`);

    const cover = page.locator('[data-od-id="event-cover"] img').first();
    await expect(cover).toBeVisible({ timeout: 20_000 });
    expect((await cover.getAttribute('src')) ?? '').toMatch(FALLBACK_SRC);
    await page.screenshot({ path: 'test-results/fallback-room.png' });
  });

  test('uncategorized + imageless event gets a typographic poster, not an empty box', async ({ page }) => {
    await page.route('**/public/events**', (route) => stripEventImages(route, { removeCategory: true }));

    const events = await firstPublicEvent(page);
    const ev = events[0];

    await page.goto(`/event/${ev.id}/${ev.slug}`);

    const cover = page.locator('[data-od-id="event-cover"] img').first();
    await expect(cover).toBeVisible({ timeout: 20_000 });
    expect((await cover.getAttribute('src')) ?? '').toMatch(/^data:image\/svg\+xml/);
    await page.screenshot({ path: 'test-results/fallback-poster.png' });
  });

  test.describe('authenticated dashboard', () => {
    test.skip(() => !readTmp('/tmp/qa-token'), 'no /tmp/qa-token fixture — create one first');

    test.beforeEach(async ({ page }) => {
      await page.addInitScript((token) => localStorage.setItem('token', token), readTmp('/tmp/qa-token'));
    });

    test('event card uses the category photo for an event with no images', async ({ page }) => {
      await page.goto('/manage/events');
      await expect(page.getByText('QA Fallback Event')).toBeVisible({ timeout: 20_000 });

      const cardImage = page.locator('[class*="compactImage"], [class*="image_"]').first();
      await expect(cardImage).toBeVisible();
      const bg = await cardImage.evaluate((el) => getComputedStyle(el).backgroundImage);
      expect(bg).toMatch(/images\/categories\/|data:image\/svg\+xml/);
      await page.screenshot({ path: 'test-results/fallback-card.png' });
    });

    test('organizer switcher shows a brand gradient with initials, not an icon', async ({ page }) => {
      await page.goto('/manage/events');
      await expect(page.getByText('2 organizers')).toBeVisible({ timeout: 20_000 });

      // The switcher's logo placeholders carry an inline linear-gradient and initials.
      const matched = await page.evaluate(() => {
        const hits: { text: string; bg: string }[] = [];
        document.querySelectorAll('div').forEach((el) => {
          const bg = getComputedStyle(el).backgroundImage;
          const text = (el.textContent || '').trim();
          if (bg.includes('linear-gradient') && /^[A-Z]{1,3}$/.test(text)) {
            hits.push({ text, bg });
          }
        });
        return hits;
      });
      expect(matched.length).toBeGreaterThan(0);
      expect(matched[0].bg).toContain('linear-gradient');
      expect(matched[0].text.length).toBeGreaterThan(0);
      await page.screenshot({ path: 'test-results/fallback-organizer.png' });
    });
  });
});
