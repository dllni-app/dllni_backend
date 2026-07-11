# Event Cleaning Booking Flow Audit Plan

## Objective

Audit the complete event-assistance cleaning booking lifecycle across:

- `Dllni_backend`
- `dllni-user-app`
- `dllni_cleaning_owner_app`

The audit must reproduce the reported `start-work` failure, separate confirmed defects from assumptions, and produce a decision-complete implementation plan without changing application code or API contracts.

The final investigation report will be written to:

```text
Dllni_backend/QA/event-cleaning-booking-flow-audit.md
```

## Restrictions

- Do not modify Laravel or Flutter production code.
- Do not create or run migrations.
- Do not rename endpoints or alter request/response contracts.
- Do not directly edit booking, assignment, security-code, or status-log rows.
- Do not reset, truncate, roll back, or delete generated test records.
- Do not run formatters that rewrite files.
- Do not commit, push, or open a pull request.
- Preserve unrelated working-tree changes, including existing owner-app changes.
- Use only `http://127.0.0.1:8000`; never call the production API.

## Verified Baseline to Investigate

The current source contains two materially different start paths:

- `CleaningBookingStartWorkController` uses an assignment-aware team start flow only when the booking requires more than one worker and assignment rows exist.
- Assignment-backed one-worker bookings fall through to `CleaningBookingService::startWork()`.
- `CleaningBookingWorkerSecurityCodeService::confirmForCustomer()` currently sets the matching assignment to `start_approved` and writes `start_approved_at` when the customer verifies the code.
- The one-worker service path rejects an assignment already in `start_approved` with `Worker has already approved the booking start.`
- The owner app exposes `StartWorkEvent` from the order-details map/verification surfaces and disables the action only while `startWorkStatus` is loading.

This is a confirmed code-level contradiction, but the audit must still establish the real HTTP sequence, database state, and whether duplicate client calls also occur before assigning the final root cause.

## Phase 1: Environment and Safety Preflight

1. Capture `git status --short` independently in all three repositories.
2. Confirm PHP, Laravel, Flutter, and Dart versions without upgrading dependencies.
3. Verify the existing Laragon MySQL service and the local API health.
4. If MySQL is stopped, start the existing service without changing database configuration.
5. If the API cannot be recovered without schema, configuration, or destructive database changes, stop live testing and document the blocker.
6. Create an audit run identifier in this form:

   ```text
   EVT-AUDIT-YYYYMMDD-HHMMSS
   ```

7. Include the run identifier in each test booking's event description or custom-service text so all generated rows remain discoverable.

## Phase 2: Flutter Application Trace

### Customer app

Trace the event-assistance flow from the occasion screens through `ClMainBloc`, `ClMainRemoteDataSource`, the order repository, and the cleaning order details/realtime coordinator.

Document:

- Estimate and creation payloads, including exact field casing.
- Order list/details endpoints and response models.
- Start-verification dialog and confirm request.
- Completion confirm, rejection, and extension actions.
- Pusher channels, normalized event names, polling, navigation callbacks, and fallback refreshes.
- Every guard that prevents or permits repeated actions.

### Cleaning worker app

Trace the order flow through `OrdersRemoteDataSource`, `OrdersBloc`, `OrderDetailsScreen`, `OrderDetailsMapBody`, `OrderDetailsVerificationBody`, `OrderDetailsMissionBody`, and `OrderLifecyclePolicy`.

Document:

- Available-booking list and details hydration.
- Accept, reject, travel, location, arrival, security-code, start-work, complete, and extension calls.
- Every `StartWorkEvent` dispatch site and its loading/request lock.
- Whether two taps can enqueue concurrent events before the loading state rebuilds.
- Whether realtime or polling invokes `start-work` or only refetches details.
- How `status`, `order_status`, `worker_order_status`, and `myAssignment.status` control each action.

## Phase 3: Laravel Backend Trace

Map the lifecycle through the user and cleaning route files and the following backend areas:

- Authentication controllers used by both Flutter apps.
- Event estimate and creation requests/services.
- Booking list/details resource serialization.
- Acceptance, rejection, team fulfillment, travel, location, arrival, security-code, start-work, completion, and extension handlers.
- `CleaningBooking`, `CleaningBookingWorkerAssignment`, both status enums, and assignment counting scopes.
- Transactions and row locks around acceptance, verification, start, and completion.
- Observer-created `booking_status_logs`, lifecycle events, broadcasts, notifications, and persisted notification rows.
- Assignment/security-code migrations and constraints.
- Pest and Playwright coverage for one-worker, multi-worker, and regular-cleaning flows.

Explicitly compare the implemented meaning of:

- Customer security-code verification.
- Worker start approval.
- `start_approved_at`.
- Assignment `start_approved` versus `in_progress`.
- Global `awaiting_start_verification`, `awaiting_worker_start_confirmation`, and `in_progress`.

## Phase 4: Live API Evidence Harness

Authenticate using the exact app endpoints and payload shapes:

- Customer: `POST /api/v1/user/login`
- Cleaning worker: `POST /api/login`

Keep bearer tokens out of the Markdown report. Record all other request headers relevant to app behavior, request bodies, response codes, and response bodies.

For every lifecycle request:

