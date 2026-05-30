# Delivery MVP Implementation Plan (Laravel + Filament + Driver API)

## 1. Repository understanding

- **Framework version detected:** Laravel `12.58.0` (from `composer.json`).
- **Filament version detected:** Filament `5.3.5`.
- **Auth approach detected:** Laravel Sanctum token auth for API (`HasApiTokens` on `User`), plus panel login for Filament.
- **Permission approach detected:** Spatie `laravel-permission` with role + permission checks and seeded dashboard permissions.
- **API response pattern detected:**
  - Mixed style across modules.
  - Common patterns are Laravel `JsonResource` and `AnonymousResourceCollection`.
  - camelCase keys are standard in resources.
  - Some endpoints return `{data: ...}`, others return direct objects; we will preserve existing patterns and avoid breaking existing response shapes.
- **Folder/module architecture detected:**
  - Core app under `app/*`.
  - Domain modules under `Modules/*` using `nwidart/laravel-modules`.
  - Active modules: `Cleaning`, `Resturants`, `Supermarket`, `User`.
- **Existing relevant data/tables confirmed:**
  - `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`.
  - `notifications`.
  - `activity_log`.
  - `workers`, `worker_availability`, `worker_trust_logs`.
  - `travel_cost_configs`.
  - `disputes`, `dispute_messages`.
  - `booking_status_logs` (polymorphic).
  - Restaurants domain `orders`, `order_status_logs` are restaurant-focused and not suitable as delivery-order base.
- **Existing queue/notification setup confirmed:**
  - Queue default: `database`.
  - Existing queued jobs and notification flow for cleaning.
  - Unified notification type registry in `config/notification_types.php`.
  - FCM integration available (`devkandil/notifire`, `HasFcm` trait on `User`).
- **Filament panel structure confirmed:**
  - Current panel: `AdminPanelProvider` at `/admin`.
  - No dedicated company panel currently.
- **Stitch assets discovered:**
  - Found and inspected: **Mandoub Delivery Driver App UI** screens (login, home, offers, active order, notifications, finance, disputes, profile).
  - Found and inspected: **Filament Company Panel: Delivery Dashboard** project.
---

## 2. Gap analysis

### Reusable as-is

- Sanctum auth/token lifecycle.
- User + role/permission infrastructure.
- Notification pipeline (database + push via canonical notification registry).
- Existing dispute engine (`disputes`, `dispute_messages`) and polymorphic relation style.
- Existing status logging style (`booking_status_logs`) for timeline-compatible events.
- Existing module/service/request/resource patterns and transaction usage.
- Existing owner-context/scoping patterns from `RestaurantOwnerContext` and `StoreOwnerContextService`.
- Existing Filament resource/table/form/infolist/action patterns.

### Needs extension (not full replacement)

- `UserModuleType` needs delivery role support for driver users.
- Panel access (`User::canAccessPanel`) needs company-panel branch.
- Permission groups/seeders need delivery-specific permission namespaces.
- Dispute status enum/validation needs delivery-compatible `rejected` support while keeping backward compatibility.

### Needs new delivery implementation

- New module: **Delivery** under `Modules/Delivery`.
- Delivery domain schema (companies, staff, drivers, GPS, delivery orders, dispatch attempts, ledger, trust logs).
- Driver API endpoints (offers, accept/reject, live status transitions, location updates, financial summary, notifications, disputes).
- Dispatch engine with race-safe assignment attempts and timeout jobs.
- Financial ledger services (immutable tx + balance update + suspension/reactivation).
- Filament company dashboard panel + resources/pages/widgets with strict company scoping.
- Delivery-specific notifications and report aggregation services.

### Risks / unclear points to lock

- Missing Stitch company dashboard project access.
- Choice between reusing global disputes vs delivery-specific disputes table.  
  - Recommended: reuse global disputes + extend enum/filters safely.
- Dispatch distance accuracy source (Haversine DB formula vs external maps API).  
  - Recommended: SQL Haversine MVP, no external API dependency.
- Financial limit semantics.  
  - Recommended: `due_balance >= financial_limit` triggers financial suspension.
- Timeouts and stale GPS thresholds need explicit defaults.

---

## 3. Database implementation plan

### Reuse existing tables

- `users`, `notifications`, `activity_log`, role/permission tables.
- `disputes`, `dispute_messages` (polymorphic, extend usage to `delivery_order` morph).
- `booking_status_logs` for normalized status timeline entries.

### New tables (delivery-specific)

1. `delivery_companies`
- `id`, `owner_user_id`, `name`, `legal_name`, `phone`, `email`, `address`.
- `latitude`, `longitude`.
- `is_active`, `is_suspended`, `suspension_reason`, `suspended_until`.
- `financial_limit` (max due balance).
- `created_at`, `updated_at`.
- Indexes: `owner_user_id`, `is_active`, `is_suspended`.

