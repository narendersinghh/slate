import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: 'tests',
  reporter: [['list'], ['html', { open: 'never' }]],
  use: { viewport: { width: 1440, height: 900 }, trace: 'retain-on-failure' },
});
