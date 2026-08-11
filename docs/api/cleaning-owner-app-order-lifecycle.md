# Cleaning Owner App Order Lifecycle Guide

This document explains how the cleaning order lifecycle should be implemented in the Flutter `cleaning_owner_app` worker flow.

It focuses on three related concepts returned by the backend:

- `status` / `order_status`: the global order lifecycle status.
- `worker_order_status`: the current authenticated worker's UI status for this order.
- `myAssignment.status`: the current authenticated worker's raw assignment status, when the order has a worker assignment for this worker.

Use this file as the source of truth for deciding which buttons, screens, timers, and realtime updates Flutter should show for cleaning worker orders.

---

## 1. Important Rule: `status` vs `worker_order_status`

### `status`

`status` is the real order status stored on `cleaning_bookings.status`.

It represents the lifecycle of the full booking/order, especially for:

- order list tabs,
- terminal states,
- customer-visible lifecycle,
- realtime tracking events,
- timer lifecycle,
- customer confirmation flow,
- cancellation/completion/dispute locking.

`order_status` is an alias of `status` in the API response. Flutter should prefer `status` and treat `order_status` as a backward-compatible alias.

### `worker_order_status`

`worker_order_status` is a derived worker-facing status.

The backend calculates it from the current authenticated worker assignment before the order reaches shared/global states. This allows a worker to see that they already accepted an order even while the full order is still globally `pending` and waiting for other workers.

Use `worker_order_status` for worker card state, worker-specific CTA state, and worker-specific labels.

### Practical Flutter Decision Rule

Always evaluate statuses in this order:

1. First check `status` for global terminal or blocking states:
   - `completed`
   - `cancelled`
   - `under_dispute`

2. Then check shared active global states:
   - `in_progress`
   - `awaiting_customer_completion`
   - `time_extension_requested`

3. Then use `worker_order_status` for worker-specific pre-start states:
   - `pending`
   - `accepted`
   - `accepted_waiting_for_order_start`
   - `awaiting_start_verification`
   - `start_approved`
   - `rejected`
   - `withdrawn`
   - `cancelled`

Do not use only `worker_order_status` to decide if the order is finished or locked. `status` remains the source of truth for terminal states.

---

## 2. Main API Response Fields Flutter Should Read

Each cleaning booking resource includes the important worker flow fields below.

```json
{
  "id": 101,
  "status": "pending",
  "order_status": "pending",
  "statusLabel": "قيد الانتظار",
  "order_status_label": "قيد الانتظار",
  "worker_order_status": "accepted_waiting_for_order_start",
  "worker_order_status_label": "تم القبول بانتظار بدء الطلب",
  "workerAcceptance": {
    "required": 2,
    "accepted": 1,
    "remaining": 1,
    "startApproved": 0,
    "notStartApproved": 1
  },
  "required_workers_count": 2,
  "accepted_workers_count": 1,
  "pending_workers_count": 1,
  "start_approved_workers_count": 0,
  "not_start_approved_workers_count": 1,
  "startedTravelAt": null,
  "arrivedAt": null,
  "workStartedAt": null,
  "workFinishedAt": null,
  "completionRequest": {
    "isAwaitingCustomerConfirmation": false,
    "message": null,
    "requestedAt": null,
    "expiresAt": null,
    "actions": {
      "canConfirm": false,
      "canReject": false,
      "canRequestExtension": false
    }
  },
  "workTimer": {
    "timerStartAt": null,
    "expectedFinishAt": null,
    "durationHours": 2.5,
    "remainingWorkSeconds": 0,
    "overdueWorkSeconds": 0,
    "isWorkOverdue": false,
    "shouldShowWorkTimer": false
  },
  "myAssignment": {
    "id": 55,
    "workerId": 7,
    "status": "accepted_waiting_for_order_start",
    "acceptedAt": "2026-07-01T10:20:00+00:00",
    "startApprovedAt": null,
    "roomIds": [1, 2]
  }
}
```

### Recommended Flutter Model Fields

Flutter should parse at least:

- `id`
- `bookingNumber`
- `status`
- `order_status`
- `worker_order_status`
- `statusLabel`
- `worker_order_status_label`
- `assignmentMode`
- `numberOfWorkers`
- `required_workers_count`
- `accepted_workers_count`
- `pending_workers_count`
- `start_approved_workers_count`
- `not_start_approved_workers_count`
- `workerAcceptance`
- `workerAssignments`
- `workerRoomAssignments`
- `roomAssignments`
- `myAssignment`
- `startedTravelAt`
- `arrivedAt`
- `customerConfirmedAt`
- `workStartedAt`
- `workFinishedAt`
- `completionRequest`
- `workTimer`
- `expectedFinishAt`
- `remainingWorkSeconds`
- `overdueWorkSeconds`
- `isWorkOverdue`
- `shouldShowWorkTimer`
- `cancelledAt`
- `cancellationReason`

---

## 3. Global Order Status Values

These values come from `CleaningBookingStatus`.

| Status | Meaning | Worker App Usage |
|---|---|---|
| `pending` | Order is created and waiting for worker/team acceptance. | Show as a new available order if `worker_order_status` is also `pending`. If current worker already accepted, show accepted/waiting state from `worker_order_status`. |
| `worker_assigned` | Required worker/team count is fulfilled and the order is assigned. | Show accepted/assigned order. Main CTA is usually `Start Travel` unless `startedTravelAt` already exists. |
| `awaiting_start_verification` | Worker arrived and the order is waiting for customer security-code verification. | Show security-code / waiting-for-customer-verification UI. Do not start work timer yet. |
| `awaiting_worker_start_confirmation` | Customer verified the security code; workers must confirm start. | Show `Start Work` for workers whose `worker_order_status` is `awaiting_start_verification`. If the current worker already approved, show waiting-for-other-workers. |
| `in_progress` | Work has started. | Show work timer, location/order details, complete/cancel/SOS actions. |
| `awaiting_customer_completion` | Worker requested completion and customer must confirm/reject/request extension. | Show waiting-for-customer-completion UI. Disable normal completion CTA to avoid duplicate requests. |
| `time_extension_requested` | Customer requested extra time after worker requested completion. | Show extension request UI. Worker must accept or reject the pending time warning. |
| `under_dispute` | Worker finished with dispute/admin review request. | Lock normal worker actions. Show suspended/manual review message. |
| `completed` | Order is finished. | Lock actions, stop timer, show completed state. |
| `cancelled` | Order is cancelled. | Lock actions, stop timer, show cancellation reason when available. |

---

## 4. Worker Assignment Status Values

These values come from `CleaningBookingWorkerAssignmentStatus`.

They are used in `myAssignment.status` and can appear in `worker_order_status`.

| Assignment Status | Meaning | Worker App Usage |
|---|---|---|
| `pending` | Worker has not committed to the order yet, or no assignment exists for this worker. | Show `Accept` and `Reject` if the order is globally `pending` and available for this worker. |
| `accepted` | Worker is committed to the order. This is treated as an accepted status by the backend. | Treat as accepted. It may appear from older data or future transitions. |
| `accepted_waiting_for_order_start` | Worker accepted the order but work has not started yet. | Show accepted state. If global `status` is still `pending`, show waiting-for-team/remaining-workers. If global `status` is `worker_assigned`, allow travel/start flow. |
| `awaiting_start_verification` | Customer verified the start code and this worker must approve starting work. | Show `Start Work`. |
| `start_approved` | This worker approved the start; other workers may still need to approve. | Show waiting-for-other-workers until global `status` becomes `in_progress`. |
| `rejected` | Worker rejected the order with a reason. | Remove from active accepted UI or show rejected history state. |
| `withdrawn` | Worker withdrew/rejected without a reason. | Remove from active accepted UI or show withdrawn state. |
| `cancelled` | Worker assignment was cancelled. | Do not allow order actions from this assignment. |

Accepted assignment statuses are:

```text
accepted
accepted_waiting_for_order_start
awaiting_start_verification
start_approved
```

The backend uses these statuses to decide whether the worker owns/can act on the order.

---

## 5. Full Worker Flow

