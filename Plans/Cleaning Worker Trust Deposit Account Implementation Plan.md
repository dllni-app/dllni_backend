# Cleaning Worker Trust and Deposit Account Implementation Plan

## 1. Current state

The backend already has a partial finance stack for cleaning workers:

- `Modules/Cleaning/app/Http/Controllers/API/WorkerDepositController.php`
- `Modules/Cleaning/app/Http/Controllers/API/DepositManagementController.php`
- `Modules/Cleaning/app/Services/DepositService.php`
- `Modules/Cleaning/app/Services/WorkerTrustService.php`
- `app/Models/CleaningWorkerDeposit.php`
- `app/Models/CleaningDepositTransaction.php`
- `app/Models/CleaningDepositSetting.php`
- `app/Models/WorkerTrustLog.php`
- `app/Filament/Pages/FinancialSettings.php`
- `app/Filament/Resources/Workers/Schemas/WorkerInfolist.php`

What already works:

- Workers can fetch a deposit status payload and a deposit transaction history.
- Admins can record deposits and withdrawals from the API.
- Workers have a trust score and trust logs.
- The worker Filament infolist already shows financial and deposit sections.
- The cleaning finance settings page already exists for pricing and billing policy.

What is still legacy or incomplete:

- Deposit eligibility is based on `completedRevenue > current_balance`, not on a true account floor.
- The transaction table only supports `deposit` and `withdrawal`.
- Trust penalties exist for cancellation, but not for reject-after-accept.
- There is no admin-fee debit on final completion.
- There is no max-negative-balance policy.
- The worker homepage summary still treats `workerAmount` as earnings, not as cumulative deposits to admin.
- The dispatch job does not filter workers by deposit or trust eligibility.
- The mobile worker app currently has `trustScore` support, but no local deposit/wallet use-case files were found in this workspace.

## 2. Gap analysis

| Area | Current state | Gap | Planned fix |
|---|---|---|---|
| Deposit policy | Single `minimum_deposit_amount` setting | No max negative balance, no trust dispatch floor | Extend worker finance settings with max negative and trust thresholds |
| Deposit account | `current_balance`, `deposited_total`, `withdrawn_total` | Missing account floor, status, and eligibility logic | Add computed status + per-worker overrides where needed |
| Transactions | `deposit` / `withdrawal` only | Missing `admin_fee` and booking linkage | Add `admin_fee`, booking reference, and admin actor fields |
| Trust penalties | Cancellation penalty only | No reject-after-accept penalty | Add a dedicated reject trust penalty path and log row |
| Order dispatch | Only active/suspended/gender/zone checks | Ineligible workers can still receive new offers | Filter by trust and deposit eligibility before notify/dispatch |
| Completion settlement | Booking completes without deposit debit | Admin fee is not deducted from worker account | Debit the worker account at final customer-confirmed completion |
| Admin UI | One pricing/time billing page | No worker finance settings section | Add a worker finance section to Filament settings |
| Worker admin UI | Deposit/trust sections are present but old | Missing max-negative and eligibility display | Expand the worker infolist with floor, exceedance, and eligibility |
| Worker app contract | Profile/home payloads are partial | No wallet/deposit contract in the local Flutter tree | Add or update the app models after backend contract stabilizes |

## 3. Recommended implementation order

### Phase 1. Normalize the data model

Target files:

- `database/migrations/*`
- `app/Models/CleaningWorkerDeposit.php`
- `app/Models/CleaningDepositTransaction.php`
- `app/Models/CleaningDepositSetting.php`
- `app/Models/WorkerTrustLog.php`

Changes:

1. Extend the deposit setting record with:
   - `default_max_negative_balance`
   - `trust_reject_after_accept_penalty`
   - `trust_minimum_for_dispatch`
   - keep `minimum_deposit_amount` and `is_enabled`
2. Extend the worker deposit record with:
   - `minimum_required`
   - `max_negative_balance`
   - a status field if the status is not fully derived
3. Extend deposit transactions with:
   - `type = admin_fee`
   - `cleaning_booking_id`
   - `created_by_admin_id`
   - optional note/reference fields for auditability
4. Extend trust logs with:
   - `cleaning_booking_id`
   - `score_before`
   - `score_after`
   - keep the current `reason` and `score_delta`

### Phase 2. Centralize the finance rules

Target files:

- `Modules/Cleaning/app/Services/DepositService.php`
- `Modules/Cleaning/app/Services/WorkerTrustService.php`
- `Modules/Cleaning/app/Services/CleaningBookingService.php`
- `Modules/Cleaning/app/Services/CleaningBookingTeamService.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`

Changes:

1. Replace the revenue-vs-balance eligibility check with a true account check:
   - `current_balance >= -max_negative_balance`
   - trust score also passes the dispatch floor
   - worker is active and not suspended
2. Make `recordDeposit` and `recordWithdrawal` use row locking and a single transaction path.
3. Add a dedicated admin-fee debit action for completed bookings.
4. Add a reject-after-accept trust penalty path:
   - only when the worker had already accepted the booking
   - only on worker reject, not on normal decline of a fresh offer
