# Cleaning Financial Penalties Implementation Plan

## 1. Goal

Add an auditable financial-penalty workflow for cancelled cleaning bookings in the Filament dashboard.

The implementation must allow the admin to:

- Open a cancelled cleaning booking.
- See exactly who cancelled the booking and the cancellation notes.
- See how long before or after the scheduled start time the cancellation happened.
- See the status of every assigned worker immediately before the booking was cancelled.
- Add one manually entered financial penalty to the worker who cancelled the booking.
- Notify that worker that a penalty was imposed.
- View financial penalties from a dedicated cleaning dashboard page.
- See the worker's current financial-penalty value inside the `إحصائيات` section.
- Reset the displayed penalty value according to the confirmed deposit/debt settlement rule.

This plan is for the backend and Filament dashboard. Existing Flutter API contracts must remain backward compatible.

---

## 2. Confirmed Business Decisions

The following decisions are final for this feature:

1. Customer penalty collection is not managed by the platform.
2. This feature applies financial penalties to workers only.
3. Only the worker who cancelled the booking may receive the penalty.
4. Only one penalty is allowed per cancelled booking.
5. The penalty is available for any cancelled booking cancelled by a worker; there is no late-cancellation threshold.
6. The admin enters the penalty amount manually.
7. The penalty amount must not exceed the booking total price.
8. A penalty has one financial source only in the business/UI model:
   - `deposit`, when it is fully covered by the worker deposit.
   - `debt`, when the charge results in worker debt.
9. Do not expose or store a split penalty state in the penalty domain/UI.
10. A deposit-backed penalty is reset when the worker account is fully refunded.
11. A debt-backed penalty is reset when a later deposit clears the worker debt.
12. Partial penalty clearing is not part of the feature.
13. Penalties cannot be reversed, voided, edited, or deleted after creation.
14. The notification text is approved:

```text
تم فرض غرامة مالية عليك بقيمة {amount} بسبب إنهاء الطلب رقم {bookingNumber} في وقت متأخر.
```

15. There is no public financial transaction type called `commission`. Remove that type and its user-facing terminology from the cleaning financial flow.

### Important terminology boundary

Removing the `commission` transaction type does not remove the existing order pricing field `admin_margin_amount` unless separately requested. The order may still calculate an administration margin, but its financial ledger effect must be represented as debt/financial due, not as a public transaction category named commission.

---

## 3. Current Repository Findings

### Existing cancellation data

`cleaning_bookings` already stores:

- `cancelled_at`
- `cancellation_reason`
- `cancelled_by_role`

The booking API resource and Filament booking table already expose part of this information.

### Cancellation gaps

Current cancellation flows are not fully consistent:

- Worker cancellation does not always explicitly save `cancelled_by_role = worker`.
- The exact worker actor is not stored reliably.
- Multi-worker assignment statuses are mutable and are not snapshotted before global cancellation.
- The duration between cancellation and scheduled start is not stored as an immutable value.

### Existing worker financial ledger

The current cleaning financial system already has:

- Worker deposit balance.
- Worker debt balance.
- Deposit, debt, settlement, and refund transactions.
- A central `DepositService` with row locking and transactions.
- Admin deposit/refund flows.
- Worker financial eligibility synchronization.

The penalty implementation must reuse this account and ledger infrastructure instead of creating a second wallet.

### Existing penalty pattern

`DisputeFinancialPenaltyService` already demonstrates useful implementation patterns:

- Validate the worker belongs to the booking.
- Use an idempotent financial reference.
- Create the balance transaction inside a database transaction.
- Save the transaction ID on a dedicated business record.
- Save the amount, notes, applying admin, and timestamp.

The cancellation penalty should follow this pattern through a dedicated penalty entity.

### Existing ledger constraint

A previous migration intentionally removed `cleaning_booking_id` from `cleaning_deposit_transactions`.

Do not restore that foreign key. Keep the booking relationship in the dedicated financial-penalty table and reference the resulting ledger transaction from that record.

---

## 4. Recommended Domain Model

Create:

- Model: `CleaningFinancialPenalty`
- Table: `cleaning_financial_penalties`