### Step 1: User creates cleaning order

Backend creates the order with:

```text
status = pending
worker_id = null
worker_order_status = pending for workers without assignment
```

Flutter screen:

- New order card in available/new requests list.
- Show `Accept` and `Reject` if the worker is eligible and order is available.
- Use `dispatchEligibility` from the list response if present to hide or disable accept actions when the worker cannot accept new orders.

Relevant endpoint:

```http
GET /api/v1/cleaning-bookings
GET /api/v1/cleaning-bookings/{id}
```

---

### Step 2: Worker accepts order

Worker calls:

```http
POST /api/v1/cleaning-bookings/{id}/accept
```

Optional request body when the worker claims specific rooms:

```json
{
  "roomIds": [1, 2, 3]
}
```

Backend behavior:

- Creates or updates the current worker assignment.
- Sets the assignment to `accepted_waiting_for_order_start`.
- Recalculates the team.
- If accepted workers are still less than `numberOfWorkers`, the global order remains `pending`.
- If the required team count is fulfilled, the global order becomes `worker_assigned`.

Flutter UI after response:

| Backend State | Flutter UI |
|---|---|
| `status = pending`, `worker_order_status = accepted_waiting_for_order_start` | Show “accepted, waiting for team”. Display `accepted_workers_count` and `pending_workers_count`. |
| `status = worker_assigned`, `worker_order_status = accepted_waiting_for_order_start` | Show assigned/ready state. Main CTA becomes `Start Travel`. |

Do not assume that accepting an order immediately means the order is globally assigned. Multi-worker orders remain `pending` until all required workers accept.

---

### Step 3: Worker can claim rooms while order is still searching

Worker can call:

```http
POST /api/v1/cleaning-bookings/{id}/rooms/claim
```

Request body:

```json
{
  "roomIds": [1, 2]
}
```

Backend allows room claiming only while the global order is still `pending` and the worker already has an accepted assignment.

Flutter UI:

- Allow room selection/claim only when:

```text
status == pending
worker_order_status is accepted/accepted_waiting_for_order_start
```

- After the order becomes `worker_assigned`, room claiming should no longer be shown as a normal action.

---

### Step 4: Team fulfilled / order assigned

When enough workers accept, backend recalculates the team and updates:

```text
status = worker_assigned
worker_id = first/primary accepted worker id
isPricingFinal = true
travel_fee/admin_margin/total_price finalized
```

Flutter UI:

- Move order from new requests to assigned/active orders.
- Show `Start Travel` if `startedTravelAt` is null.
- If `startedTravelAt` is not null, show traveling/tracking state instead of calling start-travel again.

---

### Step 5: Worker starts travel

Worker calls:

```http
POST /api/v1/cleaning-bookings/{id}/start-travel
```

Backend requires:

```text
status == worker_assigned
worker owns the booking or has an accepted assignment
```

Backend updates:

```text
startedTravelAt = now
status remains worker_assigned
```

Flutter UI:

- Do not expect `status` to change after starting travel.
- Use `startedTravelAt != null` to show the traveling state.
- Enable location updates only after travel has started.

Location update endpoint:

```http
POST /api/v1/cleaning-bookings/{id}/location
```

Request body:

```json
{
  "latitude": 36.2021,
  "longitude": 37.1343
}
```

Backend allows location updates only when:

```text
status == worker_assigned
startedTravelAt != null
```

---

### Step 6: Worker arrives

Worker calls:

```http
POST /api/v1/cleaning-bookings/{id}/arrive
```

Backend requires:

```text
status is worker_assigned or awaiting_start_verification
startedTravelAt != null
```

Backend updates:

```text
status = awaiting_start_verification
arrivedAt = now
```

Flutter UI:

- Show arrived state.
- Show security-code flow or waiting-for-customer verification.
- Do not start the work timer.

---

### Step 7: Security-code verification

Worker can request/get a security code:

```http
GET /api/v1/cleaning-bookings/{id}/security-code
```

Backend allows security code only when:

```text
status in [worker_assigned, awaiting_start_verification]
```

