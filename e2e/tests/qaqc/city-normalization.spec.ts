import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '../../fixtures';
import { createLiveEventWithPaidTicket } from '../../api/factory';

const MONNO_URL = process.env.MONNO_URL ?? 'http://localhost:3000';
const SHOTS = path.join(process.cwd(), 'test-results', 'normalization');

/**
 * City normalization: the platform stores whatever the organizer typed (or
 * Google Places returned) — "qc", "mandaluyong city", "las pinas", or nothing
 * but a region. The website folds those onto the known-city set (lib/cityAliases)
 * before city surfaces see them. This spec creates one event per variant and
 * checks the rendered city, the city page, the filter search, and the 404 for
 * a genuinely unknown city.
 */
type Case = { label: string; city: string; state?: string; expect: string };

const CASES: Case[] = [
  { label: 'qc', city: 'qc', state: 'Metro Manila', expect: 'Quezon City' },
  { label: 'mandaluyong-city', city: 'mandaluyong city', expect: 'Mandaluyong' },
  { label: 'las-pinas', city: 'las pinas', expect: 'Las Piñas' },
  { label: 'state-fallback', city: '', state: 'Metro Manila', expect: 'Manila' },
  { label: 'unknown', city: 'Atlantis', expect: 'Atlantis' },
];

test('city normalization: platform city strings fold onto the known set', async ({ api, account, page }) => {
  test.setTimeout(240_000);
  await mkdir(SHOTS, { recursive: true });
  await api.updateOrganizerStatus(account.organizerId, 'LIVE');

  const created: { c: Case; eventId: number }[] = [];
  for (const c of CASES) {
    const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
    const location = await api.createOrganizerLocation(account.organizerId, {
      name: `Loc ${c.label}`,
      structured_address: {
        venue_name: 'Normalization Test Venue',
        address_line_1: '1 Test Street',
        city: c.city,
        state_or_region: c.state ?? 'Metro Manila',
        zip_or_postal_code: '1000',
        country: 'PH',
      },
    });
    await api.updateEventLocation(event.eventId, {
      event_location: { type: 'IN_PERSON', location_id: location.id },
    });
    created.push({ c, eventId: event.eventId });
    console.log(`created ${c.label}: raw city "${c.city || '(blank)'}" -> event ${event.eventId}`);
  }

  // 1) Every variant renders its canonical city in the event page breadcrumb.
  for (const { c, eventId } of created) {
    await page.goto(`${MONNO_URL}/event/${eventId}/`, { waitUntil: 'networkidle' });
    const city = await page.locator('.event-crumbs .meta-row > span:not([aria-hidden])').innerText();
    console.log(`${c.label}: raw "${c.city || '(blank)'}" rendered as "${city}"`);
    expect(city, `${c.label} should render as ${c.expect}`).toBe(c.expect);
    if (c.label === 'qc') {
      await page.locator('.event-crumbs').screenshot({ path: `${SHOTS}/normalization-crumb-qc.png` });
    }
  }

  // The pool revalidates every 5s — let the city surfaces pick the new events up.
  await page.waitForTimeout(7000);

  // 2) The "qc" event is on the Quezon City page, in its own feed.
  await page.goto(`${MONNO_URL}/discover/quezon-city/`, { waitUntil: 'networkidle' });
  await expect(page.locator(`a[href="/event/${created[0].eventId}/"]`).first()).toBeVisible({
    timeout: 20_000,
  });
  await page.screenshot({ path: `${SHOTS}/normalization-qc-feed.png`, fullPage: true });

  // 3) The state-fallback event lands on Manila — a city that had no events.
  await page.goto(`${MONNO_URL}/discover/manila/`, { waitUntil: 'networkidle' });
  await expect(page.locator(`a[href="/event/${created[3].eventId}/"]`).first()).toBeVisible({
    timeout: 20_000,
  });

  // 4) The unknown city keeps its name in feeds (search finds it) but has no page.
  await page.goto(`${MONNO_URL}/discover/?q=Atlantis`, { waitUntil: 'networkidle' });
  await expect(page.locator(`a[href="/event/${created[4].eventId}/"]`).first()).toBeVisible({
    timeout: 20_000,
  });
  const atlantis = await page.request.get(`${MONNO_URL}/discover/atlantis/`);
  expect(atlantis.status()).toBe(404);

  // 5) The landing grid: Manila is no longer a filler — it has an event.
  await page.goto(`${MONNO_URL}/`, { waitUntil: 'networkidle' });
  const manilaTile = page.locator('[data-od-id="home-city-manila"]');
  await expect(manilaTile).toBeVisible();
  const manilaMeta = await manilaTile.locator('.explore-tile-meta').innerText();
  console.log('home Manila tile meta:', manilaMeta.replace(/\s+/g, ' '));
  expect(manilaMeta).not.toContain('No events yet');
  await page.locator('#cities').screenshot({ path: `${SHOTS}/normalization-home-cities.png` });
});
