# Flutter Supermarket Endpoint Flows and Playwright QA

## Scope

This document defines API-first QA coverage for supermarket flows integrated by:

- `dllni-user-app`
- `dllni_supermarket_owner_app`

The catalog is limited to endpoints currently called by those Flutter apps and the matching backend routes. It is intentionally API-first: Playwright should use `request` contexts with role-based tokens, not browser UI automation, for these scenarios.

No public API/interface change is required by this document.

## Source of truth

- backend routes for user flows: `Modules/User/routes/api.php`
- backend routes for owner flows: `Modules/Supermarket/routes/api.php`
- app integrations: each app's `*remote_data_source.dart` supermarket-related files

## API-first Playwright defaults

- Use separate request contexts for `guest`, `user`, `store_owner`, and `wrong_role`.
- Seed or create deterministic fixtures before each scenario: user, owner, owned store, other store, active products, unavailable products, cart rows, smart list rows, pending orders, ready-for-pickup orders, coupons, offers, and employees.
- Assert both transport contract and state transition:
  - status code
  - JSON shape
  - critical response fields
  - follow-up GET or database/API assertion when the endpoint mutates cart, order, stock, favorites, employees, or store profile.
- Prefer contract assertions over snapshot assertions. Check required keys and important values; do not pin volatile timestamps beyond existence/format unless the scenario owns the clock.

## User App Flow Map

### User endpoint matrix

| Flow | Method | Endpoint | Auth | Integrated by |
| --- | --- | --- | --- | --- |
| Featured offers | GET | `/api/v1/user/supermarket/home/featured-offers` | guest ok | `sm_home_remote_data_source.dart` |
| Nearby stores | GET | `/api/v1/user/supermarket/home/nearby-stores` | guest ok | `sm_home_remote_data_source.dart`, `rs_home_remote_data_source.dart` |
| Browse stores | GET | `/api/v1/user/supermarket/stores` | guest ok, optional auth flags | `sm_discover_remote_data_source.dart`, `rs_home_remote_data_source.dart` |
| Product search | GET | `/api/v1/user/supermarket/products/search` | guest ok, optional auth flags | `sm_discover_remote_data_source.dart` |
| Store details | GET | `/api/v1/user/supermarket/stores/{store}` | guest ok, optional auth flags | `sm_stores_remote_data_source.dart` |
| Product details | GET | `/api/v1/user/supermarket/products/{product}` | guest ok, optional auth flags | `sm_stores_remote_data_source.dart` |
| Similar products | GET | `/api/v1/user/supermarket/products/{product}/similar` | guest ok, optional auth flags | backend integrated route |
| Compare products | GET | `/api/v1/user/supermarket/products/{product}/compare` | guest ok, optional auth flags | `sm_stores_remote_data_source.dart` |
| Favorite stores list | GET | `/api/v1/user/favorites/supermarket/stores` | user | `sm_favorite_remote_data_source.dart` |
| Favorite store add/remove | POST/DELETE | `/api/v1/user/favorites/supermarket/stores/{store}` | user | `sm_home_remote_data_source.dart`, `sm_discover_remote_data_source.dart` |
| Favorite products list | GET | `/api/v1/user/favorites/supermarket/products` | user | `sm_favorite_remote_data_source.dart` |
| Favorite product add/remove | POST/DELETE | `/api/v1/user/favorites/supermarket/products/{product}` | user | `sm_discover_remote_data_source.dart` |
| Cart show | GET | `/api/v1/user/supermarket/cart` | user | `orders_remote_data_source.dart` |
| Cart add item | POST | `/api/v1/user/supermarket/cart/items` | user | `sm_stores_remote_data_source.dart` |
| Cart update item | PATCH | `/api/v1/user/supermarket/cart/items/{itemId}` | user | `orders_remote_data_source.dart` |
| Cart delete item | DELETE | `/api/v1/user/supermarket/cart/items/{itemId}` | user | `orders_remote_data_source.dart` |
| Checkout preview | POST | `/api/v1/user/supermarket/checkout/preview` | user | backend route; not currently called by app order placement |
| Place order | POST | `/api/v1/user/supermarket/orders` | user | `orders_remote_data_source.dart` |
| Unified order show | GET | `/api/v1/user/orders/supermarket/{orderId}` or `/api/v1/user/orders/{section}/{orderId}` | user | `orders_remote_data_source.dart` |
| Order tracking | GET | `/api/v1/user/orders/supermarket/{orderId}/tracking` | user | `orders_remote_data_source.dart` |
| Supermarket order status | GET | `/api/v1/user/supermarket/orders/{order}/status` | user | backend route |
| Shopping lists CRUD | GET/POST/PATCH/DELETE | `/api/v1/user/supermarket/shopping-lists[/{shoppingList}]` | user | `shopping_lists_remote_data_source.dart` |
| Shopping list items | POST/PATCH/DELETE | `/api/v1/user/supermarket/shopping-lists/{shoppingList}/items[/{item}]` | user | `shopping_lists_remote_data_source.dart` |
| Shopping list add to cart | POST | `/api/v1/user/supermarket/shopping-lists/{shoppingList}/add-to-cart` | user | `shopping_lists_remote_data_source.dart` |
| Master product picker | GET | `/api/v1/user/supermarket/master-products/search` | user | backend route for shopping-list picker |
| Normalize product text | POST | `/api/v1/user/products/normalize-text` | user | `sm_discover_remote_data_source.dart` |

