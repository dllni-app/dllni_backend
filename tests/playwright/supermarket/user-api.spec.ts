import { expect, test } from './fixtures';

test.describe('Supermarket User API (Playwright request contexts)', () => {
  test('USR-SM-01: guest can fetch featured offers', async ({ roleRequests }) => {
    const response = await roleRequests.guest.get('/api/v1/user/supermarket/home/featured-offers');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body?.offers)).toBeTruthy();
  });

  test('USR-SM-02: guest can fetch nearby stores', async ({ roleRequests }) => {
    const response = await roleRequests.guest.get('/api/v1/user/supermarket/home/nearby-stores', {
      params: {
        limit: '10',
      },
    });

    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(Array.isArray(body?.stores)).toBeTruthy();
  });

  test('USR-SM-03/04: guest can browse stores and search by text', async ({ roleRequests, seed }) => {
    const browseResponse = await roleRequests.guest.get('/api/v1/user/supermarket/stores', {
      params: {
        page: '1',
        perPage: '20',
      },
    });
    expect(browseResponse.status()).toBe(200);
    const browseBody = await browseResponse.json();
    expect(Array.isArray(browseBody?.data)).toBeTruthy();
    expect(browseBody?.meta).toBeDefined();

    const searchResponse = await roleRequests.guest.get('/api/v1/user/supermarket/stores', {
      params: {
        search: 'PW Supermarket Store',
        page: '1',
        perPage: '20',
      },
    });
    expect(searchResponse.status()).toBe(200);
    const searchBody = await searchResponse.json();
    const storeIds = ((searchBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
    expect(storeIds).toContain(seed.fixtures.store.owned);
  });

  test('USR-SM-06: guest product search responds with catalog contract', async ({
    roleRequests,
    seed,
  }) => {
    const response = await roleRequests.guest.get('/api/v1/user/supermarket/products/search', {
      params: {
        search: 'PW Available Milk',
        page: '1',
        perPage: '20',
      },
    });

    expect(response.status()).toBe(200);
    const body = await response.json();
    const ids = ((body?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
    expect(ids).not.toContain(seed.fixtures.products.unavailable);
    expect(Array.isArray(body?.data)).toBeTruthy();
    expect(body?.meta).toBeDefined();
  });

  test('USR-SM-07/08: user can fetch product details and compare alternatives', async ({
    roleRequests,
    seed,
  }) => {
    const productId = seed.fixtures.products.available;

    const showResponse = await roleRequests.user.get(`/api/v1/user/supermarket/products/${productId}`);
    expect(showResponse.status()).toBe(200);
    const showBody = await showResponse.json();
    expect(Number(showBody?.data?.id)).toBe(productId);
    expect(showBody?.data?.store).toBeDefined();
    expect(showBody?.data?.finalPrice).toBeDefined();

    const compareResponse = await roleRequests.user.get(`/api/v1/user/supermarket/products/${productId}/compare`, {
      params: {
        page: '1',
        perPage: '20',
      },
    });
    expect(compareResponse.status()).toBe(200);
    const compareBody = await compareResponse.json();
    const comparedIds = ((compareBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
    expect(comparedIds).toContain(seed.fixtures.products.similar);
    expect(comparedIds).not.toContain(productId);
  });

  test('USR-SM-09/10: user can favorite and unfavorite a store', async ({ roleRequests, seed }) => {
    const storeId = seed.fixtures.store.owned;

    const addResponse = await roleRequests.user.post(`/api/v1/user/favorites/supermarket/stores/${storeId}`);
    expect([200, 201]).toContain(addResponse.status());

    const listAfterAdd = await roleRequests.user.get('/api/v1/user/favorites/supermarket/stores');
    expect(listAfterAdd.status()).toBe(200);
    const listAfterAddBody = await listAfterAdd.json();
    const addedIds = ((listAfterAddBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
    expect(addedIds).toContain(storeId);

    const removeResponse = await roleRequests.user.delete(`/api/v1/user/favorites/supermarket/stores/${storeId}`);
    expect([200, 204]).toContain(removeResponse.status());

    const listAfterRemove = await roleRequests.user.get('/api/v1/user/favorites/supermarket/stores');
    expect(listAfterRemove.status()).toBe(200);
    const listAfterRemoveBody = await listAfterRemove.json();
    const removedIds = ((listAfterRemoveBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
    expect(removedIds).not.toContain(storeId);
  });

  test('USR-SM-11/12: user can favorite and unfavorite a product', async ({ roleRequests, seed }) => {
    const productId = seed.fixtures.products.available;

    const addResponse = await roleRequests.user.post(`/api/v1/user/favorites/supermarket/products/${productId}`);
    expect([200, 201]).toContain(addResponse.status());

    const listAfterAdd = await roleRequests.user.get('/api/v1/user/favorites/supermarket/products');
    expect(listAfterAdd.status()).toBe(200);
    const listAfterAddBody = await listAfterAdd.json();
    const addedIds = ((listAfterAddBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
    expect(addedIds).toContain(productId);

    const removeResponse = await roleRequests.user.delete(`/api/v1/user/favorites/supermarket/products/${productId}`);
    expect([200, 204]).toContain(removeResponse.status());

    const listAfterRemove = await roleRequests.user.get('/api/v1/user/favorites/supermarket/products');
    expect(listAfterRemove.status()).toBe(200);
    const listAfterRemoveBody = await listAfterRemove.json();
    const removedIds = ((listAfterRemoveBody?.data ?? []) as Array<{ id: number }>).map((item) => item.id);
    expect(removedIds).not.toContain(productId);
  });

  test('USR-SM-13: user cart show returns empty list on fresh seed', async ({ roleRequests }) => {
    const showResponse = await roleRequests.user.get('/api/v1/user/supermarket/cart');
    expect(showResponse.status()).toBe(200);

    const showBody = await showResponse.json();
    const items = (showBody?.data?.items ?? []) as unknown[];
    expect(items).toHaveLength(0);
  });

  test('USR-SM-14/15/16: cart add, update, and delete lifecycle works', async ({ roleRequests, seed }) => {
    const addResponse = await roleRequests.user.post('/api/v1/user/supermarket/cart/items', {
      data: {
        productId: seed.fixtures.products.available,
        quantity: 2,
      },
    });
    expect([200, 201]).toContain(addResponse.status());

    const addBody = await addResponse.json();
    const itemId = Number(addBody?.data?.items?.[0]?.id);
    expect(itemId).toBeGreaterThan(0);

    const updateResponse = await roleRequests.user.patch(`/api/v1/user/supermarket/cart/items/${itemId}`, {
      data: { quantity: 3 },
    });
    expect(updateResponse.status()).toBe(200);
    const updateBody = await updateResponse.json();
    expect(Number(updateBody?.data?.items?.[0]?.quantity)).toBe(3);

    const deleteResponse = await roleRequests.user.delete(`/api/v1/user/supermarket/cart/items/${itemId}`);
    expect([200, 204]).toContain(deleteResponse.status());

    const showResponse = await roleRequests.user.get('/api/v1/user/supermarket/cart');
    expect(showResponse.status()).toBe(200);
    const showBody = await showResponse.json();
    const items = (showBody?.data?.items ?? []) as unknown[];
    expect(items).toHaveLength(0);
  });

  test('USR-SM-19/20: user can place an order and fetch tracking payload', async ({ roleRequests, seed }) => {
    const addResponse = await roleRequests.user.post('/api/v1/user/supermarket/cart/items', {
      data: {
        productId: seed.fixtures.products.available,
        quantity: 1,
      },
    });
    expect([200, 201]).toContain(addResponse.status());

    const orderResponse = await roleRequests.user.post('/api/v1/user/supermarket/orders', {
      data: {
        fulfillmentType: 'delivery',
        receiveMode: 'immediate',
        note: 'Playwright API-first order',
      },
    });
    expect([200, 201]).toContain(orderResponse.status());

    const orderBody = await orderResponse.json();
    const orderId = Number(orderBody?.data?.id);
    expect(orderId).toBeGreaterThan(0);

    const trackingResponse = await roleRequests.user.get(`/api/v1/user/orders/supermarket/${orderId}/tracking`);
    expect(trackingResponse.status()).toBe(200);
    const trackingBody = await trackingResponse.json();

    expect(trackingBody?.data?.eta?.minutes).toBeDefined();
    expect(Array.isArray(trackingBody?.data?.timeline)).toBeTruthy();
    expect(trackingBody?.data?.merchant?.id).toBeDefined();
  });

  test('USR-SM-17: placing order with empty cart is rejected', async ({ roleRequests }) => {
    const response = await roleRequests.user.post('/api/v1/user/supermarket/orders', {
      data: {
        fulfillmentType: 'delivery',
        receiveMode: 'immediate',
        note: 'Should fail due to empty cart',
      },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body?.errors?.cart?.[0] ?? body?.message).toBeTruthy();
  });

  test('USR-SM-21/22: user can create shopping list and add item', async ({ roleRequests, seed }) => {
    const createResponse = await roleRequests.user.post('/api/v1/user/supermarket/shopping-lists', {
      data: {
        name: 'PW Weekly Essentials',
        description: 'API-first list',
        isActive: true,
      },
    });
    expect(createResponse.status()).toBe(201);

    const createBody = await createResponse.json();
    const shoppingListId = Number(createBody?.data?.id);
    expect(shoppingListId).toBeGreaterThan(0);

    const addItemResponse = await roleRequests.user.post(
      `/api/v1/user/supermarket/shopping-lists/${shoppingListId}/items`,
      {
        data: {
          masterProductId: seed.fixtures.master_products.primary,
          quantity: 2,
          unit: 'piece',
          isIncluded: true,
        },
      },
    );
    expect(addItemResponse.status()).toBe(201);

    const showResponse = await roleRequests.user.get(`/api/v1/user/supermarket/shopping-lists/${shoppingListId}`);
    expect(showResponse.status()).toBe(200);
    const showBody = await showResponse.json();
    const itemMasterProductIds = ((showBody?.data?.items ?? []) as Array<{ masterProductId: number }>).map(
      (item) => item.masterProductId,
    );
    expect(itemMasterProductIds).toContain(seed.fixtures.master_products.primary);
  });

  test('USR-SM-23: add-to-cart fails when included items have no common store', async ({ roleRequests, seed }) => {
    const createResponse = await roleRequests.user.post('/api/v1/user/supermarket/shopping-lists', {
      data: {
        name: 'PW No Common Store',
        isActive: true,
      },
    });
    expect(createResponse.status()).toBe(201);

    const createBody = await createResponse.json();
    const shoppingListId = Number(createBody?.data?.id);
    expect(shoppingListId).toBeGreaterThan(0);

    const firstItemResponse = await roleRequests.user.post(
      `/api/v1/user/supermarket/shopping-lists/${shoppingListId}/items`,
      {
        data: {
          masterProductId: seed.fixtures.master_products.primary,
          quantity: 1,
          isIncluded: true,
        },
      },
    );
    expect(firstItemResponse.status()).toBe(201);

    const secondItemResponse = await roleRequests.user.post(
      `/api/v1/user/supermarket/shopping-lists/${shoppingListId}/items`,
      {
        data: {
          masterProductId: seed.fixtures.master_products.other_store_only,
          quantity: 1,
          isIncluded: true,
        },
      },
    );
    expect(secondItemResponse.status()).toBe(201);

    const addToCartResponse = await roleRequests.user.post(
      `/api/v1/user/supermarket/shopping-lists/${shoppingListId}/add-to-cart`,
      { data: {} },
    );
    expect(addToCartResponse.status()).toBe(422);

    const body = await addToCartResponse.json();
    expect(body?.errors?.items?.[0]).toBeTruthy();
  });

  test('USR-SM-24: protected endpoints reject missing token', async ({ roleRequests }) => {
    const cartResponse = await roleRequests.guest.get('/api/v1/user/supermarket/cart');
    expect(cartResponse.status()).toBe(401);

    const orderResponse = await roleRequests.guest.post('/api/v1/user/supermarket/orders', {
      data: {
        fulfillmentType: 'delivery',
        receiveMode: 'immediate',
      },
    });
    expect(orderResponse.status()).toBe(401);

    const normalizeResponse = await roleRequests.guest.post('/api/v1/user/products/normalize-text', {
      data: {
        text: 'milk',
        module: 'supermarket',
      },
    });
    expect(normalizeResponse.status()).toBe(401);
  });

  test('USR-SM-25: checkout preview rejects empty cart', async ({ roleRequests }) => {
    const response = await roleRequests.user.post('/api/v1/user/supermarket/checkout/preview', {
      data: {
        fulfillmentType: 'delivery',
        receiveMode: 'immediate',
      },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body?.errors?.cart?.[0]).toBeTruthy();
  });
});
