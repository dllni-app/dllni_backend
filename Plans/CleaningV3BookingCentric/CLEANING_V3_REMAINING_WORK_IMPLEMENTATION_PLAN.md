# Cleaning V3 — Remaining work implementation plan
## Phase 2 continuation / release candidate completion

Date: 2026-11-26. Status: proposed execution handoff for remaining work only. G1 and G2 remain BLOCKED. No production deployment, policy change or rollout activation authorized. Workspace C:\laragon\www\Dllni.

## 1. Resume from the verified baseline, not the original architecture

Read these sources in Dllni_backend/Plans/CleaningV3BookingCentric:
- CLEANING_V3_PHASE_2_IMPLEMENTATION_PLAN.md and the four original BACKEND/USER/WORKER/SUPERVISOR implementation plans.
- CLEANING_V3_BACKEND_DELIVERY_2026-11-26.md and CLEANING_V3_BACKEND_CONTINUATION_2026-11-26.md (later evidence supersedes earlier counts for rerun tests).
- contracts/PHASE2_V1_PROVISIONAL_CONTRACT.md; contracts/fixtures/phase2-v1/independent-daily-20261126-203048.json, recorded SHA256 01a3747af81ccdda09d213ca8eb5748cd5f44855d304a17e92df99e1dccc3211. Verify the actual hash in the execution baseline. Fifteen authenticated sanitized response excerpts, not full schemas, not live bookings or complete quote fixtures.
- Workspace qa/cleaning-v3/evidence/phase2/PHASE2_CONTINUATION_2026-11-26.md and all latest logs. Existing E01-E16 ledger is source of remaining acceptance scope. Append new evidence and reconcile status without destroying old ledger.
- qa/cleaning-v3/evidence/independent-daily-confirmation-race.txt and independent-daily-dashboard-continuation/dashboard.json for prior backend/browser evidence.

Product direction already agreed: one ordinary recurring appointment = one independent daily booking. Recurrence only at creation. Same ordinary daily UX for one-off and recurring-generated bookings. Keep internal execution IDs for correct role, clock, money and equipment operations. No user-facing visit/series management for ordinary daily bookings. Retain genuine event/historical groups and their authorized paths; no retroactive migration or V4. User wants simple role-specific interface, not additional behavior, verbose explanations, decimal money or internal financial statuses.

### 1.1 Current verified and partially verified increments

| Area | Evidence / disposition | Remaining |
|---|---|---|
| Independent booking creation B1 | Core verified in earlier signed MySQL test, parallel customer confirmation race and injected rollback after third link. Preserve identity, links and money. | Final candidate rerun, external HTTP/restart recovery coverage where needed. |
| P2-01 receipt | Server receiptKind=independent_daily, confirmationQuoteId, bookings[] with original priceRevision, exact amount and original appointment identity. Singular visits alias only first day. | Mandatory fields/version compatibility, full fixture/action matrix, consumer conformance. |
| P2-02 customer | Typed CleaningV3DailyBookingReceiptRow and matches full signed quote; success count and list/detail navigation; in-screen same-confirmation explicit replay. Seven parser and eight renderer tests recorded pass. | Definitive rejection vs uncertain outcome, process restart, actual captured contract fixture consumption, success widget navigation, build and emulator. |
| P2-03 requirements | CleaningV3WorkRequirements verifies signed per-occurrence digest, scope/actor, sanitized rows, unavailable vs empty; integrated into worker offer/detail and supervised detail. | Worker list/schedule coverage, old quote compatibility, typed worker app consumption, requirements refresh/authorization tests. |
| P2-03 capabilities | Supervisor allowedActions sourced from CleaningV3SupervisedVisitService, canonical start_work, searchActive remaining >0, terminal action filtering. Signed supervised API journey covered. | Complete per-role action matrix, independent worker commands, true last-slot race, notification/realtime reconciliation. |
| P2-04 | Held cancellation/revision, simulated cash and separate reviewer/receipt scenario passed; existing admitted cancellation deliberately rejects 409. | Approved cancellation policy, broader signed treasury/funding and equipment custody/exception matrix. |
| P2-05/06/07 | No dailyBooking match in three lib trees in fresh Rider search. Customer detail still routes V3 to MultiDayCleaningOrderDetailsScreen (139); worker persists MultiDayOrderDetailsBody route (885-892); supervisor _visitActions at 546 and V3 early path at 602. | Main mobile integration/redesign NOT complete; don't mistake backend projection or receipt patch for app adoption. |
| P2-08 | Prior Filament independent details, RTL 1440/1024/390 and dispatch board verified; old roomPeriods fixtures repaired (legacy assignment tests passed). | Current browser detailed permission/actions/finance/report/filter matrix, release regression. |
| P2-09/10 | Backend contract slices verified; no actual new daily three-app journey. | E01-E16 closure, G1/G2, no rollout. |