### Ordered user journeys

1. Discover/home
   - Load featured offers.
   - Load nearby stores with optional coordinates.
   - Browse stores with pagination, search, `filter[openNow]`, sort, and optional auth favorite flags.
   - Search supermarket products by typed query.
2. Store/product details and compare
   - Open store details.
   - Open product details from a store/product card.
   - Fetch similar/compare alternatives for the selected product.
3. Favorites
   - Add a store favorite, verify it appears in store favorite listing and browse response.
   - Remove store favorite, verify it disappears.
   - Repeat for product favorites.
4. Cart lifecycle
   - Show empty cart.
   - Add an available product.
   - Update quantity.
   - Delete item.
   - Confirm cart totals and merchant groups after each mutation.
5. Order placement/tracking
   - Add cart items.
   - Optionally call checkout preview.
   - Place order directly.
   - Verify cart/order state, order listing/show, tracking, and status endpoint.
6. Shopping lists
   - List/create/update/delete list.
   - Add/update/delete list item.
   - Add included list items to cart.
   - Validate no-common-store resolution failure.
7. Normalize product text
   - Submit noisy/Arabic product text.
   - Assert normalized candidate response can feed search or shopping-list picker.

## Supermarket Owner App Flow Map

### Owner endpoint matrix

| Flow | Method | Endpoint | Auth | Integrated by |
| --- | --- | --- | --- | --- |
| Dashboard | GET | `/api/v1/store-owner/dashboard` | store_owner | `home_remote_data_source.dart` |
| Notifications | GET/PATCH | `/api/v1/user/notifications`, `/api/v1/user/notifications/read-all` | store_owner | `home_remote_data_source.dart` |
| Order queues | GET | `/api/v1/sm-orders` | store_owner | `home_remote_data_source.dart`, `orders_remote_data_source.dart` |
| Order details | GET | `/api/v1/sm-orders/{order}` | store_owner | `orders_remote_data_source.dart` |
| Order accept | POST | `/api/v1/store-owner/orders/{order}/accept` | store_owner | `home_remote_data_source.dart`, `orders_remote_data_source.dart` |
| Order reject | POST | `/api/v1/store-owner/orders/{order}/reject` | store_owner | `home_remote_data_source.dart`, `orders_remote_data_source.dart` |
| Courier handover | POST | `/api/v1/store-owner/orders/{order}/courier-handover` | store_owner | `orders_remote_data_source.dart` |
| Hourly/daily count | GET | `/api/v1/sm-orders/hourly-count` | store_owner | `home_remote_data_source.dart`, `inventory_remote_data_source.dart` |
| Product list | GET | `/api/v1/sm-products`, `/api/v1/store-owner/products` | store_owner | `inventory_remote_data_source.dart`, `products_remote_data_source.dart`, `profile_remote_data_source.dart` |
| Product CRUD | POST/GET/PUT/DELETE | `/api/v1/store-owner/products[/{product}]` | store_owner | `products_remote_data_source.dart` |
| Available count | GET | `/api/v1/sm-products/available-count` | store_owner | `products_remote_data_source.dart`, `profile_remote_data_source.dart` |
| Categories | GET | `/api/v1/sm-categories` | store_owner | `products_remote_data_source.dart` |
| AI extract image | POST | `/api/v1/sm-products/ai/extract-from-image` | store_owner | `products_remote_data_source.dart` |
| AI generate image | POST | `/api/v1/sm-products/ai/generate-image` | store_owner | `products_remote_data_source.dart` |
| Product import | POST | `/api/v1/sm-products/import` | store_owner | `products_remote_data_source.dart` |
| Master product search | GET | `/api/v1/store-owner/master-products/search` | store_owner | `products_remote_data_source.dart` |
| Products from master | POST | `/api/v1/store-owner/products/from-master` | store_owner | `products_remote_data_source.dart` |
| Inventory summary | GET | `/api/v1/store-owner/inventory/summary` | store_owner | `inventory_remote_data_source.dart` |
| Low stock | GET | `/api/v1/store-owner/products/low-stock` | store_owner | `products_remote_data_source.dart` |
| Stock update | PUT | `/api/v1/store-owner/products/{product}/stock` | store_owner | `inventory_remote_data_source.dart` |
| Store profile | GET/PUT | `/api/v1/store-owner/store` | store_owner | `profile_remote_data_source.dart` |
| Operating hours | GET/PUT | `/api/v1/store-owner/store/operating-hours` | store_owner | `profile_remote_data_source.dart` |
| Offers | GET/POST | `/api/v1/sm-offers` | store_owner | `profile_remote_data_source.dart` |
| Coupons | GET/POST | `/api/v1/sm-coupons` | store_owner | `profile_remote_data_source.dart` |
| Coupon weekly analytics | GET | `/api/v1/sm-coupons/weekly-analysis` | store_owner | `profile_remote_data_source.dart` |
| Offer weekly summary | GET | `/api/v1/store-owner/offers/weekly-summary` | store_owner | `profile_remote_data_source.dart` |
| Top-selling products | GET | `/api/v1/store-owner/dashboard/top-selling-products` | store_owner | `home_remote_data_source.dart` |
| Permissions | GET | `/api/v1/store-owner/permissions` | store_owner | `profile_remote_data_source.dart` |
| Employees | GET/POST/PATCH | `/api/v1/store-owner/employees[/{staff}]`, `/api/v1/store-owner/employees/{staff}/status` | store_owner | `profile_remote_data_source.dart` |
| Activity logs | GET | `/api/v1/store-owner/activity-logs` | store_owner | `profile_remote_data_source.dart` |

