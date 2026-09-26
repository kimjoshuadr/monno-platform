import type { APIRequestContext, APIResponse } from '@playwright/test';
import { test, expect } from '../../fixtures';
import { createDraftEventWithTicket, createSoldOutEvent } from '../../api/factory';
import { BASE_URL, API_BASE_URL } from '../../utils/env';

const waitlistBody = (priceId: number) => ({
  product_price_id: priceId,
  email: `qaqc-waitlist-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.test`,
  first_name: 'QAQC',
  last_name: 'Draft',
});

const eventIds = async (response: APIResponse): Promise<number[]> => {
  const body = (await response.json()) as { data?: { id: number }[] };
  return (body.data ?? []).map((event) => event.id);
};

/**
 * The contact endpoint is throttled (5/min/IP); repeated local runs can 429.
 * Retry through the rate-limit window before concluding.
 */
const contactStatus = async (
  publicApi: APIRequestContext,
  organizerId: number,
): Promise<number> => {
  for (let attempt = 0; attempt < 7; attempt++) {
    const response = await publicApi.post(`public/organizers/${organizerId}/contact`, {
      data: { name: 'QAQC', email: `qaqc-${attempt}@example.test`, message: 'Hello from QAQC.' },
    });
    if (response.status() !== 429) {
      return response.status();
    }
    await new Promise((resolve) => setTimeout(resolve, 10_000));
  }
  throw new Error('contact endpoint stayed rate-limited for over a minute');
};

