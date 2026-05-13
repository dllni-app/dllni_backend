# Cleaning Endpoint Flows and Playwright QA Plan (User + Owner Apps)

## Scope

This document defines API-first QA coverage for cleaning flows integrated by:

- `dllni-user-app` (customer side)
- `dllni_cleaning_owner_app` (worker/owner side)

It is contract-first and backend-grounded:

- Endpoint paths, status gates, and realtime event names come from backend routes/services/events.
- Flutter app endpoint usage is mapped to verify integration alignment.

## Public Interfaces / Contracts

- No backend API changes are introduced by this document.
- This file is the QA contract for:
  - endpoint paths and methods
  - auth/ownership expectations
  - status preconditions and transitions
  - realtime event names/payload expectations

## Source of Truth

- Backend user routes: `Modules/User/routes/api.php`
- Backend cleaning routes: `Modules/Cleaning/routes/api.php`
- Realtime + auth channels: `app/Providers/AppServiceProvider.php`
- Lifecycle rules:
  - `Modules/User/app/Services/UserCleaningOrderService.php`
  - `Modules/Cleaning/app/Services/CleaningBookingService.php`
- Event contracts: `Modules/Cleaning/app/Events/*`
- Flutter integrations:
  - `C:\laragon\www\Dllni\dllni-user-app\lib\features\cl_main\data\source\cl_main_remote_data_source.dart`
  - `C:\laragon\www\Dllni\dllni-user-app\lib\features\orders\data\source\orders_remote_data_source.dart`
  - `C:\laragon\www\Dllni\dllni_cleaning_owner_app\lib\features\orders\data\source\orders_remote_data_source.dart`
  - `C:\laragon\www\Dllni\dllni_cleaning_owner_app\lib\features\home\data\source\home_remote_data_source.dart`
  - `C:\laragon\www\Dllni\dllni_cleaning_owner_app\lib\features\profile\data\source\profile_remote_data_source.dart`

## API-First Playwright Defaults

- Use Playwright `APIRequestContext` with separate actors:
  - customer token
  - worker token
  - unauthenticated/wrong-role contexts
- Assert both transport and lifecycle contract:
  - HTTP status code
  - key response shape keys
  - expected booking status mutation
  - gate rejection for invalid preconditions
- For mutation endpoints, follow with `GET` validation (`/user/cleaning/orders/{id}` or `/cleaning-bookings/{id}`).
- Realtime checkpoints are listed as assertions for manual/future automation.

## User App Endpoint Map

### User matrix

| Flow | Method | Endpoint | Auth | Used by app |
| --- | --- | --- | --- | --- |
| Estimate size | POST | `/api/v1/user/cleaning/orders/estimate-size` | user | backend route (available) |
| Estimate price | POST | `/api/v1/user/cleaning/orders/estimate-price` | user | yes |
| Previous workers | GET | `/api/v1/user/cleaning/orders/previous-workers` | user | yes |
| Create order | POST | `/api/v1/user/cleaning/orders` | user | yes |
| List orders | GET | `/api/v1/user/cleaning/orders` | user | yes |
| Show order | GET | `/api/v1/user/cleaning/orders/{order}` | user | yes |
| Patch order | PATCH | `/api/v1/user/cleaning/orders/{order}` | user | yes |
| Cancel order | POST | `/api/v1/user/cleaning/orders/{order}/cancel` | user | yes |
| Confirm start verification | POST | `/api/v1/user/cleaning/orders/{order}/start-verification/confirm` | user | yes |
| Confirm completion | POST | `/api/v1/user/cleaning/orders/{order}/completion/confirm` | user | yes |
| Reject completion | POST | `/api/v1/user/cleaning/orders/{order}/completion/reject` | user | yes |
| Extend completion time | POST | `/api/v1/user/cleaning/orders/{order}/completion/extend-time` | user | yes |
| Submit review | POST | `/api/v1/user/cleaning/orders/{order}/review` | user | called by app, route missing in backend |

### User journey order (happy path)