2. `delivery_company_staff`
- `id`, `company_id`, `user_id`, `role_key`, `is_active`, timestamps.
- Unique: `(company_id, user_id)`.
- Indexes: `company_id`, `user_id`, `is_active`.

3. `delivery_drivers`
- `id`, `company_id`, `user_id`.
- Profile: `first_name`, `phone`, `vehicle_type`, `plate_number`.
- State: `availability_status` (`available|busy|offline`), `is_active`, `is_suspended`, `suspended_until`, `suspension_reason`.
- Trust: `trust_score`, `open_disputes_count`, `last_seen_at`.
- `created_at`, `updated_at`.
- Unique: `user_id` (one delivery driver per user).
- Indexes: `(company_id, availability_status)`, `(company_id, is_suspended)`, `last_seen_at`.

4. `delivery_driver_locations`
- `id`, `driver_id`, `latitude`, `longitude`, `accuracy`, `speed`, `heading`, `recorded_at`, timestamps.
- Indexes: `(driver_id, recorded_at)`, `recorded_at`.
- Rule: append-only writes; no manual admin updates.

5. `delivery_orders`
- `id`, `company_id`, `driver_id` nullable.
- `order_number` unique.
- Customer/payload: `customer_name`, `customer_phone`, `customer_notes`.
- Pickup/dropoff: `pickup_address`, `pickup_latitude`, `pickup_longitude`, `dropoff_address`, `dropoff_latitude`, `dropoff_longitude`.
- Pricing: `distance_km`, `delivery_fee`, `currency`.
- Status: `status` (`new|dispatching|offered|accepted|in_progress|picked_up|delivered|completed|rejected|stopped|cancelled`).
- Lifecycle timestamps: `accepted_at`, `started_at`, `picked_up_at`, `delivered_at`, `completed_at`, `stopped_at`, `cancelled_at`.
- `stop_reason`, `cancel_reason`.
- `created_by_user_id`.
- `created_at`, `updated_at`.
- Indexes: `(company_id, status)`, `(driver_id, status)`, `created_at`.

6. `delivery_assignment_attempts`
- `id`, `order_id`, `driver_id`, `attempt_no`.
- `status` (`open|accepted|rejected|timed_out|cancelled`).
- `distance_to_pickup_km`.
- `offered_at`, `expires_at`, `responded_at`.
- `reject_reason`.
- timestamps.
- Unique: `(order_id, driver_id, attempt_no)`.
- Indexes: `(order_id, status)`, `(driver_id, status)`, `expires_at`.

7. `delivery_financial_accounts`
- `id`, `owner_type`, `owner_id` (morph; company or driver).
- `currency`, `current_balance`, `financial_limit`.
- `is_suspended`, `suspension_reason`, `suspended_at`.
- timestamps.
- Unique: `(owner_type, owner_id, currency)`.
- Indexes: `(owner_type, owner_id)`, `is_suspended`.

8. `delivery_financial_transactions`
- `id`, `account_id`.
- `transaction_type` (`order_fee_debit|collection_credit|manual_adjustment_debit|manual_adjustment_credit|dispute_penalty_debit|dispute_reversal_credit`).
- `direction` (`debit|credit`), `amount`.
- `balance_before`, `balance_after`.
- `reference_type`, `reference_id` (morph to order/dispute/manual collection record).
- `note`, `metadata` JSON.
- `created_by_user_id` nullable.
- timestamps.
- Indexes: `(account_id, created_at)`, `(reference_type, reference_id)`.

9. `delivery_driver_trust_logs`
- `id`, `driver_id`, `reason`, `score_delta`, `score_after`, `related_dispute_id` nullable, timestamps.
- Indexes: `(driver_id, created_at)`.

### Updates to existing tables/enums/morph map

- `users.module_type` enum mapping: add `delivery_driver` in code enum.
- `disputes` usage extension:
  - add `delivery_order` morph map in `AppServiceProvider`.
  - extend status handling to include `rejected` in code validation/enums while preserving existing values.
- `notification_types.php`: add delivery canonical types and module icon mapping.

### Migration order

1. Delivery companies and staff.
2. Delivery drivers and locations.
3. Delivery orders.
4. Assignment attempts.
5. Financial accounts and transactions.
6. Driver trust logs.
7. Enum-related compatibility migration (if DB-level checks exist).
8. Seed initial permissions/roles.
9. Optional seed sample delivery company + driver for QA.

### Data migration/backfill

- No destructive backfill required.
- For existing users promoted to delivery drivers: create `delivery_drivers` rows and corresponding financial accounts through dedicated seeder or one-off command.
- Existing disputes remain untouched; only filter/scoping expansion for `delivery_order`.

---

## 4. Backend domain architecture

### Models and relationships

