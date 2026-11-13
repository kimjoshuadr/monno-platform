import { test, expect } from '../../fixtures';

/**
 * The production indexing path: with `NEXT_PUBLIC_ALLOW_INDEXING=true` the site
 * must allow crawlers, advertise a real sitemap and mark pages `index, follow`.
 *
 * Point `MONNO_INDEXING_URL` at a server built with the flag on (default :3100);
 * the whole file skips when nothing is listening there.
 */
const BASE = process.env.MONNO_INDEXING_URL ?? 'http://localhost:3100';

test.describe('indexing path (NEXT_PUBLIC_ALLOW_INDEXING=true)', () => {
  test.beforeEach(async ({ request }) => {
    const reachable = await request
      .get(`${BASE}/robots.txt`)
      .then((response) => response.ok())
      .catch(() => false);
    test.skip(!reachable, `no indexing server on ${BASE}`);
  });

  test('robots.txt allows crawling and advertises the sitemap', async ({ request }) => {
    const robots = await request.get(`${BASE}/robots.txt`);
    const body = await robots.text();
    expect(body).not.toMatch(/Disallow: \/$/m);
    expect(body).toMatch(/Sitemap: .*\/sitemap\.xml/);
    // Account pages stay out even when indexing is on.
    expect(body).toContain('/settings/');
  });

  test('the sitemap lists events, rooms, cities and categories', async ({ request }) => {
    const sitemap = await request.get(`${BASE}/sitemap.xml`);
    expect(sitemap.ok()).toBeTruthy();
    const xml = await sitemap.text();

    expect(xml).toContain('<urlset');
    expect(xml).toMatch(/<loc>[^<]+\/event\/[0-9]+\//);
    expect(xml).toMatch(/<loc>[^<]+\/o\/[^<]+\//);
    expect(xml).toMatch(/<loc>[^<]+\/discover\/category\//);
    expect(xml).not.toMatch(/<loc>[^<]+\/(login|settings|profile)\//);
  });

  test('public pages are indexable, and an event from the sitemap is too', async ({ page, request }) => {
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
    expect(await page.locator('meta[name="robots"]').first().getAttribute('content')).toContain('index');

    const xml = await (await request.get(`${BASE}/sitemap.xml`)).text();
    const eventLoc = xml.match(/<loc>([^<]*\/event\/[0-9]+\/)<\/loc>/)?.[1];
    expect(eventLoc, 'an event in the sitemap').toBeTruthy();

    const path = new URL(eventLoc as string).pathname;
    const response = await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);
    expect(await page.locator('meta[name="robots"]').first().getAttribute('content')).toContain('index');
  });
});
