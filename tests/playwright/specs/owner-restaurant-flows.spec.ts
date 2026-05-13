import { test, expect } from '../fixtures/auth';
import {
  callApi,
  dataObject,
  expectStatus,
  numericFromPath,
  pickStatusLabel,
} from '../helpers/api-client';

function uniqueSuffix(): string {
  return `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
}

test.describe('Owner Restaurant Flows (API-first, seeded)', () => {
  test('OWN-P0 auth success and failure', async ({ roleRequests, restaurantSeed }) => {
    const success = await callApi(roleRequests.guest, 'POST', '/api/v1/user/login', {
      data: {
        phone: restaurantSeed.auth.owner.phone,
        password: restaurantSeed.auth.owner.password,
      },
    });
    expectStatus(success, [200], 'owner login success');

    const failure = await callApi(roleRequests.guest, 'POST', '/api/v1/user/login', {
      data: {
        phone: restaurantSeed.auth.owner.phone,
        password: `${restaurantSeed.auth.owner.password}-wrong`,
      },
    });
    expectStatus(failure, [401, 422], 'owner login failure');
  });

  test('OWN-P0 dashboard and notifications contracts', async ({ roleRequests }) => {
    const dashboardOverview = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/dashboard/overview');
    expectStatus(dashboardOverview, [200], 'owner dashboard overview');

    const dashboardPerformance = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/dashboard/performance', {
      params: { range: 'today' },
    });
    expectStatus(dashboardPerformance, [200], 'owner dashboard performance');

    const topSelling = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/dashboard/top-selling-products', {
      params: { range: 'today', limit: 5 },
    });
    expectStatus(topSelling, [200], 'owner top selling products');

    const notifications = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/notifications', {
      params: { tab: 'all', perPage: 20 },
    });
    expectStatus(notifications, [200], 'owner notifications list');

    const readAll = await callApi(roleRequests.owner, 'PATCH', '/api/v1/restaurant-owner/notifications/read-all', {
      data: { tab: 'all' },
    });
    expectStatus(readAll, [200, 204], 'owner notifications read-all');

    const wrongRoleAttempt = await callApi(roleRequests.restaurant_wrong_role, 'GET', '/api/v1/restaurant-owner/dashboard/performance');
    expectStatus(wrongRoleAttempt, [401, 403], 'wrong role blocked from owner dashboard');
  });

  test('OWN-P0 order handling uses seeded pending orders', async ({ roleRequests, restaurantSeed }) => {
    const pendingAcceptId = restaurantSeed.fixtures.orders.pendingAccept;
    const pendingRejectId = restaurantSeed.fixtures.orders.pendingReject;

    const ordersList = await callApi(roleRequests.owner, 'GET', '/api/v1/orders', {
      params: { perPage: 20, page: 1 },
    });
    expectStatus(ordersList, [200], 'owner orders list');

    const rejectInvalid = await callApi(roleRequests.owner, 'POST', `/api/v1/orders/${pendingRejectId}/reject`, {
      data: {},
    });
    expectStatus(rejectInvalid, [422], 'owner reject invalid payload');

    const accept = await callApi(roleRequests.owner, 'POST', `/api/v1/orders/${pendingAcceptId}/accept`, {
      data: {
        preparationTimeMinutes: 15,
        kitchenNotes: 'Accepted by Playwright QA',
      },
    });
    expectStatus(accept, [200], 'owner accept order');

    const reject = await callApi(roleRequests.owner, 'POST', `/api/v1/orders/${pendingRejectId}/reject`, {
      data: {
        reason: 'out_of_stock',
        customerMessage: 'Out of stock in QA scenario.',
      },
    });
    expectStatus(reject, [200], 'owner reject order');

    const showAccepted = await callApi(roleRequests.owner, 'GET', `/api/v1/orders/${pendingAcceptId}`);
    expectStatus(showAccepted, [200], 'owner accepted order show');

    const showRejected = await callApi(roleRequests.owner, 'GET', `/api/v1/orders/${pendingRejectId}`);
    expectStatus(showRejected, [200], 'owner rejected order show');

    expect(pickStatusLabel(showAccepted.body)).toBeTruthy();
    expect(pickStatusLabel(showRejected.body)).toBeTruthy();
  });

  test('OWN-P0 inventory/products/offers/coupons/employees/profile contracts', async ({ roleRequests, restaurantSeed }, testInfo) => {
    const categoryId = restaurantSeed.fixtures.categories.primary;

    const products = await callApi(roleRequests.owner, 'GET', '/api/v1/products', {
      params: { perPage: 20 },
    });
    expectStatus(products, [200], 'products list');

    const aiGenerateInvalid = await callApi(roleRequests.owner, 'POST', '/api/v1/products/ai/generate-image', {
      data: {},
    });
    expectStatus(aiGenerateInvalid, [422], 'AI generate invalid payload');

    const aiExtractInvalid = await callApi(roleRequests.owner, 'POST', '/api/v1/products/ai/extract-from-image', {
      data: {},
    });
    expectStatus(aiExtractInvalid, [422], 'AI extract invalid payload');

    const createProduct = await callApi(roleRequests.owner, 'POST', '/api/v1/products', {
      data: {
        categoryId,
        name: `Playwright Product ${uniqueSuffix()}`,
        price: 12.5,
        description: 'Temporary QA product',
        isAvailable: true,
      },
    });
    expectStatus(createProduct, [200, 201], 'create product');
    const createdProductId = numericFromPath(createProduct.body, 'data.id');

    if (createdProductId !== null) {
      const deleteProduct = await callApi(roleRequests.owner, 'DELETE', `/api/v1/products/${createdProductId}`);
      expectStatus(deleteProduct, [200, 204], 'delete product');
    }

    const inventorySummary = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant/inventory-summary');
    expectStatus(inventorySummary, [200], 'inventory summary');

    const inventoryList = await callApi(roleRequests.owner, 'GET', '/api/v1/inventory-items', {
      params: { perPage: 20 },
    });
    expectStatus(inventoryList, [200], 'inventory items list');

    const inventoryCreate = await callApi(roleRequests.owner, 'POST', '/api/v1/inventory-items', {
      data: {
        name: `Playwright Inventory ${uniqueSuffix()}`,
        unit: 'piece',
        quantity: 9,
        minimumLimit: 2,
        unitCost: 1.25,
      },
    });
    expectStatus(inventoryCreate, [200, 201], 'inventory create');

    const inventoryId = numericFromPath(inventoryCreate.body, 'data.id');
    expect(inventoryId).not.toBeNull();

    const inventoryUpdate = await callApi(roleRequests.owner, 'PUT', `/api/v1/inventory-items/${inventoryId}`, {
      data: {
        name: `Playwright Inventory Updated ${uniqueSuffix()}`,
        unit: 'piece',
        quantity: 11,
        minimumLimit: 3,
        unitCost: 1.5,
      },
    });
    expectStatus(inventoryUpdate, [200], 'inventory update');

    const inventoryDelete = await callApi(roleRequests.owner, 'DELETE', `/api/v1/inventory-items/${inventoryId}`);
    expectStatus(inventoryDelete, [200, 204], 'inventory delete');

    const offersList = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/offers', {
      params: { perPage: 20 },
    });
    expectStatus(offersList, [200], 'offers list');

    const offersSummary = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/offers/summary');
    expectStatus(offersSummary, [200], 'offers summary');

    const createOffer = await callApi(roleRequests.owner, 'POST', '/api/v1/restaurant-owner/offers', {
      data: {
        name: `QA Offer ${uniqueSuffix()}`,
        discountType: 'percentage',
        discountValue: 10,
        isActive: true,
      },
    });
    expectStatus(createOffer, [200, 201], 'create offer');

    const offerId = numericFromPath(createOffer.body, 'data.id');
    if (offerId !== null) {
      const deleteOffer = await callApi(roleRequests.owner, 'DELETE', `/api/v1/restaurant-owner/offers/${offerId}`);
      expectStatus(deleteOffer, [200, 204], 'delete offer');
    }

    const couponsList = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/coupons', {
      params: { perPage: 20 },
    });
    expectStatus(couponsList, [200], 'coupons list');

    const couponsSummary = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/coupons/summary');
    expectStatus(couponsSummary, [200], 'coupons summary');

    const promoCode = `PW${Date.now()}`;
    const createPromoCode = await callApi(roleRequests.owner, 'POST', '/api/v1/restaurant-owner/promo-codes', {
      data: {
        code: promoCode,
        discountType: 'fixed_amount',
        discountValue: 5,
        isActive: true,
      },
    });
    expectStatus(createPromoCode, [200, 201], 'create promo code');

    const promoId = numericFromPath(createPromoCode.body, 'data.id');
    if (promoId !== null) {
      const deletePromo = await callApi(roleRequests.owner, 'DELETE', `/api/v1/restaurant-owner/promo-codes/${promoId}`);
      expectStatus(deletePromo, [200, 204], 'delete promo code');
    }

    const permissions = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/permissions');
    expectStatus(permissions, [200], 'employee permissions list');

    const employees = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/employees', {
      params: { perPage: 20 },
    });
    expectStatus(employees, [200], 'employees list');

    const suffix = uniqueSuffix();
    const createEmployee = await callApi(roleRequests.owner, 'POST', '/api/v1/restaurant-owner/employees', {
      data: {
        name: `Playwright Employee ${suffix}`,
        email: `playwright.employee.${suffix}@qa.local`,
        phone: `+9639${Math.floor(100000000 + Math.random() * 899999999)}`,
        password: 'QaPass1234!',
        isActive: true,
      },
    });
    expectStatus(createEmployee, [201], 'create employee');

    const employeeRouteId =
      numericFromPath(createEmployee.body, 'data.userId') ??
      numericFromPath(createEmployee.body, 'data.id') ??
      numericFromPath(createEmployee.body, 'data.user.id');

    expect(employeeRouteId).not.toBeNull();

    const putUpdateAttempt = await callApi(roleRequests.owner, 'PUT', `/api/v1/restaurant-owner/employees/${employeeRouteId}`, {
      data: {
        name: `Playwright Employee Put ${suffix}`,
      },
    });
    expectStatus(putUpdateAttempt, [404, 405, 422], 'employee PUT contract drift check');
    if (putUpdateAttempt.status === 405 || putUpdateAttempt.status === 404) {
      testInfo.annotations.push({
        type: 'contract-drift',
        description: 'Owner app uses PUT for employee update, backend route is PATCH.',
      });
    }

    const patchEmployee = await callApi(roleRequests.owner, 'PATCH', `/api/v1/restaurant-owner/employees/${employeeRouteId}`, {
      data: {
        name: `Playwright Employee Patch ${suffix}`,
      },
    });
    expectStatus(patchEmployee, [200], 'employee PATCH update');

    const deleteEmployee = await callApi(roleRequests.owner, 'DELETE', `/api/v1/restaurant-owner/employees/${employeeRouteId}`);
    expectStatus(deleteEmployee, [200, 204], 'employee delete');

    const operatingHours = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/restaurant/operating-hours');
    expectStatus(operatingHours, [200], 'operating hours get');

    const operatingData = dataObject(operatingHours.body);
    const updateOperatingHours = await callApi(roleRequests.owner, 'PUT', '/api/v1/restaurant-owner/restaurant/operating-hours', {
      data: {
        isTemporarilyClosed: Boolean(operatingData?.isTemporarilyClosed ?? false),
        dailyHours: operatingData?.dailyHours ?? [],
      },
    });
    expectStatus(updateOperatingHours, [200], 'operating hours update');

    const profile = await callApi(roleRequests.owner, 'GET', '/api/v1/restaurant-owner/restaurant');
    expectStatus(profile, [200], 'restaurant profile get');

    const profileData = dataObject(profile.body);
    const userId = numericFromPath(profile.body, 'data.userId');
    const name = typeof profileData?.name === 'string' ? profileData.name : null;
    const slug = typeof profileData?.slug === 'string' ? profileData.slug : null;

    test.skip(!userId || !name || !slug, 'Cannot build minimal profile update payload from current profile response.');

    const updateProfile = await callApi(roleRequests.owner, 'PUT', '/api/v1/restaurant-owner/restaurant', {
      data: {
        userId,
        name,
        slug,
      },
    });
    expectStatus(updateProfile, [200], 'restaurant profile update');
  });
});