1. Estimate (`estimate-price` and optionally `estimate-size`).
2. Create (`POST /cleaning/orders`) with `termsAccepted=true`.
3. Worker handles booking on worker app until arrival.
4. Customer confirms start code (`start-verification/confirm`) -> status `in_progress`.
5. Worker marks complete -> status `awaiting_customer_completion`.
6. Customer chooses one completion action:
   - confirm -> `completed`
   - reject -> `in_progress`
   - extend -> `time_extension_requested`

## Cleaning Owner App Endpoint Map

### Worker/owner matrix (cleaning app)

| Flow | Method | Endpoint | Auth | Used by app |
| --- | --- | --- | --- | --- |
| Worker homepage | GET | `/api/v1/cleaning/worker/homepage` | worker token | yes |
| Worker profile | GET | `/api/v1/cleaning/worker/profile` | worker token | yes |
| Worker statistics | GET | `/api/v1/cleaning/worker/statistics` | worker token | yes |
| Worker profile update | PUT | `/api/v1/cleaning/worker/account/profile` | worker token | yes |
| Worker work areas | PUT | `/api/v1/cleaning/worker/account/work-areas` | worker token | yes |
| Booking list | GET | `/api/v1/cleaning-bookings` | worker token | yes |
| Booking details | GET | `/api/v1/cleaning-bookings/{id}` | worker token | yes |
| Accept booking | POST | `/api/v1/cleaning-bookings/{id}/accept` | worker token | yes |
| Reject booking | POST | `/api/v1/cleaning-bookings/{id}/reject` | worker token | yes |
| Start travel | POST | `/api/v1/cleaning-bookings/{id}/start-travel` | worker token | yes |
| Post location | POST | `/api/v1/cleaning-bookings/{id}/location` | worker token | yes |
| Arrive | POST | `/api/v1/cleaning-bookings/{id}/arrive` | worker token | yes |
| Get security code | GET | `/api/v1/cleaning-bookings/{id}/security-code` | worker token | yes |
| Start work | POST | `/api/v1/cleaning-bookings/{id}/start-work` | worker token | yes |
| Complete booking | POST | `/api/v1/cleaning-bookings/{id}/complete` | worker token | yes |
| Cancel booking | POST | `/api/v1/cleaning-bookings/{id}/cancel` | worker token | yes |
| Extension requests list | GET | `/api/v1/cleaning-time-warnings` | worker token | yes |
| Accept extension | POST | `/api/v1/cleaning-time-warnings/{id}/accept` | worker token | yes |
| Reject extension | POST | `/api/v1/cleaning-time-warnings/{id}/reject` | worker token | yes |
| Availability update | PUT | `/api/v1/workers/{id}` | worker token | yes (outside cleaning module routes) |

## Lifecycle Flows and Status Transitions

### Canonical main path

`pending -> worker_assigned -> awaiting_start_verification -> in_progress -> awaiting_customer_completion -> completed`

### Worker-side transitions

- Accept:
  - `pending -> worker_assigned`
  - rejects if status is not `pending`
- Start travel:
  - allowed only at `worker_assigned`
  - sets `started_travel_at`
- Location updates:
  - allowed only at `worker_assigned` and after `started_travel_at` is set
- Arrive:
  - allowed at `worker_assigned` (or repeated at `awaiting_start_verification`)
  - requires travel started
  - sets `arrived_at`, moves to `awaiting_start_verification`
- Start work:
  - from `awaiting_start_verification`, requires consumed security code
  - legacy direct start from `worker_assigned` is still accepted
  - status becomes `in_progress`
- Complete:
  - allowed only at `in_progress`
  - `in_progress -> awaiting_customer_completion`
- Worker cancel:
  - allowed only at `worker_assigned` or `in_progress`
  - -> `cancelled`
- Worker reject:
  - allowed at `pending` or `worker_assigned`
  - -> `cancelled`

### Customer-side transitions

- Cancel:
  - allowed only at `pending` or `worker_assigned`
  - -> `cancelled`
- Update/patch:
  - blocked when status is `in_progress`, `completed`, or `cancelled`
