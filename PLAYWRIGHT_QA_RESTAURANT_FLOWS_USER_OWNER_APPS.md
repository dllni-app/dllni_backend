# Restaurant Flows Documentation + Playwright API QA Plan

## Summary
- Create one new guide file: [PLAYWRIGHT_QA_RESTAURANT_FLOWS_USER_OWNER_APPS.md](c:/laragon/www/Dllni/Dllni_backend/docs/PLAYWRIGHT_QA_RESTAURANT_FLOWS_USER_OWNER_APPS.md).
- The guide will document restaurant endpoint flows for both apps from real code and routes, then define an API-first Playwright QA scenario plan for end users.
- Coverage will be full restaurant scope, including advanced social/group flows and cross-app order lifecycle validation.

## Key Changes
- Document source-of-truth mapping from:
  - [api.php](c:/laragon/www/Dllni/Dllni_backend/Modules/User/routes/api.php)
  - [api.php](c:/laragon/www/Dllni/Dllni_backend/Modules/Resturants/routes/api.php)
  - [rs_home_remote_data_source.dart](c:/laragon/www/Dllni/dllni-user-app/lib/features/rs_home/data/source/rs_home_remote_data_source.dart)
  - [orders_remote_data_source.dart](c:/laragon/www/Dllni/Dllni_resturant_owner_app/lib/features/orders/data/source/orders_remote_data_source.dart)
- Structure the new doc into fixed sections:
  - User App flows: auth, home/discovery, restaurant/product detail, favorites, cart/coupon/checkout/place-order, orders/tracking, group-order/votes/luck-box.
  - Owner App flows: auth, dashboard, notifications, order handling, products/AI, inventory, offers/coupons, employees/permissions, operating hours, profile.
  - Cross-app integrated flow: user places order -> owner sees and acts -> user tracking/status updates.
  - Contract drift notes: `PUT` vs `PATCH` for employee update, and `category-products` endpoint gap.
- For each flow, use one consistent template:
  - Trigger point
  - Endpoint sequence (method + path)
  - Required inputs
  - Expected response/state
  - Negative/failure branches

## APIs / Interfaces
- No backend API changes are planned; this task is documentation and QA planning only.
- Add a Playwright test interface contract section in the doc with fixed env vars:
  - `API_BASE_URL`
  - `QA_USER_PHONE`, `QA_USER_PASSWORD`
  - `QA_OWNER_PHONE`, `QA_OWNER_PASSWORD`
  - `QA_TIMEOUT_MS`
- Define Playwright suite layout to be implemented next (API-first via `request` context):
  - `playwright.config.ts`
  - `tests/playwright/fixtures/auth.ts`
  - `tests/playwright/helpers/api-client.ts`
  - `tests/playwright/specs/user-restaurant-flows.spec.ts`
  - `tests/playwright/specs/owner-restaurant-flows.spec.ts`
  - `tests/playwright/specs/cross-app-order-lifecycle.spec.ts`

## Test Plan
- P0 user scenarios:
  - User login success/failure.
  - Home restaurant widgets return valid payload shapes.
  - Discover/filter/search restaurants and products.
  - Restaurant details + product details retrieval.
  - Favorites add/remove/list for restaurant and product.
  - Cart add/update/remove/show/count.
  - Coupon check valid/invalid.
  - Place restaurant order and verify returned order id/status.
  - Orders list + order detail + tracking for restaurant section.
- P0 owner scenarios:
  - Owner login success/failure.
  - Dashboard overview/performance endpoints.
  - Notifications list + mark read all.
  - Orders list then accept/reject transitions and response assertions.
  - Inventory CRUD with cleanup.
  - Product list/create and AI endpoint response validation (contract-level).
- P1 scenarios:
  - Offers/coupons CRUD (owner).
  - Employees CRUD + permissions + operating hours update.
  - Group order create/join/items/submit/place (user).
  - Vote/luck-box flow happy path and guardrail cases.
- Cross-app integration scenario:
  - Create order as user, assert visibility in owner orders, accept/reject as owner, assert user detail/tracking reflects final state.
- Acceptance criteria:
  - Every documented endpoint in scope is mapped to at least one scenario.
  - Every scenario defines success assertion and at least one negative assertion.
  - Known contract mismatches are explicitly flagged in report output.

## Assumptions and Defaults
- Output language is English.
- Playwright mode is API-first only (no UI/browser flow automation in this phase).
- Tests run against a dedicated QA environment with seeded accounts, not production.
- This task does not fix endpoint mismatches; it records and tests for them explicitly.
