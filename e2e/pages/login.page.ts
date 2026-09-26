import type { Page } from '@playwright/test';

export class LoginPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/auth/login');
    await this.page.waitForLoadState('networkidle');
  }

  async login(credentials: { email: string; password: string }): Promise<void> {
    await this.page.getByLabel(/^Email/).fill(credentials.email);
    await this.page.getByLabel(/^Password/).fill(credentials.password);
    await this.page.getByRole('button', { name: 'Log in' }).click();
  }
}
