# API Contract for Flutter - Cleaning Owner Home Dashboard

**Audience:** Flutter developer (`dllni_cleaning_owner_app`)  
**Domain:** `https://dllni.mustafafares.com`  
**Scope:** Home dashboard payload for cleaning owner/worker app.

---

## 1. Endpoint

| Method | Path | Description |
| --- | --- | --- |
| GET | `/api/v1/cleaning/worker/homepage` | Returns dashboard summary + chart data for authenticated worker |

**Auth:** `Authorization: Bearer {token}` (Sanctum)  
**Content-Type:** `application/json`  
**Query params:** none

If authenticated user has no related worker profile, endpoint still returns `200` with zeroed values and empty/zero-filled chart blocks.

---

## 2. Success Response (200)

```json
{
  "date": "2026-05-27",
  "totalBookings": 42,
  "todayCount": 3,
  "completedCount": 30,
  "pendingCount": 7,
  "inProgressCount": 2,
  "cancelledCount": 3,
  "totalEarnings": 8450000,
  "todayEarnings": 420000,
  "earningsChangePercent": 11.4,
  "newOrdersCount": 4,
  "pendingExtensionRequestsCount": 1,
  "amountSummary": {
    "period": "last_4_weeks",
    "currency": "SYP",
    "workerAmount": 8450000,
    "adminAmount": 1950000,
    "grossInvoicesAmount": 10400000
  },
  "bookingsWeeklyChart": [
    { "date": "2026-05-25", "dayKey": "monday", "dayLabelAr": "monday_ar", "bookingsCount": 8 },
    { "date": "2026-05-26", "dayKey": "tuesday", "dayLabelAr": "tuesday_ar", "bookingsCount": 12 },
    { "date": "2026-05-27", "dayKey": "wednesday", "dayLabelAr": "wednesday_ar", "bookingsCount": 10 },
    { "date": "2026-05-28", "dayKey": "thursday", "dayLabelAr": "thursday_ar", "bookingsCount": 14 },
    { "date": "2026-05-29", "dayKey": "friday", "dayLabelAr": "friday_ar", "bookingsCount": 9 },
    { "date": "2026-05-30", "dayKey": "saturday", "dayLabelAr": "saturday_ar", "bookingsCount": 11 },
    { "date": "2026-05-31", "dayKey": "sunday", "dayLabelAr": "sunday_ar", "bookingsCount": 13 }
  ],
  "invoicesFourWeeksChart": [
    { "weekNumber": 1, "label": "week_1", "from": "2026-05-04", "to": "2026-05-10", "invoiceAmount": 420000, "invoiceAmountThousands": 420 },
    { "weekNumber": 2, "label": "week_2", "from": "2026-05-11", "to": "2026-05-17", "invoiceAmount": 510000, "invoiceAmountThousands": 510 },
    { "weekNumber": 3, "label": "week_3", "from": "2026-05-18", "to": "2026-05-24", "invoiceAmount": 475000, "invoiceAmountThousands": 475 },
    { "weekNumber": 4, "label": "week_4", "from": "2026-05-25", "to": "2026-05-31", "invoiceAmount": 560000, "invoiceAmountThousands": 560 }
  ]
}
```

---

## 3. Top-Level Fields

| Field | Type | Description |
| --- | --- | --- |
| `date` | string (`YYYY-MM-DD`) | Server date for this snapshot |
| `totalBookings` | int | Total bookings linked to worker |
| `todayCount` | int | Today bookings (excluding cancelled) |
| `completedCount` | int | Bookings in `completed` |
| `pendingCount` | int | Upcoming bookings in `pending` or `worker_assigned` |
| `inProgressCount` | int | Bookings in `in_progress` |
| `cancelledCount` | int | Bookings in `cancelled` |
| `totalEarnings` | number | Sum of completed booking `total_price` |
| `todayEarnings` | number | Sum of completed booking `total_price` for today |
| `earningsChangePercent` | number | Today earnings delta vs yesterday |
| `newOrdersCount` | int | Available pending orders for worker |
| `pendingExtensionRequestsCount` | int | Pending time-extension requests |

---

## 4. `amountSummary` Object

| Field | Type | Description |
| --- | --- | --- |
| `period` | string | Current fixed value: `last_4_weeks` |
| `currency` | string | Current fixed value: `SYP` |
| `workerAmount` | number | Worker net amount over last 4 weeks (`gross - admin`) |
| `adminAmount` | number | Admin margin total over last 4 weeks |
| `grossInvoicesAmount` | number | Completed invoices total over last 4 weeks |

---

## 5. `bookingsWeeklyChart` Array

Always 7 items, **Monday -> Sunday**.

| Field | Type | Description |
| --- | --- | --- |
| `date` | string (`YYYY-MM-DD`) | Calendar date for each day |
| `dayKey` | string | `monday`, `tuesday`, ... `sunday` |
| `dayLabelAr` | string | Arabic day label for direct rendering (example: localized day name) |
| `bookingsCount` | int | Non-cancelled bookings count for that day |

---

## 6. `invoicesFourWeeksChart` Array

Always 4 items for rolling 4-week window (oldest to newest).

| Field | Type | Description |
| --- | --- | --- |
| `weekNumber` | int | 1..4 |
| `label` | string | `week_1` .. `week_4` |
| `from` | string (`YYYY-MM-DD`) | Week segment start |
| `to` | string (`YYYY-MM-DD`) | Week segment end |
| `invoiceAmount` | number | Completed invoices amount in base currency |
| `invoiceAmountThousands` | number | `invoiceAmount / 1000` for "thousands" chart mode |

---

## 7. Flutter UI Mapping

- **Amount summary card**
  - `amountSummary.workerAmount` -> worker amount label
  - `amountSummary.adminAmount` -> admin amount label
- **Bookings chart**
  - `bookingsWeeklyChart[].dayLabelAr` -> x-axis label
  - `bookingsWeeklyChart[].bookingsCount` -> bar height/value
- **Invoices chart (thousands mode)**
  - `invoicesFourWeeksChart[].label` -> x-axis label (`week 1..4` in UI localization)
  - `invoicesFourWeeksChart[].invoiceAmountThousands` -> bar value

---

## 8. Error Cases

- `401 Unauthorized`: invalid/missing token.
- `403 Forbidden`: only if auth context blocked before controller; controller itself returns 200-zero payload when no worker relation exists.

---

## 9. Backward Compatibility Note

This endpoint remains backward compatible with existing clients:

- Existing top-level fields are unchanged.
- New dashboard blocks are additive:
  - `amountSummary`
  - `bookingsWeeklyChart`
  - `invoicesFourWeeksChart`
