import { defineConfig } from '@playwright/test';

const timeout = Number.parseInt(process.env.QA_TIMEOUT_MS ?? '60000', 10);
const resolvedTimeout = Number.isFinite(timeout) && timeout > 0 ? timeout : 60_000;
const baseURL = process.env.API_BASE_URL ?? 'http://127.0.0.1:8000';
const shouldStartLocalServer =
  (process.env.PLAYWRIGHT_RESTAURANT_WEB_SERVER ?? '1') !== '0' &&
  baseURL === 'http://127.0.0.1:8000';

export default defineConfig({
  testDir: './tests/playwright/specs',
  testMatch: [
    '**/user-restaurant-flows.spec.ts',
    '**/owner-restaurant-flows.spec.ts',
    '**/cross-app-order-lifecycle.spec.ts',
  ],
  timeout: resolvedTimeout,
  expect: {
    timeout: Math.max(5_000, Math.min(15_000, Math.floor(resolvedTimeout / 3))),
  },
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  outputDir: 'test-results/playwright/restaurant',
  globalSetup: './tests/playwright/global-setup.ts',
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