### Recommended columns

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint | Primary key |
| `cleaning_booking_id` | foreignId unique | The cancelled booking; enforces one penalty per booking |
| `worker_id` | foreignId | Worker who cancelled and received the penalty |
| `financial_transaction_id` | foreignId nullable unique | Ledger transaction produced by the charge |
| `financial_source` | enum/string | `deposit` or `debt` |
| `amount` | decimal(12,2) | Original penalty amount |
| `status` | enum/string | `active` or `cleared` |
| `notes` | text | Required admin notes |
| `cancellation_reason_snapshot` | text nullable | Immutable cancellation reason at application time |
| `cancellation_offset_minutes` | signed integer nullable | Immutable cancellation timing snapshot |
| `applied_by_admin_id` | foreignId | Admin who applied the penalty |
| `applied_at` | timestamp | Penalty creation time |
| `cleared_at` | timestamp nullable | When the statistics value was reset |
| timestamps | timestamps | Audit fields |

### Model constraints

- `cleaning_booking_id` must be unique.
- The booking must have global status `cancelled`.
- `cancelled_by_role` must equal `worker`.
- `worker_id` must equal the exact worker cancellation actor stored on the booking.
- The worker must belong to the booking or one of its assignments.
- Amount must be greater than zero.
- Amount must be less than or equal to `cleaning_bookings.total_price`.
- Notes are required.
- The record is append-only after creation.
- No edit, delete, reverse, or void actions.

### Relationships

Add:

- `CleaningBooking::financialPenalty(): HasOne`
- `CleaningFinancialPenalty::booking(): BelongsTo`
- `CleaningFinancialPenalty::worker(): BelongsTo`
- `CleaningFinancialPenalty::financialTransaction(): BelongsTo`
- `CleaningFinancialPenalty::appliedByAdmin(): BelongsTo`
- `Worker::cleaningFinancialPenalties(): HasMany`

---

## 5. Cancellation Audit and Snapshot Design

### 5.1 Exact cancellation actor

Add to `cleaning_bookings`:

- `cancelled_by_user_id` nullable foreign key.
- `cancelled_by_worker_id` nullable foreign key.
- `cancellation_offset_minutes` nullable signed integer.

Rules:

- Customer cancellation sets `cancelled_by_role = customer` and `cancelled_by_user_id`.
- Worker cancellation sets `cancelled_by_role = worker`, `cancelled_by_user_id`, and `cancelled_by_worker_id`.
- Admin/system flows must explicitly set their actor role and available actor ID.
- Do not infer the actor later from authentication state or mutable assignment data.

### 5.2 Cancellation timing

Calculate once when cancellation succeeds:

```text
scheduled_start_datetime - cancelled_at
```

Display rules:

- Positive: cancelled before scheduled start.
- Zero: cancelled at scheduled start.
- Negative: cancelled after scheduled start.

Store the signed value permanently so later schedule edits do not change the cancellation history.

### 5.3 Per-worker status snapshot

Add to `cleaning_booking_worker_assignments`:

- `status_before_booking_cancellation` nullable string.
- `booking_cancelled_at` nullable timestamp.
- `cancelled_by_this_worker` boolean default false.

When global booking cancellation occurs:

1. Lock the booking.
2. Lock all worker assignments.
3. Save each assignment's current status to `status_before_booking_cancellation`.
4. Save `booking_cancelled_at`.
5. Mark the exact actor assignment with `cancelled_by_this_worker = true`.
6. Transition active assignment statuses to `cancelled`.
7. Preserve acceptance, travel, arrival, work-start, and completion timestamps.

This snapshot is the source of truth for the dashboard's multi-worker cancellation table.

---

## 6. Centralize Cancellation Logic

Create:

`Modules/Cleaning/app/Services/CleaningBookingCancellationService.php`

### Responsibilities

- Lock the booking and assignments.
- Validate the requested cancellation transition.
- Explicitly store cancellation actor role and IDs.
- Store the cancellation reason.
- Store `cancelled_at`.
- Calculate and store `cancellation_offset_minutes`.
- Snapshot every assignment's previous status.
- Mark active assignments as cancelled.
- Mark which worker caused the cancellation.
- Update the global booking status.
- Dispatch realtime/lifecycle events after commit.
- Send existing booking-cancelled notifications.

### Refactor callers

Refactor these flows to use the service:

- `UserCleaningOrderCancelController`
- `UserCleaningOrderService` customer cancellation methods
- `CleaningBookingService::cancel()`
- Any Filament/admin cancellation flow

### Scope decision for multi-worker orders

The penalty action belongs to the globally cancelled booking page. A worker withdrawal/rejection that does not change the global booking status to `cancelled` is outside this penalty flow.

