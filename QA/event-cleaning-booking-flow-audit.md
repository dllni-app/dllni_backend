# Event Cleaning Booking Flow Audit

## 1. Audit metadata and outcome

- Audit run: `EVT-AUDIT-20260711-201238`
- Date: 2026-07-11
- Local API: `http://127.0.0.1:8000`
- Database: local `dllni-pro-test` only; production API was not called.
- Generated bookings: `58` (`CLN-USER-XFOSNQIZ`) and `59` (`CLN-USER-2F4MHAX7`). Both contain the run ID in the event custom-service or special-requirement data.
- Outcome: the reported one-worker `start-work` 422 was reproduced exactly. A separate multi-worker lifecycle completed successfully, but exposed an early global completion state.
- No Laravel or Flutter production code, routes, migrations, contracts, existing booking rows, commits, or remote repositories were changed.

## 2. Scope, restrictions, and evidence method

The audit covered `Dllni_backend`, `dllni-user-app`, and `dllni_cleaning_owner_app`, with source tracing followed by local HTTP and database evidence. Bearer tokens were used only in memory and are intentionally absent from this report.

The audit did not run migrations, seeders, formatters, destructive SQL, direct booking/assignment/status-log edits, or production requests. Generated test records were retained. The two local server processes and the existing Laragon MySQL instance were started only to make the named local audit surface available.

## 3. Environment and safety preflight

| Surface | Result |
|---|---|
| Backend worktree | Clean except the supplied untracked plan before this report was added |
| Customer app worktree | Clean |
| Cleaning owner app worktree | Clean |
| PHP | 8.4.14 |
| Laravel | 12.58.0 |
| Flutter / Dart | Flutter 3.44.2 / Dart 3.12.2 |
| Local database | MySQL 8.4.7, `dllni-pro-test`, 160 tables; read-only checks found 70 users and 54 bookings before generated rows |
| API health | Initially hung because the configured database was unavailable; after starting the existing MySQL 8.4 data directory, `GET /` returned HTTP 200 |
| Schema changes | None; migrations were not run |

The older MySQL 5.7 data directory was present but did not contain `dllni-pro-test`. The existing MySQL 8.4 Laragon data directory did contain the configured database, so the local listener was switched without changing `.env` or schema.

## 4. Customer application trace

Creation and estimation are implemented in `lib/features/cl_main/data/source/cl_main_remote_data_source.dart:22-73` and call:

- `POST /api/v1/user/cleaning/orders/estimate-price`
- `POST /api/v1/user/cleaning/orders`

`CreateCleaningOrderParams.eventAssistance` sends `propertyType: event_assistance`, event fields inside `propertyDetails`, `assignmentMode`, `numberOfWorkers`, `addressId`, schedule, gender preference, and terms. The event custom-service text was the run-ID carrier for this audit.

List/details/action calls are mapped in `lib/features/orders/data/source/orders_remote_data_source.dart:57-146`:

- `GET /api/v1/user/cleaning/orders`
- `GET /api/v1/user/cleaning/orders/{id}`
- `POST /start-verification/confirm`
- `POST /completion/confirm`
- `POST /completion/reject`
- `POST /completion/extend-time`

`CleaningOrderDetailsScreen` opens the verification and completion gates from the hydrated details status. It subscribes to `private-cleaning-booking.{id}`, normalizes realtime event names, refreshes details after lifecycle events, and runs a 10-second polling fallback (`cleaning_order_details_screen.dart:1566-1628,1778-1809`). Realtime and polling refresh details; they do not call worker `start-work`.

The customer completion use case sends an empty body (`confirm_cleaning_completion_use_case.dart:26-32`). The backend can target a worker or assignment, but the current customer app does not send those optional target fields. For multi-worker completion, repeated confirmation calls therefore select the next pending assignment in backend order.

## 5. Cleaning worker application trace

`lib/features/orders/data/source/orders_remote_data_source.dart:45-173` maps worker actions to:

- `GET /api/v1/cleaning-bookings`
- `GET /api/v1/cleaning-bookings/{id}`
- `POST /accept`, `/reject`, `/start-travel`, `/arrive`, `/location`, `/security-code`, `/start-work`, `/complete`
- `GET /api/v1/cleaning-time-warnings` and worker extension accept/reject endpoints

`OrderDetailsMapBody` and `OrderDetailsVerificationBody` are the two `StartWorkEvent` dispatch sites (`order_details_map_body.dart:406-449`, `order_details_verification_body.dart:330-355`). Both disable the visible action while the global `startWorkStatus` is loading. The BLoC registration is `on<StartWorkEvent>(_startWork)` with no droppable/sequential transformer (`orders_bloc.dart:97-121`), and `_startWork` immediately emits loading, awaits the network request, then emits success or failure (`orders_bloc.dart:788-820`). There is no per-booking in-flight map or state check before event dispatch.