### Ordered owner journeys

1. Dashboard, notifications, order queues
   - Load dashboard KPIs.
   - Load new/preparing orders from `/sm-orders`.
   - Load hourly/daily count.
   - Fetch notifications and mark all read.
2. Order operations
   - Accept pending order.
   - Reject pending order with valid reason and rejection type.
   - Handover ready-for-pickup order to courier.
   - Validate non-pending and wrong-store protections.
3. Products, catalog, AI, import
   - List products and categories.
   - Create/update/delete owner product.
   - Search master products.
   - Create products from master catalog.
   - Exercise AI draft endpoints with mocked upstream where possible.
   - Import product data.
4. Inventory
   - Load summary and low-stock products.
   - Update stock with SET/INCREMENT/DECREMENT.
   - Load hourly/daily count scoped to owner store.
5. Store profile and operating hours
   - Load store profile.
   - Update profile.
   - Load/update operating hours.
6. Offers, coupons, weekly analytics
   - List/create offers and coupons.
   - Load coupon weekly analysis and offer weekly summary.
   - Load top-selling products with range validation.
7. Employees, permissions, activity logs
   - Load permissions catalog.
   - Create/update employee.
   - Toggle employee status.
   - Load activity logs.

## Known Integration Risks

- Owner orders module used a literal reject path `/api/v1/store-owner/orders/{order}/reject` in one data source path. This should be covered by a regression scenario that asserts the real `orderId` is interpolated before the request is sent.
- User app places supermarket orders directly and does not currently call `/api/v1/user/supermarket/checkout/preview`. QA should keep both direct order placement and preview contract scenarios, because backend preview can catch invalid carts before order creation.

