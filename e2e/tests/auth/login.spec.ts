import { test, expect } from '../../fixtures';
import { LoginPage } from '../../pages/login.page';
import { RegisterPage } from '../../pages/register.page';
import { uniqueEmail } from '../../utils/unique';

test.describe('login', () => {
  test('user can log in with valid credentials and persist session across reloads', { tag: '@smoke' }, async ({ browser }) => {
    const email = uniqueEmail();
    const password = 'Password123!';

    const regContext = await browser.newContext();
    const regPage = await regContext.newPage();
    const register = new RegisterPage(regPage);
    await register.goto();
    await register.register({ firstName: 'Login', lastName: 'Tester', email, password });
    await expect(regPage).toHaveURL(/\/welcome/);
    await regContext.close();

    const loginContext = await browser.newContext();
    const page = await loginContext.newPage();
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login({ email, password });

    await expect(page).toHaveURL(/\/welcome|\/manage/);

    await page.reload();
    await page.waitForLoadState('networkidle');
    await expect(page).not.toHaveURL(/\/auth\/login/);

    await loginContext.close();
  });

  test('shows error notification when credentials are incorrect', async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();

    await loginPage.login({ email: 'nonexistent@example.com', password: 'WrongPassword999!' });

    await expect(page.getByText('Please check your email and password and try again')).toBeVisible();
    await expect(page).toHaveURL(/\/auth\/login/);
  });

  test('user can log out and protected routes redirect back to login', async ({ page }) => {
    const email = uniqueEmail();
    const password = 'Password123!';

    const register = new RegisterPage(page);
    await register.goto();
    await register.register({ firstName: 'Logout', lastName: 'Tester', email, password });
    await expect(page).toHaveURL(/\/welcome/);

    await page.goto('/manage/events');
    await page.waitForLoadState('networkidle');

    const avatarButton = page.getByRole('button', { name: 'LT', exact: true });
    if (await avatarButton.isVisible()) {
      await avatarButton.click();
      await page.getByRole('menuitem', { name: 'Logout' }).click();
      await expect(page).toHaveURL(/\/auth\/login/);

      await page.goto('/manage/events');
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveURL(/\/auth\/login/);
    }
  });
});
