# Restaurant food taxonomy — delivery evidence

Date: 2026-11-25
Scope: local QA, backend, customer Flutter app and restaurant-owner Flutter app. No deployment or commit performed.

## Implemented
- Additive two-level local category hierarchy, visibility inheritance, merchant scoping, guarded deletion, reviewed global food families, explicit item type/kind/servings/included components, Arabic/English aliases.
- Curated demo source: 184 available classified products in eight restaurants, 15 global families, 75 bundled photographs reused through media storage. Legacy classifier retired without changing editorial records.
- Public menu/detail/search use the same eligibility and hierarchy policy; direct/subtree counts; product card metadata; food family and explicit presentation filters; actual recent completed-order popular products.
- Filament classification review uses the shared validator. Owner product editor distinguishes presentation, role, food family, servings and included contents. Owner menu editor creates, edits, moves, hides, sorts by explicit sort order and deletes empty sections.
- Customer uses colorful food-family emoji tiles, real product photos, scoped presentation filters, menu rail/chooser and popular collection.
- Lucky Box uses reviewed known portions, stock, effective prices, required configuration cost and food-subtotal budget, returns bundle coverage and an explicit no-match reason. Dietary assertions fail closed without verification.
- Group vote suggestions accept family/type filters; vote creation and group-order mutation/placement recheck orderability and tenant ownership; prior vote history is preserved. Smart-search hydration enforces the same visibility rules.
- Fixed invalid cross-merchant product factory relationships, and updated outdated Lucky Box fixtures to specify reviewed portions.

## Evidence
| Gate | Result |
| --- | --- |
| Local MySQL restaurant contract suite | 23 tests, 1,977 assertions passed |
| SQLite social/Lucky Box regression suite | 26 tests, 162 assertions passed |
| Customer Flutter focused tests | 23 passed |
| Owner Flutter product tests | 9 passed |
| Customer focused Dart analysis | No issues |
| Owner product-module Dart analysis | No issues |

Local QA database is alnadha_worker_test on 127.0.0.1. A scoped schema migration and restaurant fixture restore were performed instead of dropping the shared database. Verified 8 restaurants, 184 browsable products, 15 global families, 4 existing cleaning bookings retained. The new MySQL contract checks every available fixture's photo and reviewed classification.

## Remaining release gates / limitations
- Full destructive migrate:fresh --seed was intentionally not performed because the database also contains ongoing cleaning-module QA scenarios. Validate full default fresh seeding in an explicitly disposable database before release.
- Emulator/device visual sign-off and authenticated Filament/owner menu end-to-end workflows remain to be exercised; focused Flutter tests are not a substitute for installed-app walkthroughs. Foundry Product UI runtime was not accessible in this session.
- Dedicated batch transactional sibling reorder (as opposed to per-section sortOrder updates), social voting filter controls, and external semantic-index rebuild/resynchronization are not fully delivered or verified.
- Production photo rights/quality review, full repository-wide tests, and deployment remain outside the verified local scope. No unrelated cleaning changes were intentionally modified.

Status at the end of the first implementation pass: substantial implementation complete; partial release verification.

## Continuation — remaining work

### Verified implementations

- **Transactional sibling reorder:** `PUT /api/v1/categories/reorder` under the existing authenticated restaurant-owner menu permission. Body: `parentId` (nullable), `expectedIds` (complete current sibling order), `orderedIds` (complete desired order). Restaurant-row and sibling locks serialize writes. Stale, partial, duplicate, foreign-parent and foreign-tenant requests are rejected before any write. Owner app provides up/down controls and reloads after rejection.
- **Social voting filters:** food family and presentation filters travel as separate intersecting query parameters; indexed itemTypes array encoding matches Laravel. An empty search can browse suggestions. Previously chosen products survive filter changes, and generation checking prevents stale responses from replacing newer results. Vote creation still validates orderability.
- **Semantic index:** `RestaurantMealIndexDocument` is the shared projection for single-product jobs and the authenticated full catalog export. Category visibility/rename/parent changes, family metadata and restaurant status changes trigger after-commit refreshes; pure sort changes do not. Hidden, cross-tenant and modifier-only products are absent from the projection; stock and temporary closure affect the availability field. Deletion captures dependent product IDs before relation removal. The database remains authoritative for purchase decisions.
- **Admin journey:** authenticated Livewire tests validate family meal servings, included components, review status, modifier-only normalization, alias changes and denial of approval to a restaurant owner.
- **Owner journey:** authenticated MySQL rollback-only test using the seeded owner creates sections, reorders roots, moves a fixture product temporarily, checks public menu visibility inheritance, rejects foreign categories and nonempty deletion, and deletes empty sections.

### Current verification evidence

| Gate | Result |
| --- | --- |
| MySQL restaurant contracts including owner journey | 24 passed; 1,999 assertions |
| SQLite category, index, admin, vote, group order and Lucky Box regressions | 38 passed; 261 assertions |
| New owner root/child reorder widget test | Passed |
| New voting filter/preservation and stale-response Flutter tests | 2 passed |
| Focused customer and owner module analysis before final expanded run | No issues |
| Customer Android x86_64 debug APK, local API override | Built |
| Owner Android x86_64 debug APK, local API override | Built |
| IDE build | Success; limited IDE diagnostics, platform-specific builds and tests used as evidence |
| Git whitespace checks | Passed; Windows line-ending notices only |

