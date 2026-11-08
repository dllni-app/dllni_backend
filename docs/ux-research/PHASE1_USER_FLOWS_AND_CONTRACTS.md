# Phase 1 — Experience flows, API/state proposals and migration handoff
Date: 2026-11-05. **Proposed contracts** for Phase 2; none of the example endpoints below are claimed to exist yet.

## Flow A — Restaurant checkout
Shared homepage (untouched) -> REST-01 Home -> REST-02 discovery -> REST-06 restaurant -> REST-07 optional modifiers -> REST-08 merchant cart -> SHARED-COMMERCE-02 auth return if needed -> REST-09 single checkout -> SHARED-COMMERCE-06 quote ready -> REST-10 confirmation -> REST-11 tracking.
Back from product retains selected category, query and scroll. Cart with configuration-specific rows. A timed-out submit goes to SHARED-COMMERCE-09 recovery rather than retrying with a new order ID.

## Flow B — Supermarket checkout
Shared homepage -> SM-01 -> SM-02 or SM-03 search -> SM-06 store -> SM-07 product -> SM-08 optional alternative -> SM-09 store cart -> SM-10 checkout -> SM-11 receipt -> SM-12 tracking.
Different store retains old cart without mixing merchant orders. If stock/price changes, show explicit before/after and acceptance; never silently substitute.

## Flow C — Search and filter
Type immediately -> debounce 350ms -> cancel stale request -> generation-protected response -> merchant/product results -> provisional filter sheet -> Apply resets pagination -> scroll to start. Filter dismiss discards provisional values; Back restores original list and scroll; failed next page leaves old results and retries that page.

## Flow D — Order details, reordering and operational states
Merchant pending -> accepted -> preparing -> ready -> courier handover (delivery only) -> in transit -> delivered. Pickup: pending -> accepted -> preparing -> ready for pickup -> existing handover confirmation -> completed. Cancelled/returned are explicit terminal branches. Reorder uses immutable original configuration and server catalogue diff, then explicitly confirmed accepted rows. Secondary restaurant group/vote/lucky and supermarket compare/lists remain accessible.

## State ownership
| Owner | Lifetime | Data | Invalidation |
|---|---|---|---|
| CommerceContext | section lifetime | section, merchant, location, route restoration | location or section change |
| Search BLoC | scope lifetime | query, filters, cursor, request generation | query/scope/filter edit |
| MerchantCartStore | session | section+merchant key, lines, revision | successful mutation, auth logout |
| CheckoutCubit | checkout route | fulfillment, address, schedule, coupon, quote, submit receipt | cart/address/context change |
| TrackingCubit | active order | status/events/freshness | push, resume, 15s foreground fallback |
| Existing cleaning ownership | unchanged | booking context | no commerce effect |

Checkout state transitions: Editing -> SynchronizingCart -> RequestingQuote -> Ready -> Submitting -> Confirmed. Branches: ValidationRequired, QuoteChanged, Offline, RecoveringSubmission. Never treat local cached quote or price as authoritative.

## Proposed API examples (adapt existing routes where possible)
All examples are *design contracts*, not verified current outputs.

### GET /api/v1/user/commerce/{section}/home
```json
{"data":{"locationContext":{"id":12,"label":"المزة"},"sections":[{"key":"nearby","status":"ready","merchants":[]},{"key":"offers","status":"unavailable","items":[]}]}}
```
Allow partial failure and lazy below-fold sections.

### GET existing cart route
```json
{"data":{"id":8,"section":"restaurant","merchantId":21,"revision":4,"currency":"SYP","fractionDigits":2,"items":[{"id":13,"productId":5,"signature":"sha256:...","quantity":2,"selectedModifierIds":[1],"note":"","lineIssues":[]}],"amounts":{"subtotalMinor":3700000}}}
```
All money is integer minor units; do not confuse currency decimal precision with displayed digits. Reads must not mutate the cart.

### PATCH existing cart item route
Request: `{"expectedRevision":4,"quantity":3}`
Success: `200` with full cart, revision 5. Conflict: `409 {"code":"CART_CONFLICT","latestCart":{...}}`. Add/bulk mutations require an idempotency key.

### POST /api/v1/user/commerce/checkout/quotes
```json
{"cartId":8,"revision":5,"fulfillmentType":"delivery","addressId":12,"scheduledAt":null,"couponCode":null,"note":""}
```
```json
{"data":{"quoteId":"uuid","expiresAt":"2026-11-05T12:05:00+03:00","cartRevision":5,"currency":"SYP","fractionDigits":2,"amounts":{"subtotalMinor":3700000,"discountMinor":0,"merchantChargesMinor":0,"deliveryMinor":450000,"customerPayableMinor":4150000},"issues":[]}}
```

### POST existing commerce order placement route
`Idempotency-Key: uuid`; payload `{"quoteId":"uuid"}`.
Reject 409 stale/consumed unrelated quote, 422 invalid selection, 503 infrastructure. Timeout recovery: `GET /api/v1/user/commerce/submissions/{idempotencyKey}` scoped to authenticated customer, returning `not_found|processing|confirmed` and order reference. Same key returns same order; a second key on consumed quote resolves existing order rather than duplicating.

### GET existing customer order detail route
Include immutable purchase snapshot, actual monetary breakdown, merchant and delivery status separated, timeline timestamps, actions allowed by backend policy, fulfillment type from persisted value, not inferred from relation loading.

## Stable error codes
PRODUCT_UNAVAILABLE; OUT_OF_STOCK; MODIFIER_INVALID; CART_CONFLICT; QUOTE_EXPIRED; QUOTE_CHANGED; INVALID_SCHEDULE; DELIVERY_UNAVAILABLE; IDEMPOTENCY_CONFLICT.
422 for invalid input, 409 for business conflicts, 401/403/404 for existing access policy, 503 infrastructure. Client maps each to localization and recovery CTA.

## Phase 2 migration specification (proposal, not applied)
1. Inspect existing schema, cart duplicate patterns, historical line formats, active consumer routes and real MySQL connection. Confirm backups before changing data.
2. Add cart revisions, supermarket cart signature, checkout_quote and submission_receipt entities, immutable order/item and fulfillment snapshots, indexes as needed. Keep existing decimal database fields.
3. Backfill only recoverable configurations. Ambiguous line variants and historical missing prices go to reconciliation output, never fabricated.
4. Consolidate same-user/same-merchant active carts under transactional lock; do not merge distinct modifiers/notes; preserve total quantities and remove exact duplicates deterministically.
5. Verify exact signatures, totals and referenced consumers; only then enforce uniqueness and switch writers/readers behind compatible APIs.
6. Placement must lock quote+cart and perform stock, coupon, delivery record, line snapshots and receipt atomically. Notify after commit. Failure cannot create a half-order.
7. Rehearse migrations only on the existing authorized local QA database with export/restore window; no substitute SQLite memory database and no automatic `migrate:fresh`.
8. Remove obsolete aliases only when owner apps, Filament, delivery consumers, user app and tests have been mapped and migrated.

## Handoff acceptance
Phase 2: service contract tests for money, zero amount, discounts, delivery once, stale revision, double-submit, recovery, stock contention and historical snapshots.
Phase 3: request sequencing and RTL widget tests.
Phases 4–7: design-to-implementation visual parity and all secondary features reachable.
Phase 8: real UI E2E across delivery/pickup for both sections, inspect local backend/Filament and linked delivery, regress shared homepage and cleaning.
