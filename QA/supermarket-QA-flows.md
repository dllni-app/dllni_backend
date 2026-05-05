# Supermarket Flows + Playwright QA Plan (User App + Owner App)

## Summary
- Create one new doc at [FLUTTER_SUPERMARKET_ENDPOINT_FLOWS_AND_PLAYWRIGHT_QA.md](C:/laragon/www/Dllni/Dllni_backend/docs/FLUTTER_SUPERMARKET_ENDPOINT_FLOWS_AND_PLAYWRIGHT_QA.md).
- Scope is **integrated endpoints only** (currently called by `dllni-user-app` and `dllni_supermarket_owner_app`).
- Style is **API-first Playwright QA** (no Playwright code files yet; this deliverable is the flow + scenario spec).

## Key Changes
- Add a “Source of truth” section in the new MD with exactly these references:
  - backend routes for user flows: `Modules/User/routes/api.php`
  - backend routes for owner flows: `Modules/Supermarket/routes/api.php`
  - app integrations: each app’s `*remote_data_source.dart` supermarket-related files
- Add a **User App Flow Map** section with endpoint matrix and ordered journeys:
  - discover/home (featured offers, nearby stores, browse/search)
  - store/product details + compare
  - favorites (store/product add/remove + listing)
  - cart lifecycle (show/add/update/delete)
  - order placement/tracking (place order, list orders, show/tracking)
  - shopping lists (list/create/update/delete list + items + add-to-cart)
  - normalize product text
- Add a **Supermarket Owner App Flow Map** section with endpoint matrix and ordered journeys:
  - dashboard, notifications, order queues
  - order operations (accept/reject/courier handover)
  - products/catalog/AI/import
  - inventory (summary/low-stock/stock update/hourly count)
  - store profile + operating hours
  - offers/coupons/weekly analytics
  - employees/permissions/activity logs
- Add a **Known Integration Risks (from current code)** section:
  - owner orders module uses literal reject path `/api/v1/store-owner/orders/{order}/reject` in one data source path (likely failure path)
  - user app places supermarket orders directly and does not currently call `/api/v1/user/supermarket/checkout/preview`
- Public API/interface changes: **none** (documentation-only update).

## Test Plan (to include inside the MD)
- Define Playwright API scenario catalog with IDs and expected results:
  - `USR-SM-01` … `USR-SM-24` for end-user supermarket journeys and validation/authorization failures
  - `OWN-SM-01` … `OWN-SM-24` for owner-side operational journeys and failure paths
- For each scenario require:
  - actor (`user`, `store_owner`)
  - preconditions/test data
  - endpoint + method
  - request payload shape
  - expected status code and response assertions
  - follow-up state assertion (cart/order/status/stock impact where applicable)
- Mandatory negative coverage:
  - cart empty order attempt
  - invalid `scheduledAt`
  - invalid shopping-list-to-cart resolution (no common store)
  - reject reason validation errors
  - stock update invalid quantity/operation
  - unauthorized token / wrong role access

## Assumptions and Defaults
- Output language: English.
- Deliverable in this step is **doc-only** (no test implementation files).
- Playwright approach is **API-first** using role-based auth contexts.
- Coverage remains limited to endpoints integrated in the two specified Flutter apps.