- `DeliveryCompany` hasMany `DeliveryCompanyStaff`, `DeliveryDriver`, `DeliveryOrder`.
- `DeliveryCompanyStaff` belongsTo `DeliveryCompany`, belongsTo `User`.
- `DeliveryDriver` belongsTo `DeliveryCompany`, belongsTo `User`, hasMany `DeliveryDriverLocation`, `DeliveryAssignmentAttempt`, `DeliveryDriverTrustLog`, `DeliveryOrder`.
- `DeliveryOrder` belongsTo `DeliveryCompany`, belongsTo `DeliveryDriver` nullable, morphMany `Dispute`, morphMany `BookingStatusLog`.
- `DeliveryAssignmentAttempt` belongsTo `DeliveryOrder`, belongsTo `DeliveryDriver`.
- `DeliveryFinancialAccount` morphTo owner, hasMany `DeliveryFinancialTransaction`.
- `DeliveryFinancialTransaction` belongsTo `DeliveryFinancialAccount`.
- `DeliveryDriverTrustLog` belongsTo `DeliveryDriver`.

### Enums

- `DeliveryOrderStatus`.
- `DeliveryDriverAvailabilityStatus`.
- `DeliveryAssignmentAttemptStatus`.
- `DeliveryFinancialTransactionType`.
- `DeliveryFinancialDirection`.
- `DeliverySuspensionReason` (`financial|manual|compliance`).

### Services

- `DeliveryOrderService`: create order, validate payload, lifecycle transitions, event logging.
- `DeliveryPricingService`: distance + fee calculation (MVP Haversine-based).
- `DriverDispatchService`: candidate selection, attempt creation, retry, stop logic.
- `DriverLocationService`: coordinate validation, append location, update `last_seen_at`.
- `FinancialLedgerService`: immutable tx creation, account locking, balance updates.
- `FinancialSuspensionService`: suspend/reactivate by financial threshold with reason locking.
- `DriverTrustService`: apply dispute penalties and recovery.
- `DeliveryNotificationService`: in-app + push events.
- `DeliveryReportService`: aggregate KPI and chart datasets.
- `DeliveryCompanyContextService`: resolve company from authenticated company user.
- `DeliveryDriverContextService`: resolve driver from authenticated driver user.

### Jobs

- `DispatchDeliveryOrderJob`.
- `ExpireAssignmentAttemptJob`.
- `SendPushNotificationJob` (only if needed beyond queued notifications; otherwise use existing notification queue path).
- `MarkStaleDriversOfflineJob`.
- `RecoverDriverTrustScoreJob`.

### Events/listeners

- `DeliveryOrderCreated`, `DeliveryAttemptOpened`, `DeliveryAttemptTimedOut`, `DeliveryOrderAccepted`, `DeliveryOrderStopped`, `DeliveryOrderStatusChanged`, `DeliveryDisputeOpened`.
- Listeners delegate to notification service and status/event logging.

### Notifications

- New notifications under delivery namespace using existing payload builder patterns:
  - driver new offer.
  - offer timed out.
  - order accepted/started/picked/delivered/completed.
  - company stopped order.
  - dispute opened/resolved.
  - financial suspension/reactivation.
- Add canonical definitions in `config/notification_types.php` under module `delivery`.

### Policies and authorization

- Add policies:
  - `DeliveryCompanyPolicy`, `DeliveryDriverPolicy`, `DeliveryOrderPolicy`, `DeliveryFinancialAccountPolicy`.
- Register in `AppServiceProvider`.
- Company panel resources enforce both:
  - policy permission checks.
  - hard company scoping in queries and actions.
- Driver API route group uses middleware that requires resolved delivery driver and denies non-driver users.

### Validation/FormRequests

- Create delivery-specific FormRequests for all endpoints:
  - auth login.
  - location update.
  - offer accept/reject.
  - order start/pickup/deliver.
  - availability change.
  - order create/cancel/retry-dispatch.
  - dispute create.
- Keep validation strict on status transitions and coordinate ranges.

### API Resources/Transformers

- Delivery resources return camelCase fields and preserve current project style:
  - `DeliveryDriverResource`
  - `DeliveryOrderResource`
  - `DeliveryOfferResource`
  - `DeliveryFinancialSummaryResource`
  - `DeliveryDisputeResource` (wrapping global dispute model with delivery projection)

---

## 5. Filament company dashboard plan

### Panel structure

- Add **new Filament panel**: `CompanyPanelProvider`.
- URL path: `/company`.
- Scope: delivery company users only.
- Keep existing `/admin` panel unchanged for platform/admin operations.

### Navigation groups

- `Dashboard`
- `Operations`
- `Drivers`
- `Orders`
- `Financial`
- `Disputes`
- `Notifications`
- `Reports`

### Resources/pages/widgets

