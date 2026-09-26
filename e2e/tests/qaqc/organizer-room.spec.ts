import {mkdir} from 'node:fs/promises';
import path from 'node:path';
import {test, expect} from '../../fixtures';
import {createLiveEventWithProduct, createPastEventWithCoverImage} from '../../api/factory';
import {BASE_URL} from '../../utils/env';

const OUT = path.join(process.cwd(), 'test-results', 'visual');

const VIEWPORTS = {
    desktop: {width: 1440, height: 1000},
    tablet: {width: 834, height: 1112},
    mobile: {width: 390, height: 844},
} as const;

/**
 * The organizer room: monno's organizer design, rendered from the platform's data.
 * Screenshots are written to test-results/visual/room-*.png for visual comparison with
 * monno's /o/<handle>/.
 */
test.describe('organizer room', () => {
    test('the public organizer page renders the room design', async ({api, account, page, browser}) => {
        test.setTimeout(180_000);
        await mkdir(OUT, {recursive: true});

        await api.updateOrganizerStatus(account.organizerId, 'LIVE');
        await createLiveEventWithProduct(api, {organizerId: account.organizerId, title: 'Room Upcoming Night', price: 25});
        await createLiveEventWithProduct(api, {organizerId: account.organizerId, title: 'Room Second Night', price: 0});

        // Any slug redirects to the canonical one (the loader enforces it).
        await page.goto(`${BASE_URL}/events/${account.organizerId}/organizer`);
        await page.waitForURL(new RegExp(`/events/${account.organizerId}/[^/]+$`));
        await page.waitForLoadState('networkidle');
        const roomUrl = page.url();

        // Identity card: name, handle, the two live stats and the "next up" pill.
        await expect(page.locator('#organizer-title')).toBeVisible();
        await expect(page.locator('[data-od-id="organizer-identity"]')).toContainText('@');
        await expect(page.locator('[data-od-id="organizer-identity"]')).toContainText(/upcoming date/);
        await expect(page.locator('[data-od-id="organizer-identity"]')).toContainText(/cit(y|ies)/);
        await expect(page.locator('[data-od-id="organizer-next"]')).toContainText('Next up');

        // Toolbar + filters + the live count.
        await expect(page.locator('[data-od-id="view-list"]')).toBeVisible();
        await expect(page.locator('[data-od-id="view-calendar"]')).toBeVisible();
        await expect(page.locator('[data-od-id="filter-category"]')).toBeVisible();
        await expect(page.locator('[data-od-id="filter-city"]')).toBeVisible();
        await expect(page.locator('[data-od-id="filter-price"]')).toBeVisible();
        await expect(page.locator('[data-od-id="when-upcoming"]')).toContainText('Upcoming');
        await expect(page.locator('[data-od-id="when-past"]')).toContainText('Past');

        // The list view: at least one room card, and the rail's stats + export.
        await expect(page.locator('.cal-card').first()).toBeVisible();
        await expect(page.locator('[data-od-id="organizer-month"]')).toBeVisible();
        await expect(page.locator('.cal-rail-stats')).toBeVisible();
        await expect(page.locator('[data-od-id="export-ics-organizer"]')).toBeVisible();

        await page.screenshot({path: path.join(OUT, 'room-public-desktop.png')});

        // Search narrows the list.
        await page.locator('#cal-search').fill('Room Second');
        await expect(page.locator('.cal-count')).toContainText('1 event');
        await page.locator('#cal-search').fill('');

        // Calendar view renders a month grid, and a dated cell opens the day panel.
        await page.locator('[data-od-id="view-calendar"]').click();
        await expect(page.locator('[data-od-id="organizer-calendar-view"]')).toBeVisible();
        const firstCell = page.locator('.cal-view-cell.has-events').first();
        await firstCell.click();
        await expect(page.locator('[data-od-id="organizer-day-panel"]')).toBeVisible();
        await page.screenshot({path: path.join(OUT, 'room-calendar-desktop.png')});

        // Each viewport keeps the room's layout.
        for (const [name, viewport] of Object.entries(VIEWPORTS)) {
            const context = await browser.newContext({viewport});
            const viewportPage = await context.newPage();
            await viewportPage.goto(roomUrl);
            await expect(viewportPage.locator('#organizer-title')).toBeVisible();
            await viewportPage.screenshot({path: path.join(OUT, `room-${name}.png`)});
            await context.close();
        }
    });

    test('the Past tab lists past dates and the designer preview renders the same room', async ({
        api,
        authedPage,
        account,
        page,
        browser,
    }) => {
        test.setTimeout(180_000);

        await api.updateOrganizerStatus(account.organizerId, 'LIVE');
        await createLiveEventWithProduct(api, {organizerId: account.organizerId, title: 'Room Future Night', price: 25});
        await createPastEventWithCoverImage(api, account.organizerId);

        await page.goto(`${BASE_URL}/events/${account.organizerId}/organizer`);
        await page.waitForURL(new RegExp(`/events/${account.organizerId}/[^/]+$`));
        await page.waitForLoadState('networkidle');
        const roomUrl = page.url();

        // The Past segment reports its own count and swaps the timeline over.
        const pastTab = page.locator('[data-od-id="when-past"]');
        await expect(pastTab).not.toContainText('Past (0)');
        await pastTab.click();
        await expect(pastTab).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('.cal-card').first()).toBeVisible();

        // The designer's iframe renders the room for the owner (draft organizer included).
        const authedState = await authedPage.context().storageState();
        const context = await browser.newContext({
            baseURL: BASE_URL,
            ignoreHTTPSErrors: true,
            viewport: VIEWPORTS.desktop,
            storageState: authedState,
        });
        const previewPage = await context.newPage();
        await previewPage.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/organizer-homepage-designer`);
        await expect(previewPage.getByRole('heading', {name: 'Homepage Design'})).toBeVisible();
        await expect(previewPage.getByRole('button', {name: 'Add section'})).toHaveCount(0);

        const frame = previewPage.frameLocator('iframe[title="Organizer Homepage Preview"]');
        await expect(frame.locator('#organizer-title')).toBeVisible({timeout: 20_000});
        await expect(frame.locator('[data-od-id="organizer-toolbar"]')).toBeVisible();
        await expect(frame.locator('[data-od-id="organizer-month"]')).toBeVisible();
        await previewPage.screenshot({path: path.join(OUT, 'room-designer-preview.png')});
        await context.close();

        // The public room and the preview agree on the same URL space.
        expect(roomUrl).toContain(`/events/${account.organizerId}/`);
    });
});
