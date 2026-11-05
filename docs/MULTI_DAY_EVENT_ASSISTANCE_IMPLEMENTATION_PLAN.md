# Multi-Day Event Assistance Implementation Plan

## 1. Scope

Implement multi-day scheduling for `propertyType=event_assistance` across:

- Backend: `dllni-app/dllni_backend`
- User app: `dllni-app/dllni-user-app`
- Worker app: `dllni-app/dllni_cleaning_owner_app`
- Filament cleaning dashboard in the backend repository

The change must remain backward compatible with existing single-day cleaning and event-assistance requests.

## 2. Phase-1 Product Decisions

1. A multi-day event is still **one `CleaningBooking`** for the customer and business.
2. Each selected day is an independent **booking session**.
3. Accepting the booking commits a worker to **all active future sessions**.
4. The same required worker team is used for all sessions in Phase 1.
5. Travel, arrival, verification, start, completion, extension, cancellation, and financial snapshots are session-scoped.
6. The parent booking is completed only after all required non-cancelled sessions are completed.
7. Review remains one parent-level review after the whole booking completes.
8. Travel fee is calculated per session/visit.
9. Cancellation fees apply only to cancelled future sessions.
10. Existing single-day API fields remain as compatibility fields.

## 3. Current Single-Day Dependencies

The current flow assumes one `scheduled_date`, `scheduled_time`, and `total_hours` per booking. The main areas that must be refactored are:

### Backend

