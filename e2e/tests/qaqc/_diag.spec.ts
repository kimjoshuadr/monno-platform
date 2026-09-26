import {test} from '../../fixtures';
import {BASE_URL} from '../../utils/env';
import {fileURLToPath} from 'node:url';

const fixture = (name: string) => fileURLToPath(new URL(`../../fixtures/assets/${name}`, import.meta.url));

test('diag strings and sliders', async ({api, authedPage, account}) => {
    test.setTimeout(120_000);
    const event = await api.createEvent({
        title: 'Diag Event',
        type: 'SINGLE',
        organizer_id: account.organizerId,
        start_date: new Date(Date.now() + 86400000).toISOString(),
        category: 'MUSIC',
        currency: 'USD',
        timezone: 'UTC',
    });

    await authedPage.goto(`${BASE_URL}/manage/event/${event.id}/homepage-designer`);
    await authedPage.getByLabel(/Background Color/i).waitFor({state: 'visible'});
    await authedPage.getByTestId('background-type-select').click();
    console.log('OPTION_LABELS', await authedPage.locator('[data-testid^="background-type-select-option-"]').allInnerTexts());
    await authedPage.getByTestId('background-type-select-option-IMAGE').click();

    await authedPage.getByTestId('background-image-upload-input').setInputFiles(fixture('not-an-image.txt'));
    await authedPage.getByTestId('background-image-upload-errors').waitFor();
    console.log('WRONG_TYPE_ERROR', JSON.stringify(await authedPage.getByTestId('background-image-upload-errors').innerText()));

    await authedPage.getByTestId('background-image-upload-input').setInputFiles(fixture('background-oversize.png'));
    await authedPage.waitForTimeout(4000);
    console.log('OVERSIZE_ERROR', JSON.stringify(await authedPage.getByTestId('background-image-upload-errors').innerText()));

    console.log('OVERLAY_VALUE', JSON.stringify(await authedPage.getByTestId('background-overlay-value').innerText()));
    console.log('BLUR_VALUE', JSON.stringify(await authedPage.getByTestId('background-blur-value').innerText()));
    console.log('OVERLAY_THUMB_ROLE', await authedPage.getByTestId('background-overlay-slider').locator('[role="slider"]').count());
    console.log('OVERLAY_THUMB_LABEL', await authedPage.getByTestId('background-overlay-slider').locator('[role="slider"]').getAttribute('aria-label'));
});