Fresh repository status via Rider: dev across all four repos. Backend 226 pending entries (29 staged,114 unstaged,91 untracked), customer 26 (25 unstaged,1 untracked), worker 12 (9 unstaged,3 untracked), supervisor 19 (4 unstaged,15 untracked). Snapshot only; includes pre-existing/unrelated changes. Refresh HEAD/diffs before editing. No automatic stash/reset/clean or commit other agents' work.

### 1.2 Test evidence reconciliation

| Evidence | Result | Limits |
|---|---|---|
| qa/cleaning-v3/evidence/phase2/contracts-v3-20261126-233244.txt | 77 passed, 1035 assertions | Includes 2 focused independent recurring tests /330 assertions per ledger; overlapping counts. |
| features-v3-default-off-20261126-233937.txt | 160 passed, 1734 assertions, 180.72 s | Later log than ledger's Rider-error result. Inspect exact command, exit and code fingerprint; 164 vs 160 earlier inventory difference not explained by count alone. |
| feature-default-off-probe-20261126-233702.txt | 11 passed, 68 assertions | Probe for inherited QA flag mismatch, not substitute for full suite. |
| Phase 2 ledger customer cash quote and catalog renderer tests | 7 and 8 passed, exit 0 | Parser fixture synthetic; full widget journey, analyze and APK unverified. |
| Earlier compatibility/lifecycle/privacy/legacy Filament | 38/398, 51/339, 6/71 passed | Prior evidence, subsets overlap; legacy roomPeriods fixture defect repaired. |
| Earlier independent-daily dashboard | 5 browser stages pass | Prior browser test, not current detailed action/finance acceptance. |

Last execution ledger recorded adb devices -l empty, emulator launch timeout, backend loopback 8000 timeout, 18085 refused, customer analyzer timeout and Gradle build timeout. These are last observed blockers, not a verified current outage; probe again before repair. Do not repeat failed launch/build blindly. Existing local QA .env had enabled flags, so default-off feature suite requires process-only settings. No changes to production configuration or actual database during this review.

## 2. Priority and dependency plan

Use R0-R9 as incremental closure packages; preserve original P2 IDs and E-case mapping in evidence. All R packages initially NOT_STARTED as a continuation; original passed slices are baseline, not new work to redo.

| Package | Owner | Priority | Original mapping | Depends on |
|---|---|---|---|---|
| R0 QA/environment recovery and baseline | Integration | P0 | P2-00/09 | none |
| R1 Finalize remaining API/role/requirements contracts | Backend | P0 | P2-01/03 | R0 safe repo/DB |
| R2 Finish customer receipt recovery and widget integration | Customer | P0 | P2-02 | frozen receipt slice R1 |
| R3 Financial policy and remaining treasury/equipment checks | Backend/finance | P0 | P2-04 | R0 + existing authorized policy |
| R4 Customer unified list/detail UX | Customer | P1 | P2-05 | relevant contract slice R1 |
| R5 Worker daily offer/mission/agenda/earnings | Worker | P1 | P2-06 | relevant contract slice R1 |
| R6 Supervisor daily task/permissions/clock/cash | Supervisor | P1 | P2-07 | relevant contract slice R1 |
| R7 Filament operations and accounting acceptance | Backend/QA | P1 | P2-08 | R1, financial cases R3 |
| R8 Full integrated real device/browser/MySQL regression | QA + all owners | P0 | P2-09 | R0-R7 and G1 |
| R9 Release candidate final audit/handoff | Integration | P0 | P2-10 | R8/G2 and existing release audit |

Avoid circular G1: the previous plan asks all consumers to confirm G1 before integration. Freeze each verified wire slice (receipt, daily projection, workload/action matrix) first as a *development contract checkpoint*, allowing client adapters/tests and approved UI work in parallel. Full G1 remains blocked until actual backend and all three consumer conformance, complete action dispositions and legacy/event compatibility are verified. Do not call slice checkpoint G1 release. If a slice changes, update version/fixtures and rerun every affected parser/command test. R3 policy-dependent financial operations can be blocked while unrelated client UI proceeds; blocked mandatory cancellation/funding/custody prevents G2. R7 browser operations can begin with safe test fixtures independently of mobile build once local server reachable. One backend owner serializes overlapping PHP changes; one owner per app.

