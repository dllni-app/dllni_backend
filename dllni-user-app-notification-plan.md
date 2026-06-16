# Dllni User App Notification Plan (Cleaning)

## 1) Scope
- Integrate push + in-app feed for cleaning lifecycle notifications using the backend canonical contract.
- Keep realtime order-screen updates, but prevent duplicate state application when both realtime and push arrive.

## 2) Backend Contract (Now Available)
- Feed:
  - `GET /api/v1/user/notifications`
  - `PATCH /api/v1/user/notifications/{id}/read`
  - `PATCH /api/v1/user/notifications/read-all`
- FCM token registration:
  - `PUT /api/v1/user/notifications/token`
  - Accepted keys: `fcmToken`, `fcm_token`, `deviceToken`, `device_token`, `pushToken`, `push_token`, `token`
- Canonical cleaning lifecycle types:
  - `cleaning.booking.worker_assigned`
  - `cleaning.booking.worker_started_travel`
  - `cleaning.booking.worker_arrived`
  - `cleaning.booking.completion_requested`
  - `cleaning.booking.order_cancelled`
- Customer-targeted payload keys to consume:
  - `type`, `canonical_type`, `module`, `title`, `message`, `body`
  - `bookingId`, `orderId`, `status`, `action`, `deep_link_target`, `occurred_at`

## 3) App Tasks
1. Register FCM token on app boot/login refresh using `PUT /api/v1/user/notifications/token`.
2. Build canonical type mapper (`canonical_type` preferred, fallback `type`).
3. On push tap:
   - Read `bookingId`/`orderId`
   - Navigate to cleaning order details directly.
4. Notification center:
   - Load paginated notifications from feed endpoint.
   - Support mark-one and mark-all as read.
5. Duplicate suppression:
   - Use `bookingId + action + occurred_at` as idempotency key.
   - If realtime already applied same transition, skip second apply from push.
6. Order details refresh policy:
   - On push tap, always re-fetch latest order snapshot before rendering state badge.

## 4) UI Behavior Mapping
- `worker_assigned` -> show assigned state + worker card.
- `worker_started_travel` -> show en-route state and tracking CTA.
- `worker_arrived` -> show arrival state and verification hint.
- `completion_requested` -> open completion confirmation sheet.
- `order_cancelled` -> show cancelled summary and disable action CTAs.

## 5) QA Checklist
1. Foreground: push received while order screen open does not double-apply.
2. Background: push tap opens correct order directly.
3. Terminated: cold start from push preserves navigation intent.
4. Feed read state remains consistent after deep-link open.
5. Token refresh after logout/login updates backend token successfully.

## 6) Rollout
1. Ship feature-flagged canonical mapper + token registration.
2. Enable for internal QA users.
3. Enable for 10% users, monitor notification open-to-order success.
4. Full rollout when no duplicate or routing regressions are observed.
