import { APIRequestContext, test as base } from '@playwright/test';
import {
  CleaningSeedData,
  RestaurantSeedData,
  readCleaningSeedData,
  readRestaurantSeedData,
} from '../support/seed-data';

type RoleRequests = {
  guest: APIRequestContext;
  user: APIRequestContext;
  worker: APIRequestContext;
  wrong_role: APIRequestContext;
  outsider_worker: APIRequestContext;
  restaurant_user: APIRequestContext;
  owner: APIRequestContext;
  restaurant_wrong_role: APIRequestContext;
};

type Fixtures = {
  seed: CleaningSeedData;
  restaurantSeed: RestaurantSeedData;
  roleRequests: RoleRequests;
};

function authHeader(token: string): Record<string, string> {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };
}

export const test = base.extend<Fixtures>({
  seed: [
    async ({}, use) => {
      await use(readCleaningSeedData());
    },
    { scope: 'worker' },
  ],

  restaurantSeed: [
    async ({}, use) => {
      await use(readRestaurantSeedData());
    },
    { scope: 'worker' },
  ],

  roleRequests: async ({ playwright, baseURL, seed, restaurantSeed }, use) => {
    if (!baseURL) {
      throw new Error('baseURL is required. Set API_BASE_URL or configure use.baseURL.');
    }

    const guest = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: { Accept: 'application/json' },
    });

    const user = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(seed.actors.user.token),
    });

    const worker = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(seed.actors.worker.token),
    });

    const wrongRole = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(seed.actors.wrong_role.token),
    });

    const outsiderWorker = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(seed.actors.outsider_worker.token),
    });

    const restaurantUser = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(restaurantSeed.actors.user.token),
    });

    const owner = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(restaurantSeed.actors.owner.token),
    });

    const restaurantWrongRole = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(restaurantSeed.actors.wrong_role.token),
    });

    await use({
      guest,
      user,
      worker,
      wrong_role: wrongRole,
      outsider_worker: outsiderWorker,
      restaurant_user: restaurantUser,
      owner,
      restaurant_wrong_role: restaurantWrongRole,
    });

    await Promise.all([
      guest.dispose(),
      user.dispose(),
      worker.dispose(),
      wrongRole.dispose(),
      outsiderWorker.dispose(),
      restaurantUser.dispose(),
      owner.dispose(),
      restaurantWrongRole.dispose(),
    ]);
  },
});

export { expect } from '@playwright/test';
