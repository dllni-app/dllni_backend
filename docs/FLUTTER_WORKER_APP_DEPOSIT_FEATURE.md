# Security Deposit System - Flutter Integration Guide

**Last Updated:** 2026-07-05  
**Audience:** Flutter Developer (dllni_cleaning_owner_app)  
**Domain:** `https://dllni.mustafafares.com`

---

## 1. Feature Overview

The Security Deposit System manages a worker trust account with a configurable balance floor. Workers must maintain enough deposit balance and trust score to receive new offers and start work. Admin fees are debited from the account when a customer confirms booking completion.

### Business Rules

- Admin configures worker finance policy in Filament **Financial Settings**.
- Workers are blocked from new offers when their deposit balance or trust score is below the configured rules.
- Workers must meet `minimumRequired` balance to start travel / begin work.
- Admin fee (`admin_fee` transaction type) is debited at customer-confirmed completion and does not increase `withdrawnTotal`.
- Manual deposit, withdrawal, settlement, refund, and adjustment records are created by admin only.
- Workers can view deposit status, debt amount, eligibility, and full financial movement history in the app.

---

## 2. API Endpoints (Worker-Only, Read Access)

### 2.1 GET `/api/v1/cleaning/worker/account/deposit`

**Purpose:** Retrieve worker's current deposit status and eligibility.

**Auth:** `Authorization: Bearer {token}` (Sanctum)

**Query Parameters:** None

**Success Response (200 OK):**

```json
{
  "workerId": 42,
  "currentBalance": 851500,
  "depositedTotal": 1000000,
  "withdrawnTotal": 0,
  "minimumRequired": 50000,
  "maxNegativeBalance": 0,
  "status": "active",
  "exceedanceAmount": null,
  "isEligibleForNewRequests": true,
  "createdAt": "2026-05-20T10:30:00Z",
  "updatedAt": "2026-05-30T14:22:00Z"
}
```

The Flutter app can derive outstanding debt from existing balance fields when a dedicated debt field is not present:

```text
debtAmount = max(0, depositedTotal - withdrawnTotal - currentBalance)
```

### 2.2 GET `/api/v1/cleaning/worker/account/deposit/transactions`

**Purpose:** Retrieve worker's full financial movement history.

**Auth:** `Authorization: Bearer {token}` (Sanctum)

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number for pagination |
| `perPage` | int | 20 | Records per page, max 100 |
| `type` | string | - | Optional filter: `deposit`, `withdrawal`, `admin_fee`, `settlement`, `refund`, or `adjustment` |

**Example Request:**

```bash
GET /api/v1/cleaning/worker/account/deposit/transactions?page=1&perPage=20&type=admin_fee
```

**Success Response (200 OK):**

```json
{
  "data": [
    {
      "id": 1001,
      "type": "admin_fee",
      "amount": 148500,
      "balanceBefore": 1000000,
      "balanceAfter": 851500,
      "reference": "admin_fee_booking_35",
      "notes": "Admin fee for booking #35",
      "cleaningBookingId": 35,
      "createdAt": "2026-07-04T16:01:00Z",
      "updatedAt": "2026-07-04T16:01:00Z"
    }
  ],
  "meta": {
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 20,
    "total": 1
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Transaction ID |
| `type` | string | `deposit`, `withdrawal`, `admin_fee`, `settlement`, `refund`, or `adjustment` |
| `cleaningBookingId` | int \| null | Linked booking for `admin_fee` rows |
| `amount` | float | Transaction amount in SYP |
| `balanceBefore` | float | Balance immediately before transaction |
| `balanceAfter` | float | Balance immediately after transaction |
| `reference` | string | Reference code or description |
| `notes` | string \| null | Admin notes explaining the transaction |
| `createdAt` | string | ISO 8601 timestamp when transaction occurred |
| `updatedAt` | string | ISO 8601 timestamp of last update |

### 2.3 Extended Homepage Response

The existing `GET /api/v1/cleaning/worker/homepage` endpoint includes deposit information under `depositSummary` and `amountSummary.depositAccountBalance`.

---

## 3. UI Screen Requirements

### 3.1 Financial Wallet Screen

Display these sections:

- Trust score card.
- Amount summary card.
- **Debt card (`المديونية`)** using `debtAmount` when provided, otherwise deriving it from `depositedTotal`, `withdrawnTotal`, and `currentBalance`.
- Deposit status card.
- History switcher:
  - `سجل الطلبات`
  - `سجل الحركة المالية`
- Financial movement tabs:
  - `الكل`
  - `إيداع`
  - `سحب`
  - `مديونية`
  - `تسوية`
  - `استرداد`
  - `تعديل`

Admin fee rows should be shown to the worker as debt movements, not as generic withdrawals.
