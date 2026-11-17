import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: 'tests',
  workers: 1, // the specs share one site (settings, logins), so they run one after another
  reporter: [['list'], ['html', { open: 'never' }]],
  use: { viewport: { width: 1440, height: 900 }, trace: 'retain-on-failure' },
});