## 3. R0 — Restore execution environment and establish reproducible baseline

- [ ] Rider: read local AGENTS, refresh four HEADs and full git status/diff, inspect prior WPs and exact actual routes. Record code fingerprints, dependency/SDK versions, test inventory and prior unrelated failures. Preserve staged and unstaged edits.
- [ ] Read-only probes first: adb devices -l, emulator -list-avds, local HTTP health/route and ports, PHP/Composer, Flutter/Dart/Gradle/Java, local MySQL connectivity and DB name. Check actual Flutter build definitions and emulator host routing. Last report used API_BASE_URL=http://10.0.2.2:8000 and ENABLE_CLEANING_V3_RENDERER=true for customer; verify exact defines per repo, do not assume same for all.
- [ ] Recover verified local QA server/emulator: diagnose ADB offline/missing devices, SDK/AVD, host resources/virtualization, Gradle lock or network/dependency failure; collect startup/build logs, retry narrowly. Don't terminate unrelated processes, change credential, wipe emulator data, disable security or point apps to production. If necessary use dedicated loopback port (prior 18085) without taking over port 8000; configure actual local app endpoint for chosen server. Use approved QA-only accounts and test-only signed quotes with verified local DB. No global flag or .env changes. Do not reuse earlier race fixtures IDs 256-258 as actionable quotes; process-scoped signing key was lost.
- [ ] Validate DB guard for alnadha_worker_test on localhost:3306 before MySQL tests; isolated SQLite :memory: for feature tests that reset DB. Use explicit process-only default-off flags from continuation ledger, with individual test overrides where needed. Compare actual suite/test list for 164 vs 160 feature results and confirm no accidental exclusion/deletion. Record complete commands, exit codes, fingerprint, timestamps. Do not rerun a destructive migration on any shared database.
- [ ] Build each actual QA APK, run analyze/tests and verify installed build identity and server URL. Recover at least one emulator; use three if possible, otherwise serial authenticated role sessions on same fixture with evidence and independent concurrent backend tests. If ADB/Playwright/API still unavailable, continue code/tests and record exact device/browser blockers; no false PASS.
Exit: explicit QA environment baseline, working authenticated local server and device/browser when possible; otherwise record blocked acceptance IDs and keep independent work moving.

## 4. R1 — Complete the remaining API contract, permissions and concurrency

Reuse implemented receipt and signed workload; do not replace V3 pricing or add a parallel API.

Backend files under Dllni_backend:
- Modules/Cleaning/app/Http/Controllers/API/{CleaningV3CreateBookingController.php,CleaningBookingScheduleController.php,CleaningBookingShowController.php,SupervisedWorkerBookingController.php,SupervisorAppController.php,SupervisorWorkflowController.php}
- Modules/Cleaning/app/Services/{CleaningV3DailyBookingProjection.php,CleaningV3WorkRequirements.php,CleaningV3AcceptedQuoteResolver.php,CleaningBookingSchedulePresenter.php,CleaningV3WorkerBookingReadProjection.php,CleaningBookingSessionAcceptanceService.php,CleaningV3SupervisedVisitService.php,SupervisedTeamService.php,CleaningV3SupervisorVisitRoster.php,CleaningV3VisitDispatchService.php,CleaningV3WorkClockAccessPolicy.php,CleaningWorkerCustomerDataDisclosurePolicy.php,WorkerBookingScheduleConflictService.php}
- Modules/User/app/Http/Resources/UserCleaningBookingResource.php; Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php; Modules/Cleaning/routes/{api.php,sessions.php}; inspect real list/home/agenda resources via route:list and Rider.

