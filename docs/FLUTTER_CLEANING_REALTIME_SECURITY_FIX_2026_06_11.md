# Flutter changes for cleaning realtime start security

Date: 2026-06-11

## Summary

The backend realtime start flow now separates two states:

- `awaiting_start_verification`: worker has arrived and the customer must enter the 4-digit code.
- `awaiting_worker_start_confirmation`: the customer entered the correct code, but work is not started until the worker taps start.

This fixes a security issue where the app flow could keep treating a verified order as still waiting for code verification. The worker app must not request or show a fresh security code after the customer has already verified the current one.

## Backend contract change

Customer endpoint:

- `POST /api/v1/user/cleaning/orders/{order}/start-verification/confirm`
- Success response `data.status` is now `awaiting_worker_start_confirmation`, not `in_progress`.
- `workStartedAt` remains `null` until the worker confirms start.
- `customerConfirmedAt` is set when the code is accepted.

Worker endpoint:

- `POST /api/v1/cleaning-bookings/{id}/start-work`
- Valid after `awaiting_worker_start_confirmation`.
- Moves the order to `in_progress` when the required worker approvals are complete.

Security-code endpoint:

- `GET /api/v1/cleaning-bookings/{id}/security-code`
- Valid only before customer verification (`worker_assigned` or `awaiting_start_verification`).
- After customer verification, it returns validation error because the order is now `awaiting_worker_start_confirmation`.

Realtime:

- `ArrivalVerified` payload now includes `status: "awaiting_worker_start_confirmation"`.
- `CleaningBookingTrackingUpdated.tracking.status` also sends `awaiting_worker_start_confirmation` after customer code success.

## `dllni-user-app` changes

Update these files:

- `lib/features/orders/data/models/cleaning_booking_status.dart`
- `lib/features/orders/data/models/cleaning_orders_api_models.dart`
- `lib/features/orders/view/screens/cleaning_order_details_screen.dart`
- `lib/core/realtime/cleaning_realtime_contract.dart`
- related tests under `test/core/realtime` and `test/features/orders`

Required behavior:

1. Add `CleaningBookingStatus.awaitingWorkerStartConfirmation = 'awaiting_worker_start_confirmation'`.
2. Add an Arabic label such as `بانتظار تأكيد مقدم الخدمة لبدء العمل`.
3. After `ConfirmCleaningStartVerificationUseCase` succeeds, close the code dialog and show a waiting state. Do not reopen the code dialog for `awaiting_worker_start_confirmation`.
4. Treat `ArrivalVerified` and `CleaningBookingTrackingUpdated` with this status as a details refresh event.
5. Block cancel/reschedule the same way as `awaiting_start_verification` until product decides otherwise.

## `dllni_cleaning_owner_app` changes

Update these files:

- `lib/features/orders/data/models/cleaning_booking_status.dart`
- `lib/features/orders/view/widgets/order_details/order_details_map_body.dart`
- `lib/features/orders/view/screens/order_details_screen.dart`
- `lib/features/orders/view/screens/orders_screen.dart`
- `lib/features/orders/view/manager/order_notifier.dart`
- related realtime hydration tests

Required behavior:

1. Add `CleaningBookingStatus.awaitingWorkerStartConfirmation = 'awaiting_worker_start_confirmation'`.
2. Include the new status in active order filters, next to `awaiting_start_verification`.
3. When order status is `awaiting_worker_start_confirmation`, stop calling `FetchSecurityCodeEvent`.
4. Show a "customer verified, press start work" action instead of the security-code card.
5. Call existing `StartWorkUseCase` / `POST /api/v1/cleaning-bookings/{id}/start-work` from this state.
6. Handle `ArrivalVerified` and `CleaningBookingTrackingUpdated` by refreshing or hydrating the order to the new status.

## Security notes

- The customer app must never receive, cache, display, or log the plaintext security code.
- The worker app should clear any displayed code when status changes away from `awaiting_start_verification`.
- Do not retry `GET /security-code` once status is `awaiting_worker_start_confirmation`; it should be treated as a completed verification step, not a fetch failure.