---

## 7. Financial Penalty Application Service

Create:

`Modules/Cleaning/app/Services/CleaningCancellationFinancialPenaltyService.php`

### Validation flow

1. Lock the booking.
2. Confirm status is `cancelled`.
3. Confirm `cancelled_by_role = worker`.
4. Resolve `cancelled_by_worker_id`.
5. Confirm no existing penalty for the booking.
6. Validate amount `> 0`.
7. Validate amount `<= booking.total_price`.
8. Require admin notes.
9. Lock the worker account.
10. Apply the financial charge.
11. Store the penalty and transaction link.
12. Send the notification after commit.

### Idempotency

Use a stable reference:

```text
cleaning_cancellation_penalty:{booking_id}
```

The service must reject duplicate application even if the Filament action is submitted twice.

### Financial source resolution

The penalty domain exposes only one source:

#### Deposit source

Use `deposit` when the penalty is fully covered by the current worker deposit balance.

Result:

- Deduct the full amount from `current_balance`.
- Do not increase `debt_balance`.
- Save `financial_source = deposit`.

#### Debt source

Use `debt` when the penalty cannot be fully covered as a deposit-backed charge and the resulting account obligation is debt.

Result:

- Apply the amount through the worker debt flow using the existing financial-account rules.
- The final worker account must preserve the invariant that deposit and debt are not both positive.
- Save only `financial_source = debt` in the penalty record.
- Do not expose a split source or split amounts in the penalty UI/API.

### Recommended financial service method

Add a dedicated method instead of relying blindly on the existing generic charge presentation:

```php
DepositService::recordFinancialPenalty(
    Worker $worker,
    float $amount,
    string $reference,
    ?string $notes,
    ?int $createdByAdminId,
): array
```

Recommended result:

```php
[
    'transaction' => CleaningDepositTransaction,
    'financialSource' => 'deposit' | 'debt',
]
```

The method must run in one database transaction and call `syncEligibilityStatus()` after the account update.

---

## 8. Penalty Clearing Rules

The worker statistics card displays the full amount of active penalties:

```text
SUM(amount WHERE status = active)
```

There is no partial-clearing state.

### 8.1 Deposit-backed penalty

When the worker account is fully refunded:

- Find active penalties with `financial_source = deposit`.
- Mark them `cleared`.
- Set `cleared_at` to the refund timestamp.
- The statistics value becomes zero for those penalties.

The clearing hook must run inside the full-refund database transaction.

### 8.2 Debt-backed penalty

When a later deposit clears the worker debt:

- After the deposit/settlement operation, check the final `debt_balance`.
- Only when final `debt_balance = 0`, mark active penalties with `financial_source = debt` as `cleared`.
- Set `cleared_at` to the debt-clearance timestamp.
- The statistics value becomes zero for those penalties.

Do not implement partial penalty clearing or allocation.

### 8.3 Service

Create:

`Modules/Cleaning/app/Services/CleaningFinancialPenaltySettlementService.php`

Methods:

```php
clearDepositPenaltiesOnFullRefund(Worker $worker): void
clearDebtPenaltiesWhenDebtIsZero(Worker $worker): void
```

Call from:

- `AdminCleaningTransactionService::refundFullBalance()`
- `DepositService::recordDeposit()` after settlement
- `DepositService::recordSettlement()` or any direct full-debt settlement flow

---

## 9. Remove the `commission` Financial Type

There must be no public cleaning financial type or dashboard label named `commission` / `عمولة`.

### 9.1 Transaction model

Update `CleaningDepositTransaction`:

- Remove `commission` from `PUBLIC_TYPES`.
- Remove `commission` from `normalizePublicType()`.
- Remove the `commission` branch from `scopeForPublicType()`.
- Remove `publicType()` logic that maps automatic administration debt to `commission`.
- Normalize automatic administration charges as `debt`.

### 9.2 Ledger writes

Update financial services so they do not write transaction type `commission` or `admin_fee`.

- `DepositService::recordAdminFeeDebit()` must write a debt transaction.
- Keep an internal reference prefix if it is needed for idempotency.
- Do not expose the reference as a separate public type.

### 9.3 Filament transaction UI

Update:

- `CleaningTransactionForm`
- `CleaningTransactionsTable`
- transaction filters, badges, labels, and helper text

Allowed public types:

- `deposit`
- `debt`
- `refund`

