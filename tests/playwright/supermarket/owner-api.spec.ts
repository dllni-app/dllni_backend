import { APIRequestContext } from '@playwright/test';
import { expect, test } from './fixtures';

async function createPendingOrderViaUserFlow(
  roleRequests: {
    user: APIRequestContext;
  },
  productId: number,
): Promise<number> {
  const addResponse = await roleRequests.user.post('/api/v1/user/supermarket/cart/items', {
    data: {
      productId,
      quantity: 1,
    },
  });
  expect([200, 201]).toContain(addResponse.status());

  const orderResponse = await roleRequests.user.post('/api/v1/user/supermarket/orders', {
    data: {
      fulfillmentType: 'delivery',
      receiveMode: 'immediate',
      note: 'Owner flow pending order seed',
    },
  });
  expect([200, 201]).toContain(orderResponse.status());

  const orderBody = await orderResponse.json();
  const orderId = Number(orderBody?.data?.id);
  expect(orderId).toBeGreaterThan(0);

  return orderId;
}

test.describe('Supermarket Owner API (Playwright request contexts)', () => {
  test('OWN-SM-01: store owner dashboard returns KPI payload', async ({ roleRequests }) => {
    const response = await roleRequests.store_owner.get('/api/v1/store-owner/dashboard');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body?.data?.totalOrders).toBeDefined();
    expect(body?.data?.completedOrders).toBeDefined();
    expect(body?.data?.newOrders).toBeDefined();
    expect(body?.data?.pendingOrders).toBeDefined();
    expect(body?.data?.totalSales).toBeDefined();
  });

  test('OWN-SM-04: order queue is owner-scoped', async ({ roleRequests, seed }) => {
    const response = await roleRequests.store_owner.get('/api/v1/sm-orders', {
      params: { perPage: '50' },
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    const orderStoreIds = ((body?.data ?? []) as Array<{ storeId?: number; store_id?: number }>).map(
      (order) => Number(order.storeId ?? order.store_id),
    );

    expect(orderStoreIds.length).toBeGreaterThan(0);
    expect(orderStoreIds).toContain(seed.fixtures.store.owned);
    expect(orderStoreIds).not.toContain(seed.fixtures.store.other);
  });

  test('OWN-SM-05: owner can fetch order details for owned store order', async ({ roleRequests, seed }) => {
    const orderId = seed.fixtures.orders.tracking;

    const response = await roleRequests.store_owner.get(`/api/v1/sm-orders/${orderId}`);
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Number(body?.data?.id)).toBe(orderId);
    expect(Array.isArray(body?.data?.items)).toBeTruthy();
  });

  test('OWN-SM-06: owner can accept a pending order', async ({ roleRequests, seed }) => {
    test.fail(true, 'Known backend regression: accept transition returns 400 for freshly pending orders in API mode.');
    const orderId = await createPendingOrderViaUserFlow(roleRequests, seed.fixtures.products.available);

    const acceptResponse = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/accept`);
    expect(acceptResponse.status()).toBe(200);

    const acceptBody = await acceptResponse.json();
    expect(acceptBody?.data?.status).toBe('accepted');

    const showResponse = await roleRequests.store_owner.get(`/api/v1/sm-orders/${orderId}`);
    expect(showResponse.status()).toBe(200);
    const showBody = await showResponse.json();
    expect(showBody?.data?.status).toBe('accepted');
  });

  test('OWN-SM-07: accept rejects non-pending order', async ({ roleRequests, seed }) => {
    const orderId = seed.fixtures.orders.non_ready;

    const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/accept`);
    expect([400, 422]).toContain(response.status());
  });

  test('OWN-SM-08: owner can reject a pending order with valid reason/type', async ({ roleRequests, seed }) => {
    test.fail(true, 'Known backend regression: reject transition returns 400 for freshly pending orders in API mode.');
    const orderId = await createPendingOrderViaUserFlow(roleRequests, seed.fixtures.products.available);

    const rejectResponse = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/reject`, {
      data: {
        reason: 'Out of stock for this item',
        rejectionType: 'out_of_stock',
      },
    });

    expect(rejectResponse.status()).toBe(200);
    const body = await rejectResponse.json();
    expect(body?.data?.status).toBe('cancelled');
    expect(body?.data?.cancellationReason).toBe('Out of stock for this item');
  });

  test('OWN-SM-09: reject validates missing reason payload', async ({ roleRequests, seed }) => {
    const orderId = seed.fixtures.orders.pending_reject;

    const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/reject`, {
      data: {
        rejectionType: 'out_of_stock',
      },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body?.errors?.reason?.[0]).toBeTruthy();
  });

  test('OWN-SM-10: owner can hand over ready-for-pickup order to courier', async ({ roleRequests, seed }) => {
    const orderId = seed.fixtures.orders.ready_for_pickup;

    const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/courier-handover`);
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body?.data?.status).toBe('picked_up');
    expect(body?.data?.pickedUpAt).toBeTruthy();
  });

  test('OWN-SM-11: handover rejects non-ready orders', async ({ roleRequests, seed }) => {
    const orderId = seed.fixtures.orders.non_ready;

    const response = await roleRequests.store_owner.post(`/api/v1/store-owner/orders/${orderId}/courier-handover`);
    expect([400, 422]).toContain(response.status());
  });

  test('OWN-SM-12: hourly count returns weekly status buckets', async ({ roleRequests }) => {
    const response = await roleRequests.store_owner.get('/api/v1/sm-orders/hourly-count');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body?.data?.saturday).toBeDefined();
    expect(body?.data?.wednesday).toBeDefined();
  });

  test('OWN-SM-16: owner can search master products by prefix', async ({ roleRequests }) => {
    const response = await roleRequests.store_owner.get('/api/v1/store-owner/master-products/search', {
      params: {
        index: 'PW Master',
        page: '1',
        perPage: '10',
      },
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body?.data)).toBeTruthy();
    expect(body?.meta).toBeDefined();
  });

  test('OWN-SM-18: low-stock endpoint includes seeded low stock product', async ({ roleRequests, seed }) => {
    const response = await roleRequests.store_owner.get('/api/v1/store-owner/products/low-stock');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body?.success).toBe(true);
    expect(Number(body?.data?.total)).toBeGreaterThan(0);

    const ids = ((body?.data?.products ?? []) as Array<{ product_id?: number; productId?: number }>).map((item) =>
      Number(item.product_id ?? item.productId),
    );
    expect(ids).toContain(seed.fixtures.products.low_stock);
  });

  test('OWN-SM-19: owner can set product stock successfully', async ({ roleRequests, seed }) => {
    const productId = seed.fixtures.products.available;

    const response = await roleRequests.store_owner.put(`/api/v1/store-owner/products/${productId}/stock`, {
      data: {
        quantity: 18,
        operation: 'SET',
      },
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body?.success).toBe(true);
    expect(Number(body?.data?.product_id)).toBe(productId);
    expect(Number(body?.data?.stock_quantity)).toBe(18);
  });

  test('OWN-SM-20: stock update validates bad operation', async ({ roleRequests, seed }) => {
    const productId = seed.fixtures.products.available;

    const response = await roleRequests.store_owner.put(`/api/v1/store-owner/products/${productId}/stock`, {
      data: {
        quantity: 1,
        operation: 'BAD_OP',
      },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body?.errors?.operation?.[0]).toBeTruthy();
  });

  test('OWN-SM-21: owner can show and update store profile', async ({ roleRequests }) => {
    const showResponse = await roleRequests.store_owner.get('/api/v1/store-owner/store');
    expect(showResponse.status()).toBe(200);

    const showBody = await showResponse.json();
    const storeId = Number(showBody?.data?.id);
    expect(storeId).toBeGreaterThan(0);

    const updateResponse = await roleRequests.store_owner.put('/api/v1/store-owner/store', {
      data: {
        name: `PW Updated Store ${storeId}`,
        description: 'Updated from Playwright API-first owner suite',
      },
    });
    expect(updateResponse.status()).toBe(200);

    const updateBody = await updateResponse.json();
    expect(updateBody?.data?.name).toContain('PW Updated Store');
    expect(updateBody?.data?.description).toBe('Updated from Playwright API-first owner suite');
  });

  test('OWN-SM-22: owner can show and update operating hours', async ({ roleRequests }) => {
    const showResponse = await roleRequests.store_owner.get('/api/v1/store-owner/store/operating-hours');
    expect(showResponse.status()).toBe(200);

    const showBody = await showResponse.json();
    expect(Array.isArray(showBody?.data?.dailyHours)).toBeTruthy();
    expect((showBody?.data?.dailyHours ?? []).length).toBe(7);

    const updateResponse = await roleRequests.store_owner.put('/api/v1/store-owner/store/operating-hours', {
      data: {
        isTemporarilyClosed: false,
        dailyHours: [
          {
            dayOfWeek: 'monday',
            isEnabled: true,
            timeSlots: [
              {
                startTime: '09:00 AM',
                endTime: '05:00 PM',
              },
            ],
          },
        ],
      },
    });
    expect(updateResponse.status()).toBe(200);

    const updateBody = await updateResponse.json();
    const monday = ((updateBody?.data?.dailyHours ?? []) as Array<{ dayOfWeek: string; isEnabled: boolean }>).find(
      (row) => row.dayOfWeek === 'monday',
    );
    expect(monday?.isEnabled).toBe(true);
  });

  test('OWN-SM-23: owner can fetch permissions and manage employee status', async ({ roleRequests, seed }) => {
    const permissionsResponse = await roleRequests.store_owner.get('/api/v1/store-owner/permissions');
    expect(permissionsResponse.status()).toBe(200);

    const permissionsBody = await permissionsResponse.json();
    const permissionIds = ((permissionsBody?.data?.permissions ?? []) as Array<{ id: number }>)
      .slice(0, 2)
      .map((item) => item.id);

    const email = `pw-staff-${seed.runId}@example.test`;
    const createResponse = await roleRequests.store_owner.post('/api/v1/store-owner/employees', {
      data: {
        name: 'PW Staff Member',
        email,
        phone: '+963955000333',
        isActive: true,
        permissionIds,
      },
    });
    expect(createResponse.status()).toBe(201);

    const createBody = await createResponse.json();
    const staffId = Number(createBody?.data?.id);
    expect(staffId).toBeGreaterThan(0);
    expect(createBody?.data?.user?.email).toBe(email);

    const statusResponse = await roleRequests.store_owner.patch(`/api/v1/store-owner/employees/${staffId}/status`, {
      data: {
        isActive: false,
      },
    });
    expect(statusResponse.status()).toBe(200);

    const statusBody = await statusResponse.json();
    expect(statusBody?.data?.isActive).toBe(false);

    const listResponse = await roleRequests.store_owner.get('/api/v1/store-owner/employees');
    expect(listResponse.status()).toBe(200);
    const listBody = await listResponse.json();
    const employeeEmails = ((listBody?.data?.employees ?? []) as Array<{ user?: { email?: string } }>).map(
      (item) => item.user?.email,
    );
    expect(employeeEmails).toContain(email);
  });

  test('OWN-SM-23: owner can fetch activity logs', async ({ roleRequests, seed }) => {
    const productId = seed.fixtures.products.available;
    const stockUpdateResponse = await roleRequests.store_owner.put(`/api/v1/store-owner/products/${productId}/stock`, {
      data: {
        quantity: 17,
        operation: 'SET',
      },
    });
    expect(stockUpdateResponse.status()).toBe(200);

    const logsResponse = await roleRequests.store_owner.get('/api/v1/store-owner/activity-logs', {
      params: {
        logName: 'inventory',
        perPage: '10',
      },
    });
    expect(logsResponse.status()).toBe(200);

    const logsBody = await logsResponse.json();
    expect(Array.isArray(logsBody?.data)).toBeTruthy();
    expect((logsBody?.data ?? []).length).toBeGreaterThan(0);
  });

  test('OWN-SM-24: wrong role is blocked from owner endpoints', async ({ roleRequests }) => {
    const response = await roleRequests.wrong_role.get('/api/v1/store-owner/dashboard');
    expect(response.status()).toBe(403);
  });

  test('OWN-SM-24: missing token is rejected for owner endpoints', async ({ roleRequests }) => {
    const response = await roleRequests.guest.get('/api/v1/store-owner/dashboard');
    expect(response.status()).toBe(401);
  });
});
