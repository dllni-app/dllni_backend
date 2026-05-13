import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const thisDir = dirname(fileURLToPath(import.meta.url));

const seedOutputPath = resolve(
  thisDir,
  '..',
  '..',
  'tmp',
  'playwright',
  'cleaning-seed.json',
);

function runSeedScript(): unknown {
  const seedScriptPath = resolve(thisDir, 'scripts', 'seed_cleaning_playwright.php');

  const result = spawnSync('php', [seedScriptPath], {
    cwd: resolve(thisDir, '..', '..'),
    encoding: 'utf-8',
  });

  if (result.status !== 0) {
    throw new Error(
      ['Cleaning Playwright seed script failed.', result.stdout, result.stderr]
        .filter(Boolean)
        .join('\n'),
    );
  }

  const stdout = (result.stdout ?? '').trim();
  if (!stdout) {
    throw new Error('Cleaning Playwright seed script returned empty output.');
  }

  return JSON.parse(stdout);
}

export default async function globalSetup(): Promise<void> {
  const seedData = runSeedScript();
  mkdirSync(dirname(seedOutputPath), { recursive: true });
  writeFileSync(seedOutputPath, JSON.stringify(seedData, null, 2), 'utf-8');
}

export { seedOutputPath };