## User App Playwright API Scenario Catalog

| ID | Actor | Preconditions / test data | Endpoint + method | Request payload shape | Expected status and response assertions | Follow-up state assertion |
| --- | --- | --- | --- | --- | --- | --- |
| USR-SM-01 | guest | Active store with active offer exists | `GET /api/v1/user/supermarket/home/featured-offers` | query: optional `page`, `perPage` | 200; `data` array; offer/store identifiers present | None |
| USR-SM-02 | guest | Active stores with coordinates exist | `GET /api/v1/user/supermarket/home/nearby-stores` | query: optional `latitude`, `longitude`, `page`, `perPage` | 200; `data` array; store card fields present | None |
| USR-SM-03 | guest | Active, inactive, suspended stores exist | `GET /api/v1/user/supermarket/stores` | query: `page=1&perPage=20` | 200; paginated `data`, `links`, `meta`; inactive/suspended stores excluded | None |
| USR-SM-04 | guest | One matching store and one nonmatching store exist | `GET /api/v1/user/supermarket/stores` | query: `search=<prefix>` | 200; matching store returned; nonmatching store absent | None |
| USR-SM-05 | guest | Store hours include one open and one closed store | `GET /api/v1/user/supermarket/stores` | query: `filter[openNow]=1` | 200; open store present; closed store absent | None |
| USR-SM-06 | guest | Store has active products and one unavailable product | `GET /api/v1/user/supermarket/products/search` | query: `search=<text>&page=1&perPage=20` | 200; active/available product returned; unavailable product absent | None |
| USR-SM-07 | user | Product exists and user token is valid | `GET /api/v1/user/supermarket/products/{product}` | none | 200; `data.id`, price, store, selectable options/favorite flag present | None |
| USR-SM-08 | user | Product has comparable products by title/master product | `GET /api/v1/user/supermarket/products/{product}/compare` | query: optional filters | 200; alternatives array returned; original product context present | None |
| USR-SM-09 | user | Store exists and is not favorited | `POST /api/v1/user/favorites/supermarket/stores/{store}` | none or `{}` | 200/201; success or favorite resource returned | `GET /favorites/supermarket/stores` includes store |
| USR-SM-10 | user | Store is already favorited | `DELETE /api/v1/user/favorites/supermarket/stores/{store}` | none | 200/204 | Store absent from favorite listing |
| USR-SM-11 | user | Product exists and is not favorited | `POST /api/v1/user/favorites/supermarket/products/{product}` | none or `{}` | 200/201 | `GET /favorites/supermarket/products` includes product |
| USR-SM-12 | user | Product is already favorited | `DELETE /api/v1/user/favorites/supermarket/products/{product}` | none | 200/204 | Product absent from favorite listing |
| USR-SM-13 | user | Empty cart | `GET /api/v1/user/supermarket/cart` | none | 200; `data.items` empty or cart empty shape | Cart remains empty |
| USR-SM-14 | user | Available product with stock exists | `POST /api/v1/user/supermarket/cart/items` | `{ "productId": number, "quantity": number }` | 200/201; line item returned with totals | Cart show includes item and quantity |
| USR-SM-15 | user | Cart item exists | `PATCH /api/v1/user/supermarket/cart/items/{itemId}` | `{ "quantity": number }` | 200; quantity/totals updated | Cart show reflects new quantity |
| USR-SM-16 | user | Cart item exists | `DELETE /api/v1/user/supermarket/cart/items/{itemId}` | none | 200/204 | Cart show no longer includes item |
| USR-SM-17 | user | Cart is empty | `POST /api/v1/user/supermarket/orders` | order body with pickup/schedule fields as app sends | 422; validation/business message indicates empty cart | No order created |
| USR-SM-18 | user | Cart has item; invalid date/time provided | `POST /api/v1/user/supermarket/orders` | `{ "scheduledAt": "invalid-or-past" }` plus required fields | 422; `errors.scheduledAt` or equivalent validation present | Cart remains unchanged; no order created |
| USR-SM-19 | user | Cart has available item; address/pickup data valid | `POST /api/v1/user/supermarket/orders` | valid order placement body | 200/201; order id/status/amounts returned | Order list/show/tracking can fetch created order |
| USR-SM-20 | user | Existing supermarket order belongs to user | `GET /api/v1/user/orders/supermarket/{orderId}/tracking` | none | 200; tracking/status timeline fields present | None |
| USR-SM-21 | user | No shopping lists initially | `POST /api/v1/user/supermarket/shopping-lists` | `{ "name": string, "description"?: string, "isActive"?: boolean, "schedule"?: object }` | 201; `data.id`, `name`, `items` present | List endpoint includes created list |
| USR-SM-22 | user | Shopping list exists and master product exists | `POST /api/v1/user/supermarket/shopping-lists/{shoppingList}/items` | `{ "masterProductId": number, "quantity": number, "unit"?: string, "isIncluded"?: boolean }` | 201; detail includes new line | Show list includes item |
| USR-SM-23 | user | Shopping list has included items whose master products resolve to different stores only | `POST /api/v1/user/supermarket/shopping-lists/{shoppingList}/add-to-cart` | `{}` | 422; `items` validation/business error | Cart remains unchanged |
| USR-SM-24 | user and wrong token | User token exists; wrong-role/unauthenticated contexts available | protected supermarket endpoints, e.g. `GET /cart`, `POST /orders`, `POST /products/normalize-text` | endpoint-specific minimal body | 401 for missing token; 403/404 for wrong ownership where applicable; normalize returns 200 for valid user body | No protected state mutation for unauthorized requests |

