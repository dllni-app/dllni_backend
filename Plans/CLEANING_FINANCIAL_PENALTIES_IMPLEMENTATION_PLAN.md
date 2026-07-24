# Cleaning Financial Penalties Implementation Plan

## 1. Goal

Add an auditable financial-penalty workflow for cancelled cleaning bookings in the Filament dashboard.

The admin must be able to:

- Open a cancelled cleaning booking.
- See who cancelled it and the cancellation notes.
- See how long before or after the scheduled start time the cancellation happened.
- See the exact booking status of every assigned worker at the time of cancellation.
- Apply a financial penalty to the responsible worker or customer, subject to the confirmed business rules.
- Notify the penalized party.
- View all financial penalties from a dedicated cleaning dashboard page.
- See the worker's current financial-penalty value in the worker statistics section.
- Clear the worker penalty value according to the deposit/refund settlement rules.

## 2. Current Repository Findings

### Existing cancellation data

`cleaning_bookings` already stores:

- `cancelled_at`
- `cancellation_reason`
- `cancelled_by_role`

The booking API resource and Filament booking table already expose part of this information.

### Existing worker financial ledger

The current cleaning financial system already has:

- Worker deposit balance.
- Worker debt balance.
- Deposit, debt, settlement, commission, and refund transactions.
- A central `DepositService` that can charge an amount by consuming deposit first and moving the uncovered remainder to debt.
- Admin deposit/refund flows.
- Worker financial eligibility synchronization.

This logic should be reused instead of creating a second wallet implementation.

### Existing dispute penalty pattern

`DisputeFinancialPenaltyService` already demonstrates the expected pattern:

- Validate the worker belongs to the booking.
- Create an idempotent financial transaction.
- Save the transaction reference on a dedicated business record.
- Save the amount, notes, admin, and applied timestamp.

The new cancellation penalty should follow this pattern but use a dedicated cancellation-penalty entity.

### Important ledger constraint

A previous migration intentionally removed `cleaning_booking_id` from `cleaning_deposit_transactions`.

Therefore, the booking relationship must be stored in a dedicated penalty table, while the ledger transaction remains a financial settlement record referenced by the penalty.

### Current cancellation gap

Worker cancellation currently updates the booking to `cancelled` but does not always explicitly persist `cancelled_by_role = worker`.

The observer only infers the cancellation source for a limited set of previous statuses, so cancellations from other allowed statuses can remain without a reliable cancellation source.

## 3. Recommended Domain Model

Create a dedicated table and model:

`cleaning_financial_penalties`

Recommended columns:

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint | Primary key |
| `cleaning_booking_id` | foreignId | Cancelled booking |
| `penalized_party_type` | enum/string | `worker` or `customer` |
| `worker_id` | foreignId nullable | Penalized worker |
| `customer_id` | foreignId nullable | Penalized customer |
| `cancellation_actor_role` | string | Snapshot of who cancelled |
| `amount` | decimal(12,2) | Original penalty amount |
| `deposit_component_amount` | decimal(12,2) | Amount deducted from worker deposit |
| `debt_component_amount` | decimal(12,2) | Amount added to worker debt |
| `deposit_component_cleared_amount` | decimal(12,2) | Deposit-funded amount already cleared |
| `debt_component_cleared_amount` | decimal(12,2) | Debt-funded amount already settled |
| `financial_transaction_id` | foreignId nullable | Ledger transaction created for worker penalty |
| `status` | string | `active`, `partially_cleared`, `cleared`, `voided` |
| `notes` | text | Admin reason/notes |
| `cancellation_reason_snapshot` | text nullable | Immutable cancellation reason snapshot |
| `cancellation_offset_minutes` | signed integer | Minutes before scheduled start; negative means after start |
| `applied_by_admin_id` | foreignId | Admin who applied it |
| `applied_at` | timestamp | Application time |
| `cleared_at` | timestamp nullable | Full clearing time |
| `voided_at` | timestamp nullable | Reversal/void time |
| timestamps | timestamps | Audit fields |

### Constraints

