# Backend handoff: Per-worker hours from room assignments

**Audience:** Backend developer  
**Goal:** Make each worker receive their own working hours on cleaning booking APIs (not the full booking sum), based on assigned rooms — same split logic already used for pay.

**No DB migration required.** Hours are derived from existing `rooms_weight` / room `weight` + booking `total_hours` / `estimated_hours`.

---

## Problem

Today:

- `cleaning_bookings.total_hours` is the **full job** estimate (sum of all rooms’ time).
- `CleaningBookingResource` returns that same value as `totalHours` to **every** worker.
- `workTimer.durationHours` also uses the full booking duration.
- Multi-worker pay already splits by `rooms_weight / total_weight` in `CleaningBookingTeamService::recalculateBookingTeam`.
- Time does **not** follow that split, so Order Details / timers show e.g. `7.5` for every worker instead of each worker’s share.

---

## Required formula

```text
bookingHours = total_hours > 0 ? total_hours : estimated_hours
workerHours  = bookingHours × (assignment.rooms_weight / sum(all rooms.weight))
```

Rules:

1. Round with half-hour ceiling: `ceil(hours * 2) / 2` (same as estimation).
2. If `number_of_workers <= 1` → return `bookingHours`.
3. If no assignment / zero total weight / zero worker weight → return `bookingHours`.
4. Prefer `assignment.rooms_weight` when > 0; otherwise sum weights of rooms where `assigned_worker_id = assignment.worker_id`.
5. Total weight = sum of all booking rooms’ `weight` (fallback: sum of loaded assignments’ `rooms_weight`).

Example: booking `5.0` hours, weights `3.0` and `2.0` → workers get `3.0` and `2.0`.

---

## Files to change / add

| Action | Path |
|--------|------|
| **Add** | `Modules/Cleaning/app/Support/WorkerAssignmentHoursResolver.php` |
| **Edit** | `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php` |
| **Edit** | `Modules/Cleaning/app/Services/WorkerBookingScheduleConflictService.php` |
| **Add** | `tests/Feature/Cleaning/WorkerAssignmentHoursTest.php` |

Reference pay-split logic (do not change money calc, only mirror the ratio):

- `Modules/Cleaning/app/Services/CleaningBookingTeamService.php` → `recalculateBookingTeam()`

---

## Step 1 — Add `WorkerAssignmentHoursResolver`

Create:

`Modules/Cleaning/app/Support/WorkerAssignmentHoursResolver.php`

Public API:

```php
WorkerAssignmentHoursResolver::bookingHours(CleaningBooking $booking): float
WorkerAssignmentHoursResolver::resolve(
    CleaningBooking $booking,
    ?CleaningBookingWorkerAssignment $assignment = null,
    iterable|null $rooms = null,
): float
WorkerAssignmentHoursResolver::roundToHalfHour(float $hours): float
```

Responsibilities:

- `bookingHours()` — `total_hours` if > 0, else `estimated_hours`
- `resolve()` — apply formula + fallbacks above
- Load rooms from relation / query when `$rooms` is null
- Keep class `final`, `declare(strict_types=1)`, no DI needed (static helpers are fine)

---

## Step 2 — Update `CleaningBookingResource`

File: `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`

### In `toArray()`

1. Import `WorkerAssignmentHoursResolver`.
2. Compute:

```php
$bookingTotalHours = WorkerAssignmentHoursResolver::bookingHours($this->resource);
$workerTotalHours = WorkerAssignmentHoursResolver::resolve(
    $this->resource,
    $myAssignmentModel,
    $this->relationLoaded('rooms') ? $this->rooms : null,
);
$personalizedHours = $myAssignmentModel instanceof CleaningBookingWorkerAssignment
    && max(1, (int) ($this->number_of_workers ?? 1)) > 1;
$responseTotalHours = $personalizedHours ? $workerTotalHours : $bookingTotalHours;
$workTimer = $this->workTimerPayload(
    (string) $orderStatus,
    $myAssignmentModel,
    $responseTotalHours,
    $personalizedHours,
);
```

3. Replace top-level hours fields:

```php
'bookingTotalHours' => $bookingTotalHours,
'booking_total_hours' => $bookingTotalHours,
'totalHours' => $responseTotalHours,
```

Keep `estimatedHours` as today (booking-level).

### In `serializeWorkerAssignment()`

Add on every assignment payload:

