import { APIRequestContext, test as base } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

type SupermarketSeedData = {
  runId: string;
  actors: {
    user: { id: number; token: string };
    store_owner: { id: number; token: string };
    wrong_role: { id: number; token: string };
  };
  fixtures: {
    store: { owned: number; other: number };
    products: { available: number; similar: number; low_stock: number; unavailable: number };
    orders: {
      tracking: number;
      pending_accept: number;
      pending_reject: number;
      ready_for_pickup: number;
      non_ready: number;
      other_store_pending: number;
    };
    master_products: { primary: number; other_store_only: number };
  };
  generatedAt: string;
};

type RoleRequests = {
  guest: APIRequestContext;
  user: APIRequestContext;
  store_owner: APIRequestContext;
  wrong_role: APIRequestContext;
};

type Fixtures = {
  seed: SupermarketSeedData;
  roleRequests: RoleRequests;
};

function authHeader(token: string): Record<string, string> {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };
}

function generateSeedData(): SupermarketSeedData {
  const thisDir = dirname(fileURLToPath(import.meta.url));
  const seedScriptPath = resolve(thisDir, 'scripts', 'seed_supermarket_playwright.php');
  const repoRoot = resolve(thisDir, '..', '..', '..');

  const result = spawnSync('php', [seedScriptPath], {
    cwd: repoRoot,
    encoding: 'utf-8',
  });

  if (result.status !== 0) {
    throw new Error(
      [
        'Failed to seed supermarket Playwright fixtures.',
        result.stdout,
        result.stderr,
      ]
        .filter(Boolean)
        .join('\n'),
    );
  }

  return JSON.parse((result.stdout ?? '').trim()) as SupermarketSeedData;
}

export const test = base.extend<Fixtures>({
  seed: async ({}, use) => {
    await use(generateSeedData());
  },

  roleRequests: async ({ playwright, baseURL, seed }, use) => {
    if (!baseURL) {
      throw new Error('baseURL is required. Set PLAYWRIGHT_API_BASE_URL or configure use.baseURL.');
    }

    const guest = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: { Accept: 'application/json' },
    });

    const user = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(seed.actors.user.token),
    });

    const storeOwner = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(seed.actors.store_owner.token),
    });

    const wrongRole = await playwright.request.newContext({
      baseURL,
      extraHTTPHeaders: authHeader(seed.actors.wrong_role.token),
    });

    await use({
      guest,
      user,
      store_owner: storeOwner,
      wrong_role: wrongRole,
    });

    await Promise.all([guest.dispose(), user.dispose(), storeOwner.dispose(), wrongRole.dispose()]);
  },
});

export { expect } from '@playwright/test';