`settlement` may remain an internal ledger event but must be presented under debt settlement, not as commission.

### 9.4 API resources

Update:

- `CleaningDepositTransactionResource`
- `WorkerTransactionsController`
- worker deposit timeline responses
- any financial summary payloads

Remove or rename user-facing fields such as:

- `totalCommission`
- `commissionDue`
- `adminCommissionBalance`
- commission filters or labels

Use debt/administration-due terminology that matches the actual account behavior.

### 9.5 Data migration

Add a forward migration/backfill that:

- Converts any remaining `commission` or `admin_fee` transaction rows to `debt`.
- Preserves amounts, references, timestamps, and audit data.
- Updates MySQL enum definitions if still applicable.
- Does not recreate the removed booking foreign key.

### 9.6 Regression boundary

Do not remove `admin_margin_amount` from booking pricing or worker assignment pricing in this change. Only remove the financial transaction category/terminology named commission.

---

## 10. Notification

Create:

`App\Notifications\Cleaning\CleaningFinancialPenaltyNotification`

### Recipient

- The user account belonging to `cancelled_by_worker_id`.

### Canonical type

```text
cleaning.financial_penalty.applied
```

### Payload

- Penalty ID.
- Booking ID.
- Booking number.
- Worker ID.
- Amount.
- Currency.
- Admin notes.
- Cancellation reason.
- Cancellation timing.
- Financial source: deposit or debt.
- Applied timestamp.

### Arabic text

```text
تم فرض غرامة مالية عليك بقيمة {amount} بسبب إنهاء الطلب رقم {bookingNumber} في وقت متأخر.
```

Use the existing database/push notification infrastructure and send after the financial transaction commits successfully.

---

## 11. Filament Dashboard Changes

## 11.1 Cancelled booking details

Update `CleaningBookingInfolist` with a dedicated cancellation section:

- Cancellation source.
- Exact cancellation actor.
- Cancellation date/time.
- Cancellation reason/notes.
- Scheduled start date/time.
- Cancellation duration before/after scheduled start.
- Existing penalty summary.
- Worker status snapshot table.

Worker snapshot columns:

- Worker name.
- Status before booking cancellation.
- Current assignment status.
- Accepted at.
- Started travel at.
- Arrived at.
- Work started at.
- `نعم/لا` value indicating whether this worker cancelled the booking.

## 11.2 Add penalty action

Add a danger-colored action:

```text
إضافة غرامة مالية
```

Location:

- Cancelled booking view page header.
- Optionally in the cancelled booking table row actions.

Visibility:

- Booking status is `cancelled`.
- `cancelled_by_role = worker`.
- `cancelled_by_worker_id` is present.
- No existing penalty for this booking.
- Current admin has permission.

The target worker is read-only and automatically resolved from `cancelled_by_worker_id`.

Modal fields:

- Read-only worker.
- Numeric amount.
- Maximum amount helper showing the booking total.
- Required notes.
- Read-only cancellation source.
- Read-only cancellation timing.
- Read-only predicted source: deposit or debt.
- Confirmation explaining that the action is permanent and sends a notification.

Validation:

- Amount greater than zero.
- Amount less than or equal to booking total.
- Notes required.
- Duplicate server-side validation.

There is no edit, reverse, delete, or void action.

## 11.3 Dedicated penalties resource

Create a Filament resource under the cleaning section:

```text
الغرامات المالية
```

Recommended columns:

- Penalty ID.
- Booking number linked to booking view.
- Worker name.
- Cancellation source.
- Cancellation timing.
- Amount.
- Financial source: `الإيداع` or `الدين`.
- Status: `نشطة` or `مصفرة`.
- Applied by.
- Applied at.
- Cleared at.
- Notes.

Filters:

- Worker.
- Financial source.
- Status.
- Applied date range.
- Cleared/active.
- Cancellation timing before/after scheduled start.

Actions:

- View.
- Open booking.
- Open worker.

No create action from the resource list. Penalties are created only from a cancelled booking to guarantee context and actor validation.

## 11.4 Worker statistics card

Update `CleaningWorkerInfolist` in section `إحصائيات`:

- Label: `قيمة الغرامات المالية`.
- Value: sum of active penalty amounts.
- Currency suffix.
- Link to the penalties resource filtered by worker.

The value becomes zero when the related active penalty records are marked cleared by refund or debt clearance.

## 11.5 Cancelled bookings table

Add toggleable columns:

- Cancellation reason.
- Exact cancellation actor.
- Cancellation duration before/after scheduled start.
- Penalty amount.
- Penalty status.

Add filters:

- Cancelled by customer/worker/admin/system.
- Has financial penalty.
- Cancelled before scheduled time.
- Cancelled after scheduled time.

---

## 12. API and Resource Changes

All changes must be additive unless removing the invalid `commission` public type/fields.

### Booking resource additions

```json
{
  "cancelledByRole": "worker",
  "cancelledByWorkerId": 15,
  "cancellationOffsetMinutes": 45,
  "cancellationTimingLabel": "Cancelled 45 minutes before the scheduled start",
  "workerCancellationSnapshots": [],
  "financialPenalty": {
    "amount": 25000,
    "financialSource": "deposit",
    "status": "active"
  }
}
```

Detailed penalty information may be restricted to admin/internal contexts unless a Flutter screen requires it.

### Worker statistics response

If the worker app consumes this statistic, add:

```json
{
  "financialPenaltiesValue": 25000,
  "financialPenaltiesCurrency": "SYP"
}
```

### Financial transaction API

Remove `commission` from:

- Accepted type filters.
- Returned type names.
- Labels and translations.
- Summary keys.

Provide debt-equivalent fields during a compatibility window only if existing Flutter clients still read old commission fields. Mark them deprecated and remove them after client migration.

---

## 13. Authorization and Audit

Add policies/permissions for:

- View financial penalties.
- Apply financial penalty.

Do not add permissions for edit/delete/reverse because those operations do not exist.

Audit requirements:

- Store applying admin ID.
- Log penalty creation with Spatie Activity Log or the existing audit mechanism.
- Log automatic clearing event and source.
- Keep cancellation actor, reason, timing, and assignment snapshots immutable.
- Use row locks and database transactions for cancellation, penalty application, refund clearing, and debt clearing.

---

## 14. Migration and Backfill

### New migrations

1. Create `cleaning_financial_penalties`.
2. Add cancellation actor/timing fields to `cleaning_bookings`.
3. Add cancellation snapshot fields to `cleaning_booking_worker_assignments`.
4. Normalize remaining commission/admin-fee transaction types to debt.

### Existing cancelled bookings

Best-effort backfill:

- Calculate cancellation offset where schedule and `cancelled_at` are available.
- Preserve null when the scheduled datetime cannot be resolved safely.
- Copy current assignment status into `status_before_booking_cancellation` for historical records.
- Keep `cancelled_by_worker_id` null when the exact actor cannot be proven.
- Do not generate penalties automatically for historical cancellations.
- Do not allow the penalty action when the exact cancelling worker is unknown.

---

## 15. Testing Plan

### Unit tests

- Cancellation offset before, at, and after scheduled start.
- Exact worker actor resolution.
- Multi-worker status snapshot creation.
- Deposit-backed penalty.
- Debt-backed penalty.
- Penalty amount equal to booking total.
- Penalty amount greater than booking total rejected.
- Duplicate penalty rejected.
- Customer-cancelled booking penalty rejected.
- Non-cancelling worker target rejected.
- Deposit-backed penalty clears on full refund.
- Debt-backed penalty clears only when final debt reaches zero.
- No partial penalty status is produced.
- Commission public type is no longer returned.

### Feature tests

- Customer cancellation stores customer actor metadata.
- Worker cancellation stores role, user ID, and worker ID in every allowed status.
- Global multi-worker cancellation stores all previous assignment statuses.
- Admin sees the penalty action only for worker-cancelled bookings.
- Admin can apply one penalty from the cancelled booking.
- Admin cannot create a second penalty.
- Admin cannot penalize an active/completed/customer-cancelled booking.
- Notification is sent to the cancelling worker.
- Dedicated resource filters and links work.
- Statistics card updates after creation and clearing.

### Financial regression tests

- Deposit creation remains correct.
- Debt creation and settlement remain correct.
- Full refund remains correct.
- Worker eligibility is synchronized after penalty.
- Existing dispute penalties continue to work after commission-type removal.
- Existing administration-margin charging remains idempotent but appears as debt.
- Existing transaction endpoints do not expose commission.

### Filament QA

Test:

- Action visibility and authorization.
- Read-only target worker.
- Amount maximum validation.
- Required notes.
- Permanent-action confirmation text.
- Duplicate-submit protection.
- Success/error notifications.
- Resource search, filters, sorting, pagination, empty state, and RTL layout.
- Booking and worker navigation links.
- Table query performance and eager loading.

