import { test, expect } from '../../fixtures';
import { RegisterPage } from '../../pages/register.page';
import { uniqueEmail } from '../../utils/unique';

test.describe('registration', () => {
  test('a new organizer can register and reach the welcome page', { tag: '@smoke' }, async ({ page, mailpit }) => {
    const email = uniqueEmail();

    const register = new RegisterPage(page);
    await register.goto();
    await register.register({ firstName: 'New', lastName: 'Organizer', email, password: 'Password123!' });

    await expect(page).toHaveURL(/\/welcome/);
    await expect(page.getByRole('heading', { level: 1, name: /Welcome to/ })).toBeVisible();

    if (await mailpit.isAvailable()) {
      const welcomeEmail = await mailpit.waitForMessage(email, { subjectContains: 'Welcome to' });
      expect(welcomeEmail.Subject).toContain('Welcome to');
    }

    await expect(page.getByRole('heading', { level: 2, name: 'Set up your organization' })).toBeVisible();
    await expect(page.getByLabel(/^Contact Email/)).toHaveValue(email);
    await expect(page.getByRole('combobox', { name: 'Currency' })).toHaveValue('Philippine Peso (PHP)');
    await page.getByRole('button', { name: 'Continue to event creation' }).click();
    await expect(page.getByRole('heading', { level: 2, name: 'Create your first event' })).toBeVisible();
  });

  test('shows validation error when passwords do not match', async ({ page }) => {
    const register = new RegisterPage(page);
    await register.goto();

    await page.getByLabel(/^First Name/).fill('Mismatch');
    await page.getByLabel(/^Email/).fill(uniqueEmail());
    await page.getByLabel(/^Password/).fill('Password123!');
    await page.getByLabel(/^Confirm Password/).fill('MismatchPass456!');
    await page.getByRole('button', { name: 'Register' }).click();

    await expect(page.getByText('Passwords are not the same')).toBeVisible();
    await expect(page).toHaveURL(/\/auth\/register/);
  });

  test('rejects registration when email is already registered', async ({ browser }) => {
    const email = uniqueEmail();
    const password = 'Password123!';

    const context1 = await browser.newContext();
    const page1 = await context1.newPage();
    const register1 = new RegisterPage(page1);
    await register1.goto();
    await register1.register({ firstName: 'First', lastName: 'User', email, password });
    await expect(page1).toHaveURL(/\/welcome/);
    await context1.close();

    const context2 = await browser.newContext();
    const page2 = await context2.newPage();
    const register2 = new RegisterPage(page2);
    await register2.goto();
    await register2.register({ firstName: 'Second', lastName: 'User', email, password });

    await expect(page2.getByText(/There is already an account associated with this email/i).first()).toBeVisible();
    await expect(page2).toHaveURL(/\/auth\/register/);
    await context2.close();
  });
});