- Require exactly one target: worker or customer.
- Booking must be cancelled.
- Target must belong to the booking.
- Amount must be greater than zero.
- Prevent duplicate active penalties for the same booking and target unless multiple penalties are explicitly approved.
- Do not delete penalty records; use `voided` with an audit trail.

## 4. Cancellation Snapshot Design

### Booking-level cancellation timing

Add an immutable signed column to `cleaning_bookings`:

- `cancellation_offset_minutes`

Calculation:

```text
scheduled_start_at - cancelled_at
```

Display rules:

- Positive: `Cancelled 45 minutes before the scheduled start`.
- Zero: `Cancelled at the scheduled start time`.
- Negative: `Cancelled 20 minutes after the scheduled start`.

The value must be calculated once when the booking is cancelled and should not be recalculated if the schedule is edited later.

### Per-worker status snapshot

Add to `cleaning_booking_worker_assignments`:

- `status_before_booking_cancellation`
- `booking_cancelled_at`

When the booking is cancelled:

1. Lock the booking and all worker assignments.
2. Store each assignment's current status in `status_before_booking_cancellation`.
3. Store the booking cancellation timestamp.
4. Change active assignment statuses to `cancelled` for consistent lifecycle state.
5. Preserve all assignment timestamps such as accepted, travel, arrival, and work-start timestamps.

This gives the dashboard an accurate answer for multi-worker bookings without relying on mutable current state.

## 5. Centralize Cancellation Logic

Create a service such as:

`CleaningBookingCancellationService`

Responsibilities:

- Lock the booking.
- Validate the cancellation transition.
- Resolve and explicitly store the actor role: `customer`, `worker`, `admin`, or `system`.
- Store the actor ID when available.
- Store the cancellation reason.
- Calculate and store `cancellation_offset_minutes`.
- Snapshot every worker assignment status.
- Transition active worker assignments to `cancelled`.
- Update the global booking status.
- Dispatch lifecycle/realtime events after commit.
- Send the normal booking-cancelled notifications.

Refactor these flows to use the same service:

- Customer cancellation.
- Worker cancellation.
- Any future Filament/admin cancellation.

This removes duplicated cancellation behavior and guarantees that cancellation metadata is always complete.

## 6. Financial Penalty Application Service

Create:

`CleaningCancellationFinancialPenaltyService`

### Worker penalty flow

1. Validate that the booking is cancelled.
2. Validate that the selected worker belongs to the booking or its worker assignments.
3. Lock the worker deposit account and penalty target.
4. Generate an idempotent reference such as:

```text
cleaning_cancellation_penalty:{penalty_id}
```

5. Reuse `DepositService::recordDebtCharge()`.
6. Calculate the actual financial split from the transaction snapshots:
   - Deposit component = `balance_before - balance_after`.
   - Debt component = `debt_balance_after - debt_balance_before`.
7. Save both components on the penalty record.
8. Sync worker financial eligibility.
9. Send the penalty notification after commit.

The existing charge behavior already satisfies the requested rule:

- Use the worker's deposit balance first.
- Add only the uncovered amount to debt.

### Customer penalty flow

Do not implement financial collection until the customer-side financial source is confirmed.

The model and UI may support a customer target, but the application service must use the confirmed customer payment/wallet/debt mechanism. It must not silently reuse the worker deposit ledger.

## 7. Penalty Clearing Rules

The worker statistics card should show the current uncleared penalty amount:

```text
remaining deposit component + remaining debt component
```

### Deposit-funded penalty component

When a full worker refund is completed:

- Clear the remaining deposit-funded penalty components for that worker.
- Record the cleared amount and clearing timestamp.
- Update the penalty status.

This hook should be added inside the same transaction as the refund operation.

### Debt-funded penalty component

When a new deposit settles worker debt:

- Allocate only the actual debt-settlement amount to active debt-funded penalty components.
- Use a deterministic allocation order, recommended FIFO by `applied_at`.
- Support partial clearing when the deposit is smaller than the outstanding penalty debt.
- Mark the penalty fully cleared only when both components are fully cleared.

