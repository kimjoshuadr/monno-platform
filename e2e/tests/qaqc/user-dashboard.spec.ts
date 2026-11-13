import { test, expect } from '@playwright/test';

const MONNO_URL = process.env.MONNO_URL ?? 'http://127.0.0.1:3001';

test.describe('Revamped User Dashboard & Organizer Distinction', () => {
  test.describe.configure({ mode: 'serial' });

  test('1. Legacy /profile/ redirects cleanly to /dashboard/', async ({ page }) => {
    await page.goto(`${MONNO_URL}/profile/`);
    await page.waitForURL('**/dashboard/**', { timeout: 15_000 });
    expect(page.url()).toContain('/dashboard');
  });

  test('2. Attendee experience: personal dashboard, tabs & no organizer clutter', async ({ page }) => {
    // Register a fresh attendee account so the test survives database reseeds
    const attendeeEmail = `attendee_${Date.now()}@monno.example`;
    await page.goto(`${MONNO_URL}/register/`, { waitUntil: 'networkidle' });
    await page.fill('#reg-first', 'Elena');
    await page.fill('#reg-last', 'Rostova');
    await page.fill('#reg-email', attendeeEmail);
    await page.fill('#reg-password', 'Password123!');
    await page.fill('#reg-confirm', 'Password123!');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard/**', { timeout: 15_000 });

    // 1. Verify Attendee identity
    await expect(page.getByText('Elena Rostova')).toBeVisible();
    await expect(page.getByText('monno attendee')).toBeVisible();

    // 2. Verify Attendee top banner (Host invitation, NOT organizer workspace banner)
    await expect(page.getByText('Hosting your own event?')).toBeVisible();
    await expect(page.locator('[data-od-id="dash-organizer-banner"]')).toHaveCount(0);

    // 3. Verify segmented main tabs
    await expect(page.locator('[data-od-id="tab-events"]')).toBeVisible();
    await expect(page.locator('[data-od-id="tab-calendars"]')).toBeVisible();
    await expect(page.locator('[data-od-id="tab-interests"]')).toBeVisible();
    await expect(page.locator('[data-od-id="tab-reviews"]')).toBeVisible();

    // 4. Subtabs: attendee has Upcoming, Past, Saved (no Hosting subtab)
    await expect(page.locator('[data-od-id="subtab-upcoming"]')).toBeVisible();
    await expect(page.locator('[data-od-id="subtab-past"]')).toBeVisible();
    await expect(page.locator('[data-od-id="subtab-saved"]')).toBeVisible();
    await expect(page.locator('[data-od-id="subtab-hosting"]')).toHaveCount(0);

    // 5. Test empty upcoming passes state
    await expect(page.locator('[data-od-id="empty-upcoming"]')).toBeVisible();
    await expect(page.getByText('No upcoming passes')).toBeVisible();

    // 6. Test Calendars tab
    await page.click('[data-od-id="tab-calendars"]');
    await expect(page.locator('[data-od-id="panel-calendars"]')).toBeVisible();

    // 7. Test Interests tab
    await page.click('[data-od-id="tab-interests"]');
    await expect(page.locator('[data-od-id="panel-interests"]')).toBeVisible();
  });

  test('3. Organizer experience: workspace banner, hosting subtab & clear distinction', async ({ page }) => {
    // Login as organizer
    await page.goto(`${MONNO_URL}/login/`, { waitUntil: 'networkidle' });
    await page.fill('#login-email', 'northlight@monno.example');
    await page.fill('#login-password', 'MonnoPass123!');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard/**', { timeout: 15_000 });

    // 1. Verify Organizer Workspace banner exists with distinction copy
    const orgBanner = page.locator('[data-od-id="dash-organizer-banner"]');
    await expect(orgBanner).toBeVisible();
    await expect(orgBanner.getByText('Organizer Workspace')).toBeVisible();
    await expect(orgBanner.getByText('personal attendee dashboard', { exact: false })).toBeVisible();

    // 2. Verify Organizer quick action links
    await expect(orgBanner.locator('[data-od-id="btn-org-dashboard"]')).toBeVisible();
    await expect(orgBanner.locator('[data-od-id="btn-manage-events"]')).toBeVisible();

    // 3. Verify Identity Card stats include "hosted"
    await expect(page.getByRole('heading', { name: 'Northlight Studio' })).toBeVisible();
    await expect(page.getByText('hosted', { exact: true })).toBeVisible();

    // 4. Verify "Hosting" subtab exists for organizers
    const hostingSubtab = page.locator('[data-od-id="subtab-hosting"]');
    await expect(hostingSubtab).toBeVisible();
    await hostingSubtab.click();

    // 5. Verify hosted events are listed with LIVE status pills
    const hostingPanel = page.locator('[data-od-id="panel-hosting"]');
    await expect(hostingPanel).toBeVisible();
    await expect(hostingPanel.getByText('LIVE').first()).toBeVisible();
    await expect(hostingPanel.getByText('Manage in Platform ↗').first()).toBeVisible();
  });

  test('4. User Menu (Desktop & Mobile): clean header without black pill and separated attendee/organizer sections', async ({ page }) => {
    // Login as organizer
    await page.goto(`${MONNO_URL}/login/`, { waitUntil: 'networkidle' });
    await page.fill('#login-email', 'northlight@monno.example');
    await page.fill('#login-password', 'MonnoPass123!');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard/**', { timeout: 15_000 });

    // --- Desktop User Menu ---
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.click('[data-od-id="user-menu-trigger"]');

    const menu = page.locator('.user-menu-panel');
    await expect(menu).toBeVisible();
    await expect(menu.getByText('Northlight Studio')).toBeVisible();
    await expect(menu.getByText('northlight@monno.example')).toBeVisible();

    // Assert that the ugly black pill badge is NOT present
    await expect(menu.locator('.user-menu-badge')).toHaveCount(0);

    // Assert clear section separation
    await expect(menu.locator('.user-menu-group-label').filter({ hasText: 'Attendee' })).toBeVisible();
    await expect(menu.locator('.user-menu-group-label').filter({ hasText: 'Organizer' })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Dashboard', exact: true })).toBeVisible();
    await expect(menu.getByRole('menuitem', { name: 'Organizer Dashboard' })).toBeVisible();

    // Close desktop menu
    await page.click('[data-od-id="user-menu-trigger"]');

    // --- Mobile Drawer Menu ---
    await page.setViewportSize({ width: 390, height: 844 });
    await page.click('.menu-toggle');

    const mobileNav = page.locator('#mobile-nav');
    await expect(mobileNav).toBeVisible();
    await expect(mobileNav.getByText('Northlight Studio')).toBeVisible();
    await expect(mobileNav.getByText('northlight@monno.example')).toBeVisible();
    await expect(mobileNav.locator('.user-menu-badge')).toHaveCount(0);
    await expect(mobileNav.getByText('Attendee', { exact: true })).toBeVisible();
    await expect(mobileNav.getByText('Organizer · Northlight Studio', { exact: false })).toBeVisible();
  });

  test('5. Responsive layout across Desktop, Tablet, and Mobile viewports', async ({ page }) => {
    const outDir = '/Users/kimjoshuadr/.gemini/antigravity-cli/brain/cc7244a3-318b-4b63-8cf4-0a42b5af1dd3/screenshots';

    // Login as organizer to verify full dashboard with workspace banner & hosting events
    await page.goto(`${MONNO_URL}/login/`, { waitUntil: 'networkidle' });
    await page.fill('#login-email', 'northlight@monno.example');
    await page.fill('#login-password', 'MonnoPass123!');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard/**', { timeout: 15_000 });

    // Switch to Hosting tab to display rich content
    await page.click('[data-od-id="subtab-hosting"]');
    await page.waitForTimeout(500);

    // 1. Desktop (1440 x 900)
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(page.locator('[data-od-id="dash-organizer-banner"]')).toBeVisible();
    await expect(page.locator('[data-od-id="panel-hosting"]')).toBeVisible();
    await page.screenshot({ path: `${outDir}/qaqc-desktop.png`, fullPage: true });

    // 2. Tablet (768 x 1024)
    await page.setViewportSize({ width: 768, height: 1024 });
    await expect(page.locator('[data-od-id="dash-organizer-banner"]')).toBeVisible();
    await expect(page.locator('[data-od-id="panel-hosting"]')).toBeVisible();
    await page.screenshot({ path: `${outDir}/qaqc-tablet.png`, fullPage: true });

    // 3. Mobile (390 x 844)
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('[data-od-id="dash-organizer-banner"]')).toBeVisible();
    await expect(page.locator('[data-od-id="panel-hosting"]')).toBeVisible();
    await page.screenshot({ path: `${outDir}/qaqc-mobile.png`, fullPage: true });
  });
});