Final expanded Dart analysis commands timed out while the machine was resource-constrained. The earlier focused analyzers, new focused Flutter tests and both actual APK builds completed successfully.

### Disposable full seed

Ran the actual default `migrate:fresh --seed` against a uniquely created local MySQL schema with isolated public/private media roots, an isolated database queue, external HTTP blocked and meal-index writes disabled. Original media were validated; queued conversions were not drained. Results: 8 restaurants, 184 browsable reviewed products, 184 validated primary photos, 15 active families, 76 cleaning bookings, 226 queued jobs. The disposable schema and media directory were removed. Shared cleaning bookings before/after: 4/4. The existing QA database was never reset.

Evidence: `.tmp/restaurant-taxonomy-disposable-fresh.log`, `.tmp/restaurant-taxonomy-disposable-fresh.php`. APK logs: `.tmp/dllni-user-app-taxonomy-build.log`, `.tmp/dllni_resturant_owner_app-taxonomy-build.log`.

### Remaining release gates

1. Live semantic index reconciliation: configured local meal service `http://127.0.0.1:9523` was unreachable; Docker Linux engine was stopped. Indexing remains disabled. Start the intended local service and verify its Laravel source points to the local QA backend before invoking full delta sync. Do not send local fixture data to production or delete remote index rows. Projection and dependency jobs have mocked HTTP coverage.
2. Device visual sign-off: both local debug APKs were built. The emulator reported an Android system-process ANR under resource contention. Installed-app walkthroughs (including actual voting selection and owner section reorder) remain unverified. Local Laravel dev API was started on loopback port 8000 for QA.
3. Full repository-wide regression, queued image conversion verification, image-rights review, production deployment and observation remain outside the verified release scope.

No production deployment, commit, push or unrelated cleaning changes were performed. **Status: requested implementation gates complete; local runtime/release verification remains partial.**

## Continuation — live Docker search and media verification

- Docker engine restored. Started only the missing `meal-search` service on loopback port 9523 using the existing image and a local Compose override at `Dllni_backend/.tmp/restaurant-taxonomy-compose.local.yml`. Verified the authenticated Laravel source at `http://host.docker.internal:8000` and used a distinct collection, `meals_taxonomy_local_e5s_v1`, without deleting or modifying the pre-existing Milvus collections. Model: multilingual-e5-small, 384 dimensions.
- Full live sync: 240 documents added; 240/240 expected catalog rows present; 184 available; zero missing records and zero checked metadata mismatches. Arabic sandwich, burger and family-meal searches each returned five products matching availability and presentation constraints. Raw semantic similarity alone is not an exact food-family classifier.
- Live incremental test: temporarily hid a seeded root inside a rolled-back database transaction, deleted the affected product from Milvus, rolled back, and restored the product using the real upsert job. Repeated delta sync: 240 total, 0 added/updated/deleted.
- Found and fixed Arabic normalization of عائلية to عايلية in the smart-intent presentation matcher. The real customer smart endpoint returned three shawarma family meals and no other families/presentations using local fallback intent. New MySQL regression passed (1 test, 24 assertions), including conflicting explicit sandwich chips.
- Checked all 184 browsable products: 184 original photos, 184 app-thumb and 184 app-webp conversions exist on disk. No restaurant media regeneration required. Existing shared queue contains only 12 unrelated cleaning jobs; those were not executed.
- Enabled meal indexing in the ignored local Laravel .env. Added optional queue and indexing-specific timeout configuration; defaults unchanged. Local values: `DALLELNI_MEAL_INDEX_ENABLED=true`, `DALLELNI_MEAL_INDEX_QUEUE=restaurant-meal-index`, `DALLELNI_MEAL_INDEX_TIMEOUT=45`. Existing 10-second job timeout was insufficient under resource contention; retry on the isolated queue succeeded in 12 seconds. Projection/queue tests passed (3 tests, 19 assertions). Dedicated local worker process PID 4100 started and confirmed running; restart it after host reboot as needed: `php artisan queue:work database --queue=restaurant-meal-index --sleep=3 --timeout=75 --tries=3`.
- Live evidence: `.tmp/restaurant-taxonomy-live-sync.log`, `.tmp/restaurant-taxonomy-live-verify.php`, `.tmp/restaurant-meal-index-worker.log`. To recreate only the local QA meal service, from alnadha-ai-main run `docker compose -f docker-compose.yml -f docker-compose.local.yml -f ../Dllni_backend/.tmp/restaurant-taxonomy-compose.local.yml up -d --no-deps --no-build meal-search`. The regular Compose configuration still uses its original collection.
- Original cleaning bookings remained 4/4; all 12 cleaning queue jobs preserved. No production writes or deployment.

### Remaining checks after live synchronization

Live search-index synchronization, local incremental queue processing and restaurant product image variants are now verified. The expanded MySQL regression rerun timed out under resource contention (the targeted new test passed; prior consolidated results remain historical). Android emulator system-process ANR persisted; non-destructive ADB reboot initiated, but device visual acceptance is not yet verified. Host free physical memory was approximately 334 MiB after Docker and emulator activity. Final repository-wide regression, photo rights/visual review, commit/deployment and production observation remain outstanding.