test.describe('draft visibility', () => {
  test('a draft event is hidden from every anonymous surface and opens up once published', async ({
    api,
    publicApi,
    page,
    authedPage,
    account,
  }) => {
    await api.updateOrganizerStatus(account.organizerId, 'LIVE');
    const draft = await createDraftEventWithTicket(api, account.organizerId, { title: 'QAQC Draft Visibility' });
    await api.createQuestion(draft.eventId, {
      title: 'How did you hear about us?',
      type: 'SINGLE_LINE_TEXT',
      belongs_to: 'ORDER',
      product_ids: [],
      required: false,
      is_hidden: false,
    });
    await api.createPromoCode(draft.eventId, {
      code: 'qaqcdraft',
      discount_type: 'PERCENTAGE',
      discount: 10,
    });

    // --- DRAFT: every anonymous read/write surface refuses ---
    // The occurrences endpoint requires a range (422 without one) — pass a valid
    // one so the request actually reaches the visibility gate.
    const occurrenceRange = {
      start_date_from: new Date(Date.now() + 86_400_000).toISOString(),
      start_date_to: new Date(Date.now() + 7 * 86_400_000).toISOString(),
    };
    expect((await publicApi.get(`public/events/${draft.eventId}`)).status()).toBe(404);
    expect(
      (await publicApi.get(`public/events/${draft.eventId}/occurrences`, { params: occurrenceRange })).status(),
    ).toBe(404);
    expect((await publicApi.get(`public/events/${draft.eventId}/questions`)).status()).toBe(404);
    expect((await publicApi.get(`public/events/${draft.eventId}/promo-codes/qaqcdraft`)).status()).toBe(404);
    expect(
      (await publicApi.post(`public/events/${draft.eventId}/waitlist`, { data: waitlistBody(draft.priceId) })).status(),
    ).toBe(404);

    const listWhileDraft = await publicApi.get(`public/organizers/${account.organizerId}/events`);
    expect(listWhileDraft.status()).toBe(200);
    expect(await eventIds(listWhileDraft)).not.toContain(draft.eventId);

    // Sitemap must never mention a draft event (a stale cache can only omit, never include).
    // Match on the event id, not the slug: every run of this spec reuses the same title, so
    // slugs collide across runs and earlier LIVE events legitimately share this one's slug.
    const sitemap = await publicApi.get('public/sitemap-events-1.xml');
    if (sitemap.ok()) {
      expect(await sitemap.text()).not.toContain(`/event/${draft.eventId}/`);
    }

    // Public event page does not show the draft's title.
    const draftFetch = page.waitForResponse(
      (response) => response.url().includes(`/public/events/${draft.eventId}`),
      { timeout: 20_000 },
    );
    await page.goto(`${BASE_URL}/event/${draft.eventId}/${draft.slug}`);
    await draftFetch;
    await expect(page.getByText(draft.title)).toBeHidden();

    // The owner still previews it while DRAFT.
    await authedPage.goto(`${BASE_URL}/event/${draft.eventId}/preview`);
    await expect(authedPage.getByText(draft.title)).toBeVisible();

    // --- PUBLISHED: the same surfaces now serve it ---
    await api.publishEvent(draft.eventId);

    expect((await publicApi.get(`public/events/${draft.eventId}`)).status()).toBe(200);
    expect(
      (await publicApi.get(`public/events/${draft.eventId}/occurrences`, { params: occurrenceRange })).status(),
    ).toBe(200);

    const questions = await publicApi.get(`public/events/${draft.eventId}/questions`);
    expect(questions.status()).toBe(200);
    expect(((await questions.json()) as { data: unknown[] }).data.length).toBeGreaterThan(0);

    const promo = await publicApi.get(`public/events/${draft.eventId}/promo-codes/qaqcdraft`);
    expect(promo.status()).toBe(200);
    expect(((await promo.json()) as { valid: boolean }).valid).toBe(true);

    const listWhileLive = await publicApi.get(`public/organizers/${account.organizerId}/events`);
    expect(await eventIds(listWhileLive)).toContain(draft.eventId);

    const liveFetch = page.waitForResponse(
      (response) => response.url().includes(`/public/events/${draft.eventId}`),
      { timeout: 20_000 },
    );
    await page.goto(`${BASE_URL}/event/${draft.eventId}/${draft.slug}`);
    await liveFetch;
    await expect(page.getByText(draft.title).first()).toBeVisible();
  });

  test('waitlist refuses draft events but accepts a sold-out live event', async ({ api, publicApi, account }) => {
    await api.updateOrganizerStatus(account.organizerId, 'LIVE');
    const draft = await createDraftEventWithTicket(api, account.organizerId);

    const draftEntry = await publicApi.post(`public/events/${draft.eventId}/waitlist`, {
      data: waitlistBody(draft.priceId),
    });
    expect(draftEntry.status()).toBe(404);

    const soldOut = await createSoldOutEvent(api, publicApi, account.organizerId, { waitlist: true });
    const liveEntry = await publicApi.post(`public/events/${soldOut.eventId}/waitlist`, {
      data: waitlistBody(soldOut.priceId),
    });
    expect(liveEntry.status()).toBe(201);
  });

  test('a draft organizer hides its profile, events and contact form', async ({ freshAccount, publicApi, playwright }) => {
    test.setTimeout(180_000);
    const { api, organizerId } = freshAccount;

    await api.updateOrganizerStatus(organizerId, 'LIVE');
    const event = await api.createEvent({
      title: 'QAQC Draft Org Event',
      type: 'SINGLE',
      organizer_id: organizerId,
      start_date: new Date(Date.now() + 30 * 86_400_000).toISOString(),
      category: 'MUSIC',
      currency: 'USD',
      timezone: 'UTC',
    });
    await api.publishEvent(event.id);

    // LIVE organizer: profile + events + contact all reachable.
    expect((await publicApi.get(`public/organizers/${organizerId}`)).status()).toBe(200);
    expect((await publicApi.get(`public/organizers/${organizerId}/events`)).status()).toBe(200);
    expect(await contactStatus(publicApi, organizerId)).toBe(200);

    // DRAFT organizer: everything refuses for anonymous callers.
    await api.updateOrganizerStatus(organizerId, 'DRAFT');
    expect((await publicApi.get(`public/organizers/${organizerId}`)).status()).toBe(404);
    expect((await publicApi.get(`public/organizers/${organizerId}/events`)).status()).toBe(404);
    expect(await contactStatus(publicApi, organizerId)).toBe(404);

    // The owner still sees their own draft organizer (profile + events).
    const ownerApi = await playwright.request.newContext({
      baseURL: API_BASE_URL,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Accept: 'application/json', Authorization: `Bearer ${freshAccount.token}` },
    });
    expect((await ownerApi.get(`public/organizers/${organizerId}`)).status()).toBe(200);
    expect((await ownerApi.get(`public/organizers/${organizerId}/events`)).status()).toBe(200);
    await ownerApi.dispose();
  });
});
