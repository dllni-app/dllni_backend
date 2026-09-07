# Multi-Day Event Assistance — Backend Implementation Plan

## 1. Objective

Implement multi-day scheduling for `propertyType=event_assistance` in the Laravel backend while preserving full backward compatibility with current single-day cleaning and event-assistance clients.

This plan is intentionally backend-only. Flutter and Filament presentation concerns are covered in separate plans.

## 2. Phase-1 Product Rules

1. One customer request remains one parent `CleaningBooking`.
2. Every selected event day becomes one `CleaningBookingSession`.
3. The same required worker team is committed to every active session in Phase 1.
4. Worker acceptance is all-or-nothing across the active future sessions.
5. Each session has an independent execution lifecycle.
6. Completing one session must not complete the parent booking.
7. The parent completes only when every required non-cancelled session is completed.
8. Travel fee is calculated per visit/session.
9. Time extension affects only the active session.
10. Cancellation after partial completion affects only future active sessions.
11. Review remains parent-level and is unlocked only after aggregate booking completion.
12. Existing single-day API fields must continue to work.

## 3. Current Backend Assumptions to Refactor

The current backend is designed around one schedule per booking:

- `scheduled_date`
- `scheduled_time`
- `estimated_hours` / `total_hours`

Current request validation also expects one `scheduledDate` and one `scheduledTime`.

Primary files to audit/refactor:

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
- `Modules/Cleaning/app/Services/CleaningLifecycleNotificationService.php`
- `Modules/Cleaning/app/Services/CleaningExtendedTimePricingService.php`
- `Modules/Cleaning/app/Services/WorkerOrderSolvencyService.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
- `Modules/Cleaning/app/Http/Controllers/API/WorkerHomepageController.php`

Do not build the feature on the legacy `EventBooking` model. The active event-assistance flow uses `CleaningBooking` with `property_type = event_assistance`.

## 4. Data Model

### 4.1 Parent booking

Keep `cleaning_bookings` as the aggregate root.

For event-assistance bookings with real session rows:

- `scheduled_date` = first session date, compatibility only
- `scheduled_time` = first session time, compatibility only
- `estimated_hours` / `total_hours` = sum of session durations
- parent pricing = aggregate of session pricing
- parent status = aggregate booking status
- sessions = operational source of truth

Do not store the multi-day schedule only as JSON inside `property_details`.

### 4.2 New table: `cleaning_booking_sessions`

Recommended columns:

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

Recommended constraints/indexes:

```text
FOREIGN KEY cleaning_booking_id -> cleaning_bookings.id
UNIQUE(cleaning_booking_id, sequence)
INDEX(scheduled_date, status)
INDEX(cleaning_booking_id, status)
```

### 4.3 New table: `cleaning_booking_session_worker_assignments`

The existing parent worker assignment represents the worker's booking-level commitment. Add a session assignment for day-level execution and financial snapshots.

Recommended columns:

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

Add nullable session reference where the business event belongs to a specific execution day:

```text
booking_security_codes.cleaning_booking_session_id
cleaning_time_warnings.cleaning_booking_session_id
```

Any dispute/SOS/extension record that is currently only booking-scoped must be audited. If it can happen independently per execution day, add a nullable session id.

## 5. Models and Relationships

Add:

- `CleaningBookingSession`
- `CleaningBookingSessionWorkerAssignment`

Add relationships:

```text
CleaningBooking::sessions()
CleaningBooking::activeSessions()
CleaningBooking::nextSession()
CleaningBookingSession::booking()
CleaningBookingSession::workerAssignments()
CleaningBookingSessionWorkerAssignment::session()
CleaningBookingSessionWorkerAssignment::worker()
CleaningBookingSessionWorkerAssignment::parentAssignment()
```

Prefer explicit methods for aggregate values instead of duplicating query logic across controllers.

Suggested parent helpers:

```text
isMultiDayEventAssistance()
sessionsCount()
completedSessionsCount()
cancelledSessionsCount()
remainingSessionsCount()
nextActiveSession()
firstSession()
lastSession()
aggregateSessionStatus()
```

## 6. Schedule Normalization Service

Create a dedicated service:

`CleaningBookingScheduleService`

Responsibilities:

1. normalize legacy single-day input into one session definition
2. normalize `schedule.sessions`
3. validate chronological order
4. reject duplicates
5. calculate total hours
6. set parent compatibility fields
7. create/update session rows in one transaction
8. block unsafe schedule changes after worker acceptance

Recommended internal normalized object:

```php
[
    [
        'date' => '2026-09-10',
        'time' => '18:00',
        'hours' => 4.0,
    ],
]
```

## 7. Create / Estimate / Update API Contract

### 7.1 Keep legacy request

```json
{
  "scheduledDate": "2026-09-10",
  "scheduledTime": "18:00",
  "propertyDetails": {
    "hours": 4
  }
}
```

Backend normalizes it into one session.

### 7.2 Add multi-day request

```json
{
  "propertyType": "event_assistance",
  "schedule": {
    "mode": "multi_day",
    "sessions": [
      {
        "date": "2026-09-10",
        "time": "18:00",
        "hours": 4
      },
      {
        "date": "2026-09-11",
        "time": "17:00",
        "hours": 5
      }
    ]
  }
}
```

Validation:

```text
schedule: required for new multi-day clients
schedule.mode: in(single_day,multi_day)
schedule.sessions: array|min:1
schedule.sessions.*.date: date|after_or_equal:today
schedule.sessions.*.time: date_format:H:i
schedule.sessions.*.hours: numeric|min:1|max:24
```

Additional rules:

- reject exact duplicate session date/time pairs
- sort sessions chronologically before persistence
- multi-day mode allowed only for event assistance in Phase 1
- do not trust client-calculated totals
- enforce existing event hour increments/policies server-side
- after worker acceptance, direct schedule update is blocked in Phase 1

### 7.3 Estimate response

Return session-level estimate plus aggregate totals.

Example:

```json
{
  "schedule": {
    "mode": "multi_day",
    "daysCount": 2,
    "totalHours": 9,
    "sessions": [
      {
        "date": "2026-09-10",
        "time": "18:00",
        "hours": 4,
        "basePrice": 0,
        "travelFee": 0,
        "totalPrice": 0
      }
    ]
  },
  "pricing": {
    "basePrice": 0,
    "travelFee": 0,
    "totalPrice": 0
  }
}
```

## 8. Booking Resource Contract

Extend `CleaningBookingResource`.

Required additive object:

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
    "nextSession": {},
    "sessions": []
  }
}
```

