# Cleaning Multi-Worker Independent Lifecycle

This document defines the backend contract for cleaning orders that require more than one worker. A multi-worker cleaning booking is an aggregate order, but every worker assignment has an independent operational lifecycle.

## Core Rule

For multi-worker orders, do not use the global booking status as the only source of truth for worker actions. Each worker must be controlled by its own `workerAssignments[]` row.

The global booking status is only an aggregate UI/helper status. The worker-specific source of truth is:

```json
workerAssignments[].status
```

## Independent Worker Flow

Each worker independently goes through:

```text
accepted_waiting_for_order_start
→ travel started
→ arrived
→ awaiting_start_verification
→ start_approved
→ in_progress
→ awaiting_customer_completion
→ completed
```

The booking becomes `completed` only when all required accepted workers are completed.

## Security Code Flow

Each arrived worker gets a separate security code scoped by `worker_id`.

The same endpoint is used:

```http
GET /api/v1/cleaning-bookings/{bookingId}/security-code
```

The generated code belongs only to the authenticated worker assignment. Confirming Worker 1's code does not approve Worker 2.

Customer confirmation endpoint remains unchanged:

```http
POST /api/v1/user/cleaning/orders/{orderId}/start-verification/confirm
```

Payload:

```json
{
  "code": "1234"
}
```

## Completion Requests

When a worker finishes, only that worker assignment moves to:

```text
awaiting_customer_completion
```

The existing backward-compatible field still exists:

```json
completionRequest
```

It contains the first pending worker completion request.

For complete multi-worker UI support, use:

```json
completionRequests
```

Example:

```json
{
  "completionRequests": [
    {
      "assignmentId": 12,
      "workerId": 44,
      "worker": {
        "id": 44,
        "firstName": "Worker Name"
      },
      "message": "Finished kitchen and living room.",
      "requestedAt": "2026-07-10T10:15:00+00:00",
      "expiresAt": "2026-07-10T10:45:00+00:00",
      "finishedCleaningServices": [],
      "finishedPropertyRooms": [],
      "actions": {
        "canConfirm": true,
        "canReject": true,
        "canRequestExtension": true
      }
    }
  ]
}
```

## Customer Completion Decision Targeting

The endpoints remain unchanged:

```http
POST /api/v1/user/cleaning/orders/{orderId}/completion/confirm
POST /api/v1/user/cleaning/orders/{orderId}/completion/reject
POST /api/v1/user/cleaning/orders/{orderId}/completion/extend-time
```

The payload can optionally target a specific worker assignment:

```json
{
  "workerId": 44
}
```

or:

```json
{
  "assignmentId": 12
}
```

Snake case is also supported:

```json
{
  "worker_id": 44,
  "assignment_id": 12
}
```

Backward compatibility rule:

- If `workerId` / `assignmentId` is sent, the decision applies only to that assignment.
- If no target is sent, the backend applies the decision to the first pending completion request.

## Extension Request Targeting

When the customer requests extra time from the completion screen, the extension warning is scoped to the selected worker by `worker_id` in `cleaning_time_warnings`.

Example:

```json
{
  "workerId": 44,
  "additionalMinutes": 30,
  "message": "Please continue this worker only."
}
```

Only that worker should see and respond to the extension warning.

## Worker Lifecycle Summary

The booking response includes:

```json
workerLifecycleSummary
```

Example:

```json
{
  "workerLifecycleSummary": {
    "required": 2,
    "accepted": 2,
    "remaining": 0,
    "acceptedWaitingForOrderStart": 0,
    "awaitingStartVerification": 0,
    "startApproved": 0,
    "inProgress": 1,
    "awaitingCustomerCompletion": 1,
    "timeExtensionRequested": 0,
    "completed": 0,
    "rejected": 0,
    "withdrawn": 0,
    "cancelled": 0,
    "isFullyCompleted": false
  }
}
```

Use this object for aggregate worker cards and progress UI instead of relying only on the global booking status.

## Aggregate Booking Status Priority

When computing global booking status for multi-worker bookings:

1. If any worker is `awaiting_customer_completion`, global status should remain `awaiting_customer_completion`.
2. Else if any worker is `time_extension_requested`, global status should be `time_extension_requested`.
3. Else if any worker is `in_progress`, global status should be `in_progress`.
4. The booking becomes `completed` only when all required workers are completed.

## Flutter Notes

Current Flutter code can continue using `completionRequest`.

For the complete multi-worker UX, Flutter should render `completionRequests[]` as multiple cards, one per worker waiting for customer action.

When confirming/rejecting/requesting extension from a worker card, Flutter should send either `workerId` or `assignmentId` with the existing endpoint.