R1a receipt/fixture closure:
- [ ] Preserve receiptKind, confirmationQuoteId, original per-row priceRevision, amounts and booking/occurrence/appointment mapping; legacy first alias must remain single day. Audit explicit independent receipt missing required fields: current Flutter parser allows confirmationQuoteId absent and per-row revision nullable for compatibility; actual new contract documents both. Distinguish strict explicitly versioned daily response from older additive legacy single/group contract; test missing, null, unknown kind, wrong quote/revision, reordered/duplicate rows, alias conflict and tampered amount. Do not break verified legacy clients or relax signature validation.
- [ ] Test replay after approval, cancellation and revision including the first booking; original accepted commercial facts remain stable even if current status/revision changes. Compare accepted receipt identity, current execution and historical alias contract, document differences and reconcile. Existing tests already cover three-day/atomic/replay; extend only missing variants. Do not silently substitute a current price or auto-create another booking.
- [ ] Current 15 samples are sanitized excerpts, not full signed quote or complete API schema. Add explicitly sanitized full-shaped contract fixtures for daily one/three, legacy single/group, genuine event, malformed responses, HTTP 401/403/404/409/422 and network/error categories. Use actual authenticated test responses plus clearly identified synthetic malformed cases; no secret, token, code, raw private GPS or customer PII. Record schema/fixture SHA256 and source tests. Supply consumer fixture source for actual parser tests, not just independently fabricated JSON. Document original acceptance vs live financial state.

R1b daily projection and action matrix:
- [ ] Verify exact wire fields across customer list/detail/schedule, worker offer/list/detail/schedule, supervised worker detail, supervisor home/calendar/detail. Keep absent/null dailyBooking for genuine event/history and authoritative non-null for ordinary daily bookings. Avoid stale fallback to parent aggregate. Explicitly distinguish missing, zero, empty, unavailable. Confirm role access and exact booking/execution/assignment IDs at read and write. Document supported routes/actions only after checking actual methods and predicates.
- [ ] Complete state/action matrix held, searching zero/partial/full, reschedule, scheduled, travel, arrived, both verification states, ready, running/pause pending/paused/handover/completed/cancelled/disputed. Customer confirmation/review, independent worker accept/travel/arrive/start/complete and supervised membership, supervisor request/confirm/start_work/pause/handover. Include extension, cancellation, revision, custody/finance only if server currently authorizes, otherwise explicitly withheld. Unknown states/actions safe read-only. Ensure projection agrees with actual service write guards; test stale/expired code, incomplete crew, revoked role, mismatched scope, terminal, repeated command. No inferred permission by label.
- [ ] Signed worker requirements: extend authorized list/schedule projection or document lazy exact detail read and unavailable behavior; do not duplicate expensive per-row digest query unnecessarily. Preserve exact quantity/effort, lineId identity, old quotes without digest -> unavailable not empty; no back-signing or retroactive data migration. Tests for tampered persisted taskGroups/digest, duplicate item/scope, wrong worker/execution, changed membership and disclosure. Ensure legacy work remains safely operable under established policy; don't invent start-block for existing historical booking merely because no digest.
- [ ] Supervisor agenda actual scheduled date/duration, assigned role, counts and reassignment remains verified. Distinct actual arrival vs verification, no double division by worker count. Scoped worker address privacy: only authorized active travel and explicit sessionId; no customer total/review target in worker mutation response. Preserve rate limits and existing approved funding/dispatch admission.
- [ ] Real competing last-slot acceptance: two authorized eligible workers, independent MySQL connections and synchronized race, assert exactly one remaining slot filled, one authoritative conflict, no duplicate assignment, deposit/conflict/eligibility maintained, retry stable and no sibling mutation. Existing parallel quote-confirm race is different and not proof of this case.
- [ ] Realtime/event identity audit: daily booking + execution + assignment + revision as supported, dedupe and authoritative refresh. Test late/duplicate notifications, wrong scope, warm/cold navigation and reconnect. No new notification product.
Exit: versioned validated receipt slice for R2, daily/worker/supervisor slice for R4-R6, documented route and permission/legacy matrix and true race proof. G1 remains provisional until all client consumers verified.

## 5. R2 — Finish receipt and uncertain outcome recovery

Repository dllni-user-app. Files lib/features/cl_main/data/models/cleaning_v3_cash_quote.dart (165-444); data/source/cl_main_remote_data_source.dart; view/screens/cleaning_v3_catalog_preview_screen.dart (success UI ~214; _submitPendingBooking/_sendConfirmedBooking ~948-1040); test/features/cl_main/v3/{cleaning_v3_cash_quote_test.dart,cleaning_v3_catalog_renderer_test.dart}; inspect auth/session/storage and existing quote replay mechanism.