1. Record the authenticated account and worker ID, when applicable.
2. Capture database state before the request.
3. Execute the real HTTP request.
4. Capture the response without retrying automatically.
5. Capture database state after the request.
6. Fetch list and details responses when stale or inconsistent resource data is under investigation.
7. Record matching status logs, notifications, and relevant Laravel log entries.

Database snapshots must include, where applicable:

- Booking ID, booking number, property type, required worker count, and global status.
- Every assignment ID, worker ID, status, and lifecycle timestamp.
- Accepted/start-approved/remaining counts returned by the API and independently derived from assignments.
- Security-code worker ownership, attempts, expiry, consumption, and verification timestamps.
- Booking-level travel, arrival, work, completion, confirmation, rejection, and cancellation timestamps.
- Room assignments, time warnings/extensions, status logs, and notification records.

## Phase 5: Persistent Test Scenarios

Use separate bookings where branches are mutually exclusive.

### A. Creation and assignment

- Valid event-assistance estimate.
- Valid one-worker booking.
- Valid booking requiring at least two workers.
- First and final required worker acceptance.
- Duplicate acceptance by the same worker.
- Rejection before acceptance.
- Rejection after acceptance, recording whether the API permits it.

### B. Travel, arrival, and authorization

- Start travel before acceptance.
- Valid and duplicate start travel.
- Arrival before travel.
- Valid and duplicate arrival.
- Security-code retrieval by the assigned worker.
- Retrieval and lifecycle actions by an unrelated worker.

### C. Verification and start

- Incorrect customer code, followed by the correct code.
- Duplicate correct-code verification.
- Worker start before customer verification.
- One-worker start immediately after successful customer verification.
- Immediate sequential retry of `start-work`.
- Two coordinated concurrent `start-work` requests for the same worker.
- Multi-worker confirmation/start for each worker independently.
- Confirmation that global `in_progress` occurs at the intended point and not earlier.

### D. Completion branches

- Completion before `in_progress`.
- Valid and duplicate worker completion.
- Customer confirmation on one completed-work booking.
- Customer rejection on a separate completed-work booking.
- Extension request with worker acceptance on a separate booking.
- Extension request with worker rejection on another booking.
- Invalid actions after completed or cancelled states.

### E. Regression comparison

- Complete one one-worker event-assistance lifecycle.
- Complete one multi-worker event-assistance lifecycle.
- Exercise the equivalent regular-cleaning gates to identify shared-service regressions.

## Phase 6: Root-Cause Classification

Classify the reported 422 against each candidate from the original audit prompt:

1. Duplicate Flutter request.
2. Successful first request not processed by Flutter.
3. Automatic realtime-triggered request.
4. Backend state written before all validation completed.
5. Partial transaction success followed by failure.
6. Missing current-worker approval state in details.
7. UI controlled only by global status.
8. Correct non-idempotent backend response incompatible with the app flow.
9. Another worker's approval attributed to the authenticated worker.
10. Legacy single-worker logic conflicting with assignment-backed logic.

For every confirmed issue, include video, Flutter, backend, HTTP, and database evidence. Leave unmatched candidates under unconfirmed risks with the missing evidence stated explicitly.

## Phase 7: Final Report and Future Implementation Plan

Write `QA/event-cleaning-booking-flow-audit.md` using the exact 14-section structure required by the original prompt.

The recommended future fix plan must:

- Preserve endpoint URLs, methods, payloads, envelopes, and field names.
- Keep assignment state authoritative for assignment-backed bookings.
- Define customer verification and worker approval as separate transitions if live evidence confirms that intended flow.
- Unify one-worker and multi-worker start logic so both use the same transaction and row-locking rules.
- Define intentional duplicate behavior and test sequential/concurrent retries.
- Keep global status as an aggregate derived from active assignment states.
- Exclude rejected, withdrawn, cancelled, and inactive assignments from team/start/completion counts.
- Preserve legacy `worker_id` behavior only for genuine bookings without assignment rows.
- Recommend Flutter changes only for confirmed duplicate dispatch, stale hydration, or missing request locks.

## Required Automated Tests for the Later Fix

- Laravel feature test reproducing the assignment-backed one-worker 422 after customer verification.
- Laravel feature tests for each multi-worker start transition and per-worker code ownership.
- Duplicate and concurrent `start-work` tests verifying transaction safety and chosen idempotency behavior.
- Authorization tests for unrelated workers.
- Resource tests comparing list, details, action, and realtime status fields.
- Completion and extension tests for independent worker assignments.
- Regular-cleaning regression tests using the shared lifecycle services.
- Flutter BLoC/widget tests proving a start action cannot be queued twice and disappears after the worker has started or approved.

## Acceptance Criteria

- All required API cases have pass/fail results and reproducible evidence.
- One-worker and multi-worker transition matrices distinguish global and assignment states.
- Every generated booking remains in the local database and is identifiable by the audit run ID.
- The known 422 is reproduced or explicitly marked unreproduced with the exact blocking evidence.
- Confirmed defects and unconfirmed risks are separated.
- The report identifies stale or contradictory existing tests.
- The future implementation plan names the exact backend and Flutter areas likely to change without modifying them.
- No production code, contract, migration, existing data, commit, or remote repository is changed during the audit.
