## Cleaning Endpoint Flows + Playwright QA Plan (User + Owner Apps)

### Summary
- Create a new documentation file at [CLEANING_ENDPOINT_FLOWS_AND_PLAYwright_QA_PLAN.md](C:\laragon\www\Dllni\Dllni_backend\docs\CLEANING_ENDPOINT_FLOWS_AND_PLAYWRIGHT_QA_PLAN.md).
- Document backend-integrated cleaning flows for both `dllni-user-app` and `dllni_cleaning_owner_app` using backend routes/services as source of truth.
- Define an API-first Playwright QA strategy with core scenarios plus critical negative cases for end users and workers.

### Key Changes
- Add a canonical endpoint map grouped by actor:
  - User app endpoints (`/api/v1/user/cleaning/orders*` lifecycle + gates).
  - Cleaning owner app endpoints (`/api/v1/cleaning-bookings*`, time warnings, worker dashboard/profile endpoints).
- Add lifecycle flow sections with status transitions:
  - `pending -> worker_assigned -> awaiting_start_verification -> in_progress -> awaiting_customer_completion -> completed`.
  - Cancellation/rejection branches and forbidden transitions.
- Add realtime contract mapping for each stage:
  - Booking channel events (`WorkerLocationUpdated`, `WorkerArrived`, `cleaning_order.awaiting_start_verification`, `cleaning_order.awaiting_customer_completion`, `CompletionDecisionMade`, `ServiceExtensionRequested`, etc.).
  - Worker channel refresh triggers in owner app.
- Add “contract gaps/risk notes” section:
  - User app review endpoint usage (`POST /user/cleaning/orders/{id}/review`) not confirmed in current backend routes.
  - Owner app worker update route usage (`PUT /api/v1/workers/{id}`) appears outside mapped cleaning routes.
- Add explicit “Public Interfaces / Contracts” section in the doc:
  - No backend API changes are introduced by this work.
  - The doc becomes the QA contract for endpoint paths, required auth, expected statuses, and expected event names.

### Test Plan (Playwright API-first)
- Define test architecture in the markdown:
  - Use Playwright `APIRequestContext` for authenticated multi-actor flows (customer token + worker token).
  - Shared fixtures for users, booking creation, and cleanup strategy.
  - Deterministic assertions on HTTP status, response shape, status transitions, and gate conditions.
- Include core end-user scenarios:
  - Create booking from user app flow (estimate -> create).
  - Worker accept -> start travel -> location updates -> arrive.
  - User start-verification confirm with valid code.
  - Worker complete -> user completion confirm.
  - Extension flow: user extends time at completion gate and worker sees resulting state/event.
- Include critical negative scenarios:
  - Invalid/expired/wrong security code and throttle behavior.
  - User cancel blocked in disallowed states.
  - Reschedule/update blocked in `in_progress/completed/cancelled`.
  - Completion confirm/reject/extend only valid in `awaiting_customer_completion`.
  - Worker actions rejected when status preconditions are not met.
  - Channel authorization mismatch expectations (403) documented as contract checks.
- Add acceptance criteria:
  - Every documented scenario maps to exact endpoint sequence, expected status progression, and pass/fail assertions.
  - Realtime checkpoints are listed for manual or future automated event validation.

### Assumptions and Defaults
- Single combined markdown file for both apps and QA strategy.
- Documentation language is English and follows existing `Dllni_backend/docs` contract style.
- Scope is documentation + test design only (no code mutation in this phase).
- Playwright is used as backend/API QA driver (not Flutter UI automation).
