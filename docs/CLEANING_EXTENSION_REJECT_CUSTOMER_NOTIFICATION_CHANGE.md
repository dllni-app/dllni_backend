# Cleaning Extension Reject Customer Notification Change

## Summary

This backend change ensures the **customer** is notified when a worker rejects a time-extension request for a cleaning booking.

Previously, the rejection lifecycle notification (`cleaning.booking.time_extension_rejected`) was sent only to the worker context in `CleaningTimeWarningWorkerNotificationService`.  
Now, the same lifecycle notification is also sent to the booking customer.

## Branch and Commit

- Branch: `fix/cleaning-extension-reject-customer-notify`
- Commit: `63ba108acde9c594ea5309e9789435afda3b6da3`

## Changed Files

1. `Modules/Cleaning/app/Services/CleaningTimeWarningWorkerNotificationService.php`
2. `tests/Feature/Cleaning/CleaningTimeWarningActionsTest.php`

## Behavior Change

### Before

- Worker rejects extension request via:
  - `POST /api/v1/cleaning-time-warnings/{id}/reject`
- Service sent lifecycle notification to worker (`notifyWorkerById` or fallback `notifyWorker`).
- Customer did not receive the corresponding notification payload for this action.

### After

- Worker notification behavior remains unchanged.
- Service additionally calls `notifyCustomer(...)` using:
  - `canonicalType: cleaning.booking.time_extension_rejected`
  - `action: time_extension_rejected`
  - `actorRole: worker`
- Extra payload now includes:
  - `message`
  - `workerRejectMessage`
  - `worker_reject_message`
  - `warningId`
  - `assignmentId`

This makes the customer-side notification feed/push payload include the worker rejection message consistently.

## Test Coverage Added

In `CleaningTimeWarningActionsTest`:

- Added test: `it('notifies the customer when worker rejects an extension request', ...)`
- Verifies:
  - notification sent to booking customer
  - notification type is `cleaning.booking.time_extension_rejected`
  - target role is `customer`
  - payload contains `workerRejectMessage` with the rejection message

## API Contract Impact

- No endpoint or request shape changes.
- No route changes.
- Change is notification-targeting and payload enrichment only.
