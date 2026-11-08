# ALNADHA — PHASE 2: COMMERCE DATA INTEGRITY IMPLEMENTATION

Date: 2026-11-05
Environment: authorized local `C:\laragon\www\Dllni\Dllni_backend` (branch dev, uncommitted)
Status: core backend integrity capabilities implemented and tested; **Phase 2 full sign-off pending**.

## Implemented and validated

### 1. Atomic merchant order + linked delivery creation
- Restaurant and supermarket checkout now create the merchant order, linked delivery, relevant status record, coupon redemption, cart consumption and supermarket stock decrement inside the encompassing database transaction.
- When delivery linking fails, rollback leaves merchant order absent and cart/stock intact. Notifications fire after the parent transaction returns.
- Existing pickup semantics remain unchanged.

### 2. Idempotent order placement and recovery
- New `commerce_checkout_receipts` table and `CommerceCheckoutReceiptService`.
- Optional header `Idempotency-Key` on existing REST and SM cart order routes. Key scope is authenticated user + section. Exact payload replay yields the original order, even after cart removal. Changed payload conflicts with HTTP 409. Invalid key yields 422.
- GET `/api/v1/user/commerce/submissions/{idempotencyKey}?section=restaurant|supermarket` resolves `completed|not_found` receipts for the authenticated user. Unresolved processing state also supported.
- Notifications do not duplicate on successfully replayed requests.

### 3. Persisted, expiring checkout quotes
- New `commerce_checkout_quotes` table and `CommerceCheckoutQuoteService`.
- POST `/api/v1/user/commerce/checkout/quotes` with `section`, `cartId`, `fulfillmentType`, `receiveMode`, optional `scheduledAt`, `addressId`, `couponCode`, `note` returns `data.quoteId`, `data.expiresAt` and existing preview fields including `amounts`.
- Quotes expire after five minutes, are scoped to user/section/cart, retain a cart fingerprint and selected checkout parameters.
- Existing cart order routes accept optional `quoteId`. Placement checks ownership, expiry, cart & catalogue fingerprint and selection, then atomically verifies final financial amounts and consumes quote. Any discrepancy returns 409 `QUOTE_CHANGED` and rolls back order, stock, receipt and linked delivery.
- A confirmed retry with the original idempotency key remains valid even after the quote is consumed.
- The prior preview endpoints remain untouched to preserve the Flutter contract.

### 4. Versioned carts and optimistic concurrency
- `carts.revision` and `sm_carts.revision` additive columns default to zero.
- Eloquent cart item created/updated/deleted events atomically bump the corresponding cart revision; mutation responses expose `revision`.
- Add/update/delete REST and SM item APIs support optional `expectedRevision`. Stale writes return HTTP 409 `CART_CONFLICT`, and current cart remains unchanged. The supermarket whole-cart deletion also accepts the revision.
- Parent cart row is locked before targeted update/delete to align lock order with checkout; old callers without expectedRevision remain supported.
- Schema guards allow old deployments to continue processing legacy requests until migrations are applied; revision checks report unavailable on a database missing the new column.

### 5. Restaurant historical purchase snapshots
- Nullable `order_items.product_name` and `order_items.modifier_snapshot` columns.
- Restaurant checkout now records the selected product and modifier names/group names/prices into immutable order-item fields; OrderResource prefers the saved product name and returns `productName` and `modifierSnapshot` without removing legacy fields.
- Existing historical orders retain null snapshots with live-name fallback. No invented historical backfill is performed.

## Real local QA database safety
- Confirmed connection: MySQL `alnadha_worker_test` on 127.0.0.1; approximately 13.83 MB before scoped migrations.
- Saved a full consistent local mysqldump at `C:\laragon\backups\commerce-phase2\alnadha_worker_test_before_phase2_2026-11-05.sql` (2,405,448 bytes).
- Applied ONLY these four additive migrations via explicit `--path` commands:
  1. `2026_11_05_080000_create_commerce_checkout_receipts`
  2. `2026_11_05_080100_add_restaurant_order_item_purchase_snapshots`
  3. `2026_11_05_080200_create_commerce_checkout_quotes`
  4. `2026_11_05_080300_add_revisions_to_commerce_carts`
