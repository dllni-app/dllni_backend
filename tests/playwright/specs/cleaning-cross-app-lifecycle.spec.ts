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

test.describe('Cleaning cross-app lifecycle + critical negatives', () => {
  test('X-CL-01: full booking lifecycle with user + worker actors', async ({ roleRequests, seed }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);

    const created = await flow.userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    expect(bookingStatus(created.body)).toBe('pending');
    const bookingId = requireOrderId(created.body);

    const accepted = await flow.workerApi.acceptBooking(bookingId);
    expect(accepted.response.status()).toBe(200);
    expect(bookingStatus(accepted.body)).toBe('worker_assigned');

    const startedTravel = await flow.workerApi.startTravel(bookingId);
    expect(startedTravel.response.status()).toBe(200);
    expect(extractBooking(startedTravel.body)?.startedTravelAt).toBeTruthy();

    const location = await flow.workerApi.postLocation(bookingId, 33.5201, 36.2810);
    expect(location.response.status()).toBe(200);

    const arrived = await flow.workerApi.arrive(bookingId);
    expect(arrived.response.status()).toBe(200);
    expect(bookingStatus(arrived.body)).toBe('awaiting_start_verification');

    const securityCodeResponse = await flow.workerApi.securityCode(bookingId);
    expect(securityCodeResponse.response.status()).toBe(200);
    const securityCode = String(
      ((securityCodeResponse.body as Record<string, unknown>)?.data as Record<string, unknown>)
        ?.securityCode ?? '',
    );
    expect(securityCode).toHaveLength(4);

    const confirmedStart = await flow.userApi.confirmStartVerification(bookingId, securityCode);
    expect(confirmedStart.response.status()).toBe(200);
    expect(bookingStatus(confirmedStart.body)).toBe('in_progress');

    const completedByWorker = await flow.workerApi.complete(bookingId);
    expect(completedByWorker.response.status()).toBe(200);
    expect(bookingStatus(completedByWorker.body)).toBe('awaiting_customer_completion');

    const completionDecision = await flow.userApi.confirmCompletion(bookingId);
    expect(completionDecision.response.status()).toBe(200);
    expect(bookingStatus(completionDecision.body)).toBe('completed');

    const userView = await flow.userApi.showUserOrder(bookingId);
    expect(userView.response.status()).toBe(200);
    expect(bookingStatus(userView.body)).toBe('completed');
  });

  test('N-CL-01: invalid start verification code returns validation error', async ({
    roleRequests,
    seed,
  }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const bookingId = created.orderId;

    await flow.moveToAwaitingStartVerification(bookingId);
    const issued = await flow.workerApi.securityCode(bookingId);
    expect(issued.response.status()).toBe(200);

    const realCode = String(
      ((issued.body as Record<string, unknown>)?.data as Record<string, unknown>)?.securityCode ?? '',
    );
    const wrongCode = realCode === '0000' ? '9999' : '0000';

    const confirm = await flow.userApi.confirmStartVerification(bookingId, wrongCode);
    expect(confirm.response.status()).toBe(422);
  });

  test('N-CL-03: repeated wrong verification code attempts are throttled', async ({
    roleRequests,
    seed,
  }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const bookingId = created.orderId;

    await flow.moveToAwaitingStartVerification(bookingId);
    const issued = await flow.workerApi.securityCode(bookingId);
    expect(issued.response.status()).toBe(200);

    const realCode = String(
      ((issued.body as Record<string, unknown>)?.data as Record<string, unknown>)?.securityCode ?? '',
    );
    const wrongCode = realCode === '0000' ? '9999' : '0000';

    for (let attempt = 0; attempt < 5; attempt += 1) {
      const response = await flow.userApi.confirmStartVerification(bookingId, wrongCode);
      expect(response.response.status()).toBe(422);
    }

    const throttled = await flow.userApi.confirmStartVerification(bookingId, wrongCode);
    expect(throttled.response.status()).toBe(429);
  });

  test('N-CL-06: completion action outside awaiting_customer_completion is rejected', async ({
    roleRequests,
    seed,
  }) => {
    const userApi = new CleaningApiClient(roleRequests.user);
    const created = await userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    const bookingId = requireOrderId(created.body);

    const confirm = await userApi.confirmCompletion(bookingId);
    expect(confirm.response.status()).toBe(422);
  });

  test('N-CL-08: channel auth mismatch returns 403 for non-member actor', async ({
    roleRequests,
    seed,
  }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const wrongRoleApi = new CleaningApiClient(roleRequests.wrong_role);
    const outsiderWorkerApi = new CleaningApiClient(roleRequests.outsider_worker);

    const created = await flow.createPendingOrder();
    const bookingId = created.orderId;
    await flow.workerApi.acceptBooking(bookingId);

    const bookingChannel = `private-cleaning-booking.${bookingId}`;
    const workerChannel = `private-cleaning-worker.${seed.actors.worker.workerId}`;

    const allowedBookingAuth = await flow.workerApi.broadcastAuth(bookingChannel);
    expect(allowedBookingAuth.response.status()).toBe(200);
    expect((allowedBookingAuth.body as Record<string, unknown>)?.auth).toBeDefined();

    const deniedBookingAuth = await wrongRoleApi.broadcastAuth(bookingChannel);
    expect(deniedBookingAuth.response.status()).toBe(403);

    const allowedWorkerChannel = await flow.workerApi.broadcastAuth(workerChannel);
    expect(allowedWorkerChannel.response.status()).toBe(200);

    const deniedWorkerChannel = await outsiderWorkerApi.broadcastAuth(workerChannel);
    expect(deniedWorkerChannel.response.status()).toBe(403);
  });
});
