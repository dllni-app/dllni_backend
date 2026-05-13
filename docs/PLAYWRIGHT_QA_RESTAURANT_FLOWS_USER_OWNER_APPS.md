# Playwright QA - Restaurant Flows (User + Owner Apps)

## Scope and Goal
This guide is the implementation contract for API-first QA of restaurant flows used by:
- `dllni-user-app`
- `dllni_resturant_owner_app`

The scope is documentation + Playwright API test scaffolding only. No backend API behavior is changed in this phase.

## Source of Truth Mapping
Primary sources used to derive this plan:
- Backend user routes: `Modules/User/routes/api.php`
- Backend restaurant/owner routes: `Modules/Resturants/routes/api.php`
- User app integration source: `dllni-user-app/lib/features/rs_home/data/source/rs_home_remote_data_source.dart`
- Owner app integration source: `dllni_resturant_owner_app/lib/features/orders/data/source/orders_remote_data_source.dart`

Additional app integration sources used for full restaurant coverage:
- `dllni-user-app/lib/features/rs_discover/data/source/rs_discover_remote_data_source.dart`
- `dllni-user-app/lib/features/orders/data/source/orders_remote_data_source.dart`
- `dllni-user-app/lib/features/profile/data/source/profile_remote_data_source.dart`
- `dllni_resturant_owner_app/lib/features/home/data/source/home_remote_data_source.dart`
- `dllni_resturant_owner_app/lib/features/products/data/source/products_remote_data_source.dart`
- `dllni_resturant_owner_app/lib/features/inventory/data/source/inventory_remote_data_source.dart`
- `dllni_resturant_owner_app/lib/features/profile/data/source/profile_remote_data_source.dart`

## Playwright API Interface Contract
All API-first Playwright tests in this track use these environment variables:
- `API_BASE_URL`
- `QA_USER_PHONE`
- `QA_USER_PASSWORD`
- `QA_OWNER_PHONE`
- `QA_OWNER_PASSWORD`
- `QA_TIMEOUT_MS`

## Playwright Suite Layout
Implemented scaffold paths:
- `playwright.config.ts`
- `tests/playwright/fixtures/auth.ts`
- `tests/playwright/helpers/api-client.ts`
- `tests/playwright/specs/user-restaurant-flows.spec.ts`
- `tests/playwright/specs/owner-restaurant-flows.spec.ts`
- `tests/playwright/specs/cross-app-order-lifecycle.spec.ts`

---

## User App Flows

### 1) Auth
Trigger point:
- User app sign-in before restaurant actions.

Endpoint sequence (method + path):
- `POST /api/v1/user/login`
- Negative contract check: same endpoint with invalid password.

Required inputs:
- `phone`, `password`.

Expected response/state:
- Success returns `token` and user payload.
- Token can access protected restaurant endpoints.

Negative/failure branches:
- Invalid credentials return validation/auth error (typically `422`).
- Missing token on protected endpoints returns `401`.

### 2) Home and Discovery
Trigger point:
- User opens restaurant home/discover views.

Endpoint sequence (method + path):
- `GET /api/v1/user/restaurants/home/featured-offers`
- `GET /api/v1/user/restaurants/home/categories`
- `GET /api/v1/user/restaurants/home/exclusive-offers`
- `GET /api/v1/user/restaurants/home/suggested-products`
- `GET /api/v1/user/restaurants/home/nearest-restaurants`
- `GET /api/v1/user/restaurants/home/latest-ordered-products` (auth)
- `GET /api/v1/user/restaurants/discover`
- `GET /api/v1/user/restaurants/products/search`
- `GET /api/v1/user/restaurants/products/with-offers`
- `POST /api/v1/user/restaurants/home/latest-ordered-products/reorder` (auth)

Required inputs:
- Optional pagination/filter/search query parameters.

Expected response/state:
- Home and discover endpoints return lists/paginated payloads.
- Reorder latest endpoint returns success action payload.

