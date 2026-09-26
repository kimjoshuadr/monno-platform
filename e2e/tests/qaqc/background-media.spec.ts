import {test, expect} from '../../fixtures';
import {createLiveEventWithPaidTicket} from '../../api/factory';
import {BASE_URL} from '../../utils/env';
import {fileURLToPath} from 'node:url';

const fixture = (name: string) => fileURLToPath(new URL(`../../fixtures/assets/${name}`, import.meta.url));

/**
 * Media backgrounds: an image (including GIF) or a short video, in either placement, with the
 * overlay and blur the organizer set. Driven through the settings API — the designer UI for it
 * is the next step.
 */
test.describe('background media', () => {
    test('an image background renders on the event page in both placements', async ({api, account, page}) => {
        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);

        await api.updateEventSettings(event.eventId, {
            homepage_theme_settings: {
                accent: '#7C3AED',
                background: '#0F172A',
                mode: 'dark',
                background_type: 'IMAGE',
                background_placement: 'PAGE',
                background_image_url: 'https://example.com/background.gif',
                background_overlay_opacity: 0.5,
                background_blur: 8,
            },
        } as Record<string, unknown>);

        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        const layer = page.locator('[data-od-id="page-background"]');
        await expect(layer).toBeVisible();
        await expect(layer).toHaveAttribute('data-placement', 'PAGE');
        await expect(layer.locator('img')).toHaveAttribute('src', 'https://example.com/background.gif');
        await expect(layer.locator('img')).toHaveCSS('filter', 'blur(8px)');
        // The colour still sits behind the media, so there is no flash of nothing.
        await expect(page.locator('.event-room')).toHaveCSS('background-color', 'rgb(15, 23, 42)');

        // Hero placement scopes the layer to the top band instead of the whole page.
        await api.updateEventSettings(event.eventId, {
            homepage_theme_settings: {
                accent: '#7C3AED',
                background: '#0F172A',
                mode: 'dark',
                background_type: 'IMAGE',
                background_placement: 'HERO',
                background_image_url: 'https://example.com/background.gif',
            },
        } as Record<string, unknown>);
        await page.reload();
        await expect(page.locator('[data-od-id="page-background"]')).toHaveAttribute('data-placement', 'HERO');

        // Back to a colour: no media layer at all.
        await api.updateEventSettings(event.eventId, {
            homepage_theme_settings: {
                accent: '#7C3AED',
                background: '#0F172A',
                mode: 'dark',
                background_type: 'COLOR',
            },
        } as Record<string, unknown>);
        await page.reload();
        await expect(page.locator('[data-od-id="page-background"]')).toHaveCount(0);
    });

    test('a video background plays with a poster, and falls back to the poster under reduced motion', async ({
        api,
        account,
        browser,
    }) => {
        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);

        await api.updateEventSettings(event.eventId, {
            homepage_theme_settings: {
                accent: '#7C3AED',
                background: '#0F172A',
                mode: 'dark',
                background_type: 'VIDEO',
                background_placement: 'PAGE',
                background_video_url: 'https://example.com/background.mp4',
                background_poster_url: 'https://example.com/poster.jpg',
            },
        } as Record<string, unknown>);

        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        const video = page.locator('[data-od-id="page-background"] video');
        await expect(video).toHaveAttribute('poster', 'https://example.com/poster.jpg');
        await expect(video).toHaveAttribute('loop', '');
        await expect(video).toHaveAttribute('autoplay', '');
        // React sets `muted` as a property rather than an attribute.
        expect(await video.evaluate((element: HTMLVideoElement) => element.muted)).toBe(true);
        await context.close();

        // A visitor who asks for less motion gets the still frame instead of a playing video.
        const reduced = await browser.newContext({reducedMotion: 'reduce'});
        const reducedPage = await reduced.newPage();
        await reducedPage.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await expect(reducedPage.locator('[data-od-id="page-background"] video')).toHaveCount(0);
        await expect(reducedPage.locator('[data-od-id="page-background"] img')).toHaveAttribute(
            'src',
            'https://example.com/poster.jpg',
        );
        await reduced.close();
    });

    test('the designer preview shows a media background while you edit it', async ({api, authedPage, account}) => {
        test.setTimeout(120_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await api.updateEventSettings(event.eventId, {
            homepage_theme_settings: {
                accent: '#7C3AED',
                background: '#0F172A',
                mode: 'dark',
                background_type: 'IMAGE',
                background_placement: 'PAGE',
                background_image_url: 'https://example.com/designer-bg.png',
                background_overlay_opacity: 0.4,
            },
        } as Record<string, unknown>);

        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByRole('button', {name: 'Add section'})).toBeVisible();

        // The preview renders the production page, so the background must be there too — the
        // designer sends the theme wholesale, which is what makes this work without extra wiring.
        const frame = authedPage.frameLocator('iframe[title="Event Preview"]');
        const layer = frame.locator('[data-od-id="page-background"]');
        await expect(layer).toBeVisible({timeout: 20_000});
        await expect(layer.locator('img')).toHaveAttribute('src', 'https://example.com/designer-bg.png');
    });

    test('the organizer room renders a media background too', async ({api, account, browser, authedPage}) => {
        await api.updateOrganizerStatus(account.organizerId, 'LIVE');
        const response = await authedPage.request.patch(`/api/organizers/${account.organizerId}/settings`, {
            data: {
                homepage_theme_settings: {
                    accent: '#0B0B0C',
                    background: '#F5F6F8',
                    mode: 'light',
                    background_type: 'IMAGE',
                    background_placement: 'PAGE',
                    background_image_url: 'https://example.com/organizer-bg.png',
                    background_overlay_opacity: 0.2,
                },
            },
        });
        expect(response.ok()).toBeTruthy();

        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(`${BASE_URL}/events/${account.organizerId}/organizer`);
        await page.waitForURL(new RegExp(`/events/${account.organizerId}/[^/]+$`));
        const layer = page.locator('[data-od-id="page-background"]');
        await expect(layer).toBeVisible();
        await expect(layer.locator('img')).toHaveAttribute('src', 'https://example.com/organizer-bg.png');
        await context.close();
    });
});

