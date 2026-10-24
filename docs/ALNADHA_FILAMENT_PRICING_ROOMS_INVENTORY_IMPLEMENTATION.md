# Al Nadha — Dashboard V2: Rooms, Supervisor Pricing, and Materials Inventory

**Execution mode:** Codex with parallel subagents and a final integration owner  
**Project:** Laravel backend + Filament admin + User / Cleaning Worker / Supervisor Flutter clients  
**Local URL:** http://127.0.0.1:8000  
**Admin panel:** http://127.0.0.1:8000/admin  
**Status:** Implementation specification; actual verification must be recorded separately.

## 1. Mission and non-negotiable rules

Implement four coherent business changes:

1. A configurable supervisor **hourly rate** in existing cleaning financial settings and a server-owned charge in supervised group booking quotes/orders.
2. A full backend-driven **room catalog** (Arabic name, stable code, icon/image, display order, enabled state), with Filament create/edit/archive/delete rules and an API for customer and worker apps.
3. **Hourly room pricing** by room type and cleaning mode (حوسة / تعزيل), with an automatically suggested **editable hours** field instead of a hidden weight/deep multiplier.
4. Separate **consumable cleaning supplies** from **returnable equipment**. Complete stock reservation, issue to individual worker or group supervisor, handover, use/return, shortage, damage/loss, and audit history.

Use the existing Filament design system, Arabic RTL, resource structure, clusters, statuses, money formatting, permission policies, and server pricing services. Never create a second independent pricing engine, duplicate equipment ledger, or bypass domain services from Filament actions.

Preserve preexisting uncommitted supervisor/backend changes, use additive migrations, do not reset data, and do not commit unrelated modifications. Record original statuses first. Do not execute migrate:fresh without explicit new permission.

## 2. Verified existing code (audit baseline)

- Filament panel route is `/admin` in `app/Providers/Filament/AdminPanelProvider.php`.
- Financial settings: `app/Filament/Pages/FinancialSettings.php`; existing deep multiplier and per-room weight-like settings are still present.
- Existing room-period pricing: `Modules/User/app/Services/RoomPeriodPricingService.php`; it currently uses a single-room estimate for each room period.
- Existing aggregate and booking APIs use `UserCleaningOrderEstimationService`, `CleaningPricingCalculator`, quote issue/validation, and `RoomPeriodBookingCreationService`.
- Existing inventory: `CleaningMaterialInventoryService`, `CleaningEquipmentReservation`, material kits, materials, types, units and movement resources.
- Existing supervisor domain: `CleaningSupervisorAssignment` and `SupervisedTeamService`. Supervisor execution code has preexisting uncommitted edits: do not overwrite them.
- Existing Filament clusters: `CleaningCatalogCluster`, `CleaningMaterialsCluster`, `CleaningOperationsCluster` and cleaning finance.

## 3. Desired pricing contract

For each room line `i`:

- `roomTypeId`: stable catalog id (accept legacy `roomType` code during migration).
- `cleaningMode`: `regular` = حوسة; `deep` = تعزيل.
- `suggestedHours`: derived from room type/size/mode defaults **for UI guidance only**.
- `selectedHours`: customer's explicit override, else suggested hours. Validate positive, reasonable bounds, and scale; preserve override in order snapshot.
- `hourlyRate`: DB-configured rate for this room + mode, captured as immutable quote/order snapshot.
- `roomLinePrice = selectedHours × hourlyRate` (plus applicable quantities when explicitly modeled).

Compute `roomServiceSubtotal = sum(roomLinePrice)`. Reusable room sizes may influence *suggested hours*, but must not silently multiply the line price again. Remove the global deep ×5 and room weights from active V2 pricing, yet preserve old data/algorithm versions for existing historical orders.

**Supervisor charge**: for a supervised group booking, `supervisorCharge = supervisorBillableHours × supervisorHourlyRate`. Use the group's effective scheduled **elapsed service time** / defined supervised session durations, never multiply the same supervisor by worker count and never include gaps between separate visits. Define, document, and test the rounding rule (prefer minutes/60 with final currency rounding); single-worker jobs have zero supervisor charge. Expose `supervisorBillableHours`, `supervisorHourlyRate` and `supervisorCharge` in the admin/internal quote breakdown and snapshots.

Then `serviceSubtotal = roomServiceSubtotal + supervisorCharge + applicable specialty services + billable consumables`; feed correct bases into existing transport, margin, minimum-order, discount and final-pricing routines **exactly once**. Quote preview and create/update must use the same pricing service and same canonical request normalization. Server is the source of truth; client cannot submit monetary amounts.

Customer should still see the final price rather than internal transport/commission components. Filament admins may see the complete audit breakdown and older algorithm version for legacy bookings.

## 4. Room catalog and UX

### Storage / migration