The user/customer app verifies the code using the user-side API. After customer verification, backend updates:

```text
status = awaiting_worker_start_confirmation
customerConfirmedAt = now
accepted/accepted_waiting_for_order_start assignments become awaiting_start_verification
```

Flutter worker UI:

- If `status = awaiting_start_verification`, show “waiting for customer verification”.
- If `status = awaiting_worker_start_confirmation` and `worker_order_status = awaiting_start_verification`, show `Start Work`.
- If `status = awaiting_worker_start_confirmation` and `worker_order_status = start_approved`, show “waiting for other workers to approve start”.

---

### Step 8: Worker starts work / approves start

Worker calls:

```http
POST /api/v1/cleaning-bookings/{id}/start-work
```

Backend behavior depends on the current state.

#### Case A: Simple direct start from `worker_assigned`

If the order is still:

```text
status = worker_assigned
```

Backend can start work directly:

```text
status = in_progress
workStartedAt = now
current worker assignment = start_approved
```

#### Case B: Start after customer security-code verification

If the order is:

```text
status = awaiting_worker_start_confirmation
```

Then each accepted worker must call `start-work`.

For the current worker after calling start-work:

```text
myAssignment.status = start_approved
worker_order_status = start_approved
```

The global order remains:

```text
status = awaiting_worker_start_confirmation
```

until all required workers approve. When all required workers approve, backend updates:

```text
status = in_progress
workStartedAt = now
```

Flutter UI:

- For single-worker orders, the order usually moves to `in_progress` immediately.
- For multi-worker orders, the first worker who approves may see `worker_order_status = start_approved` while `status = awaiting_worker_start_confirmation`.
- Show progress using:

```text
start_approved_workers_count
not_start_approved_workers_count
required_workers_count
```

- Only start/show the real work timer when:

```text
status == in_progress
workTimer.shouldShowWorkTimer == true
```

---

### Step 9: Work in progress

Global state:

```text
status = in_progress
```

Flutter UI:

- Show timer from `workTimer`.
- Use `workTimer.expectedFinishAt`, `remainingWorkSeconds`, `overdueWorkSeconds`, and `isWorkOverdue`.
- Show normal active actions:
  - complete/request customer completion,
  - cancel when allowed by product rules,
  - SOS,
  - support/contact.

Important timer rule:

The backend says the timer should only be shown for:

```text
in_progress
time_extension_requested
```

Use:

```text
workTimer.shouldShowWorkTimer
```

as the safest UI flag.

---

### Step 10: Worker requests completion

Normal worker completion flow should call:

```http
POST /api/v1/cleaning-bookings/{id}/complete
```

Optional request body:

```json
{
  "completionMessage": "تم إنهاء العمل بالكامل"
}
```

Backend requires:

```text
status == in_progress
```

Backend updates:

```text
status = awaiting_customer_completion
workFinishedAt = now
workerCompletionMessage = completionMessage
```

Flutter UI:

- Stop showing the normal “Complete” CTA.
- Show waiting-for-customer-completion state.
- Read `completionRequest`:
  - `isAwaitingCustomerConfirmation`
  - `requestedAt`
  - `expiresAt`
  - `message`

The customer can then:

1. approve completion,
2. reject completion,
3. request extra time.

---

### Step 11A: Customer approves completion

User/customer side confirms completion.

Backend updates:

```text
status = completed
customerConfirmedAt = now
```

Flutter worker UI:

- Move order to completed/history.
- Stop timer.
- Disable all order action buttons except read-only/history actions.

---

### Step 11B: Customer rejects completion

User/customer side rejects completion.

Backend updates:

```text
status = in_progress
workFinishedAt = null
```

Flutter worker UI:

- Return to active in-progress screen.
- Show rejection note/message if available.
- Allow worker to continue work and later request completion again.

---

### Step 11C: Customer requests time extension

Customer requests extra time after the worker requested completion.

Backend updates:

```text
status = time_extension_requested
```

Backend also creates a `CleaningTimeWarning` record with:

```text
customer_response = extend_time
worker_response = null
additional_minutes = requested minutes
quoted_amount = calculated extension price
```

