# V2 visit funding: verified-source prerequisite (2026-10-29)

## Decision

**Blocked: there is no verified, spendable cleaning-platform money ledger in the current backend.** A numeric gap, an admin's statement that money exists, or an `approved` flag cannot supply funding. `CleaningV2VisitFundingPreflightService` must continue returning `fundingProven = false`, `startWorkAllowed = false` and `settlementAllowed = false` until actual source-backed reservation and approval are implemented and tested.

## Examined existing records

- `cleaning_deposit_transactions` / `CleaningDepositTransaction`: each entry is keyed to a **worker** and tracks that worker's deposit balance, debts, and reimbursements. It cannot be spent on a cleaning booking's worker or supervisor wages without misappropriating worker-owned funds.
- `delivery_financial_transactions` / `DeliveryFinancialAccount`: the account is owned by a **delivery company**, and its balance reflects the delivery-company financial limit and debits/credits. `FinancialLedgerService::recordCollectionCredit` merely records a manual delivery-company collection; no clearing or custody evidence proves a platform cleaning cash balance.
- `platform_coupon_redemptions.platform_funded_amount`: measures a coupon discount's platform-funded **cost** on a redemption. It is not proof of deposited funds or an unrestricted cash asset. Coupon allocations cannot pay unrelated cleaning liabilities.
- `cleaning_booking_sessions.payment_status`, `total_price` and `is_pricing_final`: quote and lifecycle state, not verified collection or platform cash custody.

## Necessary evidence and integration

1. Identify or introduce an authoritative platform-owned, cleaning-specific monetary source, with independently verified collected/cleared credits, currency, source transaction identity, gross and remaining unreserved balance, and reconciliation/rollback semantics. Admin manual approval is permitted **only after** independent credit verification; the admin cannot create the credit by approving an amount.
2. Reserve the exact amount from an eligible source inside a DB transaction locking both source and its allocations. Unique source identities, per-source aggregate allocations, same-currency checks, reversals and cancellation must prevent double spending and amendments after approval. Record approver user, approval timestamp, source transaction ID, visit ID, reserved amount, quote/version fingerprint, worker liabilities and supervisor compensation snapshot. An independent actor must be able to verify the source.
3. Canonically create and obtain customer approval for any revised signed quote through the existing V2 customer quotation flow. Preserve signed-vs-current sources enforced by `CleaningV2VisitApprovalProofService`; do not replace `pricing_snapshot` or forge a signature. Reject changing a frozen approved quote without another customer approval.
4. In `CleaningV2VisitFundingPreflightService`, verify the source and allocation **from persisted, authoritative records** at review time. Require full worker roster, no double counting of travel, supervisor obligation based on frozen hourly rate and actual/approved hours, and `CleaningSessionFundingConservationService` conservation. Only verified and sufficient source reservation may permit `fundingProven=true`; actual work/settlement gates must also consult that proof at execution time.
5. Test missing/unverified source, wrong owner/currency, insufficient credit, concurrent over-allocation, tampered source or quote snapshot, reversed source, removed approval, and valid cleared-source allocation. Do not create a fake positive path using local seed money without an independent deposited/cleared transaction.

## Current validation

`tests/Unit/Cleaning/CleaningSessionFundingConservationServiceTest.php`: 5 passing tests / 19 assertions on 2026-10-29, including missing source and underfunding. These are mathematical conservation tests; they do **not** establish an authorized external funding source. Positive `fundingProven` from that pure service's caller-provided verification argument must never be surfaced by preflight unless a separate authoritative source verifier supplies the evidence.

## Phase 2.16 additive funding registry foundation (2026-10-29)

- Added `cleaning_platform_funding_sources` and `cleaning_visit_funding_reservations` through `2026_10_29_020000_create_cleaning_platform_funding_registry.php`. Source identity is unique by provider and external credit; reservations are linked to real visits, a quote evidence digest, liability snapshots and approver. The schema is **not** itself a clearing integration or a spending authorization.
- Added read-only `CleaningV2FundingReservationAuditService` and a supplementary `fundingRegistry` field to the supervisor-only financial preflight. It detects owner/currency mismatch, absent quote fingerprint, reversed credits, amount/obligation inconsistencies and aggregate reservations exceeding gross source amount. It never returns `fundingProven=true`, even if the database rows claim `cleared`/`approved`.
- **No write endpoint or automatic reservation service exists yet.** No admin-supplied balance can create clearance. No existing worker deposits, delivery-company funds, coupon allocations, booking status or signed customer quotes were used to fund the registry.
- To proceed: integrate an independently authenticated *cleaning-platform-owned* collected/cleared credit provider and reconcile source IDs; implement transactional source-row locking and active-reservation admission with uniqueness, reversals and release operations; bind reservation snapshots to the freshly verified customer-approved V2 quote and actual worker/supervisor liabilities; then enable positive funding decisions only after read-time independent verification and comprehensive race/security tests. Until these items exist, **V2 work/settlement remain disabled.**
- QA: Existing local MySQL `alnadha_worker_test` migration applied additively; transaction-rolled-back negative probe in `qa/cleaning-lifecycle/phase-2/evidence/phase216_funding_registry_probe.php`; temporary-token authenticated localhost GET in `phase216_funding_api_probe.php`. No database reset or test-credit persistence.

## Phase 2.17 locked admission diagnostics and visit uniqueness (2026-10-29)

- Additive `2026_10_29_030000_guard_active_cleaning_visit_funding_reservations.php` enforces no more than one active reservation **per visit across all source IDs**; released historical records retain their nullable active slot. Conflicting existing active rows prevent migration rather than being deleted.
- Internal `CleaningV2FundingReservationAdmissionService::assess()` uses a transaction with visit-first then source-row locks, evaluates canonical signed quote proof, source ownership, reversal, currency, recorded balance across **all** visits and existing unreleased visit reservations. **It does not write reservations or authorize funds**; `canReserve=false` stays hard-blocked until independently authenticated collected/cleared credits are integrated.
- Read-only registry audit additionally reports multiple unreleased visit reservations to catch manually corrupted rows with null active slot values. This does not enforce cross-visit source capacity against arbitrary direct SQL writes; admission must be enforced by the future real source-backed transactional writer.
- Existing MySQL QA negative probe `qa/cleaning-lifecycle/phase-2/evidence/phase217_funding_admission_probe.php` passes 10 rollback-only checks, including database rejection of cross-source duplicate active slots. No fake credit or payout persisted. Real authenticated supervisor/customer/API execution regressions remain blocked as expected.
