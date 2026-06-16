## Cleaning Notifications Flow Audit + UX Upgrade Plan

### Summary
- Baseline scope is `Dllni_backend`, `dllni-user-app`, and `dllni_cleaning_owner_app`.
- Current cleaning lifecycle states to anchor all notifications: `pending -> worker_assigned -> awaiting_start_verification -> in_progress -> awaiting_customer_completion -> (completed | in_progress | time_extension_requested)`, plus `cancelled`.
- Confirmed user app endpoints in use:
  - `/api/v1/user/cleaning/orders` (+ status actions: cancel, start-verification confirm, completion confirm/reject/extend-time, review)
  - `/api/v1/user/notifications` (+ read/read-all)
- Confirmed cleaning owner app endpoints in use:
  - `/api/v1/cleaning-bookings/{id}/accept|reject|start-travel|arrive|start-work|complete|cancel|location|security-code`
  - `/api/v1/cleaning-time-warnings` (+ accept/reject)
- Current pushed notification families in backend are limited (`new_order_request`, `extension_request`, `dispute_opened`, generic booking lifecycle), while many lifecycle moments are realtime-only; this is the core UX gap for background/offline users.

### Key Implementation Changes
- Backend notification contract hardening:
  - Define a canonical cleaning notification type set for lifecycle milestones, minimum: `worker_assigned`, `worker_started_travel`, `worker_arrived`, `start_verified`, `completion_requested`, `completion_approved`, `completion_rejected`, `time_extension_requested`, `order_cancelled`.
  - Standardize payload shape for all cleaning notifications: `type`, `module=cleaning`, `bookingId`, `orderId`, `status`, `action`, `deep_link_target`, `title`, `message`, `occurred_at`.
  - Dispatch both `database` and `push` notifications at each lifecycle transition (service-layer trigger points), preserving existing events/routes for backward compatibility.
  - Verify and finalize FCM registration path/contract so both apps reliably persist token updates in backend.
- `dllni-user-app` alignment:
  - Keep realtime order-screen hydration, but add robust push/deeplink handling for all new canonical types.
  - Ensure notification tap always routes to order details using `bookingId/orderId` from payload.
  - Add duplicate suppression between realtime-triggered refresh and push-triggered refresh.
- `dllni_cleaning_owner_app` alignment:
  - Implement worker notification feed consumption (list/read/read-all) and notification-center UI wiring.
  - Map push types to worker actions (open booking details, open extension/warning flow, open dispute flow).
  - Keep worker-channel realtime updates for foreground responsiveness, with same duplicate-suppression strategy.
- Public API/interface additions:
  - Add canonical cleaning type constants in backend notification config + shared Flutter mapping tables.
  - Add/confirm explicit FCM token registration API contract with accepted key alias handling needed by both apps.

### Test Plan
- State-to-notification matrix tests (backend):
  - Every lifecycle transition emits correct canonical type, recipient audience (user vs worker), and payload keys.
  - Existing notification types continue to work unchanged.
- Integration tests (backend + mobile contract):
  - User flow: assign -> travel -> arrive -> start verified -> completion requested -> approve/reject/extend.
  - Worker flow: new order request, customer decisions, cancellation, extension outcomes.
- Mobile behavior tests:
  - Foreground: realtime updates render once and do not double-apply.
  - Background/terminated: push arrives, tap deep-links correctly, order screen reflects latest server state.
  - Notification feed read/read-all sync stays consistent after deep-link navigation.
- Regression checks:
  - Existing endpoints and event names remain compatible.
  - No broken behavior for apps that still rely on current realtime event names.

### Assumptions and Defaults
- Target worker app is `dllni_cleaning_owner_app` (used as intended replacement for `dllni_cleaning_app`).
- Delivery mode is audit + concrete implementation backlog.
- UX priority is push + deep links first, with realtime retained for live in-screen updates.
- Backward compatibility is required: no breaking route changes, no removal of existing event names/types during rollout.