Flutter worker UI:

- Show time extension request state.
- Fetch pending warnings:

```http
GET /api/v1/cleaning-time-warnings
```

- Show accept/reject extension actions.

Worker accepts extension:

```http
POST /api/v1/cleaning-time-warnings/{id}/accept
```

Backend updates:

```text
booking.status = in_progress
booking.workFinishedAt = null
extension fee is applied to total price
warning.worker_response = extend_time
```

Worker rejects extension:

```http
POST /api/v1/cleaning-time-warnings/{id}/reject
```

Request body:

```json
{
  "message": "لا يمكنني التمديد"
}
```

Backend updates:

```text
booking.status = completed
booking.customerConfirmedAt = now
warning.worker_response = commit_current_time
```

Important Flutter rule:

Do not send extension automatically from the worker app. The worker app should only respond to an existing customer-created time extension request.

---

### Step 12: Worker direct finish / dispute flow

There is also a worker finish endpoint:

```http
POST /api/v1/cleaning-bookings/{id}/finish
```

Request body for success:

```json
{
  "finish_type": "success"
}
```

Request body for dispute:

```json
{
  "finish_type": "dispute",
  "dispute_reason_type": "customer_terms_violation",
  "dispute_reason_note": "Customer refused agreed terms"
}
```

Backend requires:

```text
status == in_progress
```

If `finish_type = success`, backend updates:

```text
status = completed
workFinishedAt = now
customerConfirmedAt = now
```

If `finish_type = dispute`, backend updates:

```text
status = under_dispute
workFinishedAt = now
workerCompletionMessage = dispute description
```

Flutter recommendation:

- Use `POST /complete` for the normal worker “I finished” flow because it waits for customer confirmation.
- Use `POST /finish` only for a dedicated “finish immediately / open dispute” product flow.
- Do not call both `/complete` and `/finish` for the same tap.

---

### Step 13: Cancellation

Worker cancel endpoint:

```http
POST /api/v1/cleaning-bookings/{id}/cancel
```

Request body:

```json
{
  "reason": "Worker cannot continue"
}
```

Backend allows worker cancellation mainly in:

```text
worker_assigned
in_progress
```

Backend updates:

```text
status = cancelled
cancelledAt = now
cancellationReason = reason
```

Flutter UI:

- Lock actions.
- Stop timer.
- Show cancellation reason.

Customer cancellation can happen while the order is:

```text
pending
worker_assigned
```

The worker app should listen for realtime updates and refresh the order details when `status = cancelled` arrives.

---

## 6. Recommended Worker UI State Machine

Use this priority order in Flutter.

```dart
WorkerOrderUiState resolveWorkerOrderUiState(CleaningOrder order) {
  final status = order.status;
  final workerStatus = order.workerOrderStatus;

  // 1. Global terminal/blocking states always win.
  if (status == 'completed') return WorkerOrderUiState.completed;
  if (status == 'cancelled') return WorkerOrderUiState.cancelled;
  if (status == 'under_dispute') return WorkerOrderUiState.underDispute;

  // 2. Shared active states.
  if (status == 'in_progress') return WorkerOrderUiState.inProgress;
  if (status == 'awaiting_customer_completion') {
    return WorkerOrderUiState.awaitingCustomerCompletion;
  }
  if (status == 'time_extension_requested') {
    return WorkerOrderUiState.timeExtensionRequested;
  }

  // 3. Worker-specific assignment states before work starts.
  if (workerStatus == 'start_approved') {
    return WorkerOrderUiState.waitingOtherWorkersToStart;
  }
  if (workerStatus == 'awaiting_start_verification') {
    return WorkerOrderUiState.readyToStartWork;
  }
  if (workerStatus == 'accepted' || workerStatus == 'accepted_waiting_for_order_start') {
    if (status == 'pending') return WorkerOrderUiState.acceptedWaitingTeam;
    if (status == 'worker_assigned') {
      if (order.startedTravelAt != null && order.arrivedAt == null) {
        return WorkerOrderUiState.traveling;
      }
      if (order.arrivedAt != null) {
        return WorkerOrderUiState.awaitingCustomerStartVerification;
      }
      return WorkerOrderUiState.assignedReadyToTravel;
    }
    if (status == 'awaiting_start_verification') {
      return WorkerOrderUiState.awaitingCustomerStartVerification;
    }
    if (status == 'awaiting_worker_start_confirmation') {
      return WorkerOrderUiState.readyToStartWork;
    }
  }

  if (workerStatus == 'rejected') return WorkerOrderUiState.rejected;
  if (workerStatus == 'withdrawn') return WorkerOrderUiState.withdrawn;

  // Default new order state.
  if (status == 'pending') return WorkerOrderUiState.availableToAccept;

  return WorkerOrderUiState.unknown;
}
```

