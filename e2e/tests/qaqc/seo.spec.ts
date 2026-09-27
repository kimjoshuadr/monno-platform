import { test, expect, type Page } from '../../fixtures';
import { API_BASE_URL } from '../../utils/env';
import { findSeededEvent } from '../../utils/parity';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';
const ORIGIN = new URL(MONNO_URL).origin;
const APP_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5678';
const API = API_BASE_URL.replace(/\/+$/, '');

const canonicalOf = (page: Page) => page.locator('link[rel="canonical"]').first().getAttribute('href');
const robotsOf = (page: Page) => page.locator('meta[name="robots"]').first().getAttribute('content');
const ogImageOf = (page: Page) => page.locator('meta[property="og:image"]').first().getAttribute('content');
const jsonLdTypes = (page: Page) =>
  page.locator('script[type="application/ld+json"]').evaluateAll((nodes) =>
    nodes.flatMap((node) => {
      try {
        const parsed = JSON.parse(node.textContent ?? '{}');
        return Array.isArray(parsed) ? parsed.map((entry) => entry['@type']) : [parsed['@type']];
      } catch {
        return [];
      }
    }),
  );

/**
 * Technical SEO, end to end. The staging build is noindex (NEXT_PUBLIC_ALLOW_INDEXING=false),
 * so this asserts the noindex path plus the app-side canonical/noindex rules and the
 * Open Graph endpoints — including the edge cases (unknown slugs, unknown images).
 */
test.describe('technical SEO', () => {
  test('staging: robots.txt disallows everything and the sitemap is empty', async ({ request }) => {
    const robots = await request.get(`${MONNO_URL}/robots.txt`);
    expect(robots.ok()).toBeTruthy();
    expect((await robots.text()).replace(/\s+/g, ' ')).toContain('Disallow: /');

    const sitemap = await request.get(`${MONNO_URL}/sitemap.xml`);
    expect(sitemap.ok()).toBeTruthy();
    const xml = await sitemap.text();
    expect(xml).toContain('<urlset');
    // No public URLs are advertised while indexing is off.
    expect(xml).not.toMatch(/<loc>[^<]+\/(event|o|discover)\//);
  });

  test('every public page carries a canonical, noindex, an OG image and JSON-LD', async ({ page, publicApi }) => {
    const event = await findSeededEvent(publicApi);

    const pages: { path: string; type: string }[] = [
      { path: '/', type: 'WebSite' },
      { path: '/discover/', type: 'WebSite' },
      { path: '/o/', type: 'WebSite' },
      { path: '/privacy/', type: 'WebSite' },
      { path: '/terms/', type: 'WebSite' },
      { path: `/event/${event.id}/`, type: 'Event' },
      { path: `/o/${event.organizerId}/`, type: 'Organization' },
    ];

    for (const { path, type } of pages) {
      await page.goto(`${MONNO_URL}${path}`, { waitUntil: 'domcontentloaded' });

      expect(await canonicalOf(page), `canonical on ${path}`).toBe(`${ORIGIN}${path}`);

      const robots = await robotsOf(page);
      expect(robots, `robots on ${path}`).toContain('noindex');

      const ogImage = await ogImageOf(page);
      expect(ogImage, `og:image on ${path}`).toContain('/public/og/');

      const types = await jsonLdTypes(page);
      expect(types, `JSON-LD on ${path}`).toContain(type);
    }
  });

  test('the city and category pages have their own canonical and OG card', async ({ page }) => {
    await page.goto(`${MONNO_URL}/discover/new-york/`, { waitUntil: 'domcontentloaded' });
    expect(await canonicalOf(page)).toBe(`${ORIGIN}/discover/new-york/`);
    expect(await ogImageOf(page)).toContain('/public/og/city/new-york');

    await page.goto(`${MONNO_URL}/discover/category/music/`, { waitUntil: 'domcontentloaded' });
    expect(await canonicalOf(page)).toBe(`${ORIGIN}/discover/category/music/`);
    expect(await ogImageOf(page)).toContain('/public/og/category/MUSIC');
  });

  test('account and auth pages are noindex', async ({ page }) => {
    for (const path of ['/login/', '/register/', '/forgot-password/', '/settings/', '/profile/', '/create/']) {
      await page.goto(`${MONNO_URL}${path}`, { waitUntil: 'domcontentloaded' });
      expect(await robotsOf(page), `robots on ${path}`).toContain('noindex');
    }
  });

  test('unknown pages 404 and are noindex', async ({ page }) => {
    for (const path of ['/event/99999999/', '/o/99999999/', '/discover/nowhere-xyz/']) {
      const response = await page.goto(`${MONNO_URL}${path}`, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), `status on ${path}`).toBe(404);
      expect(await robotsOf(page), `robots on ${path}`).toContain('noindex');
    }
  });

  test('the app is noindex and points its canonical at the website', async ({ request, publicApi }) => {
    test.skip(
      new URL(APP_URL).hostname === 'localhost',
      'the local dev server only writes head tags on the client; the production build is the real check',
    );

    const event = await findSeededEvent(publicApi);

    // Read the server response, not the hydrated DOM — that is what a crawler sees,
    // and the dev server's client re-render can drop head tags it was given.
    const head = async (path: string) => (await (await request.get(`${APP_URL}${path}`)).text());

    const eventHtml = await head(`/event/${event.id}/${event.slug}`);
    expect(eventHtml).toMatch(/<meta[^>]+name="robots"[^>]+content="noindex[^"]*"/);
    expect(eventHtml).toMatch(new RegExp(`<link[^>]+rel="canonical"[^>]+href="${ORIGIN}/event/${event.id}/"`));

    const organizerHtml = await head(`/events/${event.organizerId}/${event.organizerSlug}`);
    expect(organizerHtml).toMatch(/<meta[^>]+name="robots"[^>]+content="noindex[^"]*"/);
    expect(organizerHtml).toMatch(new RegExp(`<link[^>]+rel="canonical"[^>]+href="${ORIGIN}/o/${event.organizerId}/"`));

    const robots = await request.get(`${APP_URL}/robots.txt`);
    expect(robots.ok()).toBeTruthy();
    expect((await robots.text()).replace(/\s+/g, ' ')).toContain('Disallow: /');
  });

  test('the Open Graph endpoints return PNGs, and 404 for unknown subjects', async ({ request, publicApi }) => {
    const png = Buffer.from([0x89, 0x50, 0x4e, 0x47]);
    const event = await findSeededEvent(publicApi);

    const ok = [
      `${API}/public/og/site`,
      `${API}/public/og/city/new-york`,
      `${API}/public/og/category/MUSIC`,
      `${API}/public/og/event/${event.id}`,
      `${API}/public/og/organizer/${event.organizerId}`,
    ];

    for (const url of ok) {
      const response = await request.get(url);
      expect(response.status(), url).toBe(200);
      expect(response.headers()['content-type'], url).toContain('image/png');
      const body = await response.body();
      expect(body.subarray(0, 4), `PNG magic for ${url}`).toEqual(png);
      expect(body.length, `size for ${url}`).toBeGreaterThan(10_000);
    }

    for (const url of [
      `${API}/public/og/city/no-such-city`,
      `${API}/public/og/category/NOPE`,
      `${API}/public/og/event/99999999`,
      `${API}/public/og/organizer/99999999`,
    ]) {
      const response = await request.get(url);
      expect(response.status(), url).toBe(404);
    }
  });
});