Negative/failure branches:
- Invalid query/filter combinations return validation errors (`422`) where applicable.
- Reorder latest when no latest order may return business validation response.

### 3) Restaurant and Product Detail
Trigger point:
- User opens restaurant card, menu, and product details.

Endpoint sequence (method + path):
- `GET /api/v1/user/restaurants/{restaurant}`
- `GET /api/v1/user/restaurants/{restaurant}/menu-sections`
- `GET /api/v1/user/products/{product}`
- `GET /api/v1/user/restaurants/products/by-category/{category}`

Required inputs:
- `restaurant` id, `product` id, `category` id from prior list responses.

Expected response/state:
- Restaurant and product payload include identifiers and core metadata.
- Menu sections and category products are queryable from selected restaurant context.

Negative/failure branches:
- Missing/invalid ids return `404` or `422`.
- Inactive/unavailable entities are filtered or rejected by business rules.

### 4) Favorites
Trigger point:
- User favorites/unfavorites restaurant or product.

Endpoint sequence (method + path):
- Restaurants:
  - `GET /api/v1/user/favorites/restaurants`
  - `POST /api/v1/user/favorites/restaurants/{restaurant}`
  - `DELETE /api/v1/user/favorites/restaurants/{restaurant}`
- Products:
  - `GET /api/v1/user/favorites/products`
  - `POST /api/v1/user/favorites/products/{product}`
  - `DELETE /api/v1/user/favorites/products/{product}`

Required inputs:
- Auth token and valid `restaurant` / `product` ids.

Expected response/state:
- Add/remove succeeds.
- Follow-up list reflects the mutation.

Negative/failure branches:
- Invalid ids return `404`/`422`.
- Missing auth returns `401`.

### 5) Cart, Coupon, Checkout, Place Order
Trigger point:
- User prepares and places a restaurant order.

Endpoint sequence (method + path):
- `GET /api/v1/user/restaurants/cart`
- `POST /api/v1/user/restaurants/cart/items`
- `PATCH /api/v1/user/restaurants/cart/items/{itemId}`
- `DELETE /api/v1/user/restaurants/cart/items/{itemId}`
- `GET /api/v1/user/restaurants/cart/products-count`
- `POST /api/v1/user/coupons/check`
- `POST /api/v1/user/restaurants/checkout/preview`
- `POST /api/v1/user/restaurants/orders`

Required inputs:
- `productId`, `quantity` for cart.
- `section=restaurants`, `couponCode` for coupon check.
- Order fields: `fulfillmentType`, `receiveMode`, optional `scheduledAt`, `addressId`, `couponCode`, `note`.

Expected response/state:
- Cart item lifecycle mutates cart and totals.
- Coupon check returns availability contract.
- Successful place-order returns created order id/status (`201`).

Negative/failure branches:
- Empty cart place-order fails (`422`).
- Invalid schedule or invalid coupon fails (`422`).
- Unauthorized mutation fails (`401`).

### 6) Orders and Tracking
Trigger point:
- User opens order history/detail/tracking.

Endpoint sequence (method + path):
- `GET /api/v1/user/orders?section=restaurant`
- `GET /api/v1/user/orders/restaurant/{orderId}`
- `GET /api/v1/user/orders/restaurant/{orderId}/tracking`
- `POST /api/v1/user/orders/restaurant/{orderId}/cancel`
- `POST /api/v1/user/orders/restaurant/{orderId}/reorder`
- `PATCH /api/v1/user/orders/restaurant/{orderId}/schedule`

Required inputs:
- Existing `orderId` owned by authenticated user.

Expected response/state:
- List/detail/tracking payloads are consistent for same order.
- Status transitions (cancel/schedule/reorder) return updated state or action result.

Negative/failure branches:
- Non-owned order returns `404`/`403`.
- Invalid schedule date returns `422`.
- Disallowed transition returns business error (`400`/`422`).

