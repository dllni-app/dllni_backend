# Security Deposit System - Flutter Integration Guide

**Last Updated:** 2026-05-30  
**Audience:** Flutter Developer (dllni_cleaning_owner_app)  
**Domain:** `https://dllni.mustafafares.com`

---

## 1. Feature Overview

The Security Deposit System prevents service overload by requiring cleaning workers to maintain a minimum security deposit. When a worker's total revenue exceeds their deposit amount, they're automatically blocked from accepting new requests.

### Business Rules

- Admin sets the minimum deposit amount via the Filament dashboard
- Workers can't accept new requests if their completed revenue exceeds their current deposit balance
- All deposit/withdrawal transactions are managed by admin only
- Workers can view their deposit status and complete transaction history in the app
- Transaction history shows all deposits and withdrawals with timestamps and references

---

## 2. API Endpoints (Worker-Only, Read Access)

### 2.1 GET `/api/v1/cleaning/worker/account/deposit`

**Purpose:** Retrieve worker's current deposit status and eligibility

**Auth:** `Authorization: Bearer {token}` (Sanctum)

**Query Parameters:** None

**Success Response (200 OK):**

```json
{
  "workerId": 42,
  "currentBalance": 200.50,
  "depositedTotal": 381.00,
  "withdrawnTotal": 180.50,
  "minimumRequired": 381.00,
  "status": "insufficient_balance",
  "exceedanceAmount": 100.50,
  "isEligibleForNewRequests": false,
  "createdAt": "2026-05-20T10:30:00Z",
  "updatedAt": "2026-05-30T14:22:00Z"
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `workerId` | int | Worker's ID |
| `currentBalance` | float | Current deposit balance in SYP |
| `depositedTotal` | float | Total amount deposited (cumulative) |
| `withdrawnTotal` | float | Total amount withdrawn (cumulative) |
| `minimumRequired` | float | System-wide minimum deposit amount |
| `status` | string | "active" \| "insufficient_balance" \| "suspended" |
| `exceedanceAmount` | float \| null | Amount by which revenue exceeds deposit (null if within limit) |
| `isEligibleForNewRequests` | boolean | Whether worker can accept new requests |
| `createdAt` | string | ISO 8601 timestamp of account creation |
| `updatedAt` | string | ISO 8601 timestamp of last update |

**Status Explanation:**

- **active** - Revenue is within deposit limit, worker can accept requests
- **insufficient_balance** - Revenue exceeds deposit, worker is blocked
- **suspended** - Account administratively suspended

---

### 2.2 GET `/api/v1/cleaning/worker/account/deposit/transactions`

**Purpose:** Retrieve worker's deposit/withdrawal transaction history

**Auth:** `Authorization: Bearer {token}` (Sanctum)

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number for pagination |
| `perPage` | int | 20 | Records per page (max 100) |
| `type` | string | - | Optional filter: "deposit" or "withdrawal" |

**Example Request:**

```bash
GET /api/v1/cleaning/worker/account/deposit/transactions?page=1&perPage=20&type=deposit
```

**Success Response (200 OK):**

```json
{
  "data": [
    {
      "id": 1001,
      "type": "deposit",
      "amount": 100.00,
      "balanceBefore": 0.00,
      "balanceAfter": 100.00,
      "reference": "BANK-TXN-001",
      "notes": "Initial deposit",
      "createdAt": "2026-05-20T10:30:00Z",
      "updatedAt": "2026-05-20T10:30:00Z"
    },
    {
      "id": 1002,
      "type": "withdrawal",
      "amount": 50.00,
      "balanceBefore": 100.00,
      "balanceAfter": 50.00,
      "reference": "ADM-WDR-001",
      "notes": "Monthly fee deduction",
      "createdAt": "2026-05-25T15:45:00Z",
      "updatedAt": "2026-05-25T15:45:00Z"
    }
  ],
  "meta": {
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 20,
    "total": 2
  }
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Transaction ID |
| `type` | string | "deposit" (admin added) or "withdrawal" (admin removed) |
| `amount` | float | Transaction amount in SYP |
| `balanceBefore` | float | Balance immediately before transaction |
| `balanceAfter` | float | Balance immediately after transaction |
| `reference` | string | Reference code/description (e.g., "BANK-001", "Fee Deduction") |
| `notes` | string \| null | Admin notes explaining the transaction |
| `createdAt` | string | ISO 8601 timestamp when transaction occurred |
| `updatedAt` | string | ISO 8601 timestamp of last update |

---

### 2.3 Extended Homepage Response

The existing `GET /api/v1/cleaning/worker/homepage` endpoint now includes deposit information:

```json
{
  ...existing fields...,
  "securityDeposit": {
    "minimumRequired": 381.00,
    "currentBalance": 200.50,
    "depositedTotal": 381.00,
    "withdrawnTotal": 180.50,
    "status": "insufficient_balance",
    "exceedanceAmount": 100.50,
    "isEligibleForNewRequests": false
  }
}
```

---

## 3. Error Codes & Handling

| Code | Status | Scenario | UI Action |
|------|--------|----------|-----------|
| 401 | Unauthorized | Missing/invalid token | Display login screen, prompt re-authentication |
| 403 | Forbidden | Worker account suspended | Show "Account Suspended" message, disable all interactions |
| 429 | Too Many Requests | Rate limited | Implement exponential backoff, show "Please wait..." message |
| 200 (with insufficient_balance) | OK | Worker blocked from new requests | Disable "Accept Request" button, show blocking banner |

---

## 4. UI Screen Requirements

### 4.1 Deposit Status Card (Homepage/Dashboard)

**Location:** Main dashboard, prominently displayed

**Display Elements:**

- **Current Balance** (large text)
  - Format: "SYP 200.50" with 2 decimal places
  - Color: Dynamic based on percentage

- **Progress Bar** (visual representation)
  - Percentage: `(currentBalance / minimumRequired) * 100`
  - Colors:
    - Green: 0-50% (healthy)
    - Yellow: 51-90% (warning)
    - Red: 91-100%+ (blocked)

- **Status Badge**
  - "Active" (green) when eligible
  - "Low Balance" (yellow) when approaching limit
  - "Blocked" (red) when insufficient

- **Details Line**
  - "Min Deposit: SYP 381.00"
  - "Excess: SYP 100.50" (if applicable)

- **Tap Action:** Navigate to transaction history screen

---

### 4.2 Deposit Transaction History Screen

**Location:** Accessible from deposit status card or settings menu

**Layout:**

```
[Header: "Deposit Transactions"]
[Current Balance Display: SYP 200.50]

[Filters (Optional)]
- Show All
- Deposits Only
- Withdrawals Only

[Transaction List]
├─ [Deposit Icon] +100.00 SYP
│  Bank Transfer (BANK-001)
│  May 20, 2026 10:30 AM
│  Balance After: 100.00 SYP
│
├─ [Withdrawal Icon] -50.00 SYP
│  Monthly Fee (ADM-001)
│  May 25, 2026 3:45 PM
│  Balance After: 50.00 SYP
│
└─ [Empty State if no transactions]
   "No transactions yet"

[Load More / Pagination]
```

**Row Display:**

- **Type Icon** (visual indicator)
  - Arrow down (green) for deposits
  - Arrow up (red) for withdrawals

- **Amount** (bold, colored)
  - Green for deposits (+)
  - Red for withdrawals (-)

- **Reference** (gray)
  - Admin-provided description

- **Timestamp** (small gray)
  - "May 20, 2026 10:30 AM"

- **Expandable Details** (optional tap)
  - Balance before transaction
  - Balance after transaction
  - Admin notes (if any)

**Pagination:**

- Load more button at bottom, OR
- Infinite scroll (auto-load when near end)
- Show "Page 1 of 3" indicator

---

### 4.3 Request Acceptance Status

**When Worker Is Eligible:**

- "Accept Request" button: Enabled, green background
- No warning messages

**When Worker Is Blocked:**

- Red banner message:
  ```
  "⚠️ Deposit Limit Exceeded
   Your revenue has exceeded your security deposit.
   New requests are temporarily blocked.
   Contact admin for assistance."
  ```
- "Accept Request" button: Disabled (gray), not clickable
- Show excess amount: "Exceeded by: SYP 100.50"

---

## 5. Integration Testing Scenarios

### Scenario 1: New Worker with Active Deposit

**Setup:**
- Worker ID: 42
- Deposit: 381 SYP
- Completed Revenue: 0 SYP

**Expected Results:**

```
GET /api/v1/cleaning/worker/account/deposit
Response:
- status: "active"
- isEligibleForNewRequests: true
- exceedanceAmount: null

UI:
- Deposit card shows "Active" (green)
- "Accept Request" button enabled
- Progress bar at 0%
```

**Flutter Test:**

```dart
test('displays active status for new worker', () async {
  final response = await client.get('/api/v1/cleaning/worker/account/deposit');
  
  expect(response['status']).equals('active');
  expect(response['isEligibleForNewRequests']).isTrue();
  
  // Verify UI shows green status
  expect(depositCard.statusBadge.text).equals('Active');
  expect(depositCard.statusBadge.color).equals(Colors.green);
});
```

---

### Scenario 2: Revenue Approaching Limit

**Setup:**
- Worker ID: 42
- Deposit: 381 SYP
- Completed Revenue: 350 SYP
- Balance: 31 SYP remaining

**Expected Results:**

```
GET /api/v1/cleaning/worker/account/deposit
Response:
- status: "active"
- isEligibleForNewRequests: true
- exceedanceAmount: null
- currentBalance: 31.00

UI:
- Deposit card shows "Active" with yellow warning
- Progress bar at 92% (350/381)
- "Min Deposit: SYP 381.00" displayed
```

---

### Scenario 3: Revenue Exceeds Deposit (Blocked)

**Setup:**
- Worker ID: 42
- Deposit: 381 SYP
- Completed Revenue: 420 SYP (exceeds by 39 SYP)

**Expected Results:**

```
GET /api/v1/cleaning/worker/account/deposit
Response:
- status: "insufficient_balance"
- isEligibleForNewRequests: false
- exceedanceAmount: 39.00
- currentBalance: 381.00

UI:
- Deposit card shows "Blocked" (red)
- Progress bar at 110% (420/381), capped visually
- "Exceeded by: SYP 39.00" message
- "Accept Request" button disabled
- Red banner warning displayed
```

**Flutter Test:**

```dart
test('blocks request acceptance when deposit exceeded', () async {
  final response = await client.get('/api/v1/cleaning/worker/account/deposit');
  
  expect(response['isEligibleForNewRequests']).isFalse();
  expect(response['exceedanceAmount']).equals(39.00);
  
  // Verify UI is blocked
  expect(acceptButton.enabled).isFalse();
  expect(depositCard.statusBadge.text).equals('Blocked');
  expect(warningBanner.visible).isTrue();
});
```

---

### Scenario 4: Admin Adds Deposit (Refresh)

**Setup:**
- Worker was blocked (revenue 420, deposit 381)
- Admin adds 50 SYP via dashboard
- Worker pulls to refresh

**Expected Results:**

```
Before Refresh:
- status: "insufficient_balance"
- currentBalance: 381.00

After Refresh (pulls down):
- status: "insufficient_balance" (still blocked, 420 > 431)
- currentBalance: 431.00
- New transaction appears in history: "+50.00 SYP (Admin Deposit)"

UI:
- Transaction list updates immediately
- New entry: "+50.00 SYP | Admin Deposit | May 30, 2026 3:22 PM"
- currentBalance updates: "431.00 SYP"
- Still blocked (worker now needs 431+ to be eligible)
```

**Flutter Test:**

```dart
test('updates transaction history after admin deposit', () async {
  // Simulate pull-to-refresh
  await depositTransactionScreen.pullToRefresh();
  
  // Verify new transaction appears
  expect(transactionList.itemCount).equals(previousCount + 1);
  expect(transactionList.first.type).equals('deposit');
  expect(transactionList.first.amount).equals(50.00);
});
```

---

### Scenario 5: Admin Records Withdrawal (Refresh)

**Setup:**
- Worker balance: 200 SYP
- Admin records: 50 SYP withdrawal
- Worker pulls to refresh

**Expected Results:**

```
GET /api/v1/cleaning/worker/account/deposit/transactions
- New entry appears: {type: "withdrawal", amount: 50.00}

UI:
- Transaction list updates
- New entry: "-50.00 SYP | Monthly Fee | May 30, 2026 3:45 PM"
- Current balance reduced: "150.00 SYP"
```

---

### Scenario 6: Network Error Handling

**Setup:**
- Slow or offline connection
- User tries to load deposit status

**Expected Results:**

```
After 10 seconds (timeout):
- Show error message: "Connection failed. Retry?"
- Retry button appears
- Previous data cached locally (show it)

If offline:
- Show "Offline" indicator
- Display cached data with "Last updated: May 30, 3:22 PM"
- Disable pull-to-refresh
```

**Flutter Test:**

```dart
test('handles network timeout gracefully', () async {
  client.simulateTimeout();
  
  await depositScreen.loadDeposit();
  
  expect(errorBanner.visible).isTrue();
  expect(errorBanner.message).contains('Connection failed');
  expect(retryButton.visible).isTrue();
  expect(cachedDataDisplay.visible).isTrue();
});
```

---

### Scenario 7: Pagination - Load More Transactions

**Setup:**
- Worker has 50+ transactions
- Initial load shows 20 per page

**Expected Results:**

```
Initial Load:
- Shows 20 transactions
- Meta: {currentPage: 1, lastPage: 3, perPage: 20, total: 50}

Scroll to Bottom & Load More:
- Loads next 20 transactions
- "Load More" button still visible (page 2 of 3)

Scroll Again:
- Loads final 10 transactions
- "Load More" button hidden (page 3 of 3, end of list)
```

**Flutter Test:**

```dart
test('pagination loads next page when reaching bottom', () async {
  await transactionList.scrollToBottom();
  
  // Simulate load more tap
  await transactionList.loadMoreButton.tap();
  
  expect(transactionList.itemCount).equals(40);
  expect(transactionList.currentPage).equals(2);
});
```

---

### Scenario 8: Filter Transactions

**Setup:**
- Worker has 5 deposits and 3 withdrawals (total 8)
- User applies filter

**Expected Results:**

```
Initial (All):
- Shows all 8 transactions
- "Show All" selected

Filter: Deposits Only:
- Shows 5 transactions only
- "Deposits Only" selected

Filter: Withdrawals Only:
- Shows 3 transactions only
- "Withdrawals Only" selected

Back to All:
- Shows all 8 again
```

---

## 6. Integration Checklist

- [ ] Add `GET /api/v1/cleaning/worker/account/deposit` endpoint integration
- [ ] Add `GET /api/v1/cleaning/worker/account/deposit/transactions` endpoint integration
- [ ] Create DepositStatusCard widget
- [ ] Create DepositTransactionHistoryScreen widget
- [ ] Add deposit blocking logic to "Accept Request" button
- [ ] Implement pull-to-refresh for deposit data
- [ ] Add error handling with retry logic
- [ ] Implement network timeout handling
- [ ] Add offline support with caching
- [ ] Implement pagination/infinite scroll
- [ ] Add optional transaction filtering (deposit/withdrawal)
- [ ] Test all 8 scenarios above
- [ ] Verify UI colors match design specs
- [ ] Test on multiple devices (phone sizes)
- [ ] Test on slow networks
- [ ] Get QA sign-off

---

## 7. Code Example (Dart/Flutter)

### Fetch Deposit Status

```dart
import 'package:http/http.dart' as http;
import 'dart:convert';

Future<Map<String, dynamic>> getDepositStatus() async {
  final token = await getAuthToken();
  
  final response = await http.get(
    Uri.parse('https://dllni.mustafafares.com/api/v1/cleaning/worker/account/deposit'),
    headers: {
      'Authorization': 'Bearer $token',
      'Content-Type': 'application/json',
    },
  ).timeout(Duration(seconds: 10));

  if (response.statusCode == 200) {
    return jsonDecode(response.body);
  } else if (response.statusCode == 401) {
    // Redirect to login
    throw 'Unauthorized';
  } else {
    throw 'Failed to load deposit status';
  }
}
```

### Fetch Transactions with Pagination

```dart
Future<Map<String, dynamic>> getDepositTransactions({
  required int page,
  required int perPage,
  String? type,
}) async {
  final token = await getAuthToken();
  final params = {
    'page': page.toString(),
    'perPage': perPage.toString(),
    if (type != null) 'type': type,
  };

  final uri = Uri.parse(
    'https://dllni.mustafafares.com/api/v1/cleaning/worker/account/deposit/transactions'
  ).replace(queryParameters: params);

  final response = await http.get(
    uri,
    headers: {
      'Authorization': 'Bearer $token',
      'Content-Type': 'application/json',
    },
  ).timeout(Duration(seconds: 10));

  if (response.statusCode == 200) {
    return jsonDecode(response.body);
  } else {
    throw 'Failed to load transactions';
  }
}
```

---

## 8. Support & Questions

For implementation questions or clarifications, contact the backend team.

Endpoint documentation: `/docs/API_CONTRACT_CLEANING_OWNER_DEPOSIT_SYSTEM.md`
