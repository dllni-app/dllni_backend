# API Contract: Cleaning Order Travel Fee in Flutter Details

This document explains how Flutter should handle the cleaning order delivery/travel service price in the order details screen.

## Scope

Applies to:

- `GET /api/v1/user/cleaning/orders/{orderId}`
- Cleaning order details screen in the user app.
- The displayed row currently labeled `رسوم التنقل`.

Not changed:

- Restaurant orders.
- Delivery-company orders.
- Cleaning price calculation formulas.
- Backend response field names.

## Backend Response Field

The cleaning order details API returns the delivery/travel service price as:

```json
{
  "data": {
    "travelFee": 111.19,
    "travelDistanceKm": 11.119,
    "adminMargin": 103.12,
    "isPricingFinal": true,
    "totalPrice": 1134.31
  }
}
```

Flutter should continue reading:

```text
data.travelFee
```

The snake_case alias may also appear in older/internal payloads:

```text
data.travel_fee
```

## Important Pricing Behavior

`travelFee = 0` is valid when the cleaning price is still provisional.

Backend provisional pricing rules:

- If no preferred worker is selected, `travelFee` is `0`.
- If the order is still searching for workers, `travelFee` is `0`.
- For multi-worker orders, booking-level `travelFee` remains `0` until the required team is fulfilled.
- When a preferred worker is selected at create/estimate time, backend can calculate `travelFee` immediately.
- When a worker accepts and the booking becomes finalized, backend recalculates and returns the final `travelFee`.

Use `isPricingFinal` to decide whether `travelFee` is final.

## Flutter Mapping

Current Flutter mapping is correct:

```dart
travelFee: _toDouble(_pick(m, const <String>['travelFee', 'travel_fee'])),
```

Do not change the model field name. The details screen should keep using:

```dart
order.travelFee
```

## Required Flutter UX Change

The issue is not that Flutter reads the wrong field. The issue is that the details screen displays `0` without explaining that the price is provisional.

When:

```text
order.isPricingFinal == false
```

Flutter should show a provisional pricing note near the order summary, similar to the note already used in the create-order summary:

```text
السعر المعروض تقديري وغير نهائي، وسيتم تأكيد السعر النهائي بعد قبول مقدم الخدمة للطلب.
```

Recommended behavior:

- Keep showing `travelFee` if returned.
- If `travelFee == 0` and `isPricingFinal == false`, show `0` with the provisional note.
- Do not treat `0` as a parsing error.
- Do not replace `travelFee` with another field.

## Optional Multi-Worker Display Enhancement

For multi-worker orders, after some workers accept but before the full team is complete, booking-level `travelFee` may still be `0`.

If Flutter wants to show partial accepted-worker fees, it can read:

```text
data.workerAssignments[].travelFee
```

Only use this as an additional partial/team breakdown. Do not replace booking-level `data.travelFee` unless product explicitly wants partial totals.

## Validation Checklist for Flutter

1. Call `GET /api/v1/user/cleaning/orders/{orderId}`.
2. Read `data.travelFee`.
3. Read `data.isPricingFinal`.
4. If `isPricingFinal == false`, show the provisional pricing message.
5. If `isPricingFinal == true` and `travelFee` is still `0`, confirm backend financial setting `cleaning_financial_settings.travel_per_km` is not `0`.

## Example States

### Open-count order before worker acceptance

```json
{
  "data": {
    "status": "pending",
    "assignmentMode": "open_count",
    "travelFee": 0,
    "travelDistanceKm": null,
    "adminMargin": 0,
    "isPricingFinal": false
  }
}
```

Flutter should display the provisional pricing note.

### Preferred-worker order or accepted single-worker order

```json
{
  "data": {
    "status": "worker_assigned",
    "assignmentMode": "preferred_worker",
    "travelFee": 111.19,
    "travelDistanceKm": 11.119,
    "adminMargin": 103.12,
    "isPricingFinal": true
  }
}
```

Flutter should display the returned `travelFee` normally.

## Backend Investigation Result

Backend currently returns `travelFee` from `cleaning_bookings.travel_fee` through `CleaningBookingResource`.

No backend field rename or serializer change is required for this issue.

If finalized orders still return `travelFee = 0`, check backend data/configuration:

- `cleaning_financial_settings.travel_per_km`
- Worker home coordinates.
- Customer address coordinates.
- Whether the booking is still provisional.
