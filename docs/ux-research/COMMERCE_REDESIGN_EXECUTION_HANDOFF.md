# Commerce redesign — execution handoff (2026-11-05)

## Scope and safety

Implementation source: the supplied nine-phase **Alnadha Restaurant and Supermarket Redesign** plan. Preserve the shared homepage, cleaning workflows, independent-delivery standalone experience, existing secondary commerce features, and existing framework choices. No commits, pushes, deployments, or destructive database operations are included.

## Verified local source inventory

| Area | Location | Observation |
| --- | --- | --- |
| Customer Flutter app | `dllni-user-app/lib/features/rs_home`, `rs_discover`, `rs_offers`, `rs_favourite`, `rs_main` | Separate restaurant feature directories are present. |
| Customer Flutter app | `dllni-user-app/lib/features/sm_home`, `sm_discover`, `sm_offers`, `sm_favorite`, `sm_stores`, `sm_cart`, `sm_orders` | Separate supermarket feature directories are present. |
| Orders | `dllni-user-app/lib/features/orders` | Shared order feature folder is present; consumers must be mapped before replacement. |
| Backend | `Dllni_backend/Modules/User`, `Dllni_backend/Modules/Supermarket` | User and supermarket modules are present; restaurant ownership spans existing backend modules and must be mapped at API/service level. |
| Existing editable design source | `designs/user-app-main-redesign/dllni-user-restaurants-redesign.pen` | Existing restaurant Pen design file. |
| Existing editable design source | `designs/user-app-main-redesign/dllni-user-supermarket-redesign.pen` | Existing supermarket Pen design file. |
| Restaurant screen export | `designs/user-app-restaurants-code-export/alnadha-restaurants-all-screens.pen` | Existing exported screen-design source. |
| New restaurant color variant | `designs/user-app-restaurants-code-export/alnadha-restaurants-all-screens-berry-v2.pen` | Created as a separate valid Pen JSON file, retaining 20 top-level frames and converting 116 orange accent references to berry. Original unchanged. |
| Backend contracts | `Dllni_backend/docs/API_CONTRACT_RESTAURANTS.md`, `API_CONTRACT_USER_APP_MERCHANT_SCOPED_CARTS.md`, `API_CONTRACT_SM_PRODUCT_SEARCH.md` | Prior contracts exist; verify against running code before declaring authoritative. |

## Phase 0 / 1 review gates

The existing `.pen` files are starting references, **not** evidence that all screens or states have been redesigned or reviewed. In particular, the nine Bee Order recordings were not provided in this execution context and no complete timestamped recording analysis has been verified. No Figma write operation or real Android UI run has yet been verified in this handoff.

The existing `dllni-user-supermarket-redesign.pen` contains both cleaning and supermarket frames (34 top-level frames); do not apply global search/replace to that source or imply it is an isolated commerce-only design. The new restaurant Pen variant is a palette conversion, not a finished interaction redesign or a validated prototype.

### Required screen and state IDs

| Prefix | Screens and states to document and validate |
| --- | --- |
| REST | Home, discovery/search scopes, offers, favorites, restaurant profile, menu sections, product options, merchant cart, checkout, confirmation, tracking, group orders, voting, lucky box, reorder and error/empty/loading states. |
| SM | Home, store discovery, product search, offers, favorites, store categories, product/options/alternatives, merchant cart, checkout, confirmation, tracking, comparison, shopping lists, reorder and error/empty/loading states. |
| SHARED-COMMERCE | Location selection return, authentication return, cart hub, money/fee breakdown, schedule picker, address, coupon, quote invalidation, checkout conflict, retry and submission recovery. |

## Design contract for new scoped components

Primary navy `#172554`, restaurant berry `#A63C66`, supermarket green `#168A67`, background `#F6F7F9`, surface white, foreground `#182232`, secondary `#697386`, borders `#E0E5EB`; Cairo type; 16dp horizontal padding, 16dp card radius, 12dp control radius; tap targets at least 48dp. Use section-scoped themes instead of changing the global app or cleaning theme. All user-facing Arabic layouts must be RTL and price formatting must preserve decimal precision.

## Integration acceptance map

1. Discovery: scroll/query/filter position persists across detail navigation; late search responses cannot overwrite newer queries.
2. Cart: one active merchant at checkout; other merchant carts retained; modifier and note variants remain distinct; authoritative revision and reconciliation shown to user.
3. Quote: server produces amount breakdown including delivery exactly once; stale/expired quotes cannot be submitted.
4. Placement: same idempotency key recovers a timed-out submission without duplicating orders.
5. History: purchased name, options, price and fulfillment are readable even after catalogue edits.
6. Tracking: delivery and pickup have distinct timelines; terminal orders stop refresh; valid cancellation/reschedule actions match backend policy.
7. Regression: shared homepage and cleaning behavior unchanged; previous restaurant group orders and supermarket comparison/shopping lists remain reachable.

## Outstanding evidence, not to mislabel as PASS

- A complete Bee Order recording-by-recording timestamped analysis.
- Exact mapped backend consumers/SQL schema and a validated migration/backfill rehearsal.
- Android emulator E2E of delivery and pickup for both sections, admin/owner progression, real DB financial matching.
- Screenshot comparison against reviewed screen variants, accessibility widths/scales, and real network timing.

Detailed execution results and validations should be appended in dated QA reports; this file is the source-verified inventory and acceptance handoff, not a claim of completion of phases 0–8.

## 2026-11-05 — Backend monetary precision implementation

The supermarket schema stores `price`, `discounted_price`, `offer_price`, modifier price, cart item unit price, order item unit/total prices, and order subtotal/discount/service fee/delivery fee/total/cancellation fee as `DECIMAL(...,2)`. Their Eloquent models previously cast these amounts to `integer`, potentially truncating fractional amounts on read. Updated the casts to `decimal:2` in `SmProduct`, `SmModifier`, `SmOfferProduct`, `SmCartItem`, `SmOrderItem`, and `SmOrder`. No schema migration or database mutation was necessary for this correction.

Validation: `php artisan test tests/Unit/Models/SupermarketMoneyCastsTest.php --no-ansi` — **PASS**, one test / fifteen assertions; `git diff --check` — **PASS**. This verifies model cast configuration and framework decimal/zero behavior; it is **not** evidence of complete end-to-end precision through API resources, checkout, Flutter, or the running MySQL instance.

Remaining gates: review all API money serialization and in-memory arithmetic, preserve exact quote/placement amounts and delivery fee snapshots, implement concurrency/recovery contracts, finish the editable screen/prototype package, and execute real Android/Filament end-to-end journeys. No production publish, commit, or push performed as part of this change.
