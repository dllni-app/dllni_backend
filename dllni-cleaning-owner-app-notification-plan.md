# Dllni Cleaning Owner App Notification Plan

## 1) Scope
- Implement worker notification-center flow backed by canonical cleaning lifecycle notifications.
- Keep worker realtime channel for active screens and use push/feed for background reliability.

## 2) Backend Contract (Now Available)
- Worker notification feed:
  - `GET /api/v1/cleaning/worker/account/notifications`
  - `PATCH /api/v1/cleaning/worker/account/notifications/{id}/read`
  - `PATCH /api/v1/cleaning/worker/account/notifications/read-all`
- FCM token registration:
  - `PUT /api/v1/cleaning/worker/account/notifications/token`
  - Accepted keys: `fcmToken`, `fcm_token`, `deviceToken`, `device_token`, `pushToken`, `push_token`, `token`
- Canonical worker-targeted lifecycle types:
  - `cleaning.booking.start_verified`
  - `cleaning.booking.completion_approved`
  - `cleaning.booking.completion_rejected`
  - `cleaning.booking.time_extension_requested`
  - `cleaning.booking.order_cancelled`
  - Existing flows also include `cleaning.booking.new_order_request`, `cleaning.booking.extension_request`, `cleaning.booking.dispute_opened`
- Payload keys to consume:
  - `type`, `canonical_type`, `module`, `title`, `message`, `body`
  - `bookingId`, `orderId`, `status`, `action`, `deep_link_target`, `occurred_at`

## 3) App Tasks
1. Register/refresh worker FCM token via worker token endpoint.
2. Build notification feed screen with pagination, mark-one, mark-all.
3. Canonical type routing table:
   - `start_verified` -> booking details (work can proceed confirmation state)
   - `completion_approved` -> booking details (closed/complete state)
   - `completion_rejected` -> booking details (resume work state)
   - `time_extension_requested` -> open extension handling flow
   - `order_cancelled` -> booking details (cancelled banner + close actions)
4. Push tap deep-link:
   - Resolve by `bookingId` first, fallback `orderId`.
   - Always re-fetch booking state before rendering action buttons.
5. Duplicate suppression:
   - Idempotency key: `bookingId + action + occurred_at`.
   - Skip second apply when same transition already came via realtime socket event.

## 4) Worker UX Rules
- If booking terminal (`completed`/`cancelled`), hide progression action buttons.
- If `completion_rejected`, highlight required next action (`start-work`/continue work).
- If `time_extension_requested`, prioritize extension decision UI entry.
- Keep notification center badge synced with unread count from feed.

## 5) QA Checklist
1. New order + lifecycle pushes appear in feed and open correct booking.
2. Read-all marks feed items consistently server-side.
3. Foreground realtime + push does not duplicate status transitions.
4. Background/terminated push opens correct worker flow.
5. Token rotation (reinstall/login) updates token and keeps push delivery stable.

## 6) Rollout
1. Internal QA only with verbose notification logging.
2. Pilot rollout to selected workers.
3. Validate push-to-open and action completion funnels.
4. Full rollout after duplicate and routing metrics are stable.