The owner app has Pusher listeners and a details poller for status hydration, but the source trace found no realtime callback that dispatches `StartWorkEvent`; realtime synchronizes details instead.

`OrderLifecyclePolicy` uses both global `status` and current-worker `workerOrderStatus` / `myAssignment.status`. However, the start button's request lock is global and transient, so two rapid events can be queued before the loading rebuild is observed.

## 6. Laravel route and backend trace

The worker routes are in `Modules/Cleaning/routes/api.php:87-106`; the customer routes are in `Modules/User/routes/api.php:236-250`. The route list confirmed all 19 worker cleaning-booking routes, including `start-work`, `security-code`, and `complete`.

Authentication evidence:

- Customer: `POST /api/v1/user/login`, phone/password, `Modules/User/app/Http/Controllers/API/LoginController.php:22-57`.
- Worker: `POST /api/login`, phone/password/module (`module=cleaning_worker`), `app/Http/Controllers/API/UserAuthController.php:24-50` and `app/Http/Requests/Auth/UserLoginRequest.php:24-37`.

Acceptance uses `CleaningBookingTeamService::acceptWorker()` in a transaction and creates one unique assignment per booking/worker (`CleaningBookingTeamService.php:95-156`). Travel, arrival, security-code issue, verification, worker start, completion, and customer completion all use booking/assignment row locks in their relevant transaction paths.

The critical split is in `CleaningBookingStartWorkController.php:32-40,70-74`: team start is selected only when `number_of_workers > 1` and assignment rows exist. An assignment-backed one-worker booking therefore falls through to `CleaningBookingService::startWork()`.

Customer code verification in `CleaningBookingWorkerSecurityCodeService.php:118-147` marks the matching assignment `start_approved` and sets `start_approved_at`; it also consumes the code and sets the global status from `resolveBookingStatusAfterVerification()`.

The legacy one-worker service path in `CleaningBookingService.php:383-450` checks the consumed code, finds the assignment, and explicitly rejects an assignment already in `StartApproved` with `Worker has already approved the booking start.` The same method also contains the legacy no-assignment path.

Status changes are persisted by `CleaningBookingObserver.php:51-100` into `booking_status_logs`. Lifecycle notifications and broadcasts are emitted by the observer and action services/events. Assignment rows have a unique `(cleaning_booking_id, worker_id)` key and lifecycle indexes; security codes have booking/worker indexes, attempts, expiry, consumption, and optional worker ownership.

## 7. Implemented state and transition matrix

| Transition | Global booking state | Assignment state | Implemented meaning |
|---|---|---|---|
| Worker accepts first team slot | `pending` | `accepted_waiting_for_order_start` | Worker committed, team not fulfilled |
| Final worker accepts | `worker_assigned` | accepted for each worker | Team fulfilled |
| Worker arrives | `awaiting_start_verification` | `awaiting_start_verification` | Customer code is requested |
| Customer verifies one code | `awaiting_worker_start_confirmation` for one-worker; remains waiting for team in multi-worker | `start_approved` for matching worker | Customer verification currently also writes worker approval |
| Worker starts team assignment | `awaiting_worker_start_confirmation` until all workers | `in_progress` for current worker | Assignment start is independent |
| All team assignments started | `in_progress` | `in_progress` | Global work begins |
| First team worker completes | `awaiting_customer_completion` | one assignment awaiting, another may still be `in_progress` | Confirmed early global completion state |
| Customer confirms pending assignment | remains waiting until no pending assignments; then `completed` | selected assignment `completed` | Current app sends no target, so backend selects the next pending assignment |

The resource exposes both global and worker-specific data: `status`, `globalStatus`, `order_status`, `worker_order_status`, `workerAcceptance`, `workerAssignments`, and `myAssignment` (`CleaningBookingResource.php:50-148`).

## 8. Live harness and database evidence

Accounts used were the local seeded customer user ID `2`, worker ID `1`, and event worker ID `3`. Tokens are omitted. Each lifecycle request was issued once unless a duplicate/retry case was explicitly under test. Database snapshots were read before and after relevant requests.

Booking `58` was created as one-worker event assistance. Its persisted property details contain the run ID, event type, guest count, venue type, custom service, hours, and audit note. Booking `59` was created as two-worker event assistance and contains the same run ID in its custom service and special requirement.

The local database after the audit retained both rows and all assignment/security-code/status-log rows. No row was deleted or directly edited.

## 9. Scenario results

### One-worker booking `58`