test.describe('background media designer controls', () => {
    test('event designer: uploads an image background and drives placement, overlay and blur', async ({
        api,
        authedPage,
        account,
    }) => {
        test.setTimeout(120_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByRole('button', {name: 'Add section'})).toBeVisible();
        await expect(authedPage.getByLabel(/Background Color/i)).toBeEnabled();

        await authedPage.getByTestId('background-type-select').click();
        await authedPage.getByTestId('background-type-select-option-IMAGE').click();

        await authedPage.getByTestId('background-image-upload-input').setInputFiles(fixture('background.png'));

        const frame = authedPage.frameLocator('iframe[title="Event Preview"]');
        const layer = frame.locator('[data-od-id="page-background"]');
        await expect(layer).toBeVisible({timeout: 20_000});
        await expect(layer).toHaveAttribute('data-placement', 'PAGE');
        await expect(layer.locator('img')).toHaveAttribute('src', /event_background\//);

        // Overlay: nudge up one step (0.05) from the 0.35 default.
        const overlayThumb = authedPage.getByRole('slider', {name: 'Overlay opacity'});
        await overlayThumb.focus();
        await authedPage.keyboard.press('ArrowRight');
        await expect(authedPage.getByTestId('background-overlay-value')).toHaveText('0.40');
        await expect(layer.locator('div')).toHaveCSS('opacity', '0.4');

        // Blur: nudge up one step from 0 to 1px.
        const blurThumb = authedPage.getByRole('slider', {name: 'Background blur'});
        await blurThumb.focus();
        await authedPage.keyboard.press('ArrowRight');
        await expect(authedPage.getByTestId('background-blur-value')).toHaveText('1px');
        await expect(layer.locator('img')).toHaveCSS('filter', 'blur(1px)');

        // Placement switches the layer from the whole page to the top band.
        await authedPage.getByTestId('background-placement-select').click();
        await authedPage.getByTestId('background-placement-select-option-HERO').click();
        await expect(layer).toHaveAttribute('data-placement', 'HERO');
    });

    test('event designer: uploads an animated GIF background', async ({api, authedPage, account}) => {
        test.setTimeout(120_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByLabel(/Background Color/i)).toBeEnabled();

        await authedPage.getByTestId('background-type-select').click();
        await authedPage.getByTestId('background-type-select-option-IMAGE').click();
        await authedPage.getByTestId('background-image-upload-input').setInputFiles(fixture('background.gif'));

        const frame = authedPage.frameLocator('iframe[title="Event Preview"]');
        const layer = frame.locator('[data-od-id="page-background"]');
        await expect(layer).toBeVisible({timeout: 20_000});
        await expect(layer.locator('img')).toHaveAttribute('src', /event_background\/.*\.gif$/);
    });

    test('event designer: a video upload shows Processing then applies the poster', async ({
        api,
        authedPage,
        account,
    }) => {
        test.setTimeout(180_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByLabel(/Background Color/i)).toBeEnabled();

        await authedPage.getByTestId('background-type-select').click();
        await authedPage.getByTestId('background-type-select-option-VIDEO').click();
        await authedPage.getByTestId('background-video-upload-input').setInputFiles(fixture('background.mp4'));

        await expect(authedPage.getByTestId('background-video-processing')).toBeVisible({timeout: 30_000});

        const frame = authedPage.frameLocator('iframe[title="Event Preview"]');
        const video = frame.locator('[data-od-id="page-background"] video');
        await expect(video).toHaveAttribute('poster', /event_background_poster\//, {timeout: 40_000});
        await expect(video).toHaveAttribute('src', /event_background\//);
        await expect(authedPage.getByTestId('background-video-processing')).toHaveCount(0);
    });

    test('event designer: a rejected file surfaces a visible error', async ({api, authedPage, account}) => {
        test.setTimeout(120_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByLabel(/Background Color/i)).toBeEnabled();

        await authedPage.getByTestId('background-type-select').click();
        await authedPage.getByTestId('background-type-select-option-IMAGE').click();

        // Wrong type — rejected client-side before any request.
        await authedPage.getByTestId('background-image-upload-input').setInputFiles(fixture('not-an-image.txt'));
        await expect(authedPage.getByTestId('background-image-upload-errors')).toBeVisible();
        await expect(authedPage.getByTestId('background-image-upload-errors')).toContainText(/Invalid file type/i);

        // Valid type/size but beyond the server's dimension limit — rejected by the API.
        await authedPage.getByTestId('background-image-upload-input').setInputFiles(fixture('background-oversize.png'));
        await expect(authedPage.getByTestId('background-image-upload-errors')).toContainText(/pixel/i);
    });

    test('organizer designer: uploads an image background', async ({api, authedPage, account}) => {
        test.setTimeout(120_000);

        await api.updateOrganizerStatus(account.organizerId, 'LIVE');
        await authedPage.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/organizer-homepage-designer`);
        await expect(authedPage.getByRole('heading', {name: 'Homepage Design'})).toBeVisible();
        await expect(authedPage.getByLabel(/Background Color/i)).toBeEnabled();

        await authedPage.getByTestId('background-type-select').click();
        await authedPage.getByTestId('background-type-select-option-IMAGE').click();
        await authedPage.getByTestId('background-image-upload-input').setInputFiles(fixture('background.png'));

        const frame = authedPage.frameLocator('iframe[title="Organizer Homepage Preview"]');
        const layer = frame.locator('[data-od-id="page-background"]');
        await expect(layer).toBeVisible({timeout: 20_000});
        await expect(layer.locator('img')).toHaveAttribute('src', /organizer_background\//);
    });

    test('organizer designer: a video background uploads over HTTP (Processing then poster)', async ({
        api,
        authedPage,
        account,
    }) => {
        test.setTimeout(180_000);

        await api.updateOrganizerStatus(account.organizerId, 'LIVE');
        await authedPage.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/organizer-homepage-designer`);
        await expect(authedPage.getByRole('heading', {name: 'Homepage Design'})).toBeVisible();
        await expect(authedPage.getByLabel(/Background Color/i)).toBeEnabled();

        await authedPage.getByTestId('background-type-select').click();
        await authedPage.getByTestId('background-type-select-option-VIDEO').click();
        await authedPage.getByTestId('background-video-upload-input').setInputFiles(fixture('background.mp4'));

        await expect(authedPage.getByTestId('background-video-processing')).toBeVisible({timeout: 30_000});

        const frame = authedPage.frameLocator('iframe[title="Organizer Homepage Preview"]');
        const video = frame.locator('[data-od-id="page-background"] video');
        await expect(video).toHaveAttribute('poster', /organizer_background_poster\//, {timeout: 40_000});
        await expect(authedPage.getByTestId('background-video-processing')).toHaveCount(0);
    });
});
