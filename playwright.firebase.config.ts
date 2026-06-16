import { defineConfig } from '@playwright/test';

const timeout = Number.parseInt(process.env.QA_TIMEOUT_MS ?? '60000', 10);
const resolvedTimeout = Number.isFinite(timeout) && timeout > 0 ? timeout : 60_000;
const baseURL = process.env.API_BASE_URL ?? 'http://127.0.0.1:8000';
const shouldStartLocalServer =
  (process.env.PLAYWRIGHT_FIREBASE_WEB_SERVER ?? '1') !== '0' &&
  baseURL === 'http://127.0.0.1:8000';

export default defineConfig({
  testDir: './tests/playwright/specs',
  testMatch: ['**/firebase-browser-token.spec.ts'],
  timeout: resolvedTimeout,
  expect: {
    timeout: Math.max(5_000, Math.min(15_000, Math.floor(resolvedTimeout / 3))),
  },
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  outputDir: 'test-results/playwright/firebase',
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
  },
  webServer: shouldStartLocalServer
    ? {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000',
        reuseExistingServer: true,
        timeout: 120_000,
        env: {
          APP_ENV: 'testing',
          CACHE_STORE: 'array',
          DB_CONNECTION: 'sqlite',
          DB_DATABASE: ':memory:',
          QUEUE_CONNECTION: 'sync',
          SESSION_DRIVER: 'array',
          TELESCOPE_ENABLED: 'false',
        },
      }
    : undefined,
});
