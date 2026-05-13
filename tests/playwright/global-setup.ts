import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const thisDir = dirname(fileURLToPath(import.meta.url));

const cleaningSeedOutputPath = resolve(
  thisDir,
  '..',
  '..',
  'tmp',
  'playwright',
  'cleaning-seed.json',
);

const restaurantSeedOutputPath = resolve(
  thisDir,
  '..',
  '..',
  'tmp',
  'playwright',
  'restaurant-seed.json',
);

function runSeedScript(seedScriptPath: string, label: string): unknown {

  const result = spawnSync('php', [seedScriptPath], {
    cwd: resolve(thisDir, '..', '..'),
    encoding: 'utf-8',
  });

  if (result.status !== 0) {
    throw new Error(
      [`${label} Playwright seed script failed.`, result.stdout, result.stderr]
        .filter(Boolean)
        .join('\n'),
    );
  }

  const stdout = (result.stdout ?? '').trim();
  if (!stdout) {
    throw new Error(`${label} Playwright seed script returned empty output.`);
  }

  return JSON.parse(stdout);
}

export default async function globalSetup(): Promise<void> {
  const cleaningSeedScriptPath = resolve(thisDir, 'scripts', 'seed_cleaning_playwright.php');
  const restaurantSeedScriptPath = resolve(thisDir, 'scripts', 'seed_restaurant_playwright.php');

  const cleaningSeedData = runSeedScript(cleaningSeedScriptPath, 'Cleaning');
  const restaurantSeedData = runSeedScript(restaurantSeedScriptPath, 'Restaurant');

  mkdirSync(dirname(cleaningSeedOutputPath), { recursive: true });
  writeFileSync(cleaningSeedOutputPath, JSON.stringify(cleaningSeedData, null, 2), 'utf-8');
  writeFileSync(restaurantSeedOutputPath, JSON.stringify(restaurantSeedData, null, 2), 'utf-8');
}

export { cleaningSeedOutputPath, restaurantSeedOutputPath };
