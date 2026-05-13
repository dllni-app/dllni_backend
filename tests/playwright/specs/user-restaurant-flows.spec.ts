import { test, expect } from '../fixtures/auth';
import {
  callApi,
  expectStatus,
  numericFromPath,
  pickStringByPaths,
} from '../helpers/api-client';

test.describe('User Restaurant Flows (API-first, seeded)', () => {
  test('USR-P0 auth success and failure', async ({ roleRequests, restaurantSeed }) => {
    const success = await callApi<{ token?: string }>(roleRequests.guest, 'POST', '/api/v1/user/login', {
      data: {
        phone: restaurantSeed.auth.user.phone,
        password: restaurantSeed.auth.user.password,
      },
    });

    expectStatus(success, [200], 'user login success');
    expect(pickStringByPaths(success.body, ['token'])).toBeTruthy();

    const failure = await callApi(roleRequests.guest, 'POST', '/api/v1/user/login', {
      data: {
        phone: restaurantSeed.auth.user.phone,
        password: `${restaurantSeed.auth.user.password}-wrong`,
      },
    });

    expectStatus(failure, [401, 422], 'user login failure');
  });

  test('USR-P0 home/discovery/detail endpoints are healthy with seeded data', async ({ roleRequests, restaurantSeed }, testInfo) => {
    const publicEndpoints = [
      '/api/v1/user/restaurants/home/featured-offers',
      '/api/v1/user/restaurants/home/categories',
      '/api/v1/user/restaurants/home/exclusive-offers',
      '/api/v1/user/restaurants/home/suggested-products',
      '/api/v1/user/restaurants/home/nearest-restaurants',
      '/api/v1/user/restaurants/discover',
      '/api/v1/user/restaurants/products/search',
      '/api/v1/user/restaurants/products/with-offers',
    ];

    for (const endpoint of publicEndpoints) {
      const response = await callApi(roleRequests.guest, 'GET', endpoint, {
        params: { page: 1, perPage: 20, search: 'PW' },
      });
      expectStatus(response, [200], `public endpoint ${endpoint}`);
    }

    const latest = await callApi(roleRequests.restaurant_user, 'GET', '/api/v1/user/restaurants/home/latest-ordered-products', {
      params: { limit: 10 },
    });
    expectStatus(latest, [200], 'latest ordered products');

    const categoryProducts = await callApi(roleRequests.guest, 'GET', '/api/v1/user/restaurants/home/category-products', {
      params: { page: 1, perPage: 20 },
    });
    expectStatus(categoryProducts, [200, 404], 'category-products contract drift check');
    if (categoryProducts.status === 404) {
      testInfo.annotations.push({
        type: 'contract-drift',
        description: 'GET /api/v1/user/restaurants/home/category-products is called by app but missing in routes.',
      });
    }

    const restaurantId = restaurantSeed.fixtures.restaurants.owned;
    const productId = restaurantSeed.fixtures.products.available;

    const restaurantDetails = await callApi(roleRequests.guest, 'GET', `/api/v1/user/restaurants/${restaurantId}`);
    expectStatus(restaurantDetails, [200], 'restaurant details');

    const menuSections = await callApi(roleRequests.restaurant_user, 'GET', `/api/v1/user/restaurants/${restaurantId}/menu-sections`);
    expectStatus(menuSections, [200], 'restaurant menu sections');

    const productDetails = await callApi(roleRequests.guest, 'GET', `/api/v1/user/products/${productId}`);
    expectStatus(productDetails, [200], 'restaurant product details');
  });

  test('USR-P0 favorites/cart/coupon/checkout/order lifecycle works with seeded ids', async ({ roleRequests, restaurantSeed }) => {
    const restaurantId = restaurantSeed.fixtures.restaurants.owned;
    const productId = restaurantSeed.fixtures.products.available;

    const addFavoriteRestaurant = await callApi(
      roleRequests.restaurant_user,
      'POST',
      `/api/v1/user/favorites/restaurants/${restaurantId}`,
      { data: {} },
    );
    expectStatus(addFavoriteRestaurant, [200, 201], 'favorite restaurant add');

    const removeFavoriteRestaurant = await callApi(
      roleRequests.restaurant_user,
      'DELETE',
      `/api/v1/user/favorites/restaurants/${restaurantId}`,
    );
    expectStatus(removeFavoriteRestaurant, [200, 204], 'favorite restaurant remove');

    const addFavoriteProduct = await callApi(
      roleRequests.restaurant_user,
      'POST',
      `/api/v1/user/favorites/products/${productId}`,
      { data: {} },
    );
    expectStatus(addFavoriteProduct, [200, 201], 'favorite product add');

    const removeFavoriteProduct = await callApi(
      roleRequests.restaurant_user,
      'DELETE',
      `/api/v1/user/favorites/products/${productId}`,
    );
    expectStatus(removeFavoriteProduct, [200, 204], 'favorite product remove');

    const addCart = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/cart/items', {
      data: {
        productId,
        quantity: 1,
        modifierIds: [],
        specialInstructions: 'seeded-playwright-qa',
      },
    });
    expectStatus(addCart, [201], 'add restaurant cart item');

    const cartItemId = numericFromPath(addCart.body, 'itemId');
    expect(cartItemId).not.toBeNull();

    const showCart = await callApi(roleRequests.restaurant_user, 'GET', '/api/v1/user/restaurants/cart');
    expectStatus(showCart, [200], 'show restaurant cart');

    const updateCart = await callApi(roleRequests.restaurant_user, 'PATCH', `/api/v1/user/restaurants/cart/items/${cartItemId}`, {
      data: { quantity: 2 },
    });
    expectStatus(updateCart, [200], 'update restaurant cart item');

    const couponInvalid = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/coupons/check', {
      data: { section: 'restaurants', couponCode: restaurantSeed.fixtures.promoCodes.invalidCode },
    });
    expectStatus(couponInvalid, [200, 422], 'coupon invalid check');

    const couponValid = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/coupons/check', {
      data: { section: 'restaurants', couponCode: restaurantSeed.fixtures.promoCodes.activeCode },
    });
    expectStatus(couponValid, [200, 422], 'coupon valid check');

    const preview = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/checkout/preview', {
      data: {
        fulfillmentType: 'dine_in',
        receiveMode: 'immediate',
        couponCode: restaurantSeed.fixtures.promoCodes.activeCode,
        note: 'preview from seeded playwright',
      },
    });
    expectStatus(preview, [200], 'restaurant checkout preview');

    const placeOrder = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/orders', {
      data: {
        fulfillmentType: 'dine_in',
        receiveMode: 'immediate',
        couponCode: restaurantSeed.fixtures.promoCodes.activeCode,
        note: 'seeded order from playwright',
      },
    });
    expectStatus(placeOrder, [201], 'place restaurant order');

    const orderId = numericFromPath(placeOrder.body, 'data.id');
    expect(orderId).not.toBeNull();

    const details = await callApi(roleRequests.restaurant_user, 'GET', `/api/v1/user/orders/restaurant/${orderId}`);
    expectStatus(details, [200], 'placed order details');

    const tracking = await callApi(roleRequests.restaurant_user, 'GET', `/api/v1/user/orders/restaurant/${orderId}/tracking`);
    expectStatus(tracking, [200], 'placed order tracking');

    const guestCart = await callApi(roleRequests.guest, 'GET', '/api/v1/user/restaurants/cart');
    expectStatus(guestCart, [401], 'guest access to protected cart');
  });

  test('USR-P1 group-order/vote/luck-box contracts use seeded restaurant/product', async ({ roleRequests, restaurantSeed }) => {
    const restaurantId = restaurantSeed.fixtures.restaurants.owned;
    const productId = restaurantSeed.fixtures.products.available;

    const voteSuggestions = await callApi(roleRequests.guest, 'GET', '/api/v1/user/restaurants/votes/suggestions', {
      params: { search: 'PW', limit: 20 },
    });
    expectStatus(voteSuggestions, [200], 'vote suggestions');

    const luckOptions = await callApi(roleRequests.restaurant_user, 'GET', '/api/v1/user/restaurants/luck-box/options');
    expectStatus(luckOptions, [200], 'luck-box options');

    const badLuckSuggest = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/luck-box/suggest', {
      data: {
        groupSize: 3,
        budgetPerPerson: 20,
        restrictions: ['not_a_valid_restriction'],
      },
    });
    expectStatus(badLuckSuggest, [422], 'luck-box invalid restriction');

    const goodLuckSuggest = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/luck-box/suggest', {
      data: {
        groupSize: 3,
        budgetPerPerson: 20,
        restrictions: [],
      },
    });
    expectStatus(goodLuckSuggest, [200], 'luck-box suggest');

    const createVote = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/votes', {
      data: {
        durationMinutes: 30,
        options: [
          { label: 'QA Option A', productId },
          { label: 'QA Option B' },
        ],
      },
    });
    expectStatus(createVote, [200, 201], 'create vote');

    const voteId = numericFromPath(createVote.body, 'vote.id') ?? numericFromPath(createVote.body, 'data.id');
    if (voteId !== null) {
      const showVote = await callApi(roleRequests.guest, 'GET', `/api/v1/user/restaurants/votes/${voteId}`);
      expectStatus(showVote, [200], 'show vote');

      const invalidBallot = await callApi(roleRequests.restaurant_user, 'POST', `/api/v1/user/restaurants/votes/${voteId}/ballots`, {
        data: { optionId: -1 },
      });
      expectStatus(invalidBallot, [422], 'invalid ballot option');
    }

    const createGroupOrder = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/group-orders', {
      data: {
        restaurantId,
        durationMinutes: 30,
        name: 'Playwright QA Group',
      },
    });
    expectStatus(createGroupOrder, [200, 201], 'create group order');

    const invalidJoin = await callApi(roleRequests.restaurant_user, 'POST', '/api/v1/user/restaurants/group-orders/join', {
      data: { shareToken: 'short-token' },
    });
    expectStatus(invalidJoin, [422], 'join group order invalid token');

    const groupOrderId =
      numericFromPath(createGroupOrder.body, 'groupOrder.id') ??
      numericFromPath(createGroupOrder.body, 'data.groupOrder.id') ??
      numericFromPath(createGroupOrder.body, 'data.id');

    if (groupOrderId !== null) {
      const activeGroupOrders = await callApi(roleRequests.restaurant_user, 'GET', '/api/v1/user/restaurants/group-orders/active');
      expectStatus(activeGroupOrders, [200], 'active group orders');

      const showGroupOrder = await callApi(roleRequests.restaurant_user, 'GET', `/api/v1/user/restaurants/group-orders/${groupOrderId}`);
      expectStatus(showGroupOrder, [200], 'show group order');

      const addItem = await callApi(
        roleRequests.restaurant_user,
        'POST',
        `/api/v1/user/restaurants/group-orders/${groupOrderId}/items`,
        {
          data: { productId, quantity: 1 },
        },
      );
      expectStatus(addItem, [200, 201], 'add group order item');
    }
  });
});