5. Keep the existing cancellation trust penalty, but route it through the same trust log shape.
6. Sync worker status and eligibility after every account mutation:
   - deposit credit
   - withdrawal
   - admin-fee debit
   - settings changes that lower the floor

### Phase 3. Enforce the rules in the booking flow

Target files:

- `Modules/Cleaning/app/Http/Controllers/API/CleaningBookingController.php`
- `Modules/Cleaning/app/Http/Requests/CleaningBookingRejectRequest.php`
- `Modules/Cleaning/app/Http/Controllers/API/WorkerDepositController.php`
- `Modules/Cleaning/app/Http/Controllers/API/WorkerAccountStatusController.php`
- `app/Jobs/NotifyEligibleWorkersNewOrderJob.php`

Changes:

1. Reject flow:
   - detect whether the worker had previously accepted the booking
   - apply trust penalty only in that case
   - return the updated trust score in the response if possible
2. Start-travel / start-work flow:
   - block if the deposit floor is not met
   - use the same rule as dispatch eligibility
3. Completion flow:
   - debit the worker account when the booking reaches final settlement
   - do not block completion because of a low balance
4. Dispatch flow:
   - exclude ineligible workers from the notify/offer candidate set
   - keep the existing gender/zone/availability filters
5. Profile/home payloads:
   - return `isEligibleForNewRequests`
   - expose deposit floor and account summary data needed by the worker app

### Phase 4. Filament admin UI

Target files:

- `app/Filament/Pages/FinancialSettings.php`
- `resources/views/filament/cleaning-admin/pages/financial-settings.blade.php`
- `lang/en/cleaning_admin.php`
- `lang/ar/cleaning_admin.php`
- `app/Filament/Resources/Workers/Schemas/WorkerInfolist.php`
- `app/Filament/Resources/CleaningWorkers/Pages/ViewCleaningWorker.php`

Changes:

1. Add a new section to the existing Financial Settings page:
   - minimum deposit to start work
   - default max negative balance
   - reject-after-accept trust penalty
   - minimum trust score for dispatch
   - enable or disable worker finance rules
2. Keep the finance controls in one place.
   - The hidden `CleaningDepositSettingsResource` can remain legacy or be retired.
   - Do not create a second admin settings surface unless there is a strong reason.
3. Expand the worker admin infolist:
   - current balance
   - deposited total
   - withdrawn total
   - minimum required
   - max negative balance
   - exceedance amount
   - eligibility badge
   - trust score and trust log
4. Keep the cleaning-worker view redirected to the generic worker view so there is only one detailed worker page.

### Phase 5. Worker app contract

Target files:

- `app/Http/Resources/WorkerResource.php`
- `Modules/Cleaning/app/Http/Controllers/API/WorkerHomepageController.php`
- `Modules/Cleaning/app/Http/Controllers/API/WorkerProfileController.php`
- Flutter models and screens in `dllni_cleaning_owner_app`

Changes:

1. Preserve the current profile and homepage endpoints, but add the missing deposit fields.
2. Add or update a wallet/deposit model in the Flutter app for:
   - current balance
   - minimum required
   - max negative balance
   - status
   - exceedance amount
   - transaction list
3. If the home summary still uses `amountSummary`, make sure:
   - `grossInvoicesAmount` stays the gross completed revenue
   - `adminAmount` stays the total admin margin
   - `workerAmount` is aligned with the new business meaning, not worker earnings
4. If the existing mobile UI expects the old meaning of `workerAmount`, add a new field instead of silently reusing the key.

## 4. Suggested delivery split

### MVP 1

- Add the missing schema columns and transaction types.
- Refactor the account eligibility service.
- Apply the trust penalty on reject-after-accept.
- Debit admin fees at final completion.
- Add the Filament finance section.

### MVP 2

- Add worker-level override fields and actions.
- Expand the worker admin infolist with eligibility and exceedance.
- Update the worker app models and screen copy.

### MVP 3

- Add polished analytics, notifications, and deeper audit history.
- Add any per-worker override editor or ledger page if admins need direct manual controls.

## 5. Tests to add or update

Target files:

- `tests/Feature/Cleaning/CleaningBookingWorkerActionsTest.php`
- `tests/Feature/Cleaning/CleaningBookingEndpointsTest.php`
- `tests/Feature/Filament/CleaningFinancialSettingsPageTest.php`
- new deposit/eligibility feature tests

Must cover:

1. Reject before accept does not reduce trust.
2. Reject after accept reduces trust and writes a log row.
3. Admin credit increases balance and deposited total.
4. Admin fee decreases balance without changing withdrawn total.
5. Worker withdrawal changes withdrawn total.
6. Eligibility flips off when the floor is crossed.
7. Dispatch excludes ineligible workers.
8. The Filament settings page persists the new finance fields.
9. Homepage and profile responses expose the new deposit state without breaking existing keys.
10. Concurrent updates keep the balance chain valid.

## 6. Final acceptance criteria

- The worker account can go negative only to the configured floor.
- The worker receives a trust penalty only for reject-after-accept.
- Admin fee settlement is recorded in the ledger.
- New offers are not dispatched to blocked workers.
- The Filament admin page exposes the worker finance policy in one place.
- The worker admin view shows the current finance and trust state clearly.
- Existing public API keys remain backward compatible where possible.