| Step | Result |
|---|---|
| Creation | HTTP 201; `pending`; one worker required |
| Worker 1 acceptance | HTTP 200; `worker_assigned`; assignment 29 created |
| Start travel | HTTP 200 |
| Arrival | HTTP 200; global `awaiting_start_verification` |
| Security-code retrieval | HTTP 200; worker-scoped code record 18 |
| Customer verification | HTTP 200; global `awaiting_worker_start_confirmation`; assignment 29 became `start_approved` |
| Worker `start-work` | HTTP 422; exact reproduced failure |
| Two coordinated retry requests | both HTTP 422 with the same message |
| Final state | unchanged: global `awaiting_worker_start_confirmation`, assignment `start_approved`, `work_started_at` null |

### Multi-worker booking `59`

| Step | Result |
|---|---|
| Creation | HTTP 201; `pending`; two workers required |
| Worker 1 then worker 3 acceptance | HTTP 200 each; final global `worker_assigned`; assignments 30 and 31 |
| Travel and arrival for both | HTTP 200 each; global `awaiting_start_verification` |
| Security-code retrieval | two distinct worker-owned codes; records 19 and 20 |
| Customer verification for each code | HTTP 200 each; `start_approved_workers_count` moved 1 then 2 |
| Worker 1 start | HTTP 200; worker assignment `in_progress`; global still awaiting other worker |
| Worker 3 start | HTTP 200; global `in_progress` |
| Worker 1 completion | HTTP 200, but global changed to `awaiting_customer_completion` while worker 3 was still `in_progress` |
| Worker 3 completion | HTTP 200; both assignments awaiting customer completion |
| Customer completion confirmation | first empty-body call completed assignment 30; second empty-body call completed assignment 31 and global booking |
| Duplicate customer confirmation after completed | HTTP 422; `Order is not waiting for completion confirmation.` |
| Final state | global and both assignments `completed`; status log recorded `in_progress -> awaiting_customer_completion -> completed` |

Not executed live: rejection-before/after-acceptance, invalid/duplicate travel and arrival, unrelated-worker authorization, extension accept/reject, regular-cleaning end-to-end, and a true database-level concurrent request against a multi-worker web server. They remain explicit follow-up cases in the implementation test plan.

## 10. Reproduction of the reported `start-work` failure

The decisive evidence for booking `58` was:

1. Before customer verification: booking `awaiting_start_verification`; assignment 29 `awaiting_start_verification`; security code unconsumed.
2. Customer verified the correct worker-owned code once: HTTP 200; booking `awaiting_worker_start_confirmation`; assignment 29 `start_approved`; `start_approved_at` set; code consumed.
3. Worker called `POST /api/v1/cleaning-bookings/58/start-work` once: HTTP 422 with `Worker has already approved the booking start.`
4. After the failure: assignment remained `start_approved`; `work_started_at` remained null; no transition to `in_progress` was recorded.
5. Two coordinated retry requests produced the same HTTP 422 and message.

This is not a stale-resource-only symptom. The database and action response agree on the contradictory state, and the backend source shows why the request is rejected.

## 11. Root-cause classification

| Candidate | Classification | Evidence |
|---|---|---|
| 1. Duplicate Flutter request | Unconfirmed contributing risk | Two rapid dispatch sites and no BLoC request transformer; live retries reproduce the same backend rejection, but the first request alone already fails. |
| 2. First request succeeded but Flutter did not process it | Not supported for booking 58 | HTTP response was 422 and database state remained `start_approved`, not `in_progress`. |
| 3. Automatic realtime-triggered request | Not supported | Customer realtime handler refreshes details; no start-work dispatch was found. |
| 4. Backend state written before validation completed | Confirmed semantic defect | Customer verification writes `start_approved` before the worker start service, while that service treats `start_approved` as an already-completed action. |
| 5. Partial transaction success followed by failure | Not supported for reproduced request | The start transaction rolled back/no additional start fields were written; the pre-existing approval came from verification. |
| 6. Missing current-worker approval in details | Not primary cause | Worker details returned `myAssignment.status=start_approved` and `worker_order_status=start_approved`. |
| 7. UI controlled only by global status | Confirmed UI risk | Button visibility is primarily status-driven and loading is global; worker-specific state is available but does not prevent event dispatch. |
| 8. Correct non-idempotent backend response incompatible with app flow | Confirmed compatibility defect | A valid sequential customer-verification then worker-start flow returns a non-actionable 422 because the two endpoints disagree on the meaning of `start_approved`. |
| 9. Another worker approval attributed to current worker | Not supported | Booking 58 had one assignment; booking 59 used worker-scoped codes and each code updated its matching worker. |
| 10. Legacy single-worker logic conflicting with assignment-backed logic | Confirmed root cause | Controller branch selection sends assignment-backed one-worker bookings to the legacy `CleaningBookingService::startWork()` path. |