```php
'totalHours' => WorkerAssignmentHoursResolver::resolve(
    $this->resource,
    $assignment,
    $this->relationLoaded('rooms') ? $this->rooms : null,
),
```

So both `myAssignment.totalHours` and `workerAssignments[].totalHours` are populated.

### In `workTimerPayload()`

Change signature to accept duration + personalization flag:

```php
private function workTimerPayload(
    string $status,
    ?CleaningBookingWorkerAssignment $assignment = null,
    ?float $durationHours = null,
    bool $personalizedHours = false,
): array
```

- Use `$durationHours` (fallback to `bookingHours`) for `durationHours` / expected finish / remaining / overdue.
- When `$personalizedHours` is true, set:

```php
'source' => [
    'startField' => /* unchanged per-assignment start logic */,
    'durationField' => 'assignment.total_hours',
],
```

- When false, keep existing `total_hours` / `estimated_hours` durationField behavior.

---

## Step 3 — Update schedule conflict service

File: `Modules/Cleaning/app/Services/WorkerBookingScheduleConflictService.php`

Today busy intervals use full booking `total_hours` for every worker. Change to per-worker share:

1. Pass the `Worker` into interval calculation.
2. Resolve that worker’s assignment on the booking.
3. Use `WorkerAssignmentHoursResolver::resolve(...)` for duration minutes.
4. When loading busy bookings, eager-load `rooms` and that worker’s `workerAssignments` (rooms are needed for total weight).

Candidate bookings without an assignment yet may still use full booking hours (safe fallback).

---

## Step 4 — API contract (what clients expect)

Endpoint: `GET /api/v1/cleaning-bookings/{id}` (same resource on list/lifecycle responses).

### Multi-worker + authenticated worker with assignment

```json
{
  "bookingTotalHours": 5.0,
  "totalHours": 3.0,
  "myAssignment": {
    "totalHours": 3.0,
    "roomsWeight": 3.0
  },
  "workTimer": {
    "durationHours": 3.0,
    "source": {
      "durationField": "assignment.total_hours"
    }
  }
}
```

### Customer / single-worker / no assignment

```json
{
  "bookingTotalHours": 5.0,
  "totalHours": 5.0,
  "workTimer": {
    "durationHours": 5.0,
    "source": {
      "durationField": "total_hours"
    }
  }
}
```

**Do not** change how `total_hours` is stored on create/update. Only change what the resource **returns** per viewer.

---

## Step 5 — Tests

Add `tests/Feature/Cleaning/WorkerAssignmentHoursTest.php` covering:

1. **Unit-style resolver:** weight `3/5` of `7.5` → `4.5`; single-worker stays full hours.
2. **API multi-worker:** two workers with weights `3` and `2`, booking `5` hours, both in progress:
   - Worker A → `totalHours = 3`, `myAssignment.totalHours = 3`, `workTimer.durationHours = 3`, `durationField = assignment.total_hours`
   - Worker B → `2` for the same fields
   - Both still see `bookingTotalHours = 5`
3. **Customer / single-worker:** `totalHours` and timer stay full booking hours; `durationField` stays `total_hours`.

Run:

```bash
php artisan test --filter=WorkerAssignmentHours
vendor/bin/pint --dirty
```

Also sanity-check existing work-timer tests in `tests/Feature/Cleaning/CleaningBookingEndpointsTest.php` still pass for single-worker cases.

---

## Acceptance checklist

- [ ] Multi-worker worker A and worker B get **different** `totalHours` proportional to room weights
- [ ] `bookingTotalHours` always equals full booking estimate
- [ ] `myAssignment.totalHours` matches personalized top-level `totalHours`
- [ ] `workTimer.durationHours` uses worker share for multi-worker assigned workers
- [ ] Customer responses still get full `totalHours`
- [ ] Single-worker responses unchanged
- [ ] Schedule conflicts for multi-worker use worker share duration
- [ ] No migration; no change to booking create estimation

---

## Out of scope (do not change)

- How `total_hours` / `estimated_hours` are calculated at order create (`UserCleaningOrderEstimationService`)
- Money / `service_share_amount` / `worker_amount` logic
- Event-assistance hour source (`propertyDetails.hours`)
- Mobile apps (owner app will consume the new fields; backend must ship this contract)

---

## Context for clients (FYI only)

Owner cleaning app Order Details shows `إجمالي ساعات العمل` from `totalHours`, and the mission timer uses the same duration. After this backend change, personalizing `totalHours` + `myAssignment.totalHours` + `workTimer` is enough for each worker to see their own time.
