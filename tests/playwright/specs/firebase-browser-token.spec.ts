import { expect, test } from '@playwright/test';

test('acquires a browser token and prepares backend registration payload', async ({ page }) => {
  const mockToken = 'playwright_browser_token_1234567890';
  const capturedRequests: Array<{
    authorization: string | undefined;
    payload: unknown;
  }> = [];

  await page.route('**/api/v1/user/notifications/token', async (route) => {
    capturedRequests.push({
      authorization: route.request().headers().authorization,
      payload: route.request().postDataJSON(),
    });

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        message: 'FCM token registered successfully.',
        data: {
          module: 'notifications',
          tokenRegistered: true,
          updatedAt: '2026-06-16T00:00:00+00:00',
        },
      }),
    });
  });

  await page.goto(`/qa/firebase/browser-token?mock=1&mockToken=${mockToken}&auto=1`);

  await expect(page.getByTestId('config-status')).toContainText('Mock mode is active');
  await expect(page.getByTestId('token-status')).toContainText('Token acquired via mock Firebase test mode.');
  await expect(page.getByTestId('fcm-token')).toHaveValue(mockToken);

  await page.getByTestId('bearer-token').fill('playwright-test-bearer-token');
  await page.getByTestId('register-token').click();

  await expect(page.getByTestId('register-status')).toContainText('Token registered successfully.');
  await expect(page.getByTestId('response-body')).toContainText('tokenRegistered');

  expect(capturedRequests).toHaveLength(1);
  expect(capturedRequests[0]).toEqual({
    authorization: 'Bearer playwright-test-bearer-token',
    payload: {
      fcmToken: mockToken,
    },
  });
});