- [ ] Keep current typed daily rows and exact cents validation. Tighten only contract-backed fields from R1a; cover actual server sample and explicit legacy/event behavior. Don't undo approved/cancelled replay or compare 3 signed occurrences with one legacy visit alias.
- [ ] Classify definitive authoritative rejection vs outcome unknown (network timeout, transport loss after submit, response decode/mismatch, 5xx where commit uncertain). A real 4xx is definitive only when server contract guarantees no commit; reconcile ambiguous conflicts with same confirmation. Current catch-all keeps unresolved attempt in screen. Return appropriate actionable error and allow new draft only after confirmed rejection or resolved outcome. Never automatic replay or new idempotency/quote key after uncertainty.
- [ ] Survive app/process restart: reuse existing secure session-scoped recovery storage or authenticated receipt lookup, retain minimum needed identity safely (no insecure raw signed/private draft persistence, no cross-account carry). If existing API lacks safe reconciliation, request narrow backend contract change through R1, not a broad new workflow. Restore unresolved state, explicitly reconcile same quote/draft, clear only on verified outcome/logout under safe policy. Test process death, back, account switch, quote expired after accepted, timeout, duplicate tap, retry and approved/cancelled replay.
- [ ] Widget tests drive full actual confirmation: 201 and 200 replay, 3 success cards/numbers reflected in orders list, one success routes exact detail, invalid receipt fails safely, definitive rejection unlocks correct controls, uncertain recovery does not duplicate. Integrate verified G1 receipt fixture; verify integer price text and no unnecessary internal approval jargon in confirmation. Keep truthful confirmation that booking is held if not admitted.
- [ ] Run analyze, relevant tests, real QA APK and actual customer confirmation journey after R0. Record app revision and MySQL count/sum proof.
Exit: original mismatch fixed *and* confirmed/recovery UX verified, no lost-success duplicate; P2-02 closure evidence.

## 6. R3 — Close approved financial, cancellation and custody scope

P2-DEC-01 is unresolved. UserCleaningOrderCancelController.php:40-75 and CleaningV3DailyBookingProjection::canCancelHeld currently reject admitted cancellation with 409 V3_CANCELLATION_REVIEW_REQUIRED. This is a deliberate fail-closed restriction, not authorization to remove it. Prior simulated supervisor cash workflow and held cancellation/revision are verified baseline.

- [ ] Locate existing approved fee/refund, worker compensation, deposit, coupon and equipment liability policies for admitted search 0/partial/full, travel/arrival, active work, custody and cash. If missing, prepare focused decision matrix with existing behavior, permitted/rejected options, impact on each ledger/resource and recommendation. Obtain explicit owner decision before implementing affected semantics. Keep 409 and truthful UI capability/support path; do not invent free cancellations or refunds, and do not accept risk without user approval.
- [ ] If approved, use transaction on selected booking/execution + current revision, proper lock order, idempotency and exact authorized resource release. Preserve historical ledger, record allowed fee/entitlement, release only own dispatch/assignment/reservation/funding, coupon reversal once per quote per existing policy. Reject unsafe active work, received equipment, posted cash or inconsistent proof as required. Prove rollback, repeat, concurrency and unchanged siblings. No destructive conversion.
- [ ] Reuse CleaningV3AcceptedQuoteResolver, CleaningV3CashSettlementService, CleaningV3OperationalApprovalService, CleaningV3OperationalFundingService, CleaningV3DailyCouponCancellation, CleaningV3PendingRevisionService and actual treasury/custody services. Verify signed funding proof, reservation, consumption/release, insufficient/expired/tampered/cross-scope proof, concurrent funding; prevent unverified dispatch. Test authorized equipment allocation, issue, custody acknowledgement, return and exception with correct reservation identity. Keep worker deposit/eligibility/entitlements exact.
- [ ] Extend earlier rollback-only simulated cash proof to full QA matrix: completed unpaid; actual authorized test declaration; distinct attendance reviewer and separate authorized receipt verifier; verified treasury movements; precise worker/supervisor/company split, replay and denial of self-verification/duplicate receipts. Use clearly synthetic isolated evidence, never fake production receipt or funds. Reconcile MySQL rows before/after; distinguish calculated margin, verified collection, company receipt and settled entitlement.
- [ ] If authoritative SYP amounts genuinely fractional, escalate approved rounding/allocation policy before changing exact stored/priced values. UI format integer SYP, no client float computation or fabricated paid state.
Exit: remaining approved financial/custody matrix verified; unresolved mandatory policy remains BLOCKED and stops G2, not unrelated work.

## 7. R4 — Customer unified booking experience

