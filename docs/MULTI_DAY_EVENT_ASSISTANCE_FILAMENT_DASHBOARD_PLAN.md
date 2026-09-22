# Multi-Day Event Assistance — Filament Dashboard Implementation Plan

## 1. Objective

Update the Filament cleaning booking administration experience so multi-day event-assistance bookings can be inspected, filtered, assigned, monitored, and financially audited safely.

This plan assumes the backend introduces:

- `CleaningBookingSession`
- `CleaningBookingSessionWorkerAssignment`
- session-aware status/pricing services
- session-aware conflict detection

Do not duplicate backend business rules in Filament.

## 2. Current Admin Behavior to Refactor

Primary files:

- `app/Filament/Resources/CleaningBookings/CleaningBookingResource.php`
- `app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php`
- `app/Filament/Resources/CleaningBookings/Schemas/CleaningBookingInfolist.php`
- related Cleaning Booking pages/actions/widgets
- dashboard widgets/reports that filter/group on parent `scheduled_date`

Current assumptions to remove for event assistance:

- one visible date
- one visible time
- "scheduled today" checks only parent `scheduled_date`
- add-worker eligibility checks one booking interval
- lifecycle/pricing is represented primarily at parent level

## 3. Admin UX Principles

1. Keep the parent booking as the main resource.
2. Show sessions as an embedded operational timeline/table.
3. Make "next session" visually dominant.
4. Preserve completed historical sessions.
5. Never let Filament bypass backend worker eligibility/conflict validation.
6. Show parent aggregates and per-session financial details separately.
7. Use Filament-native components before custom Blade/Livewire.
8. Keep cleaning-only fields hidden/irrelevant for event assistance.
9. Avoid excessive whitespace; prioritize compact operational information.
10. Arabic labels remain clear and business-friendly.

## 4. Cleaning Bookings Table

Update the main table for multi-day event assistance.

Recommended new computed columns:

```text
days_count
completed_days
cancelled_days
first_session_date
last_session_date
next_session
next_session_status
```

Suggested UI:

- `عدد الأيام`: badge
- `التقدم`: `1 / 3`
- `الجلسة القادمة`: date + time
- `الفترة`: first date → last date
- parent status remains existing badge
- existing total price remains parent aggregate

For single-day/non-event bookings:

- keep current table compact
- do not force extra empty columns if they reduce readability
- use conditional visibility/toggleable columns where appropriate

## 5. Scheduled Today Filter

Current parent-only logic must be replaced for session-backed event bookings.

Required behavior:

```text
normal/legacy single-day booking:
    whereDate(cleaning_bookings.scheduled_date, today)

session-backed event booking:
    whereHas(sessions, scheduled_date = today)
```

Prefer a reusable query scope/service rather than duplicating mixed legacy/session logic.

The filter should return a parent booking once even if multiple rows could theoretically match.

## 6. Additional Filters

Recommended:

### Event schedule mode

```text
Single day
Multi day
```

### Session progress

```text
Not started
In progress today
Partially completed
Completed
Has cancelled sessions
```

### Date range

Filter by session dates for event assistance.

### Session today status

Optional operational filter:

```text
Worker assigned
Awaiting start
In progress
Awaiting customer completion
Completed
Cancelled
```

## 7. Infolist / View Page

Add a dedicated section:

`أيام تنفيذ المناسبة`

Use `RepeatableEntry`, relation manager, or a compact Filament-native table.

Each session row/card should show:

```text
Day sequence (1/3)
Date
Start time
Duration
Status
Workers
Travel state
Arrival state
Work start
Work finish
Session base price
Travel fee
Extension fee
Cancellation fee
Session total
Cancellation reason/source
```

Visual rules:

- next session gets a primary/info emphasis
- completed sessions use success styling
- cancelled sessions use danger/gray styling
- current active session gets strongest operational emphasis
- no giant empty cards
- timestamps can be collapsed into an "execution details" subsection if necessary

## 8. Parent Summary Section

Keep an aggregate summary above sessions:

```text
Booking number
Customer
Event type
Guests
Venue
Required workers
Accepted workers
Days count
Completed days
First date
Last date
Next session
Total booked hours
Parent status
Aggregate price
Aggregate worker payout
```

The user should understand the whole booking without opening every session.

## 9. Worker Assignment Action

Existing `add_worker` action must become multi-session aware.

### Worker options

The selectable workers list must only contain workers who satisfy:

- existing account/worker activation rules
- gender requirement
- neighborhood/coverage requirements
- dispatch eligibility
- solvency/financial limits
- availability for every active future session

Do not reimplement conflict calculations in Filament.

Call/reuse the backend service used by worker acceptance.

### Error display

If assignment fails due to one or more session conflicts, show:

```text
تعذر إضافة العامل لأنه غير متاح في جميع أيام المناسبة.
```

When conflict dates are available, list them compactly:

```text
11-09-2026 — 17:00 إلى 22:00
13-09-2026 — 19:00 إلى 22:00
```

### Successful assignment

Clearly indicate:

- worker assigned to whole booking
- number of event days covered
- remaining workers required

## 10. Release Worker Action

For Phase 1, releasing a worker from a partially executed multi-day booking must:

- preserve completed historical session assignments
- release future eligible session assignments
- recalculate team fulfillment
- show warning before confirmation

Confirmation copy should make the scope explicit:

```text
سيتم إلغاء تعيين العامل من الأيام القادمة فقط، مع الاحتفاظ بسجل الأيام المنفذة.
```

Filament must call backend domain service rather than manually editing assignments.