- `CompanyDashboard` page with widgets:
  - new orders, active orders, completed today, available/busy/offline/suspended drivers, balance, open disputes, stopped orders.
  - basic trend charts.
- `DeliveryDriverResource`
  - list/create/edit/view.
  - actions: activate, suspend, unsuspend.
  - relation blocks: trust logs, financial summary, latest location, active order.
- `DeliveryOrderResource`
  - list/create/view.
  - actions: retry dispatch, open dispute, cancel order, mark stopped-handled.
  - infolist sections: timeline, attempts, driver/customer/pickup/dropoff, distance+fee.
- `DeliveryFinancialPage` (or resource pair Account + Transactions)
  - balance card, limit card, due warning banners, ledger table.
  - manual collection action hidden in company panel.
- `DeliveryDisputeResource`
  - scoped list + detail + note/add-message action if allowed.
- `DeliveryNotificationsPage`
  - operational notifications list, read/unread actions, deep links.
- `DeliveryReportsPage`
  - status aggregates, completed/day chart, driver availability summary, financial totals, disputes count.

### Tables/forms/infolists/actions/modals

- Tables with searchable fields, status badges, date filters, company-safe scopes.
- Forms enforce no manual GPS fields.
- Infolists include read-only GPS and event timelines.
- Actions use confirmation modals and dispatch service calls, not inline raw updates.

### Company scoping approach

- Resolve company via `DeliveryCompanyContextService`.
- Apply scoping at query level in every resource.
- For actions on record, assert `record->company_id === contextCompanyId`.
- Do not rely on frontend filtering alone.

### Authorization approach

- `canAccessPanel` panel-aware checks in `User`:
  - admin roles for `admin` panel.
  - delivery company roles for `company` panel.
- Resource-level `canViewAny/canCreate/canEdit/...` with permission names.
- Policies enforce row-level company ownership.

### Stitch company dashboard mapping

- Because company Stitch project is not accessible now, mapping uses required MVP UI semantics:
  - KPI cards -> Filament `StatsOverviewWidget`.
  - Driver/order tables -> Filament `Table`.
  - Detail inspectors -> Filament `Infolist`.
  - Operational buttons -> Filament `Action` + modal.
  - Notifications feed -> dedicated page + read actions.
- Once Stitch company project is shared, we will map iconography/ordering/labels only; backend contracts stay unchanged.

---

## 6. Driver API module plan

### Route base

- Prefix: `/api/v1/delivery/driver`.
- Middleware: `auth:sanctum` + `ensure.delivery.driver` for protected routes.

### Endpoints

1. Auth
- `POST /api/v1/delivery/driver/auth/login`
- `POST /api/v1/delivery/driver/auth/logout`
- `GET /api/v1/delivery/driver/me`

2. Availability
- `PATCH /api/v1/delivery/driver/availability`

3. GPS
- `POST /api/v1/delivery/driver/location`

4. Offers
- `GET /api/v1/delivery/driver/offers/current`
- `POST /api/v1/delivery/driver/offers/{attempt}/accept`
- `POST /api/v1/delivery/driver/offers/{attempt}/reject`

5. Current order
- `GET /api/v1/delivery/driver/orders/current`

6. Order transitions
- `POST /api/v1/delivery/driver/orders/{order}/start`
- `POST /api/v1/delivery/driver/orders/{order}/pickup`
- `POST /api/v1/delivery/driver/orders/{order}/deliver`

7. Financial
- `GET /api/v1/delivery/driver/financial/summary`

8. Notifications
- `GET /api/v1/delivery/driver/notifications`
- `PATCH /api/v1/delivery/driver/notifications/{id}/read`

9. Disputes
- `GET /api/v1/delivery/driver/disputes`

### Controllers/requests/resources

- Controllers in `Modules/Delivery/app/Http/Controllers/API/Driver/*`.
- FormRequests per endpoint to keep validation explicit.
- Resources with camelCase keys and existing project response style.

### Status transition rules

- `start` allowed only from `accepted`.
- `pickup` allowed only from `in_progress`.
- `deliver` allowed only from `picked_up`.
- each transition writes:
  - `delivery_orders` status + timestamp.
  - `booking_status_logs` row.
  - `delivery_order_events` row.

### Accept/reject locking rules

- `accept/reject` runs in DB transaction.
- lock attempt row + order row (`lockForUpdate`).
- accept only if attempt `open` and `expires_at > now`.
- once accepted:
  - assign driver to order.
  - mark order `accepted`.
  - mark driver `busy`.
  - mark all sibling open attempts `cancelled`.
- reject requires `reason`, marks attempt `rejected`, triggers redispatch job.

### Error responses

- Preserve Laravel validation shape for `422`.
- Use `403` for scope violations.
- Use `409` for expired/closed attempts when semantically conflicting.
- Do not change existing global endpoint contracts.

### Stitch driver UI mapping

