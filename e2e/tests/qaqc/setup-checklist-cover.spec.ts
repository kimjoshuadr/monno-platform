import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test, expect } from '../../fixtures';
import { createLiveEventWithPaidTicket } from '../../api/factory';
import { BASE_URL } from '../../utils/env';

const fixture = (name: string) => fileURLToPath(new URL(`../../fixtures/assets/${name}`, import.meta.url));

const imageFile = (name: string) => ({
    name,
    mimeType: 'image/png',
    buffer: readFileSync(fixture(name)),
});

/**
 * The cover is no longer part of the event page design: it is set in Settings → SEO and only
 * backs link previews and listings. The checklist's cover item must therefore track EVENT_COVER
 * specifically (a square EVENT_IMAGE does not count) and send the organizer to event settings.
 */
test.describe('setup checklist cover item', () => {
    test('needs an EVENT_COVER, points at settings, and completes after a cover upload', async ({ api, account, authedPage }) => {
        test.setTimeout(120_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);

        // Only a square event image exists — that must not be reported as a cover.
        await api.uploadEventImage(event.eventId, imageFile('event-square.png'), 'EVENT_IMAGE');

        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}`);
        await expect(authedPage.getByText('Get your event ready')).toBeVisible();

        const coverHelper = authedPage.getByText('Set a cover image in settings for link previews and listings');
        await expect(coverHelper).toBeVisible();
        await expect(coverHelper).toHaveText('Set a cover image in settings for link previews and listings');
        await expect(authedPage.getByText('Cover image added')).toHaveCount(0);

        const addCover = authedPage.getByRole('button', { name: 'Add cover image', exact: true });
        await expect(addCover).toBeVisible();

        await addCover.click();
        await authedPage.waitForURL(new RegExp(`/manage/event/${event.eventId}/settings`));
        await expect(authedPage.getByTestId('event-cover-upload')).toBeVisible();

        const uploadResponse = authedPage.waitForResponse(
            (response) => response.url().endsWith('/images') && response.request().method() === 'POST',
        );
        await authedPage.getByTestId('event-cover-upload-input').setInputFiles(fixture('event-cover.png'));
        const coverUrl = ((await (await uploadResponse).json()) as { data: { url: string } }).data.url;
        await expect(authedPage.getByTestId('event-cover-upload').locator('img')).toHaveAttribute('src', coverUrl, { timeout: 20_000 });

        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}`);
        await expect(authedPage.getByText('Cover image added')).toBeVisible();
        await expect(coverHelper).toHaveCount(0);
        await expect(authedPage.getByRole('button', { name: 'Add cover image', exact: true })).toHaveCount(0);
    });
});