## 11. Session Actions

If administrators need operational override actions, keep them minimal and controlled.

Potential actions:

```text
View session
Cancel future session
View assigned workers
View worker tracking
View session financials
View SOS/dispute
```

Avoid adding direct "force complete" / arbitrary lifecycle mutation unless the existing product explicitly requires it.

If administrative lifecycle override already exists, make it session-scoped and audited.

## 12. Session Cancellation

Add cancellation action on eligible future sessions.

Form fields:

```text
reason
cancelled_by_role = admin
fee override only if existing business rules allow it
confirmation
```

Rules:

- completed session cannot be cancelled
- currently in-progress session follows existing cancellation policy
- cancelling one session must not cancel all future sessions unless "cancel entire remaining booking" is explicitly chosen
- backend recalculates parent status/pricing

Optional parent action:

`إلغاء الأيام المتبقية`

This action must list affected dates before confirmation.

## 13. Pricing Display

Separate aggregate vs per-session values.

Parent summary:

```text
Base price
Travel fees total
Admin margin total
Extension fees total
Cancellation fees total
Grand total
Worker payout total
```

Session row/detail:

```text
Session hours
Session base price
Travel fee
Extension fee
Cancellation fee
Session total
Worker payout snapshot
```

Add tooltips/formula text where the existing dashboard already uses them.

Do not calculate values in Filament presentation code.

## 14. Worker Tracking

For multi-day bookings, location/tracking belongs to the active session.

Tracking panel should show:

```text
Current session date
Current session sequence
Worker
Travel started
Arrived
Last known location
Location updated at
Status
```

If multiple workers are assigned:

- show each worker separately
- status per worker
- map only active/trackable workers
- retain prior completed session history without mixing it into current live tracking

## 15. Security / Authorization

Resource policies/permissions should continue to control booking access.

Session actions must also validate:

- session belongs to booking
- actor can manage cleaning bookings
- lifecycle action is legal
- financial override is allowed by permission if applicable

Do not trust session ids posted from the UI without backend scoping.

## 16. Relation Loading and Performance

Main table query should eager-load only what is needed.

Potential relations/counts:

```text
sessions
sessions.workerAssignments.worker.user
workerAssignments.worker.user
customer
```

Prefer:

```text
withCount(sessions)
withCount(completedSessions)
```

over loading all session rows into every table record when only counts are needed.

Use full relation loading on the view page.

Add/verify database indexes for:

```text
cleaning_booking_sessions.cleaning_booking_id
cleaning_booking_sessions.scheduled_date
cleaning_booking_sessions.status
```

## 17. Dashboard Widgets and Reports Audit

Search for direct use of:

```text
scheduled_date
scheduled_time
total_hours
total_price
worker assignment timestamps
```

in Filament widgets/reports.

Update event-assistance metrics to use sessions where the metric is day-based.

Examples:

### Today's bookings

A multi-day booking with a session today must appear.

### Daily revenue

Use session financial date when the metric represents executed/scheduled daily revenue.

### Worker workload

Use session assignment dates.

### Completed today

Use session completion if the widget is operational per day; use parent completion if it explicitly means whole-booking completion.

Document which semantic is used for every modified widget.

## 18. Suggested Components

Prefer existing Filament components:

```text
Section
Grid
TextEntry
RepeatableEntry
Table
Badge
Action
Select
DatePicker
DateRange filter pattern
Placeholder
```

Custom Livewire/Blade is justified only for:

- active worker tracking map
- complex session timeline that cannot be represented cleanly in native components

## 19. Empty States

Required empty states:

```text
لا توجد أيام تنفيذ مسجلة لهذا الطلب.
لا توجد جلسة قادمة.
لا يوجد عامل متاح لجميع أيام المناسبة.
لا توجد بيانات تتبع للجلسة الحالية.
```

Legacy bookings without session rows should still display parent schedule information.

## 20. Filament Tests

### Table

- multi-day booking shows correct days count
- correct first/last/next session
- scheduled-today matches any session date
- single-day booking still appears correctly
- no duplicate parent rows

### Filters

- multi-day only
- partially completed
- date range
- has cancelled sessions

### Worker assignment

- conflict on one session removes/blocks worker
- eligible worker can be assigned
- backend error shown clearly
- assignment creates all session assignments

### Release

- future session assignments removed
- completed assignments preserved

### View/Infolist

- sessions ordered chronologically
- status badges correct
- next session identified
- pricing totals match backend values
- cancellation details visible

### Authorization

- unauthorized admin cannot mutate session
- cross-booking session id rejected

## 21. Implementation Order

### Phase A — Data presentation

- table columns/counts
- view/infolist session list
- parent summary

### Phase B — filters

- scheduled today
- schedule mode
- progress/date filters

### Phase C — worker operations

- add worker
- conflict details
- release worker

### Phase D — financial/lifecycle operations

- session pricing detail
- session cancellation
- admin operational actions if required

### Phase E — widgets/tracking

- update date-based widgets
- active session tracking
- reports

### Phase F — QA

- responsive layout
- Arabic labels
- empty states
- authorization
- performance
- regression of normal cleaning orders

## 22. Definition of Done

Filament work is complete when:

- admin can understand the whole multi-day booking from the main view
- every execution day is visible with independent status
- "scheduled today" works from sessions
- admin worker assignment checks all future sessions
- completed session history is never destroyed by future changes
- prices are visible per session and in aggregate
- tracking identifies the active session
- widgets/reports use the correct session semantics
- normal single-day cleaning dashboard behavior is preserved
- automated tests cover the new admin behavior
