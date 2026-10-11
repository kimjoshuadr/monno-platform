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
  // A junk city with a valid region falls back to the region; the junk string
  // itself must never render.
  { label: 'junk-city-state-fallback', city: 'Atlantis', state: 'Metro Manila', expect: 'Manila' },
  // Nothing recognisable at all: no city is shown, anywhere.
  { label: 'junk-both', city: 'Atlantis', state: 'Nowhereland', expect: '' },
];

test('city normalization: platform city strings fold onto the known set', async ({ api, account, page }) => {
  test.setTimeout(240_000);
  await mkdir(SHOTS, { recursive: true });
  await api.updateOrganizerStatus(account.organizerId, 'LIVE');

  const created: { c: Case; eventId: number; title: string }[] = [];
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
    created.push({ c, eventId: event.eventId, title: event.title });
    console.log(`created ${c.label}: raw city "${c.city || '(blank)'}" -> event ${event.eventId}`);
  }

  // 1) Known variants render their canonical city in the breadcrumb; junk
  //    strings never render — a junk city falls back to its region, and with
  //    nothing recognisable at all, no city is shown.
  for (const { c, eventId } of created) {
    await page.goto(`${MONNO_URL}/event/${eventId}/`, { waitUntil: 'networkidle' });
    const citySpans = page.locator('.event-crumbs .meta-row > span:not([aria-hidden])');
    // The event's own column only — the site footer legitimately says
    // "Makati City, Metro Manila" and must not trip the raw-string checks.
    const content = await page.locator('.event-layout').innerText();
    if (c.city) {
      expect(content, `${c.label}: the raw city "${c.city}" must never render`).not.toContain(c.city);
    }
    if (c.state) {
      expect(content, `${c.label}: the raw state "${c.state}" must never render`).not.toContain(c.state);
    }
    if (c.expect) {
      const city = await citySpans.innerText();
      console.log(`${c.label}: raw "${c.city || '(blank)'}" rendered as "${city}"`);
      expect(city, `${c.label} should render as ${c.expect}`).toBe(c.expect);
      if (c.label === 'qc') {
        await page.locator('.event-crumbs').screenshot({ path: `${SHOTS}/normalization-crumb-qc.png` });
      }
    } else {
      await expect(citySpans, `${c.label} should have no city crumb`).toHaveCount(0);
      await expect(page.locator('h1')).toBeVisible();
      console.log(`${c.label}: nothing recognisable — no city shown`);
    }
  }

  // The pool revalidates every 5s — let the city surfaces pick the new events up.
  await page.waitForTimeout(7000);

  // 2) The "qc" event is on the Quezon City page, in its own feed. Scoped by
  //    the event's unique title so accumulated test events can't push it off
  //    page one.
  await page.goto(
    `${MONNO_URL}/discover/quezon-city/?q=${encodeURIComponent(created[0].title)}`,
    { waitUntil: 'networkidle' },
  );
  await expect(page.locator(`a[href="/event/${created[0].eventId}/"]`).first()).toBeVisible({
    timeout: 20_000,
  });
  await page.screenshot({ path: `${SHOTS}/normalization-qc-feed.png`, fullPage: true });

  // 3) The state-fallback event lands on Manila — a city that had no events.
  await page.goto(
    `${MONNO_URL}/discover/manila/?q=${encodeURIComponent(created[3].title)}`,
    { waitUntil: 'networkidle' },
  );
  await expect(page.locator(`a[href="/event/${created[3].eventId}/"]`).first()).toBeVisible({
    timeout: 20_000,
  });

  // 4) The unknown city is not surfaced anywhere: search finds nothing and the
  //    city page does not exist.
  await page.goto(`${MONNO_URL}/discover/?q=Atlantis`, { waitUntil: 'networkidle' });
  await expect(page.getByText('Nothing matches that yet')).toBeVisible({ timeout: 20_000 });
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