Repo dllni-user-app. Main remaining work U2-U6/P2-05. Targets lib/features/orders/view/screens/{cleaning_order_details_screen.dart,multi_day_cleaning_order_details_screen.dart,multi_day_cleaning_order_details_content.dart,orders_screen.dart}; data/models/cleaning_booking_schedule_model.dart; data/source/cleaning_session_remote_data_source.dart; view/widgets/{orders_list_body.dart,orders_list_tab.dart,cleaning_v3_dispatch_progress_card.dart,cleaning_v3_work_clock_card.dart,v7_order_detail_components.dart}; cl_main preview, profile/recurrence shortcut, notifications and BLoC.

- [ ] Add typed canonical dailyBooking model and adapters from R1. One ordinary booking card per ID/date/status/cost; preserve pagination and origin filters only if useful. Notification/deep-link selected booking/execution exact, no first sibling. Route daily one-off and recurring-created to same existing detail shell, explicit legacy/event route unchanged. Do not route every V3 booking to multi-day at line 139.
- [ ] Use existing design components, RTL and one primary action: service/public number, status, schedule/address/duration, cost once, actual team when relevant, active stage. Remove ملخص الجلسات / إدارة الحجز الدوري / الزيارات / تكلفة الجلسة / السعر المتفق عليه / بانتظار مراجعة الإدارة on ordinary daily route. Keep historical/event semantics where valid. No internal ID, redundant descriptions or misleading cash status.
- [ ] Show search only authoritative searchActive && remaining>0 && nonterminal; concise N من M and جارٍ تجهيز الفريق. Covered shows actual team; completed/cancelled no search. Clock only active running/paused, pending pause keeps ticking, completed shows measured static duration if available, no prestart/terminal live component/poll. Use monotonic server snapshot, dispose timers, reconcile foreground and revision; manual retry for error only. Allow buttons only from server allowedActions and exact scope. Unknown read-only.
- [ ] Integer SYP grouped with ل.س, time minutes/Arabic words, no decimals, null not zero; apply preview/list/detail/extension/cancel. Use existing V7 spacing/fonts/icons, RTL narrow widths and text scale, safe areas. Test held/search/covered/verification/active/pause/handover/terminal/error, actual screenshots and no legacy regression.
Exit: daily route clean and visually verified, all customer state transitions consistent, no stale polling/duplicate actions.

## 8. R5 — Worker daily mission and authorized requirements

Repo dllni_cleaning_owner_app. Targets lib/features/orders/data/models/{fetch_orders_usecase_model.dart,fetch_order_details_usecase_model.dart,worker_booking_schedule_model.dart,supervised_worker_booking.dart,worker_v3_work_clock_model.dart}; data/source/{worker_session_remote_data_source.dart,orders_remote_data_source.dart}; view/screens/order_details_screen.dart; view/widgets/{accept_order_bottom_sheet.dart,order_details/multi_day_order_details_body.dart,order_details/worker_v3_work_clock_card.dart}; calendar/notification/realtime/finance consumers; lib/presentation/worker_v2/{contracts,data,features,design_system}. Read CONSUMER_MAP.md, VERSION, active route host and boundary tooling before changing contracts. Do not activate unrelated rebuild.

- [ ] Parse dailyBooking, exact WorkScope and authorized requirementsAvailable/requirements rows (lineId, itemKey, scopeKey, label, measurementMode, quantity, effortMinutes). Distinguish missing from empty; no unsafe raw snapshot/parent total. Show permitted task info and own earning only. Avoid visit selection on ordinary persisted single execution at order_details_screen.dart:885-892; preserve genuine groups and explicit historical scope.
- [ ] Daily offers/assigned agenda by exact date and authorized membership, accept selected only; handle last-slot/conflict and retry outcome, no auto acceptance of siblings. Supervised V3 path uses exact sessionId/myMembership.sessionAssignmentId; keep parent flags read-only. Travel privacy, compliance/location, contact, SOS and deposit safeguards intact. No customer price or private GPS before authorized stage.
- [ ] Existing mission shell with one primary action per state, role-scoped permissions, no supervisor start/approval override. Work requirements shown only when available; stable task/reservation identity. Use actual server clock; hide before start and on terminal, static completed duration. Pause/extension/resume only if permitted; stop timers and reconcile after foreground.
- [ ] Scope earnings to actual assignment and confirmed entitlement; no parent aggregate, integer SYP, zero vs unknown. Keep notifications keyed to exact booking/execution/assignment; dedupe and authorized refetch. Use existing worker_v2 central owner for contract amendments and run boundary/ownership/fixture checks.
- [ ] Tests for signed requirement available/unavailable, supervised route, privacy and acceptance race UI, clock, calendar, notification and finance. Real emulator one-off + daily/supervised mission; actual rejection and independent date.
Exit: typed worker contract and device mission evidence, no obsolete visit UI or privilege/data exposure.