- Login screen -> auth login endpoint.
- Home/dashboard -> `orders/current`, `offers/current`, `financial/summary`, notifications count.
- New offer card -> offer current + accept/reject endpoints.
- Active order detail -> current order endpoint + transition endpoints.
- Notifications screen -> notifications list + mark read.
- Financial screen -> financial summary.
- Disputes screen -> driver disputes list.

---

## 7. Dispatch flow plan

### Candidate selection query (nearest-driver MVP)

- Source set:
  - company drivers only.
  - `is_active = true`.
  - `is_suspended = false`.
  - `availability_status = available`.
  - `last_seen_at >= now - stale_location_minutes`.
  - has latest location row.
- Distance:
  - compute SQL Haversine between latest driver location and pickup point.
  - sort ascending by distance.
- Exclusions:
  - drivers already attempted for same order with `rejected|timed_out` in current cycle.

### Attempt lifecycle

- On order creation:
  - status `new` -> pricing -> `dispatching`.
  - `DispatchDeliveryOrderJob` queues.
- Job:
  - open next attempt with `attempt_no`, `status=open`, `offered_at`, `expires_at`.
  - update order status `offered`.
  - notify driver.
  - schedule `ExpireAssignmentAttemptJob` at `expires_at`.

### Timeout job behavior

- Lock attempt + order.
- If attempt still `open` at execution:
  - set `timed_out`.
  - append event log.
  - trigger next dispatch attempt.

### Retry logic

- Company action “Retry dispatch” allowed for `stopped` and `dispatching` failure states.
- Retry creates new cycle, resets order to `dispatching`, then reruns dispatch job.

### Stopped-order logic

- If no eligible drivers or all attempts exhausted:
  - set order `stopped`.
  - set `stopped_at`, `stop_reason`.
  - notify company + admin channels.

### Race-condition protections

- `lockForUpdate` on order and attempt in accept/reject/timeout paths.
- Idempotent guard: transitions no-op when already resolved.
- Single active order per driver:
  - pre-check in transaction for driver active-order existence.
- Queue after-commit dispatch for attempt and notification jobs.

### Default operational constants (configurable)

- `stale_location_minutes = 5`
- `offer_timeout_seconds = 30`
- `max_search_radius_km = 15` (if no one in radius -> stopped)
- `max_attempts_per_order = candidate count (bounded by 20)`

---

## 8. Financial ledger plan

### Account ownership model

- One financial account per `delivery_company` (required).
- Optional financial account per `delivery_driver` for summary view (recommended for driver screen requirement).

### Ledger behavior

- Immutable transactions only in `delivery_financial_transactions`.
- Every balance update occurs in transaction:
  - lock account row.
  - compute `before`, `after`.
  - insert tx row.
  - update account balance.

### Transaction rules

- On order completion: `order_fee_debit` on company account.
- On admin collection/payment: `collection_credit` on company account.
- On dispute penalty/reversal: debit or credit transactions with dispute reference.
- No wallet top-up semantics, no spendable balance abstraction.

### Suspension logic

- `due_balance` represented by positive `current_balance`.
- If `current_balance >= financial_limit`:
  - suspend company (`is_suspended = true`, reason `financial`).
  - block new dispatch and force drivers unavailable.
- Suspended company drivers cannot set `available`.

### Reactivation logic

- If payment lowers `current_balance < financial_limit` and suspension reason is `financial`:
  - unsuspend company.
  - unsuspend drivers whose suspension reason is financial.

### Admin vs company responsibilities

- Company panel:
  - view balance, limit, ledger, warnings.
- Admin panel:
  - execute manual collection/adjustments.
  - override suspension only through admin actions with audit note.

---

## 9. Disputes and trust points plan

### Dispute creation/management

- Reuse global `disputes` and `dispute_messages`.
- Register morph map `delivery_order` -> `DeliveryOrder`.
- Delivery dispute create flow binds:
  - `booking_type = delivery_order`.
  - `booking_id = delivery_orders.id`.
- Company and driver dispute lists are filtered by order ownership/assignment.

### Dispute statuses

- Delivery workflow target statuses:
  - `open`, `under_review`, `resolved`, `rejected`.
- Compatibility plan:
  - keep existing status values.
  - extend enum/validation to include `rejected` without removing `closed`.

### Trust points

- Driver starts with default trust score (configurable, default `100`).
- Confirmed/valid dispute applies negative trust delta and writes `delivery_driver_trust_logs`.
- Trust score shown in dashboard; not used in dispatch ranking in MVP.

### Recovery job

- `RecoverDriverTrustScoreJob` runs daily:
  - increment trust for eligible drivers without recent disputes.
  - cap at max score.
  - write trust log entries with reason `scheduled_recovery`.

### Notifications

- On dispute open/resolved/rejected:
  - notify driver, company staff, and optionally admin.