- `Modules/User/app/Http/Requests/UserCleaningOrderStoreRequest.php`
- `Modules/User/app/Http/Requests/UserCleaningOrderUpdateRequest.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/User/app/Services/UserCleaningOrderEstimationService.php`
- `Modules/User/app/Http/Controllers/API/UserCleaningPreviousWorkersController.php`
- `Modules/Cleaning/app/Models/CleaningBooking.php`
- `Modules/Cleaning/app/Models/CleaningBookingWorkerAssignment.php`
- `Modules/Cleaning/app/Services/WorkerBookingScheduleConflictService.php`
- `Modules/Cleaning/app/Services/CleaningBookingService.php`
- `Modules/Cleaning/app/Services/CleaningBookingTeamService.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
- `Modules/Cleaning/app/Http/Controllers/API/WorkerHomepageController.php`

### User Flutter app

- `lib/features/cl_main/domain/usecases/create_cleaning_order_use_case.dart`
- `lib/features/cl_main/domain/usecases/estimate_cleaning_price_use_case.dart`
- `lib/features/cl_main/view/screens/cl_main_occasion_schedule_screen.dart`
- `lib/features/cl_main/view/data/cl_main_route_args.dart`
- `lib/features/orders/data/models/cleaning_orders_api_models.dart`
- `lib/features/orders/view/widgets/cleaning_order_card.dart`
- `lib/features/orders/view/screens/cleaning_order_details_screen.dart`
- `lib/features/orders/view/screens/cleaning_order_reschedule_screen.dart`

### Worker Flutter app

- `lib/features/orders/data/models/fetch_orders_usecase_model.dart`
- `lib/core/widgets/order_card.dart`
- `lib/features/orders/view/widgets/order_info_card.dart`
- `lib/features/orders/view/widgets/order_details/order_details_body.dart`
- `lib/features/orders/view/widgets/order_details/order_details_mission_body.dart`
- `lib/features/orders/view/widgets/accept_order_bottom_sheet.dart`
- `lib/features/calender/view/screens/calender_screen.dart`
- `lib/features/calender/view/widgets/week_calender.dart`
- `lib/features/calender/view/widgets/calender_order_card.dart`

### Filament

- `app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php`
- `app/Filament/Resources/CleaningBookings/Schemas/CleaningBookingInfolist.php`
- Cleaning booking widgets/statistics that filter directly by parent `scheduled_date`

## 4. Database Design

### 4.1 Parent Booking

Keep `cleaning_bookings` as the aggregate root. For multi-day events, parent schedule fields become compatibility/aggregate values:

- `scheduled_date`: first session date
- `scheduled_time`: first session time
- `estimated_hours` / `total_hours`: sum of session durations
- pricing fields: sum of session pricing
- `status`: aggregate booking state

Sessions are the operational source of truth.

### 4.2 `cleaning_booking_sessions`

Add a new table with at least:

```text
id
cleaning_booking_id
sequence
scheduled_date
scheduled_time
duration_hours
status
base_price
travel_fee
travel_distance_km
admin_margin_amount
extension_fee_total
cancellation_fee
total_price
is_pricing_final
started_travel_at
arrived_at
customer_confirmed_at
work_started_at
work_finished_at
cancelled_at
cancellation_reason
cancelled_by_role
created_at
updated_at
```

Indexes:

```text
UNIQUE(cleaning_booking_id, sequence)
INDEX(scheduled_date, status)
INDEX(cleaning_booking_id, status)
```

### 4.3 `cleaning_booking_session_worker_assignments`

Keep `cleaning_booking_worker_assignments` as the parent commitment/team assignment and compatibility financial aggregate. Add a session assignment table for operational state and daily financial snapshots:

```text
id
cleaning_booking_session_id
cleaning_booking_worker_assignment_id
worker_id
status
started_travel_at
arrived_at
last_latitude
last_longitude
location_updated_at
start_approved_at
work_started_at
work_finished_at
worker_completion_message
service_share_amount
travel_fee
admin_margin_amount
worker_amount
currency
created_at
updated_at
```

Constraint:

```text
UNIQUE(cleaning_booking_session_id, worker_id)
```

### 4.4 Session-scoped supporting records

Add nullable session linkage where needed:

- `booking_security_codes.cleaning_booking_session_id`
- `cleaning_time_warnings.cleaning_booking_session_id`

Legacy single-day rows may keep this value `NULL`.

## 5. Status Model

Recommended session lifecycle:

```text
scheduled
worker_assigned
awaiting_start_verification
awaiting_worker_start_confirmation
in_progress
awaiting_customer_completion
time_extension_requested
completed
cancelled
under_dispute
```

Add a parent aggregate state such as `partially_completed`.

Parent aggregation rules:

- fully cancelled before execution -> `cancelled`
- active session in lifecycle -> expose the applicable active state
- at least one completed session and future sessions remain -> `partially_completed`
- all required non-cancelled sessions completed -> `completed`
- never overwrite historical completed sessions because a future session is cancelled

Put aggregation in a dedicated service, e.g. `CleaningBookingSessionStatusService`.

## 6. API Contract

### 6.1 Backward compatibility

Continue accepting the current single-day event request:

```json
{
  "scheduledDate": "2026-09-10",
  "scheduledTime": "18:00",
  "propertyDetails": { "hours": 4 }
}
```

Normalize it internally to one session.

### 6.2 Multi-day request

Add an additive schedule object:

```json
{
  "propertyType": "event_assistance",
  "schedule": {
    "mode": "multi_day",
    "sessions": [
      { "date": "2026-09-10", "time": "18:00", "hours": 4 },
      { "date": "2026-09-11", "time": "17:00", "hours": 5 },
      { "date": "2026-09-13", "time": "19:00", "hours": 3 }
    ]
  }
}
```

Validation:

```text
schedule.sessions: required array, min 1
schedule.sessions.*.date: required date >= today
schedule.sessions.*.time: required H:i
schedule.sessions.*.hours: numeric min 1 max 24
```

Also:

- reject duplicate date/time entries
- normalize hours using the existing event increment policy
- sort sessions chronologically before persistence
- multi-day schedule is allowed only for `event_assistance` in Phase 1
- after a worker accepts, block direct schedule changes in Phase 1

### 6.3 Resource response

Add to `CleaningBookingResource`:

```json
{
  "schedule": {
    "mode": "multi_day",
    "daysCount": 3,
    "completedDaysCount": 1,
    "cancelledDaysCount": 0,
    "totalHours": 12,
    "firstDate": "2026-09-10",
    "lastDate": "2026-09-13",
    "nextSession": {
      "id": 52,
      "sequence": 2,
      "date": "2026-09-11",
      "time": "17:00",
      "hours": 5,
      "status": "scheduled"
    },
    "sessions": []
  }
}
```

Keep `scheduledDate`, `scheduledTime`, `estimatedHours`, and `totalHours` for compatibility.

## 7. Pricing and Financial Logic

Event pricing remains per worker per booked hour:

```text
totalEventHours = SUM(session.duration_hours)
basePrice = eventHourlyRate * totalEventHours * numberOfWorkers
```

Store pricing per session first, then aggregate the parent:

```text
sessionBasePrice = hourlyRate * sessionHours * workerCount
sessionTravelFee = visit-specific travel fee
sessionTotal = sessionBasePrice + sessionTravelFee + sessionAdminMargin

