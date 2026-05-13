import { test, expect } from '../fixtures/auth';
import {
  callApi,
  expectStatus,
  numericFromPath,
  pickStatusLabel,
} from '../helpers/api-client';

async function createUserRestaurantOrder(userRequest: any, productId: number): Promise<number | null> {
  const addCart = await callApi(userRequest, 'POST', '/api/v1/user/restaurants/cart/items', {
    data: {
      productId,
      quantity: 1,
      modifierIds: [],
      specialInstructions: 'cross-app-playwright',
    },
  });
  expectStatus(addCart, [201], 'cross-app add cart item');

  const order = await callApi(userRequest, 'POST', '/api/v1/user/restaurants/orders', {
    data: {
      fulfillmentType: 'dine_in',
      receiveMode: 'immediate',
      note: 'cross-app playwright order',
    },
  });

  expectStatus(order, [201], 'cross-app place order');

  return numericFromPath(order.body, 'data.id');
}

test.describe('Cross-App Restaurant Order Lifecycle (API-first, seeded)', () => {
  test('XAPP-RS-01 user places order, owner acts, user tracking reflects final state', async ({ roleRequests, restaurantSeed }) => {
    const productId = restaurantSeed.fixtures.products.available;

    const orderId = await createUserRestaurantOrder(roleRequests.restaurant_user, productId);
    expect(orderId).not.toBeNull();

    const ownerShowBefore = await callApi(roleRequests.owner, 'GET', `/api/v1/orders/${orderId}`);
    expectStatus(ownerShowBefore, [200], 'owner can see user-created order');

    const rejectInvalid = await callApi(roleRequests.owner, 'POST', `/api/v1/orders/${orderId}/reject`, {
      data: {},
    });
    expectStatus(rejectInvalid, [422], 'owner reject invalid payload in cross-app flow');

    const accept = await callApi(roleRequests.owner, 'POST', `/api/v1/orders/${orderId}/accept`, {
      data: {
        preparationTimeMinutes: 20,
        kitchenNotes: 'Cross-app accepted by Playwright',
      },
    });
    expectStatus(accept, [200], 'owner accept cross-app order');

    const userOrderDetails = await callApi(roleRequests.restaurant_user, 'GET', `/api/v1/user/orders/restaurant/${orderId}`);
    expectStatus(userOrderDetails, [200], 'user order details after owner action');

    const userTracking = await callApi(roleRequests.restaurant_user, 'GET', `/api/v1/user/orders/restaurant/${orderId}/tracking`);
    expectStatus(userTracking, [200], 'user tracking after owner action');

    const finalStatus = pickStatusLabel(userOrderDetails.body);
    expect(finalStatus).toBeTruthy();
  });
});