- Start verification confirm:
  - allowed only at `awaiting_start_verification`
  - -> `in_progress`
- Completion confirm:
  - allowed only at `awaiting_customer_completion`
  - -> `completed`
- Completion reject:
  - allowed only at `awaiting_customer_completion`
  - -> `in_progress` and clears `work_finished_at`
- Completion extend-time:
  - allowed only at `awaiting_customer_completion`
  - -> `time_extension_requested`

### Forbidden transition examples (must assert negative)

- `start-travel` before accept.
- `location` before `start-travel`.
- `arrive` before `start-travel`.
- `complete` before `in_progress`.
- customer completion actions outside `awaiting_customer_completion`.
- user cancel when already `in_progress`/`completed`/`cancelled`.

## Realtime Contract Mapping

### Channels and auth

- Booking channel: `private-cleaning-booking.{bookingId}`
- Worker channel: `private-cleaning-worker.{workerId}`
- Auth endpoint: `POST /broadcasting/auth`
- Expected authorization failures for non-member/non-assigned actors: `403`

### Stage-to-event map

| Stage/action | Event(s) |
| --- | --- |
| Worker location update | `WorkerLocationUpdated` |
| Worker arrive | `WorkerArrived`, `cleaning_order.awaiting_start_verification`, `CleaningBookingTrackingUpdated` |
| Customer verifies start code | `ArrivalVerified`, `CleaningBookingTrackingUpdated` |
| Worker completes work | `cleaning_order.awaiting_customer_completion`, `CleaningBookingTrackingUpdated` |
| Customer completion decision | `CompletionDecisionMade`, `CleaningBookingTrackingUpdated` |
| Extension warning row created | `ServiceExtensionRequested` |
| Most status/time mutations | `CleaningBookingTrackingUpdated` |

### Owner app refresh triggers (current app behavior)

- Worker-channel listener in owner app refreshes order list when:
  - `ArrivalVerified`
  - `CompletionDecisionMade`
  - `ServiceExtensionRequested`
- Booking-channel listener in order details screen triggers details sync for all booking events and opens extension sheet on `ServiceExtensionRequested`.

## Contract Gaps / Risk Notes

1. User review endpoint gap:
   - User app calls `POST /api/v1/user/cleaning/orders/{id}/review`.
   - This route is not present in `Modules/User/routes/api.php`.
2. Worker update route out-of-module:
   - Owner app uses `PUT /api/v1/workers/{id}`.
   - This exists in root `routes/api.php`, outside cleaning module route grouping.
3. Status filter gate mismatch:
   - Filter validators for cleaning bookings only allow: `pending,worker_assigned,in_progress,completed,cancelled`.
   - Lifecycle includes `awaiting_start_verification`, `awaiting_customer_completion`, `time_extension_requested`.
4. Extension event path ambiguity:
   - `ServiceExtensionRequested` is dispatched on `CleaningTimeWarning` creation.
   - Customer `completion/extend-time` path sets booking status and dispatches `CompletionDecisionMade`, but does not create `CleaningTimeWarning` row directly in current service code.
5. Extension list scoping risk:
   - Owner app currently requests `/api/v1/cleaning-time-warnings` without `filter[forCurrentWorker]=1` or `filter[pending]=1`.
   - Contract tests should enforce expected scoping behavior.

## Playwright Test Interface Contract

Use these environment variables for deterministic API-first runs:

- `API_BASE_URL`
- `QA_USER_PHONE`
- `QA_USER_PASSWORD`
- `QA_WORKER_PHONE`
- `QA_WORKER_PASSWORD`
- `QA_TIMEOUT_MS`

Recommended suite structure:

- `playwright.config.ts`
- `tests/playwright/fixtures/auth.ts`
- `tests/playwright/helpers/cleaning-api-client.ts`
- `tests/playwright/specs/cleaning-user-flows.spec.ts`
- `tests/playwright/specs/cleaning-owner-flows.spec.ts`
- `tests/playwright/specs/cleaning-cross-app-lifecycle.spec.ts`

## Playwright Scenario Catalog (API-first)

### Core user scenarios