Inspect current room codes and `CleaningFinancialDefaults::APP_ROOM_TYPES` first; create reusable room types/catalog only if not already present. Fields: `code` (immutable after use), `name_ar`, optional `name_en`, `icon_key` or validated image path, `is_active`, `sort_order`, `regular_hourly_rate`, `deep_hourly_rate`, `suggested_regular_minutes`, `suggested_deep_minutes`, optional size-based suggestions. Decimal money, integer minutes. Unique room code, nonnegative rates, positive suggested durations.

Do not destructively delete a room referenced by any order; deactivate/soft-delete. Render historical snapshot name/icon even if catalog data changes. Use `storage:link`/existing public disk and validated image upload limits when custom icons are allowed. Sanitize icon identifiers and supply client fallback.

### Filament interface

Place **أنواع الغرف** in existing Cleaning Catalog cluster. Table: icon preview, Arabic name, code, two hourly rates, two default durations, active, sort; search, filters and sort. Create/Edit: visually separated **Basic details**, **حوسة**, **تعزيل**, **Icon**, and **Availability** sections. Meaningful Arabic helper text for the editable default duration. Validate duplicate codes, decimals, uploaded files. Delete only when unused; else deactivate. Confirm destructive action and audit administrator.

### Client API and behavior

Expose active room types through existing customer cleaning discovery endpoint or a versioned endpoint (`GET /api/v1/user/cleaning/room-types` recommended after checking routes); include id/code/name/icon/modes/rate and suggested duration. Avoid N+1. Respect locale and stable field casing. The Flutter room selector must build cards and icons from API, not hardcoded names/assets alone; retain safe fallback for cached/offline data.

Upon selecting room/mode/size, fill proposed hours; allow direct editing. Recompute preview when edited. An API estimate returns `suggestedHours`, `selectedHours`, `hourlyRate`, `linePrice` per line so UX can show what changed. On mode/room change, refresh suggestions without silently overwriting a deliberate manual override; offer reset-to-suggestion affordance. Keep core user booking navigation unchanged.

## 5. Materials vs equipment stock lifecycle

### Two inventory classes

**Consumable materials:** units/quantities, on-hand, reserved, issued, consumed, returned-unopened (only if eligible), wasted, restocked and adjustments. Stock issuance is accounted once; never deduct again on consumption. Existing `CleaningMaterialInventoryService` reserve/release logic should be reused. Batch/unit costs remain internal unless charged by pricing policy.

**Reusable equipment:** each asset or tracked quantity with serial/asset ID (as appropriate), location, state (available, reserved, checked_out, return_pending, returned, maintenance, damaged, lost, retired), responsible custodian, and a movement ledger. Availability excludes outstanding checkouts. A return must restock only after verified acceptance, never after an arbitrary booking completion.

### Custody rule

- Single-worker booking: issue to the assigned/eligible worker.
- Supervised team booking: issue to **assigned supervisor**, never automatically to each worker. Record custody changes when supervisor is replaced.
- Checkout includes booking/session, issuer admin, recipient, item/quantity, issue timestamp, due date/time and optional condition/photo.
- Return includes actual quantity, returned condition, confirmer, timestamp, shortage/damage/loss reason; partial returns leave outstanding balance.
- Cancellation before handover releases reservations; after handover it triggers an explicit return/reconciliation flow rather than making stock magically available.

Use append-only stock movement rows with unique idempotency references, transactional row locks, authorized mutations, and nonnegative available quantities. Prevent double issue/return and conflicts on overlapping scheduled reservations. History survives catalog deactivation and booking cancellation.

### Filament UX

Reuse Cleaning Materials cluster. Add a material kind indicator, dedicated inventory views for **Consumables / Equipment / Issued / Awaiting Return / Damaged or Missing**, and clear quantity summaries. Equipment custody actions: Reserve, Hand over, Confirm Receipt, Request Return, Receive Return, Report Lost/Damaged, Adjust Stock (admin-only). Display outstanding equipment directly in order detail and supervisor profile.

Every action uses a Filament confirmation modal with appropriate precondition, quantity and reason validation; show readable Arabic notification and an audit record. Avoid exposing generic status editing or deletion where a domain action is needed.

### APIs / supervisor and worker apps

Read-only assigned kit/required materials for worker or supervisor; supervisor can see **group-level custody**, assigned equipment and return obligations. Mutating handover/receipt/return endpoints must require the authenticated responsible role and be idempotent. Do not expose inventory for unrelated orders.

## 6. Data consistency / compatibility

- Backfill legacy room codes to catalog records without rewriting historical booking snapshot prices.
- Preserve legacy quote support only where existing clients require it; new V2 algorithms must have a bumped `algorithmVersion`. Expired or payload-mismatched quotes return informative 422.
- Fields representing supervisor earnings vs customer charge must not be conflated. If the charge is also a payable, model its payout separately from service margin/worker shares.
- New status transitions are service-owned and audited; Filament actions call services, not raw `update(['status'=>...])`.
- Model supervisor eligibility by actual group booking `booking_kind` or authoritative service flags, not just `number_of_workers > 1` if those concepts differ.
- Work against current supervisor migration/workflow without discarding concurrent uncommitted work.