---

## 7. CTA Matrix for Flutter

| UI State | Main CTA | Endpoint | Notes |
|---|---|---|---|
| Available new order | Accept | `POST /cleaning-bookings/{id}/accept` | Optional `roomIds`. |
| Available new order | Reject | `POST /cleaning-bookings/{id}/reject` | Optional reason depending on UI. |
| Accepted waiting team | Claim/update rooms | `POST /cleaning-bookings/{id}/rooms/claim` | Only while global `status = pending`. |
| Assigned ready to travel | Start Travel | `POST /cleaning-bookings/{id}/start-travel` | Status remains `worker_assigned`; use `startedTravelAt`. |
| Traveling | Send location | `POST /cleaning-bookings/{id}/location` | Only after `startedTravelAt`. |
| Traveling/arrived | Mark Arrived | `POST /cleaning-bookings/{id}/arrive` | Requires started travel. |
| Awaiting customer start verification | Get/show security code | `GET /cleaning-bookings/{id}/security-code` | Customer verifies from user app. |
| Ready to start work | Start Work | `POST /cleaning-bookings/{id}/start-work` | In multi-worker orders, this may only set current worker to `start_approved`. |
| Waiting other workers to start | Refresh/listen | `GET /cleaning-bookings/{id}` / realtime | Wait until global `status = in_progress`. |
| In progress | Complete | `POST /cleaning-bookings/{id}/complete` | Normal flow; waits for customer confirmation. |
| In progress | Finish with dispute | `POST /cleaning-bookings/{id}/finish` | Use only for explicit dispute flow. |
| Awaiting customer completion | Read-only wait | realtime / refresh | Customer decides. |
| Time extension requested | Accept extension | `POST /cleaning-time-warnings/{id}/accept` | Returns warning; refresh booking after response if needed. |
| Time extension requested | Reject extension | `POST /cleaning-time-warnings/{id}/reject` | Rejecting extension completes the order. |
| Worker assigned / in progress | Cancel | `POST /cleaning-bookings/{id}/cancel` | Use confirmation dialog. |
| Active non-terminal | SOS | `POST /cleaning-bookings/{id}/sos` | Not allowed after completed/cancelled. |

---

## 8. Tabs / Lists Recommendation

Recommended worker app tabs:

### New Requests

Show orders where:

```text
status == pending
worker_order_status == pending
```

### Accepted / Waiting Team

Show orders where:

```text
status == pending
worker_order_status in [accepted, accepted_waiting_for_order_start]
```

### Assigned / Go To Location

Show orders where:

```text
status == worker_assigned
```

Use `startedTravelAt` and `arrivedAt` to sub-classify:

```text
startedTravelAt == null => ready to travel
startedTravelAt != null && arrivedAt == null => traveling
arrivedAt != null => arrived / start verification flow
```

### Waiting Start Confirmation

Show orders where:

```text
status in [awaiting_start_verification, awaiting_worker_start_confirmation]
```

Use `worker_order_status`:

```text
awaiting_start_verification => show Start Work after customer verified
start_approved => waiting for other workers
```

### Active

Show orders where:

```text
status in [in_progress, awaiting_customer_completion, time_extension_requested]
```

### History

Show orders where:

```text
status in [completed, cancelled, under_dispute]
```

---

## 9. Realtime Update Handling

The backend broadcasts cleaning booking tracking updates with fields like:

```json
{
  "cleaningBookingId": 101,
  "status": "in_progress",
  "workerId": 7,
  "assignmentMode": "open_count",
  "requiredWorkers": 2,
  "acceptedWorkers": 2,
  "remainingWorkers": 0,
  "startApprovedWorkers": 2,
  "notStartApprovedWorkers": 0,
  "isTeamFulfilled": true,
  "startedTravelAt": "2026-07-01T10:40:00+00:00",
  "arrivedAt": "2026-07-01T11:00:00+00:00",
  "workStartedAt": "2026-07-01T11:05:00+00:00",
  "workFinishedAt": null,
  "isTimerRunning": true,
  "updatedAt": "2026-07-01T11:05:01+00:00"
}
```

Flutter should:

1. Update the list item quickly from the event payload.
2. Fetch the full order details after important lifecycle changes to refresh:
   - `worker_order_status`
   - `myAssignment`
   - `workerAssignments`
   - `completionRequest`
   - `workTimer`
   - `timeWarnings`
3. Never rely only on a realtime event for final UI if the event does not include all nested data.

Important lifecycle statuses that should trigger full refresh:

```text
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

---

## 10. Common Flutter Mistakes to Avoid

### Mistake 1: Treating `pending` as only a new request

In multi-worker orders, a worker can already be accepted while the global order is still `pending`.

Correct logic:

```text
status == pending && worker_order_status == pending => new request
status == pending && worker_order_status == accepted_waiting_for_order_start => accepted, waiting team
```

### Mistake 2: Expecting `start-travel` to change `status`

`start-travel` updates `startedTravelAt`, but keeps:

```text
status = worker_assigned
```

Use `startedTravelAt` to change UI from “Start Travel” to “Traveling”.

### Mistake 3: Starting the timer on arrival

Arrival changes:

```text
status = awaiting_start_verification
```

The timer starts only when:

```text
status = in_progress
workStartedAt != null
workTimer.shouldShowWorkTimer = true
```

### Mistake 4: Showing `Start Work` for all workers after one worker approves

In multi-worker orders, one worker can have:

```text
worker_order_status = start_approved
status = awaiting_worker_start_confirmation
```

That worker should see “waiting for other workers”, not another `Start Work` button.

### Mistake 5: Automatically sending extension requests from the worker app

Extension request is customer-side after completion request.

Worker app should only:

- display the pending extension request,
- accept it,
- reject it.

### Mistake 6: Calling `/finish` after `/complete`

Normal completion should call `/complete` only.

`/finish` is a separate direct finish/dispute endpoint. Calling both can skip or duplicate the intended customer confirmation flow.

### Mistake 7: Ignoring `under_dispute`

`under_dispute` is a global order state. Always check global `status` first and lock normal actions when it appears.

---

## 11. Backend Source Files

Main backend files that define this lifecycle:

- `Modules/Cleaning/app/Enums/CleaningBookingStatus.php`
- `Modules/Cleaning/app/Enums/CleaningBookingWorkerAssignmentStatus.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
- `Modules/Cleaning/app/Services/CleaningBookingService.php`
- `Modules/Cleaning/app/Services/CleaningBookingTeamService.php`
- `Modules/Cleaning/app/Services/CleaningTimeWarningService.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/Cleaning/routes/api.php`

---

## 12. Final Implementation Summary for `cleaning_owner_app`

Flutter should implement the worker flow using this rule:

```text
Use `status` for the real global lifecycle.
Use `worker_order_status` for the current worker's pre-start assignment state.
Use `startedTravelAt`, `arrivedAt`, `workStartedAt`, `workFinishedAt`, and `workTimer.shouldShowWorkTimer` for sub-states that do not always change the global status.
```

The safest order screen logic is:

1. Parse `status` and `worker_order_status` as enums with fallback unknown values.
2. Resolve one local UI state using the priority function in this document.
3. Render buttons from the CTA matrix only.
4. After every mutation endpoint, replace the local order with the API response.
5. On realtime lifecycle events, refresh full order details for nested fields.
6. Never start timers, send extensions, or mark completion automatically from local clock logic.
