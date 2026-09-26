import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures';
import { findSeededEvent } from '../../utils/parity';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';

/**
 * The website's own pages, against the seeded platform data (see
 * `php artisan monno:seed`). There is no hand-written catalogue any more, so the
 * event and organizer ids are resolved from the public feed at run time.
 */

/** Collects uncaught page errors; asserted empty at the end of each visit. */
const trackErrors = (page: Page): string[] => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  return errors;
};

test.describe('monno site', () => {
  test('homepage links all 24 platform categories', async ({ page }) => {
    const errors = trackErrors(page);
    await page.goto(`${MONNO_URL}/`);

    const categoryLinks = page.locator('a[href^="/discover/category/"]');
    await expect(categoryLinks.first()).toBeVisible();
    const hrefs = await categoryLinks.evaluateAll((links) =>
      [...new Set(links.map((link) => (link as HTMLAnchorElement).getAttribute('href')))],
    );
    expect(hrefs.length).toBe(24);
    expect(errors).toEqual([]);
  });

  test('event page renders every content block', async ({ page, publicApi }) => {
    const event = await findSeededEvent(publicApi);
    const errors = trackErrors(page);
    await page.goto(`${MONNO_URL}/event/${event.id}/`);

    await expect(page.locator('h1')).toBeVisible();
    await expect(page.locator('#about-heading')).toBeVisible();
    await expect(page.locator('#agenda-heading')).toBeVisible();
    await expect(page.locator('#details-heading')).toBeVisible();
    await expect(page.locator('#hosts-heading')).toBeVisible();
    await expect(page.getByText("Who's coming")).toBeVisible();
    expect(errors).toEqual([]);
  });

  test('a legacy category slug redirects to its platform replacement', async ({ page }) => {
    const errors = trackErrors(page);
    await page.goto(`${MONNO_URL}/discover/category/ai/`);
    await page.waitForURL('**/discover/category/tech/', { timeout: 15_000 });
    expect(page.url()).toContain('/discover/category/tech/');
    expect(errors).toEqual([]);
  });

  test('organizer room page renders identity and calendar', async ({ page, publicApi }) => {
    const event = await findSeededEvent(publicApi);
    const errors = trackErrors(page);
    await page.goto(`${MONNO_URL}/o/${event.organizerId}/`);

    await expect(page.getByText(`@${event.organizerSlug}`)).toBeVisible();
    await expect(
      page.getByText('A small production studio putting on open-air music, film and art nights', { exact: false }),
    ).toBeVisible();
    expect(errors).toEqual([]);
  });
});
