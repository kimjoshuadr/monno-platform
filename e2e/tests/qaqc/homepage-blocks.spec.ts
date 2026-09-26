import {test, expect} from '../../fixtures';
import {createLiveEventWithPaidTicket} from '../../api/factory';
import {BASE_URL} from '../../utils/env';

/**
 * The builder's authored blocks ("Write your own" sections) were stored in
 * `homepage_blocks` and rendered nowhere. These pin that they now appear — in the order they
 * were authored, honouring the visibility toggle, and ignoring the empty placeholders the
 * builder starts from.
 */
const AUTHORED_BLOCKS = [
    {id: 'b1', type: 'TEXT', visible: true, settings: {body: '<p>Authored block prose</p>'}},
    {id: 'b2', type: 'FAQ', visible: true, settings: {items: [{question: 'Is there parking?', answer: 'Yes, on site.'}]}},
    {id: 'b3', type: 'LINEUP', visible: true, settings: {artists: [{name: 'DJ Nova'}]}},
    {
        id: 'b4',
        type: 'GALLERY',
        visible: true,
        settings: {
            images: [
                {url: 'https://example.com/one.jpg', alt: 'One'},
                {url: 'https://example.com/two.jpg', alt: 'Two'},
            ],
        },
    },
    {id: 'b5', type: 'CTA', visible: true, settings: {label: 'Buy merch', url: 'https://example.com/merch'}},
    {id: 'b6', type: 'EMBED', visible: true, settings: {url: 'https://example.com/embed'}},
    {id: 'b7', type: 'TEXT', visible: false, settings: {body: '<p>Hidden block prose</p>'}},
];