## 9. R6 — Supervisor daily mission and permissions

Repo dllni_supervisor_app. Targets lib/src/{models.dart,api.dart,screens_orders.dart,screens_operations.dart,screens_cash_accounting.dart,v3_clock_card.dart,v3_live_extension_dialog.dart}, home/agenda and existing design system/attendance/inventory components.

- [ ] Typed dailyBooking, authorized roster/actions and exact IDs; actual date/duration/crew counts, null not zero. Home/agenda correct independent days, reassignment revocation and no stale scope.
- [ ] Replace early V3 ListView at screens_orders.dart:602 with common mission shell for ordinary daily jobs, preserve event/history. _visitActions at 546 currently infers from status and excludes awaiting_worker_start_confirmation; use backend allowedActions including canonical request_verification/confirm_verification/start_work and actual guards. No inferred permission, raw visit ID or repeated visit explanation. Crew/arrival/code readiness, one next action.
- [ ] Clock one active elapsed component, pending pause still running, approved paused freeze, resume/extension/handover exact scoped actions, static terminal. Guard stale refresh, dispose, no redundant updates. Preserve supported incident/report/replacement/photos/equipment only with V3 authorization, not old legacy writes.
- [ ] Cash accounting separate from operational details, declaration vs receipt/verification/settlement and correct daily identity, no self-verification. Integer display exact authoritative amount. Test actual supervisor app date/assignment, both verification stages, code expiry, partial crew, pause/resume/handover, cash/custody and stale/reassigned actors. Visual RTL and parity with existing worker design system.
Exit: actual supervisor role UI consumes canonical actions, complete approved mission flow, no hidden authorized verification or unsupported action.

## 10. R7 — Finish Filament acceptance

Repo Dllni_backend app/Filament/Resources/CleaningBookings/{Tables/CleaningBookingsTable.php,Schemas/CleaningBookingInfolist.php,Pages/ViewCleaningBooking.php,Widgets/CleaningBookingStats.php}; CleaningV3VisitDispatches/CleaningV3VisitDispatchResource.php; Modules/Cleaning/app/Services/{CleaningOperationsReportingService.php,CleaningBookingTeamPresentation.php}.

- [ ] Preserve verified independent daily rows and corrected stats. Test actual filters/date/status/pagination, searching 0/partial/full and no terminal search, scoped detail without siblings, actual roster and reassignment/conflict. Reuse existing sections Booking/Team/Execution/Accounting and contextual authorization; no extra dashboard product.
- [ ] Browser via Playwright with real local server, admin and distinct allowed/denied roles; exercise approved operations, financial/equipment actions, validation errors, distinct reviewer/evidence, report reconciliation with MySQL. Guest protection, Arabic RTL 1440/1024/390; screenshots and actual writes only QA. Verify historical legacy supervisor assignment regression (previous fixtures fixed, do not reintroduce roomPeriods).
- [ ] Rerun targeted and expanded Filament tests and check numbers/receipt labels; keep computed margin distinct from verified treasury/customer cash and company receipts; no false paid status. Log current evidence, not historical screenshots.
Exit: detailed operations/finance/filter/role browser matrix and DB evidence, current regression green.

## 11. G1 and R8 — Final integrated acceptance

Full G1 requires verified versioned contract fixtures and all three typed consumer conformance tests, complete allowed/withheld action matrix and safe legacy compatibility. Receipt and role slice checkpoints can support parallel work but do not release G1. Reconcile new action availability after R3. Freeze fixture hashes and commit/diff fingerprints, do not modify after candidate without revalidation.