## Owner App Playwright API Scenario Catalog

| ID | Actor | Preconditions / test data | Endpoint + method | Request payload shape | Expected status and response assertions | Follow-up state assertion |
| --- | --- | --- | --- | --- | --- | --- |
| OWN-SM-01 | store_owner | Owner has one store and today orders | `GET /api/v1/store-owner/dashboard` | none | 200; `data.totalOrders`, `completedOrders`, `newOrders`, `pendingOrders`, `totalSales` present | Values match seeded store only |
| OWN-SM-02 | store_owner | Owner has notifications | `GET /api/v1/user/notifications` | query: optional pagination | 200; notification list returned | None |
| OWN-SM-03 | store_owner | Notifications unread | `PATCH /api/v1/user/notifications/read-all` | `{}` | 200/204 | Subsequent notifications show read state |
| OWN-SM-04 | store_owner | Pending and preparing orders exist for owner store and other store | `GET /api/v1/sm-orders` | query: `filter[status]=pending` or app params | 200; only owner-store orders returned | Other store orders absent |
| OWN-SM-05 | store_owner | Order belongs to owner store | `GET /api/v1/sm-orders/{order}` | none | 200; order detail and items returned | None |
| OWN-SM-06 | store_owner | Pending order with sufficient stock exists | `POST /api/v1/store-owner/orders/{order}/accept` | `{}` | 200; status becomes `accepted` | Product stock decremented; order status persisted |
| OWN-SM-07 | store_owner | Non-pending order exists | `POST /api/v1/store-owner/orders/{order}/accept` | `{}` | 400/422; business error message | Order status and stock unchanged |
| OWN-SM-08 | store_owner | Pending order exists | `POST /api/v1/store-owner/orders/{order}/reject` | `{ "reason": "Out of stock", "rejectionType": "out_of_stock" }` | 200; status `cancelled`; cancellation reason present | Store trust/notification side effects recorded as applicable |
| OWN-SM-09 | store_owner | Pending order exists | `POST /api/v1/store-owner/orders/{order}/reject` | `{ "rejectionType": "out_of_stock" }` or short reason | 422; `reason` validation present | Order remains pending |
| OWN-SM-10 | store_owner | Ready-for-pickup order exists | `POST /api/v1/store-owner/orders/{order}/courier-handover` | `{}` | 200; status `picked_up`, `pickedUpAt` present | Status log created once |
| OWN-SM-11 | store_owner | Order is not ready for pickup | `POST /api/v1/store-owner/orders/{order}/courier-handover` | `{}` | 400/422; business error | Status unchanged |
| OWN-SM-12 | store_owner | Weekly orders exist for owner store and other store | `GET /api/v1/sm-orders/hourly-count` | none | 200; day/status buckets returned | Counts exclude other store orders |
| OWN-SM-13 | store_owner | Products/categories exist | `GET /api/v1/sm-products` and/or `/api/v1/store-owner/products` | query: pagination/filter | 200; product list shape returned | Owner-scoped list excludes other store products |
| OWN-SM-14 | store_owner | Valid category/store owner context exists | `POST /api/v1/store-owner/products` | product create body with name, price, stock, category fields | 200/201; product resource returned | Product exists under owner store |
| OWN-SM-15 | store_owner | Product belongs to owner store | `PUT /api/v1/store-owner/products/{product}` | partial/full update body | 200; updated fields returned | Product show reflects changes |
| OWN-SM-16 | store_owner | Active master products exist | `GET /api/v1/store-owner/master-products/search` | query: `index=<prefix>&page=1&perPage=10` | 200; paginated `data`, `links`, `meta`; only active prefix matches | None |
| OWN-SM-17 | store_owner | Active master product exists; owner store may have no matching category | `POST /api/v1/store-owner/products/from-master` | `{ "masterProductIds": [number] }` | 201; created product has `masterProductId` | Product linked to master product under owner store |
| OWN-SM-18 | store_owner | Products with low and normal stock exist | `GET /api/v1/store-owner/products/low-stock` | none | 200; low-stock collection returned | Normal-stock product absent |
| OWN-SM-19 | store_owner | Product belongs to owner store | `PUT /api/v1/store-owner/products/{product}/stock` | `{ "quantity": 10, "operation": "SET" }`, plus increment/decrement variants | 200; new stock returned | Product stock and inventory log updated |
| OWN-SM-20 | store_owner | Product belongs to owner store | `PUT /api/v1/store-owner/products/{product}/stock` | invalid `{ "quantity": -1 }` or bad `operation` | 422; validation errors returned | Stock unchanged |
| OWN-SM-21 | store_owner | Owner store exists | `GET /api/v1/store-owner/store`, then `PUT /api/v1/store-owner/store` | update body with profile fields | 200; profile fields returned | Store show reflects update |
| OWN-SM-22 | store_owner | Owner store hours exist | `GET /api/v1/store-owner/store/operating-hours`, then `PUT /api/v1/store-owner/store/operating-hours` | `{ "hours": [...] }` or app-supported shape | 200; seven-day schedule or updated schedule returned | Store hours persisted with `open_time`/`close_time` |
| OWN-SM-23 | store_owner | Permission catalog exists | `GET /api/v1/store-owner/permissions`, then employee create/update/status endpoints | create/update employee bodies with `permissionIds` and `isActive` | 200/201; employee resource includes permissions | Employee list reflects create/update/status |
| OWN-SM-24 | wrong_role and unauthenticated | User token without `supermarket_seller`, missing token, and other owner order/product fixtures | Owner protected endpoints, especially orders reject/accept/stock/store | endpoint-specific minimal body | 401 for missing token; 403 for wrong role or wrong owner resource | No order, stock, product, employee, or store mutation |

## Playwright implementation notes

- Put API specs under a future QA folder such as `tests/playwright/supermarket/*.spec.ts` when test files are introduced.
- Keep fixture builders close to the tests, but authenticate through the real API where practical.
- For expensive upstream AI endpoints, use backend fakes or a test-only mock provider. The scenario should assert request validation and response mapping, not real Gemini behavior.
- For scenarios requiring database seeding, prefer Laravel seeders/factories exposed through test setup over hard-coded production ids.
- For the owner reject regression, assert the path contains the numeric order id in the request URL. The literal `{order}` path must never be emitted by the app.