Each session should expose at minimum:

```text
id
sequence
date
time
hours
status
isToday
isPast
canStartTravel
canArrive
canStartWork
canComplete
canExtend
pricing
workerAssignmentState
```

Keep these legacy fields:

```text
scheduledDate
scheduledTime
estimatedHours
totalHours
```

For multi-day bookings they are compatibility/aggregate values, not the operational source of truth.

## 9. Pricing

Current event pricing remains per worker per hour.

Formula:

```text
totalEventHours = SUM(session.duration_hours)
basePrice = eventHourlyRate * totalEventHours * numberOfWorkers
```

Calculate per session first:

```text
sessionBasePrice = hourlyRate * sessionHours * workerCount
sessionTravelFee = visit-specific travel fee
sessionAdminMargin = configured margin
sessionTotal = sessionBasePrice + sessionTravelFee + sessionAdminMargin + extension fees
```

Parent aggregates:

```text
bookingBasePrice = SUM(sessionBasePrice)
bookingTravelFee = SUM(sessionTravelFee)
bookingAdminMargin = SUM(sessionAdminMargin)
bookingTotalPrice = SUM(sessionTotal)
```

Rules:

- travel fee is per session/visit
- extension fee modifies only one session
- cancellation fee belongs to cancelled session(s)
- financial values must be snapshotted per session
- parent values must be recalculated from sessions, not incrementally guessed
- worker amount must be stored per session and aggregated into the parent assignment

## 10. Worker Schedule Conflict Detection