test.describe('homepage blocks', () => {
    test('an event page renders the authored blocks and skips hidden or empty ones', async ({api, account, page}) => {
        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        await api.updateEventSettings(event.eventId, {
            homepage_blocks: AUTHORED_BLOCKS,
        } as Record<string, unknown>);

        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await page.waitForLoadState('networkidle');

        await expect(page.getByText('Authored block prose')).toBeVisible();
        await expect(page.getByText('Is there parking?')).toBeVisible();
        await expect(page.getByText('DJ Nova')).toBeVisible();
        await expect(page.locator('.block-gallery img')).toHaveCount(2);
        await expect(page.locator('[data-od-id="block-b5"] a')).toHaveAttribute('href', 'https://example.com/merch');
        await expect(page.locator('[data-od-id="block-b6"] iframe')).toHaveAttribute('src', 'https://example.com/embed');

        // Hidden blocks never render. (The builder's empty placeholders can't be saved —
        // the API rejects a FAQ item without a question — so the renderer's empty-skip
        // guard stays defensive rather than something a saved page can reach.)
        await expect(page.getByText('Hidden block prose')).toHaveCount(0);

        // The purchase flow is unaffected by the extra sections.
        await expect(page.locator('.hi-product-row').first()).toBeVisible();
    });

    test('an organizer room renders the organizer\u2019s authored blocks', async ({authedPage, account}) => {
        // Organizer settings have no e2e client helper, so use the authed session's own request.
        const response = await authedPage.request.patch(`/api/organizers/${account.organizerId}/settings`, {
            data: {
                homepage_blocks: [
                    {id: 'o1', type: 'TEXT', visible: true, settings: {body: '<p>Organizer authored prose</p>'}},
                    {id: 'o2', type: 'LINEUP', visible: true, settings: {artists: [{name: 'House Band'}]}},
                    {id: 'o3', type: 'ABOUT', visible: true, settings: {}},
                ],
            },
        });
        expect(response.ok()).toBeTruthy();

        await authedPage.goto(`${BASE_URL}/events/${account.organizerId}/organizer`);
        await authedPage.waitForURL(new RegExp(`/events/${account.organizerId}/[^/]+$`));
        await authedPage.waitForLoadState('networkidle');

        await expect(authedPage.getByText('Organizer authored prose')).toBeVisible();
        await expect(authedPage.getByText('House Band')).toBeVisible();
        // A data-driven block works here too: About renders as the room's own section.
        await expect(authedPage.locator('#organizer-about-heading')).toBeVisible();
    });

    test('an anonymous visitor sees the organizer room\u2019s authored blocks', async ({api, account, browser, authedPage}) => {
        // An anonymous visitor only reaches a published organizer's room.
        await api.updateOrganizerStatus(account.organizerId, 'LIVE');

        // Seeded with the authenticated API, then read anonymously — the path a real visitor takes.
        const response = await authedPage.request.patch(`/api/organizers/${account.organizerId}/settings`, {
            data: {
                homepage_blocks: [
                    {id: 'anon1', type: 'TEXT', visible: true, settings: {body: '<p>Public organizer prose</p>'}},
                ],
            },
        });
        expect(response.ok()).toBeTruthy();

        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(`${BASE_URL}/events/${account.organizerId}/organizer`);
        await page.waitForURL(new RegExp(`/events/${account.organizerId}/[^/]+$`));
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('Public organizer prose')).toBeVisible();
        await context.close();
    });

    test('data-driven blocks arrange the page — About appears and moves where the builder puts it', async ({
        api,
        account,
        page,
    }) => {
        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);

        // Venue before About: the page's own sections must follow the builder's order.
        await api.updateEventSettings(event.eventId, {
            homepage_blocks: [
                {id: 'v1', type: 'VENUE', visible: true, settings: {}},
                {id: 'a1', type: 'ABOUT', visible: true, settings: {}},
            ],
        } as Record<string, unknown>);

        await page.goto(`${BASE_URL}/event/${event.eventId}/${event.slug}`);
        await expect(page.locator('#about-heading')).toBeVisible();
        await expect(page.locator('#details-heading')).toBeVisible();

        const ids = await page.locator('.event-section').evaluateAll((sections) =>
            sections.map((section) => section.id),
        );
        expect(ids.indexOf('details')).toBeGreaterThanOrEqual(0);
        expect(ids.indexOf('details')).toBeLessThan(ids.indexOf('about'));

        // Hiding it in the builder removes it from the page.
        await api.updateEventSettings(event.eventId, {
            homepage_blocks: [{id: 'a1', type: 'ABOUT', visible: false, settings: {}}],
        } as Record<string, unknown>);
        await page.reload();
        await expect(page.locator('#about-heading')).toHaveCount(0);

        // With no blocks at all the page falls back to its own layout.
        await api.updateEventSettings(event.eventId, {homepage_blocks: []} as Record<string, unknown>);
        await page.reload();
        await expect(page.locator('#details-heading')).toBeVisible();
        await expect(page.locator('.hi-product-row').first()).toBeVisible();
    });

    test('a page section added in the event designer shows in the preview before saving', async ({
        api,
        authedPage,
        account,
    }) => {
        test.setTimeout(180_000);

        const event = await createLiveEventWithPaidTicket(api, account.organizerId, 25);
        const marker = 'Unsaved section marker';

        await authedPage.goto(`${BASE_URL}/manage/event/${event.eventId}/homepage-designer`);
        await expect(authedPage.getByRole('button', {name: 'Add section'})).toBeVisible();

        // The cover-image background mode is gone: a colour is the only background.
        await expect(authedPage.getByText('Use cover image')).toHaveCount(0);
        await expect(authedPage.getByLabel(/Background Color/i)).toBeEnabled();

        await authedPage.getByRole('button', {name: 'Add section'}).click();
        await authedPage.getByRole('menuitem', {name: 'Text', exact: true}).click();
        const eventBlock = authedPage.getByRole('textbox', {name: 'Text', exact: true});
        await expect(eventBlock).toBeVisible();
        await eventBlock.fill(marker);

        const eventFrame = authedPage.frameLocator('iframe[title="Event Preview"]');
        await expect(eventFrame.getByText(marker)).toBeVisible({timeout: 20_000});
    });

    test('the organizer designer offers no sections builder, and stored sections still render', async ({
        api,
        authedPage,
        account,
    }) => {
        test.setTimeout(180_000);
        await api.updateOrganizerStatus(account.organizerId, 'LIVE');

        const seeded = await authedPage.request.patch(`/api/organizers/${account.organizerId}/settings`, {
            data: {
                homepage_blocks: [
                    {id: 'keep1', type: 'TEXT', visible: true, settings: {body: '<p>Stored organizer section</p>'}},
                ],
            },
        });
        expect(seeded.ok()).toBeTruthy();

        await authedPage.goto(`${BASE_URL}/manage/organizer/${account.organizerId}/organizer-homepage-designer`);

        await expect(authedPage.getByRole('button', {name: 'Add section'})).toHaveCount(0);
        await expect(authedPage.getByText('Page sections', {exact: true})).toHaveCount(0);

        const orgFrame = authedPage.frameLocator('iframe[title="Organizer Homepage Preview"]');
        await expect(orgFrame.getByText('Stored organizer section')).toBeVisible({timeout: 20_000});
    });
});