Carry E01-E16 from Phase 2 ledger; no scenario is end-to-end PASS as of latest inspected ledger. Do not overwrite partial backend evidence. Required closure:
| ID | Remaining proof | Owner |
|---|---|---|
| E01 | Actual 3-date customer confirmation, 3 independent cards/IDs, exact sum and lost-response/restart replay | R2/R4/QA |
| E02 | One-off and genuine event/historical contracts and UI paths | R1/all |
| E03 | Selected approval, 0/2-1/2-2/2 search and no held sibling leakage | R1/R4/R5/R7 |
| E04 | True competing last-slot workers + conflict, client refresh | R1/R5 |
| E05 | Actual supervisor date/assignment and revoked old supervisor | R6/R7 |
| E06 | Travel/arrival privacy, full crew, code/expiry, proper role start | R5/R6/R4 |
| E07 | Clock agreement, pending pause/reject/approve/resume/extension, reconnect | all |
| E08 | Handover/customer confirm, static measured duration and unchanged sibling | all |
| E09 | Held revision/cancel and approved admitted case or explicit policy blocker | R3/R4 |
| E10 | Signed funding and equipment custody/exception proof | R3/R5/R7 |
| E11 | Unpaid completion, separate QA cash receipt and verified reconciliation | R3/R6/R7 |
| E12 | Wrong actor/scope, stale/revoked access and privacy matrix | R1/all |
| E13 | Notification/deep link warm/cold and realtime recovery | all |
| E14 | Current detailed dashboard role/actions/filter/reports browser acceptance | R7 |
| E15 | Real three-app visual state gallery and integer price/time design | R4/R5/R6 |
| E16 | Default-off flag behavior, historical accepted work remains operable, rollback and old clients | integration |

- [ ] Execute exact applicable prior regression cases, full V3 feature suite with process-only default-off settings, MySQL contracts with isolated guard, targeted compatibility/privacy/funding/custody/Filament and full applicable backend suites. Reconcile test inventory/exit codes. Existing approved tests not deleted to make green.
- [ ] Each Flutter repo: dart format --output=none --set-exit-if-changed changed paths; flutter analyze; targeted and full flutter test; build QA debug APK. Verify actual installed revisions. Worker boundary tooling. Resolve confirmed failures; distinguish existing unrelated from new defects.
- [ ] Artemis actual local authenticated applications, Playwright actual dashboard, MySQL scoped before/after proof. Multiple emulators if available, serial role sessions if one; race still independent DB connections. Test QA actors and isolated fixtures only. No API-only replacement for actual app acceptance. Record per state screenshots, route/IDs, actual behavior; avoid secrets/private GPS/customer data.
- [ ] Extend existing ledger with case, WP, actor, fixture, precondition, expected, actual, PASS/FAIL/BLOCKED/NOT_RUN, command/exit/HTTP/DB/screenshots, version fingerprint, defect/retest. New path qa/cleaning-v3/evidence/remaining-work/ (or established phase2 continuation subdir). Keep old evidence. Tool/device unavailable -> exact blocker and affected case IDs; continue safe independent tasks.
Exit: G1 consumer contract proven and all mandatory E-cases verified against same release candidate; no unresolved critical defects.

## 12. R9 — Final readiness and decisions

G2 remains BLOCKED until full required acceptance, complete approved financial policy and existing release-readiness audit pass. Do not declare 100% or commercial release on source/unit tests alone.

- [ ] Deliver current contract/fixtures, WP status mapping R0-R9 -> original P2-00..10 and E01..16, changed files, actual test counts/exit codes, screenshots/DB evidence, risk/decision/deferred register. Use NOT_STARTED/IN_PROGRESS/PARTIALLY_VERIFIED/VERIFIED/BLOCKED/DEFERRED. Distinguish verified baseline from current candidate tests. No silent dropped cases.
- [ ] Decision gates: admitted cancellation/refund/compensation after admission; any actual fractional SYP policy; duplicate same-date grouping; changes to role/finance approval boundaries; historical migration. No unauthorized design changes. If blocked, keep fail-closed and state exact remaining decision owner and consequence.
- [ ] Existing feature flags remain in safe state. config/cleaning_v3_catalog.php default-off is not proof of live production config; don't expose environment secrets or change shared flags. No production rollout authorized in this plan. Separate owner approves release. Rollback via existing new-commercial-work switches and compatible app version, never delete accepted booking/ledger or disable existing execution; financial corrections reconcile forward.
- [ ] No V4/new workflow, no generic extra feature, no unnecessary redesign, no historical conversion, no global test/security bypass.

## 13. Agent instruction

Execute this remaining-work plan, not a new implementation plan. Start R0 and refresh evidence, preserve already verified source and unrelated dirty work. Start R1/R2 critical recovery, R3 approved financial closure and independent client adapter/UI work against verified slices. Continue R4-R7 where dependencies permit, then R8/R9. Do not repeat completed work unless regression proves it broken. Use Rider for source, Artemis for real Android, Playwright for real browser, existing Foundry/design-system UI tools if available (actual render required before claiming use), and scoped QA MySQL. If Foundry not available, reuse existing Flutter/Filament components and verify real pixels. Never invent approval/cancellation policy, payment proof or successful test results. Plan authoring did not run tests, change source, launch apps or modify DB; next agent must perform verification.