Do not reset all debt-funded penalties merely because a deposit transaction exists; clearing should match the amount actually settled.

### Service

Create a small settlement coordinator such as:

`CleaningFinancialPenaltySettlementService`

Methods:

- `clearDepositComponentsOnRefund(Worker $worker, float $realizedAmount)`
- `allocateDebtSettlement(Worker $worker, float $settledAmount)`
- `refreshPenaltyStatus(CleaningFinancialPenalty $penalty)`

Call it from:

- `AdminCleaningTransactionService::refundFullBalance()`.
- `DepositService::recordDeposit()` after debt settlement.
- Any other existing endpoint that settles worker debt directly.

## 8. Notifications

Create a dedicated notification, for example:

`CleaningFinancialPenaltyNotification`

Send it to the penalized worker or customer through the existing database/push notification system.

Recommended payload:

- Canonical type: `cleaning.financial_penalty.applied`.
- Booking ID and booking number.
- Penalty ID.
- Amount and currency.
- Admin notes/reason.
- Cancellation actor role.
- Cancellation timing text.
- Financial application result for a worker:
  - Deducted from deposit.
  - Added to debt.
  - Split between deposit and debt.
- `occurredAt`.

Suggested Arabic notification meaning:

```text
تم فرض غرامة مالية عليك بقيمة {amount} بسبب إنهاء الطلب رقم {bookingNumber} في وقت متأخر.
```

Use separate wording for customer and worker and avoid exposing internal ledger terminology unless useful.

## 9. Filament Dashboard Changes

### 9.1 Cancelled booking details

Update `CleaningBookingInfolist` with a dedicated cancellation section containing:

- Cancellation source.
- Cancellation date/time.
- Cancellation reason/notes.
- Scheduled start date/time.
- Cancellation duration before/after the scheduled start.
- Existing penalties for the booking.
- Worker cancellation-state snapshot table for multi-worker bookings.

Worker snapshot columns:

- Worker name.
- Status before booking cancellation.
- Accepted at.
- Started travel at.
- Arrived at.
- Work started at.
- Whether this worker was the cancellation actor, if applicable.

### 9.2 Add penalty action

Add a danger-colored action labeled:

`إضافة غرامة مالية`

Location:

- Cancelled booking view page header.
- Optionally the cancelled booking row actions in the booking table.

Visibility:

- Only when global booking status is `cancelled`.
- Only for authorized admins.

Modal fields:

- Penalized party type.
- Worker/customer target.
- Amount.
- Required notes.
- Read-only cancellation source.
- Read-only cancellation timing.
- Preview of expected worker financial split:
  - Amount deducted from deposit.
  - Amount added to debt.
- Confirmation describing that the action affects the financial account and sends a notification.

### 9.3 Dedicated penalties resource/page

Create a Filament resource under the cleaning section:

`الغرامات المالية`

Recommended list columns:

- Penalty ID.
- Booking number with link to the cancelled booking.
- Penalized party.
- Cancellation source.
- Amount.
- Deposit component.
- Debt component.
- Remaining amount.
- Status.
- Cancellation duration before/after start.
- Applied by.
- Applied at.
- Cleared at.

Filters:

- Penalized party type.
- Worker/customer.
- Status.
- Cancellation source.
- Financial source: deposit, debt, or split.
- Applied date range.
- Cleared/uncleared.

Actions:

- View.
- Open booking.
- Void/reverse only if a safe reversal rule is implemented and confirmed.

### 9.4 Worker statistics card

Update the `إحصائيات` section in `CleaningWorkerInfolist` and add:

- Label: `قيمة الغرامات المالية`.
- Value: sum of remaining active penalty components for the worker.
- Currency suffix.
- Link or action to the filtered financial penalties page for that worker.

Prefer a query scope or summary service to avoid duplicating calculation logic.

### 9.5 Cancelled bookings table

Add or update columns:

- Cancellation reason.
- Cancellation duration before/after scheduled start.
- Penalty status/value.

