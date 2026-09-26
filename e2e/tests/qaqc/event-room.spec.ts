import {mkdir} from 'node:fs/promises';
import path from 'node:path';
import {test, expect} from '../../fixtures';
import {createLiveEventWithPaidTicket, createLiveEventWithProduct} from '../../api/factory';
import {BASE_URL} from '../../utils/env';
import {monnoSiteUrl} from '../../utils/parity';

const OUT = path.join(process.cwd(), 'test-results', 'visual');
/** The website's page for a platform event — id-keyed, so every event has one. */
const websiteEventUrl = (eventId: number | string) => `${monnoSiteUrl()}/event/${eventId}/`;

/**
 * The event page in monno's design. The ticket widget is the platform's own, so this also
 * guards the purchase path: the page must still expose the widget's product rows.
 */
test.describe('event room', () => {
    test('the public event page renders the event room around the real ticket widget', async ({
        api,
        account,
        page,
        browser,
    }) => {
        test.setTimeout(180_000);
        await mkdir(OUT, {recursive: true});

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await page.waitForLoadState('networkidle');

        // monno's structure: the site chrome, the three-column layout, the cover and the rail.
        await expect(page.locator('#event-title')).toBeVisible();
        await expect(page.locator('[data-od-id="site-header"]')).toBeVisible();
        await expect(page.locator('[data-od-id="site-footer"]')).toBeVisible();
        await expect(page.locator('.event-layout')).toBeVisible();
        await expect(page.locator('[data-od-id="event-cover"]')).toBeVisible();
        await expect(page.locator('[data-od-id="event-ticket-card"]')).toBeVisible();
        await expect(page.locator('#details-heading')).toBeVisible();

        // The purchase flow is untouched and lives in the rail — one picker, no duplicate
        // tickets section below it.
        await expect(page.locator('.register-rail .hi-product-row').first()).toBeVisible();
        await expect(page.locator('.event-main #tickets')).toHaveCount(0);

        // The rail's own actions: save, share and calendar. Share hands out the
        // website's page for the event — every LIVE event has one now.
        await expect(page.locator('[data-od-id="event-save"]')).toBeVisible();
        await expect(page.locator('[data-od-id="event-calendar"]')).toBeVisible();
        await page.locator('[data-od-id="event-share"]').click();
        await expect(page.locator('[data-od-id="share-dialog"] .share-url')).toHaveValue(
            websiteEventUrl(event.eventId),
        );
        await page.screenshot({path: path.join(OUT, 'share-dialog-platform.png'), animations: 'disabled'});
        await page.locator('[data-od-id="share-dialog"] .icon-btn').click();
        await expect(page.locator('[data-od-id="share-dialog"]')).toHaveCount(0);

        // Who's coming: counts, never names.
        await expect(page.locator('[data-od-id="event-who-coming"]')).toBeVisible();

        await page.screenshot({path: path.join(OUT, 'event-room-desktop.png'), fullPage: true});

        for (const [name, viewport] of Object.entries({
            tablet: {width: 834, height: 1112},
            mobile: {width: 390, height: 844},
        })) {
            const context = await browser.newContext({viewport});
            const viewportPage = await context.newPage();
            await viewportPage.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
            await expect(viewportPage.locator('#event-title')).toBeVisible();
            await viewportPage.screenshot({path: path.join(OUT, `event-room-${name}.png`), fullPage: true});
            await context.close();
        }
    });

    test('the event designer preview renders the same event room', async ({api, authedPage, account, browser}) => {
        test.setTimeout(180_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        const authedState = await authedPage.context().storageState();

        const context = await browser.newContext({
            baseURL: BASE_URL,
            ignoreHTTPSErrors: true,
            viewport: {width: 1440, height: 1000},
            storageState: authedState,
        });
        const page = await context.newPage();
        await page.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(page.getByRole('button', {name: 'Add section'})).toBeVisible();

        // The preview iframe renders the production room, so the design matches 1:1.
        const frame = page.frameLocator('iframe[title="Event Preview"]');
        await expect(frame.locator('#event-title')).toBeVisible({timeout: 20_000});
        await expect(frame.locator('.event-layout')).toBeVisible();
        await expect(frame.locator('[data-od-id="event-ticket-card"]')).toBeVisible();
        await expect(frame.locator('.hi-product-row').first()).toBeVisible();
        // The preview hands out the website's page too, never its own auth-gated
        // `/event/{id}/preview` address.
        await frame.locator('[data-od-id="event-share"]').click();
        await expect(frame.locator('[data-od-id="share-dialog"] .share-url')).toHaveValue(
            websiteEventUrl(event.eventId),
        );
        await page.screenshot({path: path.join(OUT, 'share-dialog-preview.png'), animations: 'disabled'});
        await page.screenshot({path: path.join(OUT, 'event-room-designer-preview.png')});
        await context.close();
    });

    test('following the host: a signed-out visitor is handed over, a signed-in one follows in place', async ({
        api,
        account,
        authedPage,
        page,
    }) => {
        await api.updateOrganizerStatus(account.organizerId, 'LIVE');
        const event = await createLiveEventWithProduct(api, {organizerId: account.organizerId, price: 0});

        // Signed out: the host's room, where an account can subscribe.
        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        const signedOut = page.locator('[data-od-id="event-follow"]');
        await expect(signedOut).toBeVisible({timeout: 20_000});
        await expect(signedOut).toHaveText('Follow');
        await expect(signedOut).toHaveAttribute('href', /\/events\/\d+/);

        // Signed in: it toggles, and the state survives a reload.
        await authedPage.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        const follow = authedPage.locator('[data-od-id="event-follow"]');
        await expect(follow).toBeVisible({timeout: 20_000});
        await expect(follow).toHaveText(/Follow/);
        await follow.click();
        await expect(follow).toHaveText(/Following/);

        await authedPage.reload();
        await expect(authedPage.locator('[data-od-id="event-follow"]')).toHaveText(/Following/);
    });
});
