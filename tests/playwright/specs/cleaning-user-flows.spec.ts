import { expect, test } from '../fixtures/auth';
import {
  bookingStatus,
  CleaningFlowHarness,
  CleaningApiClient,
  extractBooking,
  futureDate,
} from '../helpers/cleaning-api-client';

function requireOrderId(body: unknown): number {
  const booking = extractBooking(body);
  const orderId = Number(booking?.id);
  expect(orderId).toBeGreaterThan(0);
  return orderId;
}

test.describe('Cleaning User API contract scenarios', () => {
  test('U-CL-01: estimate then create booking', async ({ roleRequests, seed }) => {
    const userApi = new CleaningApiClient(roleRequests.user);

    const estimateResponse = await userApi.estimatePrice();
    expect(estimateResponse.response.status()).toBe(200);
    expect((estimateResponse.body as Record<string, unknown>)?.pricing).toBeTruthy();
    expect((estimateResponse.body as Record<string, unknown>)?.size).toBeTruthy();

    const createResponse = await userApi.createOrder(seed.runId);
    expect(createResponse.response.status()).toBe(201);

    const order = extractBooking(createResponse.body);
    expect(order?.status).toBe('pending');
    expect(order?.totalPrice).toBeDefined();
    expect(order?.basePrice).toBeDefined();
  });

  test('U-CL-02: list + show own bookings', async ({ roleRequests, seed }) => {
    const userApi = new CleaningApiClient(roleRequests.user);
    const created = await userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    const orderId = requireOrderId(created.body);

    const listResponse = await userApi.listUserOrders({ perPage: '50' });
    expect(listResponse.response.status()).toBe(200);
    const ids = (((listResponse.body as Record<string, unknown>)?.data ?? []) as Array<{ id: number }>).map(
      (item) => item.id,
    );
    expect(ids).toContain(orderId);

    const showResponse = await userApi.showUserOrder(orderId);
    expect(showResponse.response.status()).toBe(200);
    const shown = extractBooking(showResponse.body);
    expect(Number(shown?.id)).toBe(orderId);
  });

  test('U-CL-07: patch schedule in allowed status', async ({ roleRequests, seed }) => {
    const userApi = new CleaningApiClient(roleRequests.user);
    const created = await userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    const orderId = requireOrderId(created.body);

    const patchResponse = await userApi.patchUserOrder(orderId, {
      scheduledDate: futureDate(3),
      scheduledTime: '11:30',
    });
    expect(patchResponse.response.status()).toBe(200);

    const patched = extractBooking(patchResponse.body);
    expect(String(patched?.scheduledTime)).toContain('11:30');
  });

  test('U-CL-03: confirm start verification transitions to in_progress', async ({ roleRequests, seed }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const orderId = created.orderId;

    await flow.moveToAwaitingStartVerification(orderId);
    const issued = await flow.workerApi.securityCode(orderId);
    expect(issued.response.status()).toBe(200);
    const securityCode = String(
      ((issued.body as Record<string, unknown>)?.data as Record<string, unknown>)?.securityCode ?? '',
    );
    expect(securityCode).toHaveLength(4);

    const confirm = await flow.userApi.confirmStartVerification(orderId, securityCode);
    expect(confirm.response.status()).toBe(200);
    expect(bookingStatus(confirm.body)).toBe('in_progress');

    const show = await flow.workerApi.showCleaningBooking(orderId);
    expect(show.response.status()).toBe(200);
    expect(bookingStatus(show.body)).toBe('in_progress');
  });

  test('U-CL-04: completion confirm transitions to completed', async ({ roleRequests, seed }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const orderId = created.orderId;
    await flow.moveToAwaitingCustomerCompletion(orderId);

    const confirm = await flow.userApi.confirmCompletion(orderId);
    expect(confirm.response.status()).toBe(200);
    expect(bookingStatus(confirm.body)).toBe('completed');

    const show = await flow.userApi.showUserOrder(orderId);
    expect(show.response.status()).toBe(200);
    expect(bookingStatus(show.body)).toBe('completed');
  });

  test('U-CL-05: completion reject transitions back to in_progress and clears workFinishedAt', async ({
    roleRequests,
    seed,
  }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const orderId = created.orderId;
    await flow.moveToAwaitingCustomerCompletion(orderId);

    const reject = await flow.userApi.rejectCompletion(orderId, 'Need extra cleaning on kitchen.');
    expect(reject.response.status()).toBe(200);
    expect(bookingStatus(reject.body)).toBe('in_progress');

    const show = await flow.userApi.showUserOrder(orderId);
    expect(show.response.status()).toBe(200);
    const booking = extractBooking(show.body);
    expect(booking?.workFinishedAt).toBeNull();
  });

  test('U-CL-06: completion extend-time transitions to time_extension_requested', async ({
    roleRequests,
    seed,
  }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const orderId = created.orderId;

    await flow.workerApi.acceptBooking(orderId);
    await flow.workerApi.startWork(orderId);
    await flow.workerApi.complete(orderId);

    const extend = await flow.userApi.requestCompletionExtension(orderId, 30);
    expect(extend.response.status()).toBe(200);
    expect(bookingStatus(extend.body)).toBe('time_extension_requested');
  });

  test('N-CL-04: user cancel in disallowed status is rejected', async ({ roleRequests, seed }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const orderId = created.orderId;
    await flow.moveToInProgress(orderId);

    const cancel = await flow.userApi.cancelUserOrder(orderId, 'Too late');
    expect(cancel.response.status()).toBe(422);
  });

  test('N-CL-05: user patch in in_progress status is rejected', async ({ roleRequests, seed }) => {
    const flow = new CleaningFlowHarness(roleRequests.user, roleRequests.worker, seed.runId);
    const created = await flow.createPendingOrder();
    const orderId = created.orderId;
    await flow.moveToInProgress(orderId);

    const patch = await flow.userApi.patchUserOrder(orderId, {
      scheduledDate: futureDate(5),
      scheduledTime: '14:00',
    });
    expect(patch.response.status()).toBe(422);
  });

  test('Contract gap: review endpoint is currently missing in backend routes', async ({
    roleRequests,
    seed,
  }) => {
    const userApi = new CleaningApiClient(roleRequests.user);
    const created = await userApi.createOrder(seed.runId);
    expect(created.response.status()).toBe(201);
    const orderId = requireOrderId(created.body);

    const review = await userApi.postReview(orderId, {
      rating: 5,
      comment: 'Excellent service',
    });
    expect(review.response.status()).toBe(404);
  });
});