---

## 16. Expected Main Code Areas

### New

- `app/Models/CleaningFinancialPenalty.php`
- `Modules/Cleaning/app/Services/CleaningBookingCancellationService.php`
- `Modules/Cleaning/app/Services/CleaningCancellationFinancialPenaltyService.php`
- `Modules/Cleaning/app/Services/CleaningFinancialPenaltySettlementService.php`
- `app/Notifications/Cleaning/CleaningFinancialPenaltyNotification.php`
- `app/Filament/Resources/CleaningFinancialPenalties/...`
- Migrations, factories, policies, translations, and tests.

### Existing to update

- `Modules/Cleaning/app/Services/CleaningBookingService.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/User/app/Http/Controllers/API/UserCleaningOrderCancelController.php`
- `Modules/Cleaning/app/Observers/CleaningBookingObserver.php`
- `Modules/Cleaning/app/Services/DepositService.php`
- `Modules/Cleaning/app/Services/AdminCleaningTransactionService.php`
- `Modules/Cleaning/app/Models/CleaningBooking.php`
- `Modules/Cleaning/app/Models/CleaningBookingWorkerAssignment.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
- `app/Models/CleaningDepositTransaction.php`
- `Modules/Cleaning/app/Http/Resources/CleaningDepositTransactionResource.php`
- `Modules/Cleaning/app/Services/CleaningFinancialSummaryService.php`
- `app/Filament/Resources/CleaningWorkerDeposits/Schemas/CleaningTransactionForm.php`
- `app/Filament/Resources/CleaningWorkerDeposits/Tables/CleaningTransactionsTable.php`
- `app/Filament/Resources/CleaningBookings/Schemas/CleaningBookingInfolist.php`
- `app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php`
- `app/Filament/Resources/CleaningWorkers/Schemas/CleaningWorkerInfolist.php`
- Arabic/English translation files.

---

## 17. Delivery Phases

### Phase 1 — Cancellation consistency

- Centralize cancellation.
- Persist exact actor and cancellation timing.
- Snapshot worker assignment statuses.
- Add lifecycle tests.

### Phase 2 — Remove commission financial type

- Normalize model, services, API resources, filters, and dashboard labels to debt terminology.
- Add data migration.
- Preserve administration-margin pricing behavior.
- Run financial ledger regression tests.

### Phase 3 — Penalty domain and financial charge

- Add penalty model/table.
- Implement one penalty per worker-cancelled booking.
- Apply deposit/debt source rules.
- Add audit and idempotency tests.

### Phase 4 — Penalty clearing

- Clear deposit penalties on full refund.
- Clear debt penalties when debt becomes zero after deposit/settlement.
- Update statistics calculations.

### Phase 5 — Filament UI

- Add cancellation audit section.
- Add penalty action.
- Add dedicated penalties resource.
- Add worker statistics card.
- Add cancelled-booking columns and filters.

### Phase 6 — Notifications and additive API fields

- Add worker notification.
- Add cancellation and penalty response fields where required.
- Maintain compatibility for existing Flutter clients.

### Phase 7 — QA and regression

- Run cleaning lifecycle tests.
- Run financial ledger tests.
- Run Filament UI tests.
- Manually test single-worker and multi-worker cancellations.

---

## 18. Acceptance Criteria

- Every cancelled cleaning booking shows who cancelled it and why.
- The exact cancelling worker is stored when a worker cancels.
- Cancellation timing is shown as before/at/after scheduled start.
- Every worker's status immediately before global cancellation is preserved.
- The penalty action is available only for a worker-cancelled booking with a known worker actor.
- The target worker cannot be changed by the admin.
- Only one penalty can exist per booking.
- The admin enters the value manually.
- The penalty amount cannot exceed the booking total.
- The penalty record has one source only: deposit or debt.
- The worker receives the approved Arabic notification.
- Penalties cannot be edited, reversed, voided, or deleted.
- A dedicated Filament page lists and filters all penalties.
- Worker statistics show the sum of active penalties.
- Deposit penalties reset on full refund.
- Debt penalties reset when worker debt becomes zero through deposit/settlement.
- The cleaning financial UI/API no longer exposes a commission transaction type.
- Administration-margin pricing remains functional and is represented financially as debt.
- Existing Flutter booking contracts remain compatible.