## 12. Confirmed defects and unconfirmed risks

### Confirmed defects

1. Assignment-backed one-worker start contradiction: customer verification writes `start_approved`, then the one-worker start service rejects `start_approved` instead of moving the assignment to `in_progress`.
2. Global multi-worker completion is promoted too early: `resolveBookingStatusAfterWorkerCompletion()` returns `awaiting_customer_completion` when any active assignment is awaiting customer confirmation, even if another active assignment is still `in_progress`.
3. Multi-worker customer completion is assignment-by-assignment in the backend, but the current customer use case sends an empty body and has no explicit assignment target. It works only through repeated calls selecting the next pending assignment.
4. Worker start dispatch has no per-booking BLoC in-flight guard or event transformer, leaving a rapid-tap duplicate risk even though the button disables after rebuild.

### Unconfirmed risks

- A production-device duplicate tap may create concurrent requests before the loading state is rendered; the current local PHP server serialized the coordinated retry requests, so database-level concurrency remains unverified.
- Realtime authorization, Pusher delivery, and notification timing were not validated against an external Pusher session.
- Rejection, extension, unrelated-worker authorization, and regular-cleaning regression paths need a separate run because the audit was stopped after the decisive one-worker and multi-worker evidence was obtained.

## 13. Existing automated coverage and gaps

Existing coverage includes:

- `tests/Feature/Cleaning/CleaningBookingTeamLifecycleTest.php` for multi-worker start/completion state changes.
- `tests/Feature/Cleaning/CleaningBookingMultiWorkerFlowTest.php` for team acceptance and per-worker start approval counts.
- `tests/Feature/Cleaning/UserCleaningOrderRealtimeActionsTest.php` for customer verification and completion/rejection/extension event behavior.
- `tests/Feature/Cleaning/CleaningBookingWorkerActionsTest.php` for legacy worker action and security-code cases.
- `tests/Feature/Cleaning/CleaningEventBookingVisibilityTest.php` and `CleaningBookingEndpointsTest.php` for event-assistance visibility and resource shape.

The important gap is a feature test that creates an assignment-backed one-worker event booking, runs customer verification, calls `start-work`, and asserts the intended transition. No focused concurrent `start-work` test was found. Existing multi-worker tests exercise direct assignment fixtures and do not cover the controller's one-worker branch predicate. Customer completion tests also need explicit multi-assignment target/retry assertions and global aggregate assertions.

The test suite was not executed because the plan forbids running migrations and the configured test setup uses database-backed feature fixtures. No test result is represented as passing unless it was observed through the live HTTP harness above.

## 14. Future implementation plan and acceptance criteria

The later fix should preserve all current endpoint URLs, methods, payload names, response envelopes, and Flutter-facing fields.

1. Make assignment state authoritative whenever assignment rows exist, including `number_of_workers=1`; route one-worker assignment-backed starts through the same transaction/row-lock path as team starts.
2. Separate customer code verification from worker start approval in the transition rules. Customer verification should make the assignment eligible/verified; worker `start-work` should be the transition to assignment `in_progress`, with deliberate duplicate behavior.
3. Keep the legacy `worker_id` path only when no assignment row exists.
4. Define duplicate behavior explicitly: sequential and concurrent `start-work` retries should either return the current successful resource idempotently or return a documented conflict; they must not reject a valid already-approved transition as an unrelated invalid action.
5. Recompute global status from active assignments only. Exclude rejected, withdrawn, cancelled, and inactive assignments from acceptance, start, completion, and team counts. Do not enter global `awaiting_customer_completion` until the aggregate rule for all required active assignments is satisfied.
6. Preserve per-worker resource fields (`myAssignment`, `worker_order_status`, assignment timestamps, counts) and add no frontend-only replacement state.
7. Add a Flutter BLoC request lock or droppable transformer scoped by booking ID; make both start-action widgets disappear or become non-actionable from the current worker assignment state after approval/start.
8. Add the required Laravel tests: assignment-backed one-worker reproduction, multi-worker per-worker start, code ownership, sequential/concurrent duplicate start, unrelated-worker authorization, list/details/action/realtime resource parity, independent completion/extension decisions, and regular-cleaning shared-service regression.
9. Add Flutter BLoC/widget coverage proving two start events cannot be queued for one booking and that the action is removed after `start_approved` or `in_progress`.

Implementation acceptance should require: booking 58's reproduced sequence reaches `in_progress`; booking 59 remains globally `in_progress` until all required assignments start; global completion waits for all active assignments; duplicate behavior is intentional and tested; list/details/action/realtime fields agree; unrelated workers remain unauthorized; and no legacy no-assignment behavior regresses.
