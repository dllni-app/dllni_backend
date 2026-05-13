import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const thisDir = dirname(fileURLToPath(import.meta.url));

export type CleaningSeedData = {
  runId: string;
  actors: {
    user: { id: number; token: string };
    worker: { id: number; userId: number; workerId: number; token: string };
    wrong_role: { id: number; token: string };
    outsider_worker: { id: number; userId: number; workerId: number; token: string };
  };
  fixtures: {
    policies: {
      cancellationId: number | null;
      billingId: number | null;
    };
    bookings: {
      completedWithWorker: number;
      warningForWorker: number;
      warningForOutsider: number;
    };
    warnings: {
      pendingAccept: number;
      pendingReject: number;
      pendingOutsider: number;
    };
  };
  generatedAt: string;
};

export type RestaurantSeedData = {
  runId: string;
  auth: {
    user: { phone: string; password: string };
    owner: { phone: string; password: string };
  };
  actors: {
    user: { id: number; token: string };
    owner: { id: number; token: string };
    wrong_role: { id: number; token: string };
  };
  fixtures: {
    restaurants: { owned: number; other: number };
    categories: { primary: number; secondary: number };
    products: { available: number; secondary: number; unavailable: number };
    offers: { active: number };
    promoCodes: { active: number; activeCode: string; invalidCode: string };
    orders: { pendingAccept: number; pendingReject: number; completedLatest: number };
  };
  generatedAt: string;
};

type SeedBundle = {
  cleaning: CleaningSeedData;
  restaurant: RestaurantSeedData;
};

export function getCleaningSeedFilePath(): string {
  return resolve(
    thisDir,
    '..',
    '..',
    '..',
    'tmp',
    'playwright',
    'cleaning-seed.json',
  );
}

export function getRestaurantSeedFilePath(): string {
  return resolve(
    thisDir,
    '..',
    '..',
    '..',
    'tmp',
    'playwright',
    'restaurant-seed.json',
  );
}

export function readCleaningSeedData(): CleaningSeedData {
  const raw = readFileSync(getCleaningSeedFilePath(), 'utf-8');
  return JSON.parse(raw) as CleaningSeedData;
}

export function readRestaurantSeedData(): RestaurantSeedData {
  const raw = readFileSync(getRestaurantSeedFilePath(), 'utf-8');
  return JSON.parse(raw) as RestaurantSeedData;
}

export function readAllSeedData(): SeedBundle {
  return {
    cleaning: readCleaningSeedData(),
    restaurant: readRestaurantSeedData(),
  };
}

// Backward compatibility for existing cleaning fixture/tests.
export function getSeedFilePath(): string {
  return getCleaningSeedFilePath();
}

// Backward compatibility for existing cleaning fixture/tests.
export function readSeedData(): CleaningSeedData {
  return readCleaningSeedData();
}
