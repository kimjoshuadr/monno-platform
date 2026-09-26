import { test, expect } from '../../fixtures';
import { createDraftEvent } from '../../api/factory';
import { BASE_URL } from '../../utils/env';

const EVENT_BLOCK_LABELS = [
  'Hero', 'About', 'Agenda', 'Venue', 'Host', 'Attendees',
  'Text', 'Button', 'FAQ', 'Lineup', 'Gallery', 'Embed',
];

interface SettingsWithBlocks {
  homepage_blocks?: { type: string; settings?: Record<string, unknown> }[];
}

test.describe('page builder surfaces', () => {
  test('event homepage designer exposes every builder section and all 12 block types', async ({
    authedPage,
    api,
    account,
  }) => {
    const { eventId } = await createDraftEvent(api, account.organizerId, { title: 'QAQC Builder Event' });
    await authedPage.goto(`${BASE_URL}/manage/event/${eventId}/homepage-designer`);

    // Sidebar sections (all open by default).
    for (const heading of ['Images', 'Theme & Colors', 'Typography', 'Button Text', 'Page sections']) {
      await expect(authedPage.getByText(heading, { exact: true })).toBeVisible();
    }
    await expect(authedPage.getByText('Square Event Image', { exact: true })).toBeVisible();
    await expect(authedPage.getByText('Cover Image', { exact: true })).toHaveCount(0);
    await expect(authedPage.getByText('Continue Button Text', { exact: true })).toBeVisible();

    // The Add section menu lists the registry (6 data-driven + 6 authored). Tickets is not
    // offered: the picker lives in the page's rail.
    await authedPage.getByRole('button', { name: 'Add section' }).click();
    await expect(authedPage.getByRole('menu')).toBeVisible();
    for (const label of EVENT_BLOCK_LABELS) {
      await expect(authedPage.getByRole('menuitem', { name: label, exact: true })).toBeVisible();
    }
    await expect(authedPage.getByRole('menuitem', { name: 'Tickets', exact: true })).toHaveCount(0);

    // Author a TEXT block and save it.
    const blockBody = authedPage.getByRole('textbox', { name: 'Text', exact: true });
    await authedPage.getByRole('menuitem', { name: 'Text', exact: true }).click();
    await expect(blockBody).toBeVisible();
    await blockBody.fill('QAQC paragraph authored in the builder.');
    await authedPage.getByRole('button', { name: 'Save Changes' }).click();
    await expect(authedPage.getByText('Successfully Updated Homepage Design')).toBeVisible();

    // Persisted server-side (poll: the toast can land while the read settles under load).
    await expect
      .poll(
        async () => {
          const settings = (await api.getEventSettings(eventId)) as SettingsWithBlocks;
          return (
            settings.homepage_blocks?.some(
              (block) => block.type === 'TEXT' && block.settings?.body === 'QAQC paragraph authored in the builder.',
            ) ?? false
          );
        },
        { timeout: 15_000 },
      )
      .toBe(true);

    // Live preview iframe renders the event.
    await expect(authedPage.locator('iframe[title="Event Preview"]')).toBeVisible();
  });

  test('organizer homepage designer keeps images, theme, typography and backgrounds but drops sections', async ({
    authedPage,
    account,
  }) => {
    await authedPage.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/organizer-homepage-designer`);

    for (const heading of ['Images', 'Theme & Colors', 'Typography']) {
      await expect(authedPage.getByText(heading, { exact: true })).toBeVisible();
    }
    await expect(authedPage.getByText('Cover Image', { exact: true })).toBeVisible();
    await expect(authedPage.getByText('Logo', { exact: true })).toBeVisible();

    await expect(authedPage.getByTestId('background-type-select')).toBeVisible();
    await expect(authedPage.getByLabel(/Background Color/i)).toBeVisible();

    await expect(authedPage.getByText('Page sections', { exact: true })).toHaveCount(0);
    await expect(authedPage.getByRole('button', { name: 'Add section' })).toHaveCount(0);
  });

  test('the live preview renders a draft event for a token-only session', async ({ api, account, browser }) => {
    test.setTimeout(120_000);

    const { eventId } = await createDraftEvent(api, account.organizerId, { title: 'QAQC Preview Auth Event' });

    // Auth by stored token only, with no auth cookie — the state a browser ends up in
    // once the JWT cookie expires. Manage API calls keep working off the token, and the
    // preview must too (it renders the draft through the owner-authenticated public API).
    const context = await browser.newContext({
      baseURL: BASE_URL,
      ignoreHTTPSErrors: true,
      storageState: {
        cookies: [],
        origins: [{ origin: BASE_URL, localStorage: [{ name: 'token', value: account.token }] }],
      },
    });
    const page = await context.newPage();

    const publicEventResponses: number[] = [];
    page.on('response', (response) => {
      if (response.url().includes(`/public/events/${eventId}`)) {
        publicEventResponses.push(response.status());
      }
    });

    await page.goto(`${BASE_URL}/manage/event/${eventId}/homepage-designer`);
    await expect(page.getByRole('button', { name: 'Add section' })).toBeVisible();

    const preview = page.frameLocator('iframe[title="Event Preview"]');
    await expect(preview.getByText('QAQC Preview Auth Event')).toBeVisible({ timeout: 20_000 });
    await expect(preview.getByText('Event Not Available')).toHaveCount(0);
    expect(publicEventResponses).not.toContain(404);

    await context.close();
  });
});