| ID | Scenario | Endpoint sequence | Key assertions |
| --- | --- | --- | --- |
| U-CL-01 | Estimate then create booking | `POST /estimate-price` -> `POST /cleaning/orders` | 200/201; created booking in `pending`; expected price fields exist |
| U-CL-02 | List + show own bookings | `GET /cleaning/orders` -> `GET /cleaning/orders/{id}` | 200; booking belongs to customer; expected relations shape present |
| U-CL-03 | Confirm start verification | pre-step owner `arrive` + `security-code`; then `POST /start-verification/confirm` | 200; status becomes `in_progress`; tracking event checkpoint listed |
| U-CL-04 | Completion confirm | owner `complete`; then `POST /completion/confirm` | 200; status becomes `completed`; decision event checkpoint listed |
| U-CL-05 | Completion reject | owner `complete`; then `POST /completion/reject` | 200; status returns `in_progress`; `workFinishedAt` cleared |
| U-CL-06 | Completion extend time | owner `complete`; then `POST /completion/extend-time` | 200; status `time_extension_requested`; decision event checkpoint listed |
| U-CL-07 | Patch schedule in allowed status | `PATCH /cleaning/orders/{id}` while `pending`/`worker_assigned` | 200; changed schedule persisted |

### Core owner scenarios

| ID | Scenario | Endpoint sequence | Key assertions |
| --- | --- | --- | --- |
| O-CL-01 | Accept pending booking | `POST /cleaning-bookings/{id}/accept` | 200; status `worker_assigned`; worker assigned |
| O-CL-02 | Start travel + location | `POST /start-travel` -> `POST /location` | 200; tracking timestamp set; location endpoint returns `{ok:true}` |
| O-CL-03 | Arrive and gate emit | `POST /arrive` | 200; status `awaiting_start_verification`; awaiting-start event checkpoint listed |
| O-CL-04 | Start work after verification | user verifies code, then worker `POST /start-work` | 200; status `in_progress` |
| O-CL-05 | Complete booking | `POST /complete` | 200; status `awaiting_customer_completion`; completion gate event checkpoint listed |
| O-CL-06 | Extension response flow | `GET /cleaning-time-warnings` -> `POST /accept|reject` | 200; worker response persisted; list refreshable |
| O-CL-07 | Worker dashboard/profile health | homepage/profile/statistics/work-areas endpoints | 200; required keys and stable types present |

### Critical negative scenarios

| ID | Scenario | Expected contract |
| --- | --- | --- |
| N-CL-01 | Invalid/wrong start verification code | 422 validation/business error |
| N-CL-02 | Expired code | 422 with code-expired message shape |
| N-CL-03 | Code brute-force throttle | 429 after repeated failures |
| N-CL-04 | User cancel in disallowed status | 422 gate rejection |
| N-CL-05 | User patch in `in_progress/completed/cancelled` | 422 gate rejection |
| N-CL-06 | Completion actions outside `awaiting_customer_completion` | 422 gate rejection |
| N-CL-07 | Worker action precondition mismatch (`arrive` before travel, `complete` before in-progress, etc.) | 422 gate rejection |
| N-CL-08 | Channel auth mismatch (non-owner/non-worker) | 403 from `/broadcasting/auth` |
| N-CL-09 | Owner uses unsupported filter status values | 422 validation for filter status mismatch |

### Cross-app integrated scenario

| ID | Scenario | Endpoint sequence | Assertions |
| --- | --- | --- | --- |
| X-CL-01 | End-to-end booking lifecycle with both actors | user create -> worker accept/travel/location/arrive -> user verify start -> worker complete -> user completion decision | Every step returns expected status and follows lifecycle graph with no forbidden transition |

## Acceptance Criteria

- Every documented scenario maps to exact endpoint sequence and expected status progression.
- Core lifecycle statuses are asserted in order for at least one full happy-path test.
- Negative gate scenarios assert rejection status and no unintended status mutation.
- Realtime checkpoints are explicitly listed per stage for manual/future automated validation.
- Contract gaps are tracked as known risks, not silently ignored.