Keep secondary columns toggleable to avoid overcrowding the general booking table.

## 10. API and Resource Changes

Additive fields only to avoid breaking Flutter clients.

Recommended booking response additions:

```json
{
  "cancellationOffsetMinutes": 45,
  "cancellationTimingLabel": "Cancelled 45 minutes before the scheduled start",
  "workerCancellationSnapshots": [],
  "financialPenalties": []
}
```

Only include detailed penalty data for authorized admin/internal contexts unless the mobile apps require it.

Worker-facing account/statistics responses may add:

```json
{
  "financialPenaltiesValue": 25000,
  "financialPenaltiesCurrency": "SYP"
}
```

No existing response field should be renamed or removed.

## 11. Authorization and Audit

Add policies/permissions for:

- View financial penalties.
- Apply financial penalty.
- Void/reverse penalty.

Audit:

- Log penalty creation.
- Log clearing allocations.
- Log reversal/void actions.
- Store admin ID and timestamps.
- Keep the booking cancellation snapshot immutable.

Use database transactions and row locks for every operation that changes worker balances, debts, penalty components, or statuses.

## 12. Migration and Backfill

### New migrations

1. Create `cleaning_financial_penalties`.
2. Add `cancellation_offset_minutes` and optional cancellation actor ID fields to `cleaning_bookings`.
3. Add cancellation snapshot fields to `cleaning_booking_worker_assignments`.

### Backfill existing cancelled bookings

- Calculate cancellation offsets only where both schedule and `cancelled_at` exist.
- Preserve null when the scheduled datetime cannot be resolved safely.
- Copy current assignment status into the snapshot field for historical cancelled bookings, marked as a best-effort backfill.
- Do not generate financial penalties automatically for old cancellations.

## 13. Testing Plan

### Unit tests

- Cancellation offset calculation before, exactly at, and after scheduled start.
- Worker penalty fully covered by deposit.
- Worker penalty fully added to debt.
- Worker penalty split between deposit and debt.
- Refund clears only deposit-funded penalty components.
- Deposit settlement clears debt-funded components FIFO.
- Partial debt settlement leaves a partial active penalty.
- Full settlement marks a penalty cleared.
- Invalid worker/customer target rejected.
- Duplicate active penalty rejected.

### Feature tests

- Customer cancellation stores actor metadata and worker snapshots.
- Worker cancellation stores `cancelled_by_role = worker` in all allowed statuses.
- Multi-worker statuses are preserved before cancellation.
- Admin can apply a penalty from a cancelled booking.
- Admin cannot apply it to an active/completed booking.
- Notification is queued/sent to the correct target.
- Dedicated penalties resource filters and links work.
- Worker statistics card updates after penalty, refund, and deposit settlement.

### Regression tests

- Existing deposit/debt/refund behavior remains valid.
- Existing dispute financial penalties remain valid.
- Worker financial eligibility still uses the final deposit/debt state.
- Existing Flutter booking response fields remain unchanged.
- Existing booking cancellation notifications continue to work.

### Filament QA

Test:

- Action visibility.
- Modal validation and financial preview.
- Confirmation and duplicate-submit protection.
- Clear success/error notifications.
- Search, filters, sort, pagination, empty state, and RTL layout.
- Permission-based visibility.
- Large list query performance and eager loading.

## 14. Expected Main Code Areas

### New

- `app/Models/CleaningFinancialPenalty.php`
- `Modules/Cleaning/app/Services/CleaningBookingCancellationService.php`
- `Modules/Cleaning/app/Services/CleaningCancellationFinancialPenaltyService.php`
- `Modules/Cleaning/app/Services/CleaningFinancialPenaltySettlementService.php`
- `app/Notifications/Cleaning/CleaningFinancialPenaltyNotification.php`
- `app/Filament/Resources/CleaningFinancialPenalties/...`
- Migrations and tests.

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
- `app/Filament/Resources/CleaningBookings/Schemas/CleaningBookingInfolist.php`
- `app/Filament/Resources/CleaningBookings/Tables/CleaningBookingsTable.php`
- `app/Filament/Resources/CleaningWorkers/Schemas/CleaningWorkerInfolist.php`
- Arabic/English translation files.

