import { expect, test } from '../fixtures/auth';
import {
  bookingStatus,
  CleaningApiClient,
  CleaningFlowHarness,
  extractBooking,
} from '../helpers/cleaning-api-client';

function requireOrderId(body: unknown): number {
  const booking = extractBooking(body);
  const orderId = Number(booking?.id);
  expect(orderId).toBeGreaterThan(0);
  return orderId;
}

test.describe('Cleaning Owner/Worker API contract scenarios', () => {
  test('O-CL-01: accept pending booking', async ({ roleRequests, seed }) => {
    const userApi = new CleaningApiClient(roleRequests.user);
    const workerApi = new CleaningApiClient(roleRequests.worker);

    const created = await userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    const bookingId = requireOrderId(created.body);

    const accepted = await workerApi.acceptBooking(bookingId);
    expect(accepted.response.status()).toBe(200);
    expect(bookingStatus(accepted.body)).toBe('worker_assigned');

    const booking = extractBooking(accepted.body);
    expect(Number(booking?.workerId)).toBe(seed.actors.worker.workerId);
  });

  test('O-CL-02: start travel + location update', async ({ roleRequests, seed }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const bookingId = created.orderId;

    const accepted = await flow.workerApi.acceptBooking(bookingId);
    expect(accepted.response.status()).toBe(200);

    const startTravel = await flow.workerApi.startTravel(bookingId);
    expect(startTravel.response.status()).toBe(200);
    const travelBooking = extractBooking(startTravel.body);
    expect(travelBooking?.startedTravelAt).toBeTruthy();

    const location = await flow.workerApi.postLocation(bookingId, 33.5201, 36.2808);
    expect(location.response.status()).toBe(200);
    const locationBody = location.body as Record<string, unknown>;
    expect((locationBody?.data as Record<string, unknown>)?.ok).toBe(true);
  });

  test('O-CL-03: arrive transitions to awaiting_start_verification', async ({ roleRequests, seed }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const bookingId = created.orderId;

    await flow.workerApi.acceptBooking(bookingId);
    await flow.workerApi.startTravel(bookingId);
    const arrived = await flow.workerApi.arrive(bookingId);

    expect(arrived.response.status()).toBe(200);
    expect(bookingStatus(arrived.body)).toBe('awaiting_start_verification');
  });

  test('O-CL-04: legacy direct start-work from worker_assigned is accepted', async ({
    roleRequests,
    seed,
  }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const bookingId = created.orderId;

    await flow.workerApi.acceptBooking(bookingId);
    const started = await flow.workerApi.startWork(bookingId);

    expect(started.response.status()).toBe(200);
    expect(bookingStatus(started.body)).toBe('in_progress');
  });

  test('O-CL-05: complete booking transitions to awaiting_customer_completion', async ({
    roleRequests,
    seed,
  }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const bookingId = created.orderId;

    await flow.workerApi.acceptBooking(bookingId);
    await flow.workerApi.startWork(bookingId);
    const completed = await flow.workerApi.complete(bookingId);

    expect(completed.response.status()).toBe(200);
    expect(bookingStatus(completed.body)).toBe('awaiting_customer_completion');

    const booking = extractBooking(completed.body);
    expect(booking?.workFinishedAt).toBeTruthy();
  });

  test('O-CL-06: extension warning list is scoped and accept/reject flows persist response', async ({
    roleRequests,
    seed,
  }) => {
    const workerApi = new CleaningApiClient(roleRequests.worker);

    const scopedList = await workerApi.listTimeWarnings({
      perPage: '50',
      'filter[forCurrentWorker]': '1',
      'filter[pending]': '1',
    });
    expect(scopedList.response.status()).toBe(200);

    const warningIds = (((scopedList.body as Record<string, unknown>)?.data ?? []) as Array<{ id: number }>).map(
      (warning) => warning.id,
    );
    expect(warningIds).toContain(seed.fixtures.warnings.pendingAccept);
    expect(warningIds).toContain(seed.fixtures.warnings.pendingReject);
    expect(warningIds).not.toContain(seed.fixtures.warnings.pendingOutsider);

    const accepted = await workerApi.acceptTimeWarning(seed.fixtures.warnings.pendingAccept, 25);
    expect(accepted.response.status()).toBe(200);
    const acceptedBody = extractBooking(accepted.body) ?? (accepted.body as Record<string, unknown>);
    expect(acceptedBody.workerResponse).toBe('extend_time');

    const rejected = await workerApi.rejectTimeWarning(
      seed.fixtures.warnings.pendingReject,
      'Cannot extend due next booking.',
    );
    expect(rejected.response.status()).toBe(200);
    const rejectedBody = extractBooking(rejected.body) ?? (rejected.body as Record<string, unknown>);
    expect(rejectedBody.workerResponse).toBe('commit_current_time');
  });

  test('O-CL-07: worker homepage/profile/statistics/account endpoints are healthy', async ({
    roleRequests,
    seed,
  }) => {
    const workerApi = new CleaningApiClient(roleRequests.worker);

    const homepage = await workerApi.workerHomepage();
    expect(homepage.response.status()).toBe(200);
    expect((homepage.body as Record<string, unknown>)?.totalBookings).toBeDefined();
    expect((homepage.body as Record<string, unknown>)?.pendingExtensionRequestsCount).toBeDefined();

    const profile = await workerApi.workerProfile();
    expect(profile.response.status()).toBe(200);
    expect((profile.body as Record<string, unknown>)?.data).toBeTruthy();

    const statistics = await workerApi.workerStatistics();
    expect(statistics.response.status()).toBe(200);
    expect((statistics.body as Record<string, unknown>)?.summary).toBeTruthy();
    expect(Array.isArray((statistics.body as Record<string, unknown>)?.chart)).toBe(true);

    const profileUpdate = await workerApi.updateWorkerProfile({
      name: `PW Worker ${seed.runId}`,
      bio: `Playwright profile update ${seed.runId}`,
      isActive: true,
    });
    expect(profileUpdate.response.status()).toBe(200);

    const workAreas = await workerApi.updateWorkerWorkAreas({
      zones: [
        { name: `Damascus-${seed.runId}`, isActive: true },
        { name: `Homs-${seed.runId}`, isActive: true },
      ],
    });
    expect(workAreas.response.status()).toBe(200);
    const zones = ((workAreas.body as Record<string, unknown>)?.zones ?? []) as Array<{ name: string }>;
    expect(zones).toHaveLength(2);

    const workerUpdate = await workerApi.updateWorkerAvailability(seed.actors.worker.workerId, {
      userId: seed.actors.worker.userId,
      firstName: `Worker-${seed.runId}`,
      isActive: false,
    });
    expect(workerUpdate.response.status()).toBe(200);
    const workerPayload = (workerUpdate.body as Record<string, unknown>)?.data as Record<string, unknown>;
    expect(workerPayload?.id).toBe(seed.actors.worker.workerId);
  });

  test('N-CL-07: worker action precondition mismatch is rejected (start-travel before accept)', async ({
    roleRequests,
    seed,
  }) => {
    const userApi = new CleaningApiClient(roleRequests.user);
    const workerApi = new CleaningApiClient(roleRequests.worker);

    const created = await userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    const bookingId = requireOrderId(created.body);

    const startTravel = await workerApi.startTravel(bookingId);
    expect([403, 422]).toContain(startTravel.response.status());
  });

  test('N-CL-09: unsupported booking status filter is rejected', async ({ roleRequests }) => {
    const workerApi = new CleaningApiClient(roleRequests.worker);
    const response = await workerApi.listCleaningBookings({
      'filter[status]': 'awaiting_customer_completion',
    });
    expect(response.response.status()).toBe(422);
  });

  test('Wrong role actor is blocked from worker booking actions', async ({ roleRequests, seed }) => {
    const userApi = new CleaningApiClient(roleRequests.user);
    const wrongRoleApi = new CleaningApiClient(roleRequests.wrong_role);

    const created = await userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    const bookingId = requireOrderId(created.body);

    const attempt = await wrongRoleApi.acceptBooking(bookingId);
    expect(attempt.response.status()).toBe(403);
  });
});