bookingBasePrice = SUM(sessionBasePrice)
bookingTravelFee = SUM(sessionTravelFee)
bookingAdminMargin = SUM(sessionAdminMargin)
bookingTotalPrice = SUM(sessionTotal)
```

Rules:

- travel fee is per visit/session
- extensions affect only the current session
- cancellation fee applies only to affected future sessions
- worker financial snapshots are stored per session and aggregated to the parent assignment
- worker solvency/commission capacity must consider the full remaining multi-day commitment when accepting the booking

## 8. Worker Availability and Conflict Detection

Refactor `WorkerBookingScheduleConflictService` from one interval per booking to multiple intervals per booking/session.

Rules:

- the worker is eligible only if every candidate session is conflict-free
- active sessions from existing multi-day assignments become busy intervals
- completed/cancelled sessions are excluded as applicable
- acceptance must re-check conflicts server-side
- previous-worker availability, homepage new-order count, API acceptance, and Filament worker assignment must use the same conflict service

Prefer structured errors:

```json
{
  "reasonCode": "schedule_conflict",
  "conflicts": [
    { "sessionId": 52, "date": "2026-09-11", "start": "17:00", "end": "22:00" }
  ]
}
```

## 9. Worker Assignment Behavior

Phase 1:

```text
accept parent booking = commit worker to all active future sessions
```

On acceptance:

1. create/update parent `cleaning_booking_worker_assignment`
2. create session-worker assignments for all active sessions
3. calculate session worker financial snapshots
4. include all sessions in conflict checks

When releasing a worker before work starts, release future session assignments while preserving completed historical session assignments.

## 10. Session Lifecycle Endpoints

Keep existing booking-level endpoints for legacy single-day bookings. Add session-scoped endpoints for multi-day events:

```text
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/start-travel
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/location
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/arrive
GET  /api/v1/cleaning-bookings/{booking}/sessions/{session}/security-code
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/start-work
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/complete
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/sos
```

Customer actions:

```text
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/start-verification/confirm
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/completion/confirm
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/completion/reject
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/completion/extend-time
```

Every session route must verify that the session belongs to the parent booking and that the authenticated actor owns/participates in that session.

## 11. Completion, Extension, Cancellation, Review

- completing Session 1/3 must not complete the parent
- customer completion confirmation is session-scoped
- after each session decision, recalculate parent status and `nextSession`
- extension modifies only the active session duration/fees/timer
- full cancellation before execution may cancel all future sessions
- after partial completion, completed sessions remain immutable and only future sessions are cancelled
- parent cancellation/financial totals are recomputed from session states
- review stays parent-level and is allowed only after aggregate `completed`

## 12. Realtime and Notifications

All session-specific events must include at least:

```json
{
  "bookingId": 1001,
  "sessionId": 52,
  "sessionSequence": 2,
  "sessionDate": "2026-09-11"
}
```

Recommended event names:

```text
cleaning_session.worker_travel_started
cleaning_session.worker_location_updated
cleaning_session.worker_arrived
cleaning_session.start_verified
cleaning_session.started
cleaning_session.awaiting_customer_completion
cleaning_session.extension_requested
cleaning_session.completed
cleaning_session.cancelled
```

Notifications:

- new order: include number of days/date range
- reminder before every session
- non-final completion: mention completed day and next session
- final completion: send normal booking-completed notification
- cancellation: identify affected date(s)

## 13. Worker Homepage, Calendar, and Statistics

Current date-based queries use parent `scheduled_date`; multi-day events must become session-aware.

Backend should distinguish metrics such as:

```text
todayBookingsCount
todaySessionsCount
todayEarnings
sessionsWeeklyChart
```

Rules:

- a booking with a session today counts as a today booking
- one booking can appear on multiple calendar dates
- daily earnings use session worker financial snapshots
- weekly grouping uses `cleaning_booking_sessions.scheduled_date`

Worker calendar should render one card per session, e.g. `Booking #CL-1001 - Day 2/3`, while opening the same parent booking with the selected `sessionId`.

## 14. User Flutter App Changes

### Models and requests

Add a session input/response model and update:

- `CreateCleaningOrderParams.eventAssistance`
- `EstimateCleaningPriceParams.eventAssistance`
- occasion route args
- `CleaningOrderModel` / order-details models

Keep legacy single-day serialization support.

### Occasion schedule screen

Update `cl_main_occasion_schedule_screen.dart` to support:

- multi-date calendar selection
- one card per selected day
- per-day start time and duration
- "Apply same time to all days"
- remove selected day
- chronological sorting
- summary: days count, total hours per worker, workers count, total worker-hours

### Order list/details

Update `cleaning_order_card.dart` to show:

- number of days
- next session
- completed-days progress

Update `cleaning_order_details_screen.dart` to show all sessions, session statuses, and current session actions.

### Rescheduling

Legacy single-day cleaning remains unchanged. Multi-day event schedule can be changed only before worker acceptance in Phase 1; otherwise show a clear blocked-edit message.

### Previous workers

Send all candidate sessions to the availability endpoint. Only allow selecting workers available for every session; optionally display conflicting date(s) returned by the backend.

## 15. Worker Flutter App Changes

### Models