Refactor `WorkerBookingScheduleConflictService`.

Current single interval logic must support an array of candidate intervals.

Required service behavior:

```text
candidate booking sessions
        |
        v
build intervals for every session
        |
        v
load worker active busy intervals
        |
        v
compare every candidate interval
        |
        +--> conflict on any session => unavailable
        |
        +--> no conflicts => available
```

All of these must call the same source of truth:

- user previous-worker availability
- worker homepage/new orders
- worker accept endpoint
- admin/Filament add-worker action
- any preferred-worker preselection validation

Structured conflict response:

```json
{
  "reasonCode": "schedule_conflict",
  "message": "Worker is not available for all event days.",
  "conflicts": [
    {
      "sessionId": 52,
      "date": "2026-09-11",
      "start": "17:00",
      "end": "22:00"
    }
  ]
}
```

Acceptance must always re-check conflict server-side.

## 11. Worker Assignment

Phase 1 behavior:

```text
accept parent booking = accept all active future sessions
```

Within one database transaction:

1. validate worker eligibility
2. validate solvency/financial limit
3. validate conflicts for every active session
4. create/update parent `cleaning_booking_worker_assignment`
5. create one session-worker assignment per active session
6. snapshot worker financials per session
7. recalculate team fulfillment
8. publish notification/realtime events

Releasing a worker:

- preserve completed historical session assignments
- release only future/non-started sessions
- recalculate team fulfillment for future sessions

## 12. Session Status Model

Recommended session statuses:

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

Parent aggregate status rules:

```text
all future sessions cancelled before any work
    -> cancelled

at least one completed + future active sessions
    -> partially_completed

active session currently in lifecycle
    -> expose/derive active parent state as required by existing apps

all non-cancelled required sessions completed
    -> completed
```

Create:

`CleaningBookingSessionStatusService`

Do not spread parent aggregation rules across controllers, resources, notifications, and UI.

## 13. Session Lifecycle API

Keep booking-level routes for legacy single-day clients.

Add session-scoped routes for multi-day operations:

```text
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/start-travel
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/location
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/arrive
GET  /api/v1/cleaning-bookings/{booking}/sessions/{session}/security-code
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/start-work
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/complete
POST /api/v1/cleaning-bookings/{booking}/sessions/{session}/sos
```

User/customer actions:

```text
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/start-verification/confirm
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/completion/confirm
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/completion/reject
POST /api/v1/user/cleaning/orders/{booking}/sessions/{session}/completion/extend-time
```

Every route must verify:

- session belongs to booking
- authenticated actor belongs to booking/session
- session is in a valid state for the action
- action applies to this worker's session assignment when relevant

## 14. Completion Logic

When Session 1 of 3 is completed:

1. finalize session financial snapshots
2. mark session completed
3. mark worker session assignment completed
4. recalculate parent aggregate
5. parent becomes `partially_completed`
6. set `nextSession`
7. keep parent review locked
8. notify user/worker of next execution day

When final required session is completed:

1. finalize final session
2. aggregate parent financials
3. mark parent completed
4. unlock review
5. send existing booking-completed notification

## 15. Time Extension

Extension is session-scoped.

Required changes:

- request identifies `sessionId`
- warning/timer belongs to session
- added duration modifies session duration
- extension pricing modifies session price
- parent total hours and price are recalculated afterward
- next/future session schedules are not modified

## 16. Cancellation

### Before any session starts

Allow cancelling all active sessions and parent booking.

### After partial completion

- completed sessions are immutable
- future sessions can be cancelled
- cancellation reason/source stored per session
- cancellation fee applied to affected sessions only
- parent keeps historical completed sessions
- parent financial totals are recalculated

Avoid deleting session history.

## 17. Security Code, SOS, Disputes

Security code:

- generate/validate per active session
- code from Session 1 must not automatically authorize Session 2

SOS:

- session id must be stored/published when SOS belongs to a specific event day

Disputes:

- preserve parent dispute relationship
- add session linkage when the dispute concerns a specific execution day

## 18. Realtime and Notifications

Every session-specific event payload should include:

```json
{
  "bookingId": 1001,
  "sessionId": 52,
  "sessionSequence": 2,
  "sessionDate": "2026-09-11"
}
```

Recommended events:

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

Notification rules:

- new order mentions date range / days count
- reminder before every session
- non-final completion mentions next session
- final completion uses booking-completed messaging
- cancellation identifies affected dates

## 19. Worker Homepage / Calendar / Statistics Backend

Audit `WorkerHomepageController` and all date-based queries.

Current booking-level date logic must become session-aware.

Recommended response additions:

```text
todayBookingsCount
todaySessionsCount
todayEarnings
sessionsWeeklyChart
upcomingSessions
```

Rules:

- a booking with a session today is active today even if the parent first date is in the past
- one booking may appear on multiple calendar dates
- earnings group by session date
- completed/cancelled sessions follow existing business inclusion rules
- new-order availability excludes bookings where worker conflicts with any session

## 20. Backward Compatibility

No destructive migration of historical bookings is required.

Compatibility strategy:

1. legacy booking without session rows is treated as one virtual session
2. every newly created event-assistance booking persists at least one real session
3. multi-day clients use `schedule.sessions`
4. old clients keep using legacy schedule fields
5. legacy booking-level lifecycle endpoints continue to work for a one-session booking

Optional migration:

- backfill session rows only for active future event-assistance bookings
- do not rewrite historical completed bookings unless reporting requires it

## 21. Suggested Backend Services

Create:

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

## 22. Transaction Boundaries

Use DB transactions for:

- create booking + sessions
- update booking schedule + sessions
- worker accept + all session assignments
- release worker + future session assignments
- complete session + parent aggregate status
- extend session + financial recalculation
- partial cancellation + parent aggregate financial/status recalculation

Events/notifications should be dispatched after commit where possible.

## 23. Tests

### Request validation

- legacy single-day event accepted
- multi-day event accepted
- invalid date rejected
- invalid time rejected
- duplicate session rejected
- multi-day rejected for non-event cleaning
- direct reschedule rejected after worker acceptance

### Persistence

- sessions stored chronologically
- parent first date/time mirrors first session
- total hours = sum of sessions
- every new event booking has at least one session row

### Pricing

- base price sums hours × workers
- travel fee is per visit
- parent total equals session totals
- extension changes one session only
- partial cancellation changes future affected sessions only

### Availability / assignment

- conflict on one session blocks worker
- no conflicts allows worker
- acceptance creates all session assignments
- release preserves completed assignments
- previous-worker endpoint checks every candidate session
- homepage new order checks every session

### Lifecycle

- first completion does not complete parent
- parent becomes partially completed
- next session changes correctly
- final completion completes parent
- review remains blocked until final completion
- security code is session-scoped
- session route authorization prevents cross-booking access

### Realtime / statistics

- events contain session id
- today's metrics use sessions
- weekly earnings group by session date

## 24. Implementation Order

### Phase A — Foundation

- migrations
- models/relations
- schedule service
- backward-compatible virtual-session behavior

### Phase B — API and pricing

- request validation
- estimate/create/update
- resources
- pricing aggregation

### Phase C — assignment and conflicts

- conflict service
- worker acceptance
- previous-worker availability
- solvency

### Phase D — session lifecycle

- travel/location/arrival
- security code
- start
- complete
- extension
- cancellation
- SOS/dispute linkage

### Phase E — realtime/statistics

- events
- notifications
- homepage
- earnings/calendar metrics

### Phase F — hardening

- regression tests
- legacy compatibility
- active-booking backfill if required
- performance/index review

## 25. Definition of Done

Backend is complete when:

- one event booking supports one or many sessions
- old single-day requests still work
- multi-day requests are normalized and persisted safely
- worker conflicts are checked across every event session
- accepted workers are assigned to every active session
- each session can independently travel/start/complete/extend/cancel
- parent status and pricing always aggregate correctly
- partial completion does not lose history
- notifications/realtime identify the session
- statistics use session dates
- automated tests cover single-day and multi-day paths