## 15. Delivery Phases

### Phase 1 — Cancellation consistency

- Centralize cancellation.
- Persist actor and timing.
- Snapshot worker statuses.
- Add tests.

### Phase 2 — Penalty ledger domain

- Add penalty model/table.
- Implement worker penalty application using the existing deposit service.
- Add audit and idempotency tests.

### Phase 3 — Settlement lifecycle

- Hook penalty clearing into refund and deposit debt settlement.
- Implement partial/FIFO allocation.
- Add reconciliation tests.

### Phase 4 — Filament UI

- Add cancelled-booking action and details.
- Add dedicated penalties resource.
- Add worker statistics card.
- Add filters and links.

### Phase 5 — Notifications and API additions

- Add worker/customer notification.
- Add additive API fields where required.

### Phase 6 — QA and regression

- Run financial ledger tests.
- Run cleaning lifecycle tests.
- Run Filament UI tests.
- Manually test single-worker and multi-worker cancellation scenarios.

## 16. Acceptance Criteria

- Every cancelled cleaning booking clearly shows who cancelled it and why.
- Cancellation timing is shown as before/at/after scheduled start.
- Every worker's status immediately before cancellation is preserved.
- Admin can apply a penalty only from a cancelled booking.
- A worker penalty consumes deposit first and places the remainder on debt.
- The penalty record remains linked to the booking without restoring the removed booking FK on the ledger table.
- The penalized party receives a notification.
- A dedicated penalties page lists and filters all penalties.
- Worker statistics show the current uncleared penalty value.
- Deposit-funded penalty components clear on refund.
- Debt-funded penalty components clear according to actual deposit debt settlement.
- Multi-worker bookings allow selecting the specific penalized worker.
- No existing Flutter contract is broken.

## 17. Clarifications Required Before Implementation

1. عند إلغاء العميل للطلب وفرض غرامة عليه: من أي رصيد أو وسيلة مالية يجب تحصيل الغرامة؟ هل يوجد محفظة/رصيد للعميل، أم تُسجل كغرامة غير محصلة فقط؟
2. هل يسمح النظام بإضافة أكثر من غرامة لنفس الطلب ونفس الشخص، أم غرامة واحدة فقط؟
3. إذا كانت الغرامة موزعة بين الإيداع والدين، هل تعرض بطاقة الإحصائيات كامل القيمة حتى تتم تصفية الجزأين، أم تعرض فقط الجزء غير المصفر؟
4. عند إيداع مبلغ أقل من قيمة الغرامات الموجودة على الدين، هل يتم تصفير الغرامات جزئياً حسب المبلغ المدفوع، أم تبقى كاملة حتى تسديدها بالكامل؟
5. في حال وجود ديون أخرى وعمولات وغرامات على العامل، ما أولوية تسوية الإيداع: الغرامات أولاً، العمولة أولاً، الدين الإداري أولاً، أم حسب الأقدم؟
6. هل زر الغرامة يظهر فقط عندما يكون الإلغاء متأخراً ضمن مدة محددة، أم يمكن للإدارة فرض الغرامة على أي طلب ملغي؟
7. هل قيمة الغرامة يحددها المدير يدوياً دائماً، أم تريد إعدادات بقيم تلقائية حسب عدد الدقائق المتبقية قبل موعد العمل؟
8. إذا ألغى عامل واحد في طلب متعدد العمال ولم يُلغَ الطلب بالكامل، هل تريد السماح بفرض غرامة عليه من نفس الواجهة، أم الميزة تخص الطلبات التي أصبحت حالتها العامة `cancelled` فقط؟
9. هل يجب السماح بإلغاء/عكس الغرامة بعد إضافتها؟ وإذا نعم، هل يعاد المبلغ إلى الإيداع أو يخفض الدين تلقائياً؟
10. ما النص النهائي المطلوب للإشعار المرسل للعامل وللعميل؟
