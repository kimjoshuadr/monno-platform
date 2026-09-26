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
 * The event page's square tile has its own image type, so a wide cover is no longer stretched
 * into it. The cover keeps every other job (og:image, cards), so it must still render in the
 * tile when no square image exists.
 */
test.describe('event square image', () => {
    test('the designer Images panel offers the square uploader and no cover uploader', async ({ api, authedPage, account }) => {
        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);

        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByRole('button', { name: 'Add section' })).toBeVisible();

        await expect(authedPage.getByText('Cover Image', { exact: true })).toHaveCount(0);
        await expect(authedPage.getByTestId('event-cover-upload')).toHaveCount(0);

        await expect(authedPage.getByText('Square Event Image', { exact: true })).toBeVisible();
        await expect(authedPage.getByTestId('event-square-image-upload')).toBeVisible();
        await expect(authedPage.getByText('Square (1:1) image shown on your event page')).toBeVisible();
    });

    test('an event whose only image is a cover still shows the cover in the square tile', async ({ api, account, page, authedPage }) => {
        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        const cover = await api.uploadEventImage(event.eventId, imageFile('event-cover.png'), 'EVENT_COVER');

        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await page.waitForLoadState('networkidle');

        const tile = page.locator('[data-od-id="event-cover"] .event-cover-plate img');
        await expect(tile).toBeVisible();
        await expect(tile).toHaveAttribute('src', /\/event_cover\//);
        await expect(tile).toHaveAttribute('src', cover.url);

        // The cover keeps its own jobs: social previews and the management/card payload.
        await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', cover.url);
        await expect(page.locator('meta[name="twitter:image"]')).toHaveAttribute('content', cover.url);

        const response = await authedPage.request.get(`/api/events/${event.eventId}`, {
            headers: { Authorization: `Bearer ${account.token}`, Accept: 'application/json' },
        });
        expect(response.ok()).toBeTruthy();
        const { data } = await response.json();
        const images = (data.images ?? []) as { type: string; url: string }[];
        expect(images.find((image) => image.type === 'EVENT_COVER')?.url).toBe(cover.url);
        expect(images.some((image) => image.type === 'EVENT_IMAGE')).toBe(false);
    });

    test('a square image wins over the cover in the tile', async ({ api, account, page }) => {
        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await api.uploadEventImage(event.eventId, imageFile('event-cover.png'), 'EVENT_COVER');
        const square = await api.uploadEventImage(event.eventId, imageFile('event-square.png'), 'EVENT_IMAGE');

        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await page.waitForLoadState('networkidle');

        const tile = page.locator('[data-od-id="event-cover"] .event-cover-plate img');
        await expect(tile).toHaveAttribute('src', square.url);
        await expect(tile).toHaveAttribute('src', /\/event_image\//);
    });

    test('uploading a square image fills the 1:1 tile in the designer preview and on the event page', async ({
        api,
        authedPage,
        account,
    }) => {
        test.setTimeout(120_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await api.uploadEventImage(event.eventId, imageFile('event-cover.png'), 'EVENT_COVER');

        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByRole('button', { name: 'Add section' })).toBeVisible();

        const frame = authedPage.frameLocator('iframe[title="Event Preview"]');
        const previewTile = frame.locator('[data-od-id="event-cover"] .event-cover-plate img');
        await expect(previewTile).toBeVisible({ timeout: 20_000 });
        await expect(previewTile).toHaveAttribute('src', /\/event_cover\//);

        await authedPage.getByTestId('event-square-image-upload-input').setInputFiles(fixture('event-square.png'));

        // The upload refreshes the preview the same way the cover upload does.
        await expect(previewTile).toHaveAttribute('src', /\/event_image\//, { timeout: 20_000 });

        await authedPage.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await authedPage.waitForLoadState('networkidle');
        const tile = authedPage.locator('[data-od-id="event-cover"] .event-cover-plate img');
        await expect(tile).toHaveAttribute('src', /\/event_image\//);
    });

    test('a cover can be uploaded from event settings and reaches og:image', async ({ api, account, authedPage, page }) => {
        test.setTimeout(120_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);

        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/settings`);
        const coverUpload = authedPage.getByTestId('event-cover-upload');
        await coverUpload.scrollIntoViewIfNeeded();
        await expect(coverUpload).toBeVisible();
        await expect(authedPage.getByText('Shown in link previews and listings')).toBeVisible();

        const uploadResponse = authedPage.waitForResponse(
            (response) => response.url().endsWith('/images') && response.request().method() === 'POST',
        );
        await authedPage.getByTestId('event-cover-upload-input').setInputFiles(fixture('event-cover.png'));
        const coverUrl = ((await (await uploadResponse).json()) as { data: { url: string } }).data.url;

        await expect(coverUpload.locator('img')).toHaveAttribute('src', coverUrl, { timeout: 20_000 });

        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await page.waitForLoadState('networkidle');
        await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', coverUrl);
    });
});