- On trust decrease/recovery:
  - notify driver and include updated score.

---

## 10. Notifications plan

### In-system notifications

- Use existing `notifications` table and Laravel notification channels.
- Add delivery canonical types in `config/notification_types.php`.
- Add module icon mapping `delivery`.

### Push notifications (FCM)

- Reuse existing `HasFcm` + payload builder.
- Delivery notifications implement `ShouldQueue`.
- Push sent only when fcm token exists.

### Trigger events

- order offered to driver.
- offer timed out.
- offer accepted/rejected.
- order stopped.
- order started/picked/delivered/completed.
- dispute opened/updated/resolved/rejected.
- financial suspension/reactivation.
- manual collection posted.

### Read/unread handling

- Driver app uses delivery driver notification endpoints.
- Company panel uses read/unread actions and filters.
- Preserve existing mark-as-read semantics and notification payload shape conventions.

---

## 11. Testing plan

### Unit tests (services/jobs)

- `DriverDispatchService` candidate ranking + attempt flow.
- `DeliveryOrderService` transition guards + status/event writes.
- `FinancialLedgerService` immutable tx and locked balance updates.
- `FinancialSuspensionService` threshold behavior.
- `DriverTrustService` penalty/recovery logic.
- Timeout/retry jobs idempotency.

### Feature tests (API)

- Driver auth + route protection.
- Availability and suspension constraints.
- GPS endpoint validation and write behavior.
- Accept/reject timeout paths.
- Order lifecycle endpoints.
- Financial summary endpoint.
- Notifications list/read.
- Driver/company disputes list scoping.

### Filament tests

- Company panel login access for delivery roles.
- Resource query scoping by company.
- Critical actions:
  - retry dispatch,
  - suspend/unsuspend driver,
  - open dispute,
  - cancel order.
- Dashboard widget data correctness.

### Race-condition tests

- Simulate two accept calls on same attempt.
- Simulate accept arriving near timeout job execution.
- Assert single driver assignment and correct attempt closure.

### Financial tests

- sequence: debit -> suspension -> credit -> reactivation.
- ensure no mutable transaction edits.
- assert balance before/after correctness.

### Policy/scoping tests

- company staff cannot read/modify other company records.
- driver cannot accept another driver’s attempt.
- admin-only financial collection action enforcement.

---

## 12. Implementation phases

### Phase 1: Schema/domain foundation
- Create `Modules/Delivery` skeleton.
- Add delivery migrations, enums, models, relations.
- Add morph map and module activation wiring.
- Add delivery permissions/roles seeds.
- Add company/driver context services.

### Phase 2: Driver API
- Implement auth + middleware.
- Implement availability/location/current-offer/current-order endpoints.
- Implement notifications and disputes list endpoints.
- Add API resources and requests.
- Add feature tests for all endpoints.

### Phase 3: Dispatch/order lifecycle
- Implement order create + pricing + dispatch jobs.
- Implement assignment attempts and timeout job.
- Implement accept/reject with locks and retry.
- Implement order transition endpoints + event/timeline logging.
- Add race-condition tests.

### Phase 4: Filament company dashboard
- Add `CompanyPanelProvider`.
- Build dashboard widgets/pages/resources.
- Implement company scoping and policy checks.
- Wire operational actions (retry/cancel/dispute/suspend).
- Add panel/resource tests.

### Phase 5: Financial ledger/suspension
- Implement account + tx services.
- Hook completion/collection/dispute events to ledger.
- Implement financial suspension/reactivation paths.
- Build company financial page and admin financial actions.
- Add ledger correctness tests.

### Phase 6: Disputes/trust/notifications/reports
- Wire delivery disputes on global dispute engine.
- Implement trust scoring and recovery job.
- Add delivery notification types and sends.
- Implement reports page + report service aggregates.
- Add coverage for dispute/trust/report workflows.

### Phase 7: Tests/refactor/final integration
- Run full feature and unit coverage for new module.
- Refactor duplicate logic into reusable helpers.
- Validate no contract regressions on existing modules.
- Produce delivery API and Filament usage docs.

---

## 13. File-by-file change list (proposed)

### Create (new Delivery module + panel + tests)

- [Modules/Delivery/module.json](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/module.json)
- [Modules/Delivery/app/Providers/DeliveryServiceProvider.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Providers/DeliveryServiceProvider.php)
- [Modules/Delivery/app/Providers/RouteServiceProvider.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Providers/RouteServiceProvider.php)
- [Modules/Delivery/routes/api.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/routes/api.php)

- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_companies_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_companies_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_company_staff_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_company_staff_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_drivers_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_drivers_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_driver_locations_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_driver_locations_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_orders_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_orders_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_assignment_attempts_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_assignment_attempts_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_financial_accounts_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_financial_accounts_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_financial_transactions_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_financial_transactions_table.php)
- [Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_driver_trust_logs_table.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/database/migrations/2026_XX_XX_XXXXXX_create_delivery_driver_trust_logs_table.php)