### 7) Group Order, Votes, and Luck Box
Trigger point:
- User enters collaborative decision and group order flows.

Endpoint sequence (method + path):
- Votes:
  - `GET /api/v1/user/restaurants/votes/suggestions`
  - `POST /api/v1/user/restaurants/votes`
  - `GET /api/v1/user/restaurants/votes/active`
  - `GET /api/v1/user/restaurants/votes/{vote}`
  - `POST /api/v1/user/restaurants/votes/{vote}/ballots`
  - `POST /api/v1/user/restaurants/votes/{vote}/end`
- Luck box:
  - `GET /api/v1/user/restaurants/luck-box/options`
  - `POST /api/v1/user/restaurants/luck-box/suggest`
- Group orders:
  - `POST /api/v1/user/restaurants/group-orders`
  - `POST /api/v1/user/restaurants/group-orders/join`
  - `GET /api/v1/user/restaurants/group-orders/active`
  - `GET /api/v1/user/restaurants/group-orders/{groupOrder}`
  - `POST /api/v1/user/restaurants/group-orders/{groupOrder}/items`
  - `PATCH /api/v1/user/restaurants/group-orders/{groupOrder}/items/{itemId}`
  - `DELETE /api/v1/user/restaurants/group-orders/{groupOrder}/items/{itemId}`
  - `POST /api/v1/user/restaurants/group-orders/{groupOrder}/submit`
  - `POST /api/v1/user/restaurants/group-orders/{groupOrder}/unsubmit`
  - `POST /api/v1/user/restaurants/group-orders/{groupOrder}/cancel`
  - `POST /api/v1/user/restaurants/group-orders/{groupOrder}/place`

Required inputs:
- Vote creation: `durationMinutes`, `options[]`, optional `foodCategoryHint`, `cuisineTypeId`.
- Vote ballot: `optionId`.
- Luck suggest: `groupSize`, `budgetPerPerson`, optional restriction/location/cuisine filters.
- Group order create: `restaurantId`, `durationMinutes`, optional `name`.
- Group join: `shareToken`.
- Group item mutations: `productId`, `quantity`, optional notes/modifiers.

Expected response/state:
- Group and vote endpoints return active session payloads and final state updates.
- Luck box returns options and recommendation bundles.

Negative/failure branches:
- Invalid vote/group ids return `404`.
- Invalid ballot option or invalid share token returns `422`.
- Invalid luck-box restriction or bounds fails (`422`).

---

## Owner App Flows

### 1) Auth
Trigger point:
- Restaurant owner signs in.

Endpoint sequence (method + path):
- `POST /api/v1/user/login`

Required inputs:
- `phone`, `password` for owner account (`module_type=restaurant_seller`).

Expected response/state:
- Token grants access to owner and restaurant admin endpoints.

Negative/failure branches:
- Invalid credentials rejected (`422` typically).
- Wrong role token fails on owner-only endpoints (`403`/`404`/policy failure).

### 2) Dashboard
Trigger point:
- Owner opens home and performance cards.

Endpoint sequence (method + path):
- `GET /api/v1/restaurant-owner/dashboard/overview`
- `GET /api/v1/restaurant-owner/dashboard/performance`
- `GET /api/v1/restaurant-owner/dashboard/top-selling-products`

Required inputs:
- Optional range filters for performance endpoints.

Expected response/state:
- KPI summary, trends, and top-selling items are returned for owner scope.

Negative/failure branches:
- Invalid range query returns `422`.
- Unauthorized token rejected.

### 3) Notifications
Trigger point:
- Owner checks and clears notifications.

Endpoint sequence (method + path):
- `GET /api/v1/restaurant-owner/notifications`
- `PATCH /api/v1/restaurant-owner/notifications/read-all`
- `PATCH /api/v1/restaurant-owner/notifications/{notification}/read`

Required inputs:
- Optional tab/pagination filters.

