import { defineConfig } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_API_BASE_URL ?? 'http://127.0.0.1:8000';
const shouldStartLocalServer =
  (process.env.PLAYWRIGHT_SUPERMARKET_WEB_SERVER ?? '1') !== '0' &&
  baseURL === 'http://127.0.0.1:8000';

export default defineConfig({
  testDir: './tests/playwright/supermarket',
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  outputDir: 'test-results/playwright/supermarket',
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: {
      Accept: 'application/json',
    },
  },
  webServer: shouldStartLocalServer
    ? {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000',
        reuseExistingServer: true,
        timeout: 120_000,
      }
    : undefined,
});