- [Modules/Delivery/app/Models/DeliveryCompany.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryCompany.php)
- [Modules/Delivery/app/Models/DeliveryCompanyStaff.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryCompanyStaff.php)
- [Modules/Delivery/app/Models/DeliveryDriver.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryDriver.php)
- [Modules/Delivery/app/Models/DeliveryDriverLocation.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryDriverLocation.php)
- [Modules/Delivery/app/Models/DeliveryOrder.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryOrder.php)
- [Modules/Delivery/app/Models/DeliveryAssignmentAttempt.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryAssignmentAttempt.php)
- [Modules/Delivery/app/Models/DeliveryFinancialAccount.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryFinancialAccount.php)
- [Modules/Delivery/app/Models/DeliveryFinancialTransaction.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryFinancialTransaction.php)
- [Modules/Delivery/app/Models/DeliveryDriverTrustLog.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Models/DeliveryDriverTrustLog.php)

- [Modules/Delivery/app/Enums/DeliveryOrderStatus.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Enums/DeliveryOrderStatus.php)
- [Modules/Delivery/app/Enums/DeliveryDriverAvailabilityStatus.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Enums/DeliveryDriverAvailabilityStatus.php)
- [Modules/Delivery/app/Enums/DeliveryAssignmentAttemptStatus.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Enums/DeliveryAssignmentAttemptStatus.php)
- [Modules/Delivery/app/Enums/DeliveryFinancialTransactionType.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Enums/DeliveryFinancialTransactionType.php)

- [Modules/Delivery/app/Services/DeliveryOrderService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DeliveryOrderService.php)
- [Modules/Delivery/app/Services/DeliveryPricingService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DeliveryPricingService.php)
- [Modules/Delivery/app/Services/DriverDispatchService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DriverDispatchService.php)
- [Modules/Delivery/app/Services/DriverLocationService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DriverLocationService.php)
- [Modules/Delivery/app/Services/FinancialLedgerService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/FinancialLedgerService.php)
- [Modules/Delivery/app/Services/FinancialSuspensionService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/FinancialSuspensionService.php)
- [Modules/Delivery/app/Services/DriverTrustService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DriverTrustService.php)
- [Modules/Delivery/app/Services/DeliveryNotificationService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DeliveryNotificationService.php)
- [Modules/Delivery/app/Services/DeliveryReportService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DeliveryReportService.php)
- [Modules/Delivery/app/Services/DeliveryCompanyContextService.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Services/DeliveryCompanyContextService.php)

- [Modules/Delivery/app/Jobs/DispatchDeliveryOrderJob.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Jobs/DispatchDeliveryOrderJob.php)
- [Modules/Delivery/app/Jobs/ExpireAssignmentAttemptJob.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Jobs/ExpireAssignmentAttemptJob.php)
- [Modules/Delivery/app/Jobs/MarkStaleDriversOfflineJob.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Jobs/MarkStaleDriversOfflineJob.php)
- [Modules/Delivery/app/Jobs/RecoverDriverTrustScoreJob.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Jobs/RecoverDriverTrustScoreJob.php)

- [Modules/Delivery/app/Http/Middleware/EnsureDeliveryDriver.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Middleware/EnsureDeliveryDriver.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverAuthController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverAuthController.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverAvailabilityController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverLocationController.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverLocationController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverLocationController.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverOfferController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverOfferController.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverOrderController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverOrderController.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverFinancialController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverFinancialController.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverNotificationController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverNotificationController.php)
- [Modules/Delivery/app/Http/Controllers/API/Driver/DriverDisputeController.php](/C:/laragon/www/Dllni/Dllni_backend/Modules/Delivery/app/Http/Controllers/API/Driver/DriverDisputeController.php)

- [app/Providers/Filament/CompanyPanelProvider.php](/C:/laragon/www/Dllni/Dllni_backend/app/Providers/Filament/CompanyPanelProvider.php)
- [app/Filament/Company/Pages/CompanyDashboard.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Pages/CompanyDashboard.php)
- [app/Filament/Company/Widgets/DeliveryKpiStatsWidget.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Widgets/DeliveryKpiStatsWidget.php)
- [app/Filament/Company/Resources/DeliveryDrivers/DeliveryDriverResource.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Resources/DeliveryDrivers/DeliveryDriverResource.php)
- [app/Filament/Company/Resources/DeliveryOrders/DeliveryOrderResource.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Resources/DeliveryOrders/DeliveryOrderResource.php)
- [app/Filament/Company/Resources/DeliveryDisputes/DeliveryDisputeResource.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Resources/DeliveryDisputes/DeliveryDisputeResource.php)
- [app/Filament/Company/Pages/DeliveryFinancialPage.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Pages/DeliveryFinancialPage.php)
- [app/Filament/Company/Pages/DeliveryNotificationsPage.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Pages/DeliveryNotificationsPage.php)
- [app/Filament/Company/Pages/DeliveryReportsPage.php](/C:/laragon/www/Dllni/Dllni_backend/app/Filament/Company/Pages/DeliveryReportsPage.php)