Expected response/state:
- List endpoint returns notification feed.
- Read-all / mark-read updates read state.

Negative/failure branches:
- Invalid notification id fails (`404`).
- Missing auth fails (`401`).

### 4) Order Handling
Trigger point:
- Owner reviews queue and accepts/rejects orders.

Endpoint sequence (method + path):
- `GET /api/v1/orders`
- `POST /api/v1/orders/{order}/accept`
- `POST /api/v1/orders/{order}/reject`
- Detail cross-check endpoint available in owner API: `GET /api/v1/restaurant-owner/orders/{order}`

Required inputs:
- Accept body: `preparationTimeMinutes`, optional `assignedEmployeeId`, optional `kitchenNotes`.
- Reject body: `reason`, optional `customerMessage`.

Expected response/state:
- Accept updates status to accepted state.
- Reject updates status to cancelled state with reason.

Negative/failure branches:
- Missing required fields returns `422`.
- Invalid order id returns `404`.

### 5) Products and AI
Trigger point:
- Owner manages catalog and AI-assisted product drafting.

Endpoint sequence (method + path):
- `GET /api/v1/categories`
- `GET /api/v1/products`
- `POST /api/v1/products`
- `POST /api/v1/products/ai/generate-image`
- `POST /api/v1/products/ai/extract-from-image`
- `POST /api/v1/products/ai/extract-from-menu`

Required inputs:
- Product create fields per backend validation.
- AI endpoints require request payload/media/text per contract.

Expected response/state:
- Product list/create returns owner-scoped product resources.
- AI endpoints return structured extraction/generation payloads.

Negative/failure branches:
- Invalid create payload returns `422`.
- AI invalid payload/media returns `422`.

### 6) Inventory
Trigger point:
- Owner opens stock summary and edits inventory items.

Endpoint sequence (method + path):
- `GET /api/v1/restaurant/inventory-summary`
- `GET /api/v1/inventory-items`
- `POST /api/v1/inventory-items`
- `PUT /api/v1/inventory-items/{id}`
- `DELETE /api/v1/inventory-items/{id}`

Required inputs:
- Inventory item create/update body per validation.

Expected response/state:
- Summary and item list load.
- CRUD mutation is reflected in follow-up list.

Negative/failure branches:
- Invalid inventory body returns `422`.
- Invalid item id returns `404`.

### 7) Offers and Coupons
Trigger point:
- Owner manages promotions and summary widgets.

Endpoint sequence (method + path):
- `GET /api/v1/restaurant-owner/offers`
- `GET /api/v1/restaurant-owner/offers/summary`
- `POST|PUT|DELETE /api/v1/restaurant-owner/offers[/{id}]`
- `GET /api/v1/restaurant-owner/coupons`
- `GET /api/v1/restaurant-owner/coupons/summary`
- `POST|PUT|DELETE /api/v1/restaurant-owner/promo-codes[/{id}]`

Required inputs:
- Offer/coupon payload per backend validation.

Expected response/state:
- List and summary endpoints return analytics and resource lists.
- CRUD operations persist and reflect in list calls.

Negative/failure branches:
- Invalid payload returns `422`.
- Invalid id returns `404`.

### 8) Employees and Permissions
Trigger point:
- Owner manages staff and permission assignments.

Endpoint sequence (method + path):
- `GET /api/v1/restaurant-owner/permissions`
- `GET /api/v1/restaurant-owner/employees`
- `POST /api/v1/restaurant-owner/employees`
- `PATCH /api/v1/restaurant-owner/employees/{user}`
- `DELETE /api/v1/restaurant-owner/employees/{user}`

Required inputs:
- Employee create/update fields (`name`, `password`, optional contact fields, optional `permissionIds`, optional `isActive`).

Expected response/state:
- Permissions catalog returns assignable permission ids.
- Employee CRUD is owner-scoped and reflected by list endpoint.

Negative/failure branches:
- Invalid permission ids or payload fields return `422`.
- Unauthorized staff ownership returns `403`/`404`.