## 7. Parallel Codex subagent assignments

**Agent A — Pricing and Room Catalog**: migration/settings, Filament room resource, room API, new room line pricing and editable duration, supervisor fee quote+store integration, pricing tests. Own financial settings, estimate and room pricing files; coordinate any overlapping booking details edits.

**Agent B — Inventory and Equipment**: reuse material domain and equipment reservation, implement issue/return custody and audit service, migrate only missing fields, Filament actions and API adapters, unit/feature tests. Own material/equipment resources and services.

**Prime Integration/QA**: own order detail overview, test commands, browser smoke, API-client compatibility review, documentation, final regression, and conflict-free integration. Never let two agents edit one source file simultaneously.

## 8. Staged verification and gates

1. **Baseline:** `git status --short`; inspect routes/migrations and existing test results; take current browser screenshot. No data resets.
2. **Schema:** migrate additively; assert fresh migration and upgrade path on a disposable test DB; verify foreign keys, data snapshots and catalog backfill.
3. **Pricing unit tests:** different modes, arbitrary room hours, suggestions vs overrides, 1 vs many workers, supervisor session spanning, multiple visits, no double fees, threshold/minimum, quote mismatch, historical orders.
4. **Inventory unit tests:** reserve/consume/release; checkout; concurrency; supervisor custody; duplicate idempotent issue; partial/damaged returns; cancellation; stock nonnegative; audit trail.
5. **API tests:** discovery and icon output; estimate/create parity; customer/worker/supervisor role scopes; invalid rate/quantity; serial uniqueness; active/inactive rooms; timezone and recurrence.
6. **Dashboard/browser:** open `http://127.0.0.1:8000/admin/login`; authenticate with a dedicated QA admin if available; inspect Financial Settings, Room Catalog, Materials, Equipment Reservations and Booking Details. Verify Arabic RTL, saved values, filters, error states, responsive layout and status actions. Capture screenshots and browser errors. Use `node tests/browser/cleaning-dashboard-smoke.cjs` for a read-only route smoke pass.
7. **Flutter contract:** verify room cards/icons and manual hours in User App, worker materials list, supervisor custody/return visibility and customer final-price parity.
8. **Release gate:** no unresolved Critical/High failures, passing affected test suite, recorded migration and browser evidence. Record any missing device/emulator or login access as blocked rather than passed.

## 9. Delivery checklist

- [ ] Supervisor hourly rate persists in Filament and affects supervised API estimate/create exactly once.
- [ ] Room types and icons are created/edited/deactivated in Filament and loaded by Flutter-facing API.
- [ ] Each room's حوسة/تعزيل hourly rate is configurable.
- [ ] Suggested duration is shown in an editable field and override changes price.
- [ ] Weight/deep multiplier is removed from active V2 pricing, without repricing old bookings.
- [ ] Consumables stock lifecycle is complete and double deductions are impossible.
- [ ] Returnable equipment can be borrowed by worker/supervisor and safely returned/reconciled.
- [ ] Order details display supervisor charge, room lines, consumables, equipment and custody.
- [ ] Backend tests, authorization tests, Flutter contracts and local Filament browser tests have evidence.

## 10. Final handoff report format

For each phase report **Implemented**, **Tested**, **Blocked**, **Deferred** and **Files changed**. Include exact `php artisan test` commands, browser URL/status, screenshot paths and current `git status --short`. Never report a successful app flow without an observed result.

## 11. Integration review — unresolved release gates

The following are distinct from server-side quote/inventory unit tests:

1. **Arbitrary new room codes in the Flutter customer app:** the current customer room-count model uses a closed `CleaningRoomType` enum. Catalog-driven labels, icon mappings, active status, sort order and editable hours are integrated for supported codes, but newly created arbitrary codes are not selectable until a follow-up migration to a dynamic room-code model. This is necessary before claiming end-to-end "Add New Room Type" completeness.
2. **Supervisor-led operations on every V2 multi-worker booking:** the new hourly supervisor charge is calculated and snapshotted for team bookings; however, a V2 booking may retain legacy `booking_kind=standard` for compatibility with its room-session workflow. Ensure full supervisor assignment/start/completion/task authority is reconciled with the V2 workflow before a group order is treated as fully supervisor-operated. The inventory custody policy can use an actual active supervisor assignment without changing legacy lifecycle states.
3. **Flutter supervisor/worker inventory UI:** secured inventory API endpoints exist, but verify their use in both Flutter apps. Backend API tests are not a replacement for mobile receipt/return/exception UX testing.
4. **Authenticated Filament UI QA:** the local headless browser smoke covers anonymous navigation, login page and protected route redirects. It does not verify logged-in Form/Action persistence without a QA admin browser session.
5. **Local database migration:** the MySQL development DB required a shortened-constraint/partial-retry inventory migration fix. Both migration files eventually ran successfully; retain the idempotency guards and explicitly test an upgrade from old schema.