- [tests/Feature/Delivery/DriverApiTest.php](/C:/laragon/www/Dllni/Dllni_backend/tests/Feature/Delivery/DriverApiTest.php)
- [tests/Feature/Delivery/DispatchFlowTest.php](/C:/laragon/www/Dllni/Dllni_backend/tests/Feature/Delivery/DispatchFlowTest.php)
- [tests/Feature/Delivery/FinancialLedgerTest.php](/C:/laragon/www/Dllni/Dllni_backend/tests/Feature/Delivery/FinancialLedgerTest.php)
- [tests/Feature/Filament/CompanyPanelAccessTest.php](/C:/laragon/www/Dllni/Dllni_backend/tests/Feature/Filament/CompanyPanelAccessTest.php)
- [tests/Unit/Delivery/DriverDispatchServiceTest.php](/C:/laragon/www/Dllni/Dllni_backend/tests/Unit/Delivery/DriverDispatchServiceTest.php)

### Update (existing files)

- [composer.json](/C:/laragon/www/Dllni/Dllni_backend/composer.json)
- [modules_statuses.json](/C:/laragon/www/Dllni/Dllni_backend/modules_statuses.json)
- [bootstrap/providers.php](/C:/laragon/www/Dllni/Dllni_backend/bootstrap/providers.php)
- [app/Models/User.php](/C:/laragon/www/Dllni/Dllni_backend/app/Models/User.php)
- [app/Enums/UserModuleType.php](/C:/laragon/www/Dllni/Dllni_backend/app/Enums/UserModuleType.php)
- [app/Enums/PermissionGroup.php](/C:/laragon/www/Dllni/Dllni_backend/app/Enums/PermissionGroup.php)
- [database/seeders/DashboardPermissionsSeeder.php](/C:/laragon/www/Dllni/Dllni_backend/database/seeders/DashboardPermissionsSeeder.php)
- [database/seeders/TeamRoleTemplatesSeeder.php](/C:/laragon/www/Dllni/Dllni_backend/database/seeders/TeamRoleTemplatesSeeder.php)
- [app/Providers/AppServiceProvider.php](/C:/laragon/www/Dllni/Dllni_backend/app/Providers/AppServiceProvider.php)
- [routes/console.php](/C:/laragon/www/Dllni/Dllni_backend/routes/console.php)
- [config/notification_types.php](/C:/laragon/www/Dllni/Dllni_backend/config/notification_types.php)
- [app/Enums/DisputeStatus.php](/C:/laragon/www/Dllni/Dllni_backend/app/Enums/DisputeStatus.php)
- [app/Http/Requests/DisputeRequest.php](/C:/laragon/www/Dllni/Dllni_backend/app/Http/Requests/DisputeRequest.php)
- [app/Http/Requests/DisputeRequests/DisputeFilterRequest.php](/C:/laragon/www/Dllni/Dllni_backend/app/Http/Requests/DisputeRequests/DisputeFilterRequest.php)
- [app/Traits/FilterQueries/DisputeFilterQuery.php](/C:/laragon/www/Dllni/Dllni_backend/app/Traits/FilterQueries/DisputeFilterQuery.php)

---

## 14. Approval checklist

- [ ] Approve creating a **new module** `Modules/Delivery` instead of extending Cleaning/Restaurant/Supermarket modules.
- [ ] Approve adding a **new Filament company panel** at `/company` via `CompanyPanelProvider`.
- [ ] Approve using **delivery-specific tables** (`delivery_*`) instead of reusing `workers`/restaurant `orders`.
- [ ] Approve reusing global `disputes`/`dispute_messages` with `booking_type=delivery_order`, and extending status support with `rejected`.
- [ ] Approve driver auth endpoint strategy under `/api/v1/delivery/driver/auth/*` (Sanctum token issuance aligned with existing login style).
- [ ] Approve dispatch defaults: stale GPS `5` min, offer timeout `30` sec, search radius `15` km, candidate cap `20`.
- [ ] Approve financial semantics: ledger-only model, due-balance suspension when `current_balance >= financial_limit`, admin-only manual collection action.
- [ ] Approve trust model defaults: start score `100`, dispute penalty and scheduled recovery, no trust-based dispatch weighting in MVP.
- [ ] Confirm whether you can share access/link for the missing Stitch project **“Filament Company Panel: Delivery Dashboard”** so UI labels/layout can be aligned exactly before implementation.