Extend `FetchOrdersUsecaseModelDataItem` with schedule/session data:

```text
scheduleMode
daysCount
completedDaysCount
cancelledDaysCount
nextSession
sessions[]
```

Parse per-session worker assignment state.

### Order cards and acceptance

For multi-day events show days count, date range, total committed hours, and next session. Before acceptance, list all required dates and clearly state that accepting commits the worker to every day.

### Calendar

Expand one multi-day booking into one calendar entry per session. Each entry carries `bookingId`, `sessionId`, sequence, date, and time.

### Details / mission

Add a session selector/timeline. `Start Travel`, `Arrive`, `Security Code`, `Start Work`, `Complete`, extension response, SOS, and timer must operate on the active session, not aggregate parent hours.

### Homepage

Parse session-aware counts, calendar data, and earnings returned by the backend.

## 16. Filament Changes

### Cleaning bookings table

Add useful event columns:

```text
days_count
completed_days
next_session
first_date
last_date
```

Change `scheduled_today` so multi-day events use `whereHas(sessions, scheduled_date=today)` instead of only parent `scheduled_date`.

### Infolist

Add a Filament-native `RepeatableEntry` or relation manager listing sessions with:

- sequence
- date/time
- duration
- status
- assigned/accepted workers
- lifecycle timestamps
- pricing
- extension fee
- cancellation fee/reason

### Add Worker action

The worker selection query must validate availability across all active future sessions. Reuse the same backend conflict service as the apps.

### Widgets

Audit all widgets and reports that currently group/filter by parent `scheduled_date` and make them session-aware.

## 17. Backward Compatibility and Migration

Do not require destructive migration of historical bookings.

Recommended strategy:

- legacy booking without session rows -> services/resources treat parent schedule as one virtual session
- every new event-assistance booking -> persist at least one real session, even if single-day
- optionally backfill session rows for active future event bookings only
- do not remove existing API fields in this release

New clients must use `schedule.sessions` as the source of truth for multi-day operational state.

## 18. Suggested Backend Services

Prefer focused services instead of adding more branching to controllers:

```text
CleaningBookingScheduleService
CleaningBookingSessionLifecycleService
CleaningBookingSessionStatusService
CleaningBookingSessionPricingService
CleaningBookingSessionAssignmentService
```

Refactor/reuse:

```text
WorkerBookingScheduleConflictService
CleaningBookingTeamService
CleaningLifecycleNotificationService
CleaningExtendedTimePricingService
WorkerOrderSolvencyService
```

## 19. Required Tests

### Backend

- legacy single-day event still works
- multi-day event creates ordered sessions
- duplicate/invalid sessions rejected
- pricing sums all session hours and worker count
- travel fee is aggregated per session
- conflict in any one session blocks worker availability and acceptance
- accepted worker receives assignments for all sessions
- completing first session does not complete parent
- parent becomes partially completed between days
- final session completion completes parent
- extension changes only one session
- partial cancellation preserves completed sessions
- cancellation fee applies only to future cancelled sessions
- review blocked until parent completion
- previous-worker availability checks every session
- homepage today/weekly metrics use sessions
- worker earnings aggregate session assignment snapshots
- Filament scheduled-today finds multi-day events
- session route authorization/scoping
- realtime payload includes `sessionId`

### User app

- multi-date selection
- same-time-for-all action
- request serialization
- next-session/progress rendering
- session list/details
- blocked edit after worker acceptance
- legacy regression

### Worker app

- parse sessions
- acceptance confirmation lists all dates
- calendar expands sessions
- lifecycle actions use session endpoints
- timer uses session duration
- homepage session metrics

## 20. Rollout Order

1. **Backend foundation:** migrations, models, schedule normalization, resource response, pricing, conflict service, tests.
2. **User app:** multi-date creation, estimate/create payload, cards/details, previous-worker availability.
3. **Worker app:** session calendar, acceptance, lifecycle, timer, completion/extension, homepage metrics.
4. **Filament:** session details, filters, worker assignment, widgets.
5. **Hardening:** active legacy backfill if needed, notifications/realtime regression, analytics audit.

## 21. Definition of Done

The feature is complete when:

- one customer event booking can contain multiple days with different times/durations
- workers cannot accept if any selected day conflicts
- accepting commits the worker to all required event days
- each day has an independent lifecycle and financial snapshot
- completing one day does not complete the parent
- the parent completes only after all required sessions are complete
- partial cancellation preserves completed history and charges only affected future sessions
- user and worker calendars/details correctly render all sessions
- worker homepage/statistics/earnings are session-aware
- Filament can inspect and operate the multi-day booking safely
- existing single-day clients continue to work
- automated regression tests cover both paths