- All four show **Ran**. Unrelated cleaning migration `2026_11_01_050000_add_cleaning_session_supervision_fields` remains **Pending** and untouched.
- No `migrate:fresh`, cleaning data reset, destructive backfill, or schema rollback.

## Automated evidence
`php artisan test tests/Feature/UserModule/CommerceCartRevisionTest.php tests/Feature/UserModule/CommerceCheckoutQuoteTest.php tests/Feature/UserModule/CommerceCheckoutIdempotencyTest.php tests/Feature/UserModule/RestaurantOrderPurchaseSnapshotTest.php tests/Feature/Delivery/UserLinkedDeliveryCheckoutTest.php tests/Feature/UserModule/UserSupermarketCheckoutHardeningTest.php tests/Feature/UserModule/UserRestaurantCheckoutTest.php --no-ansi`

**PASS: 31 tests, 238 assertions** (SQLite :memory: test database per phpunit.xml). Scenarios include:
- REST/SM revision increments and stale 409; valid deletes
- REST/SM quote placement, changed selection/cart/catalogue, expiry, final amount mismatch, auth isolation, replay
- REST/SM idempotent replays, conflict, bad key, customer isolation and rollback
- merchant-order/delivery atomic rollback and no double stock decrement
- historical restaurant product/modifier names after catalogue edits
- legacy pickup and delivery fees

`git diff --check` passed. PHP syntax checks for the modified core services/models passed. Scoped Laravel Pint formatting applied to three new or small files; other existing modified services carry earlier style debt, not bulk-formatted to avoid unrelated diffs.

## Remaining Phase 2 risks and integration work
- REST and SM still use decimal/float presentation in existing APIs. A complete integer-minor-unit contract migration, historical financial reconciliation and all consuming clients must be planned/tested before conversion.
- Legacy FK cascade from product rows to order_items could delete historical lines on hard deletion; design safe FK migration with all consumer owners before altering historic delete behavior.
- Historic order rows whose names were never snapshotted cannot be accurately backfilled from renamed current catalogue without evidence. Preserve null/fallback.
- Revision events cover Eloquent mutations. Audit any raw SQL/bulk third-party mutations and cart normalization paths so revision semantics are universally consistent.
- Supermarket preview currently refreshes stale prices in cart during a read; requires explicit UX/backend compatibility decision and migration before making it pure.
- Quote amount and fingerprint checks are validated with SQLite tests; concurrent MySQL request races and performance need real QA transaction tests.
- Full Filament, delivery, merchant-app and Flutter integration with `quoteId`, `Idempotency-Key`, `expectedRevision`, recovery GET and HTTP 409 recovery UI is pending. No emulator E2E in this phase.
- Cleanup/expiration retention of quotes and receipts should be scheduled with business retention requirements.
- Phase 2 is **not signed off** until these remaining pieces have test evidence and relevant clients are migrated.

## Files / API touch points
Core: `Modules/User/app/Services/CommerceCheckoutReceiptService.php`, `CommerceCheckoutQuoteService.php`, `RestaurantCheckoutService.php`, `UserRestaurantCheckoutPipelineService.php`, `UserSupermarketCheckoutPipelineService.php`, `UserRestaurantCartService.php`, `UserSupermarketCartService.php`.
Controllers/requests/routes: `Modules/User/app/Http/Controllers/API/UserCommerceSubmissionShowController.php`, `UserCommerceCheckoutQuoteStoreController.php`, existing REST/SM cart/order controllers, `Modules/User/routes/api.php`.
Models/resources: `Modules/Resturants/app/Models/Cart.php`, `CartItem.php`, `OrderItem.php`, `OrderResource.php`, `Modules/Supermarket/app/Models/SmCart.php`, `SmCartItem.php`.
Migrations: `database/migrations/2026_11_05_080{000,100,200,300}_*.php`.
Tests: `tests/Feature/UserModule/CommerceCartRevisionTest.php`, `CommerceCheckoutQuoteTest.php`, `CommerceCheckoutIdempotencyTest.php`, `RestaurantOrderPurchaseSnapshotTest.php` and `tests/Feature/Delivery/UserLinkedDeliveryCheckoutTest.php`.

## Source control
No commit, push, deployment or changes to cleaning flows from this Phase 2 work. Preserve unrelated previously staged QA artifacts and existing worktree modifications.
