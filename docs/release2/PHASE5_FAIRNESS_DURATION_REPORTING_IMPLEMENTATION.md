# Release 2 / Phase 5 — Fairness, duration planning and operations reports

Date: 2026-10-11. Repository: `dllni-app/dllni_backend`; working branch: `dev`.

## Scope and source limitations

The user roadmap names SS-15, SS-17, SS-28 for fairness, parallel/sequential time planning and specialized reports. The authoritative 116-row original SRS is not available in this workspace; the Phase 5 features below are **incremental** and must not be confused with complete Release 2 acceptance.

## Implementation

### Fair specialist ranking

- `CleaningSpecialistFairnessRankingService` ranks a collection of *already eligible* workers deterministically by accepted specialist assignment-slot count within a rolling window, earlier most-recent acceptance, higher review rating, and finally immutable worker ID. It considers parent and session assignments without assuming mere notifications equal accepted jobs.
- Defaults configured at `config/cleaning_specialist_planning.php` via `CLEANING_SPECIALIST_FAIRNESS_DAYS` (30), `CLEANING_SPECIALIST_FAIRNESS_ENABLED` (true), and a bounded candidate limit.
- The specialist `CleaningScheduleChangeApprovalService::replacementOptions` flow checks qualifications, independent equipment authorization, gender, geography and schedule conflicts **before** invoking fairness ranking. General non-specialist replacement retains rating ordering. Explicit customer selection and urgent broadcast fan-out were **not** narrowed or changed.
- This is **not yet a full fairness policy on initial dispatch**; it is first integrated in specialist replacement discovery. No fairness score overrides security authorization.

### Duration planning

- `CleaningSpecialServiceDurationPlanningService` computes work-minutes from the service catalog duration and requested quantity, configurable via `CLEANING_SPECIALIST_DURATION_PER_QUANTITY`.
- Lines assigned to one worker are summed in sequence. Loads on distinct workers overlap, so their maximum determines the parallel phase. Unassigned tasks are added sequentially to avoid prematurely promising parallel execution.
- Each session has a separate critical path and the parent booking reports cumulative work-minutes and maximum single-session elapsed estimate. The plan is a forecast, **not** the final billing clock.
- The customer cleaning booking API includes an additive `specialServiceDurationPlan` property when relevant, preserving existing fields.

### Reports and operational controls

- New Filament page `CleaningSpecialOperationsReport` in the existing **Cleaning** navigation group, restricted to authorized dashboard viewers.
- `CleaningSpecialOperationsReportService` reads actual line-item records, worker accepted assignments, the sent-notification table, specialist skills, asset statuses and equipment reservations. Returns:
  - Recorded special-service line charges and completed-service line values; reconciling line charges against item snapshots.
  - Existing canonical financial ledger summary **separate** from the quote totals; recorded quote charges are not misrepresented as settled cash.
  - Specialist worker opportunity counts: unique recorded special-order notifications (not confirmed push delivery) and separate accepted work-slot counts during the configured lookback.
  - Equipment reservation status, pending administrative returns, maintenance/broken assets and an actionable limited asset list.
- Dashboard supports refreshing metrics without resetting data.

## Tests and evidence

- `CleaningPhase5FairnessDurationReportingTest`: tie handling, rolling recent workload, sequential/parallel duration, financial line/item reconciliation, actual notifications versus accepted slots and equipment repair state.
- `CleaningSpecialOperationsReportPageTest`: admin access and view rendering, non-admin rejected.
- `CleaningSpecialistReservationGateTest`: specialist qualification, separate equipment permission, buffer overlap and assignment reservation regression.
- `CleaningV2PublicContractTest`: added duration-plan response assertions; verify again after the final change.
- No real phone, emulator or production environment was tested.

## Remaining acceptance gates

1. Full integration of fair ranking with **all** initial specialist discovery and scheduling paths while preserving urgent delivery behavior and explicit worker/customer preferences. Load tests with thousands of eligible workers.
2. Validate business semantics of quantity-based duration with the original SRS, and include travel/setup/equipment cleanup buffers where mandated.
3. Filterable exports, historical maintenance cost and downtime events, worker opportunities across every notification transport (the current report counts database notification rows), and reconciliation with *settled* multi-session financial records require dedicated acceptance work.
4. Complete post-assignment special-service amendment proposal and customer approval workflow (Phase 4 open item), plus Phase 1/2 remaining P0/P1 cases.
5. MySQL concurrent reservation tests, production-like queue and realtime verification, full 116-row SRS test traceability.

Do not approve a Release 2 deployment based on this Phase 5 subset alone.