### 9) Operating Hours
Trigger point:
- Owner edits weekly operating hours.

Endpoint sequence (method + path):
- `GET /api/v1/restaurant-owner/restaurant/operating-hours`
- `PUT /api/v1/restaurant-owner/restaurant/operating-hours`

Required inputs:
- Hours payload in backend-supported shape.

Expected response/state:
- Updated schedule is returned and persists.

Negative/failure branches:
- Invalid day/time shape returns `422`.

### 10) Profile
Trigger point:
- Owner views/updates restaurant profile.

Endpoint sequence (method + path):
- `GET /api/v1/restaurant-owner/restaurant`
- `PUT /api/v1/restaurant-owner/restaurant`

Required inputs:
- Profile fields and optional media uploads.

Expected response/state:
- Profile returns scoped restaurant data.
- Update persists and returns updated resource.

Negative/failure branches:
- Invalid fields/media return `422`.

---

## Cross-App Integrated Flow

### User places order -> owner acts -> user tracks final status
Trigger point:
- End-to-end restaurant lifecycle check across user and owner tokens.

Endpoint sequence (method + path):
1. User: `POST /api/v1/user/restaurants/cart/items`
2. User: `POST /api/v1/user/restaurants/orders`
3. Owner: `GET /api/v1/orders` then find created order
4. Owner: `POST /api/v1/orders/{order}/accept` or `POST /api/v1/orders/{order}/reject`
5. User: `GET /api/v1/user/orders/restaurant/{orderId}`
6. User: `GET /api/v1/user/orders/restaurant/{orderId}/tracking`

Required inputs:
- Valid product id for cart.
- Valid order payload for placement.
- Valid accept/reject payload for owner transition.

Expected response/state:
- Same order id appears in both actor flows.
- Owner transition is reflected in user order detail/tracking status.

Negative/failure branches:
- Owner missing required accept/reject fields returns `422`.
- User cannot track non-owned order.

---

## Contract Drift Notes

1. `PUT` vs `PATCH` mismatch for employee update:
- Owner app currently uses `PUT /api/v1/restaurant-owner/employees/{id}` in `dllni_resturant_owner_app/lib/features/profile/data/source/profile_remote_data_source.dart`.
- Backend route in `Modules/Resturants/routes/api.php` exposes `PATCH /api/v1/restaurant-owner/employees/{user}`.
- QA should flag method mismatch as drift if `PUT` fails with `405`.

2. `category-products` endpoint gap:
- User app calls `GET /api/v1/user/restaurants/home/category-products` in `dllni-user-app/lib/features/rs_home/data/source/rs_home_remote_data_source.dart`.
- `Modules/User/routes/api.php` does not currently define this path.
- QA should flag as missing route drift if response is `404`.

---

## Playwright Scenario Coverage Targets

P0 user scenarios:
- Login success/failure.
- Home/discovery payload contract checks.
- Restaurant/product detail checks.
- Favorites add/remove/list.
- Cart add/update/remove/show/count.
- Coupon valid/invalid check.
- Place order and assert order id/status when preconditions exist.
- Orders list/detail/tracking.

P0 owner scenarios:
- Owner login success/failure.
- Dashboard/performance.
- Notifications list/read-all.
- Orders list + accept/reject validation.
- Inventory CRUD with cleanup.
- Product list/create and AI endpoint contract validation.

P1 scenarios:
- Offers/coupons CRUD.
- Employees CRUD + permissions + operating-hours update.
- Group-order create/join/items/submit/place.
- Vote and luck-box happy-path + guardrails.

Cross-app integration:
- User-created order visibility in owner queue.
- Owner accept/reject reflected in user detail/tracking.

Acceptance criteria:
- Every endpoint in this scope maps to at least one scenario.
- Every scenario includes a success assertion and at least one negative assertion.
- Drift issues are surfaced explicitly in test output/reporting.
