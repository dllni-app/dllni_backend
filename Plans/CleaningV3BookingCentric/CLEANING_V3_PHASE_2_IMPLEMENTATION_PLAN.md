# Cleaning V3 — Phase 2 implementation plan
## Contract finalization, mobile integration and release acceptance

Date: 2026-11-26. Status: READY FOR CONTROLLED IMPLEMENTATION of the unblocked work packages; G1/G2 and commercial rollout remain blocked. This is a continuation/handoff, not a new architecture proposal. It does not authorize deployment or changes to business policy. Workspace: C:\laragon\www\Dllni.

### 1. Objective and source authority

Complete the booking-centric V3 redesign already in progress. Every ordinary recurring appointment creates an independent booking with its own public number, date, price, team, supervisor where required, state and history. Recurrence is a creation-time convenience; normal daily jobs must not expose visit/series management. Keep internal execution identity where existing authorization, clock, financial and inventory systems require it. Ordinary one-off and recurring-generated bookings share the same role-specific UX. Retain historical multi-session and genuine event-assistance contracts; do not migrate/delete them in this phase without an approved, reconciled migration decision.

Read before implementation, from this directory:
- CLEANING_V3_BACKEND_IMPLEMENTATION_PLAN.md (B0-B7).
- CLEANING_V3_USER_IMPLEMENTATION_PLAN.md (U0-U6).
- CLEANING_V3_WORKER_IMPLEMENTATION_PLAN.md (W0-W5).
- CLEANING_V3_SUPERVISOR_IMPLEMENTATION_PLAN.md (S0-S6).
- CLEANING_V3_BACKEND_DELIVERY_2026-11-26.md and CLEANING_V3_BACKEND_CONTINUATION_2026-11-26.md. The latter supersedes older failure counts for rerun tests, not unexecuted acceptance criteria.
- qa/cleaning-v3/evidence/booking-centric-{features,contracts-final,compatibility,lifecycle-privacy,supervisor-filament}-continuation.txt; independent-daily-confirmation-race.txt; independent-daily-dashboard-continuation/dashboard.json and screenshots. These are historical execution records; recheck at release candidate.

Use latest repository source and actual test evidence when older plans conflict. The previous review's five Filament roomPeriods failures have since been addressed by corrected legacy V2 fixtures; do not reopen or add a relationship alias unless a fresh regression fails. The signed rollback-only supervisor cash lifecycle has been exercised; this is not proof of real cash receipt or full treasury/custody matrix coverage. Never count a historical PASS as a test run in this phase.

### 2. Baseline and gap register

| Area | Evidence already available | Remaining scope |
|---|---|---|
| B1 independent identity | Three unique booking IDs and execution IDs; signed quote allocation, parallel two-connection confirmation returns 201 + 200/replay; injected failure after third quote-link insertion rolls back bookings, execution, links, reservations and redemption | Preserve and rerun on final candidate; no reimplementation |
| B2 finance | Scoped signed price, coupon, held cancellation/revision, simulated supervisor declaration, separate attendance reviewer and authorized receipt, exact split and replay | Admitted cancellation policy/implementation if authorized; remaining treasury funding, equipment custody, exceptions and reconciliation |
| B3 lifecycle | Signed supervised API journey, 180-second active interval, independent sibling history; canonical dailyBooking projection | Final state/action/receipt/realtime matrix, competing last slot, client adoption |
| B4 role scope | Worker visibility/travel privacy and supervisor reassignment/agenda tested; worker mutation response no customer total/review target | Authorized worker requirements projection; supervised worker typed app integration; full permission/agenda tests |
| B5 dashboard | RTL browser checks at 1440/1024/390; three daily detail pages with no sibling numbers; legacy assignment fixture regression repaired | Detailed operations, accounting, permissions, filters and financial reporting browser acceptance |
| Mobile U/W/S | Existing V3 visit-based screens and old models; installed apps launched in prior QA, one emulator available, no new daily-booking mobile journey | Critical receipt compatibility, typed daily projection, unified screens, active-only clocks and acceptance |
| Release B7 | Feature flag defaults off; G1/G2 not frozen/released | No commercial rollout until all gates and existing release-readiness controls pass |

Recorded suites: 164 features/1761 assertions (before final privacy serialization patch); 77 MySQL contracts/1009 assertions after patch; 38 compatibility/398; 51 lifecycle/privacy/339; legacy supervisor subset 6/71 included in 38. These overlap, do not sum as unique coverage. Latest git status refreshed via Rider: all four repositories dev, backend 222, customer 23, worker 12, supervisor 19 pending entries; counts include unrelated changes and can change. No blanket git operations.

Key open defects:
- P2-D01 (P0; CR-01): customer CleaningV3BookingReceipt only parses data.visits and matches signed visit count. Server data.bookings[] contains all daily bookings; singular visits alias contains ONLY first day's execution. Three-day server success can be displayed as failure.
- P2-D02 (P0; CR-02): UserCleaningOrderCancelController and DailyBookingProjection permit eligible held cancellation, reject admitted cancellation with V3_CANCELLATION_REVIEW_REQUIRED. Preserve fail-closed behavior until actual authorized rules and resource-release design are established.
- P2-D03 (P0; CR-03): dailyBooking contract provisional; clients ignore the canonical projection or use V2/V3 legacy action inference. In particular supervisor _visitActions limits code step to awaiting_start_verification; backend also considers awaiting_worker_start_confirmation. Worker legacy myAttendance booleans deliberately false.
- P2-D04 (P1): authorized worker task/requirements data missing (requirementsAvailable=false). Missing is NOT empty/no work.
- P2-D05 (P1): user and worker still route independent persisted sessions to multi-day screens; supervisor uses visit cards and derived actions. Clock cards and financial/status jargon remain.
- P2-D06 (P1): full dashboard action, cash, reporting and permission matrix not verified.
- P2-D07 (P0): no authenticated integrated mobile daily booking journey; QA environment and test identities require controlled setup. True competing-worker acceptance and notification reconciliation still outstanding.

### 3. Non-negotiable invariants and decisions

I1: Stable public booking identity; exact authorized execution and assignment IDs on commands. Never choose first sibling as fallback. I2: Atomic quote acceptance, replay and allocation; no duplicated bookings, coupons, reservations, worker slot or ledger entries. I3: Server owns state, capabilities, money and elapsed time; client may interpolate display only. I4: Enforce customer/worker/supervisor/admin role, booking membership, location disclosure, deposit/compliance, custody and rate limits. I5: Service completion is not payment; cash declaration is not receipt, receipt is not verified treasury funding. Preserve separate actors, evidence, signed proofs and reconciliation. I6: Terminal booking has no search/live timer/start; completed duration is measured only when actual interval history exists. I7: Arabic SYP presentation e.g. 365,000 ل.س and duration ساعتان و30 دقيقة; no decimal display; keep exact decimal storage and arithmetic. Do not silently round real payable fractional amounts; resolve policy if found. I8: Existing V2/event/history remain readable and safely operable. I9: No invented unsupported action, hidden bypass, automatic re-submission after unknown outcome or client-side fake success.

Decision stops (only affected work package stops; independent safe work continues):
- P2-DEC-01 admitted cancellation/revision: retrieve approved existing fee/refund, worker compensation and custody policy first. If insufficient, present the decision owner with cases (approved zero workers; partial/full crew; travel/arrival; in progress; funding/cash/coupon/equipment). Do not invent free cancellation, refund or penalty rules. Keep 409 and accurate support copy until approved. Affected journey remains BLOCKED, not accepted risk.
- P2-DEC-02 real fractional SYP or multi-occurrence same date: preserve current exact amounts and fail-closed daily grouping; seek explicit policy if a real scenario requires change.
- P2-DEC-03 live historical conversion, rollout activation or real treasury integration: outside current authorization. No production mutation/deployment; require separate approved release or migration.
- P2-DEC-04 any changed trust boundary (who may accept/start/approve pause/collect/verify cash) requires product/security owner approval; reuse existing policy instead of adding privileges.

### 4. Work packages and dependency graph

| WP | Priority | Owner / repo | Depends on | Outcome |
|---|---|---|---|---|
| P2-00 | P0 | integration/QA | none | verified baseline and isolated test environment |
| P2-01 | P0 | backend | P2-00 | receipt contract and authoritative G1 fixture/action matrix |
| P2-02 | P0 | customer | P2-01 receipt slice | multi-booking confirmation compatibility |
| P2-03 | P0 | backend | P2-00, P2-01 | worker requirement/access and role actions; acceptance race |
| P2-04 | P0 | backend/finance | P2-00, approved policy | remaining cancellation, funding, custody and cash verification |
| G1 | P0 | integration + all consumers | P2-01, P2-02, P2-03; P2-04 action dispositions | shared contract frozen |
| P2-05 | P1 | customer | G1 | unified list/detail UX |
| P2-06 | P1 | worker | G1 | daily offer, mission, agenda, finance |
| P2-07 | P1 | supervisor | G1 | daily task, roster, calendar, clock, cash |
| P2-08 | P1 | backend/Filament | G1; accounting cases depend on P2-04 | dashboard acceptance |
| P2-09 | P0 | QA/backend + all apps | P2-02,04,05,06,07,08 | full real E2E and release candidate regression |
| G2/P2-10 | P0 | release owner | P2-09 and all policy gates | evidence-backed release readiness, not deployment |

Parallelism: begin P2-00 first; one backend owner coordinates P2-01/03/04 to avoid overlapping PHP edits. Customer can implement P2-02 against a frozen receipt slice before whole G1. Mobile teams can prepare typed fixtures/widget layouts in separate repos while G1 finalizes, but may not invent wire semantics. After G1 run P2-05/06/07/08 in parallel, one owner per repo, coordinate shared changes through backend contract owner. P2-04 can continue beyond G1 for internal evidence only if externally visible actions are clearly frozen or withheld. Any later contract change requires fixture/version review, all consumer tests and renewed G1. An unresolved mandatory cancellation/funding/custody case blocks G2 regardless of other progress.

### 5. P2-00 — Baseline and safe QA setup

- [ ] Rider: reconnect workspace; read each repo AGENTS and active docs; record branches, HEADs, staged/unstaged/untracked diffs, route map, active Flutter entry points, selected config/rollout state without exposing secrets. Preserve dirty work, no reset/stash/checkout/force-push.
- [ ] Confirm Laravel local server, MySQL and test DB identity, existing ports and Android emulator/ADB devices. Isolate QA to explicit local DB, loopback server and QA app endpoint configuration; validate runtime versions and app package IDs. Do not point test builds to production. Never use migration:fresh on existing databases. Start dedicated loopback service only if needed; don't disrupt existing port 8000. Reuse documented 18085 setup if available.
- [ ] Inspect prior race fixture (IDs 256-258), signing key limitation and historical emulator screenshots. The race fixture's random process key was not retained; it is inspection-only, not an actionable quote. Provision new genuine signed local QA quotes with approved test-only key and controlled isolated fixtures. Use separate authorized QA customer, eligible worker(s), supervisor(s), operations and independent cash verification actors. Never alter real account balances, bypass auth/eligibility or fabricate payment evidence. Test-mode cash evidence must be explicitly synthetic and restricted to isolated environment.
- [ ] If only one emulator is available, run serial role sessions against the same booking and preserve role-specific evidence; use multiple devices if available for simultaneous user experience. If unavailable, test backend/Flutter independently and mark real mobile flow BLOCKED. No mock screenshot replaces actual emulator acceptance.
- [ ] Re-run current focused tests before mutation; baseline full V3 features/contract and Flutter targeted tests as environment permits. Record existing failures and distinguish new regressions. Use existing qa/cleaning-v3 evidence directory with a new phase2 subdirectory, no overwriting old logs. Capture commit/diff fingerprints, commands, exit codes, exact environment and timestamps.
Exit: safe verified QA environment or explicit blocked subset; source ownership and test baseline recorded.

### 6. P2-01 — Freeze receipt and role API contracts (B1/B3)

Backend targets (relative Dllni_backend):
Modules/Cleaning/app/Http/Controllers/API/CleaningV3CreateBookingController.php; CleaningBookingScheduleController.php; CleaningBookingShowController.php; Modules/Cleaning/app/Services/CleaningV3DailyBookingProjection.php, CleaningV3DailyQuoteScope.php, CleaningV3AcceptedQuoteResolver.php, CleaningBookingSchedulePresenter.php, CleaningV3VisitDispatchService.php, CleaningV3WorkClockProjectionService.php, CleaningV3WorkClockAccessPolicy.php; Modules/User/app/Http/Resources/UserCleaningBookingResource.php; CleaningBookingResource.php; existing routes/api.php and Modules/Cleaning/routes/{api,sessions}.php.

- [ ] Verify daily receipt from actual API. Existing data.bookings[] includes bookingId, bookingNumber, occurrenceKey, appointmentKey, scheduledDate, scheduledTime, amount (exact decimal string), visitId and admissionStatus. Old bookingId/bookingNumber/visits refer only to first booking; never send siblings as actionable children. For daily quote, require one-to-one signed appointment membership, unique IDs and exact amounts; clarify priceRevision contract (currently singular alias includes it, new rows do not). If needed add per-row original quote revision/additive discriminator and stable immutable confirmation identity without weakening signature validation.
- [ ] Distinguish original acceptance receipt from current operational status. Replay may report current admission/cancelled status while original identity and amounts remain stable. Test after first booking admitted/completed/cancelled or another booking revised; do not recreate or fail merely because canDispatch/status changed. Test valid signed one-day, 3-day, historical/event receipt, malformed/missing/duplicate/mismatched identities, quote tamper, unknown extra fields, lost response and concurrent confirmation. Reconcile approved/cancelled replay against original accepted quote; don't falsely require unpaid/pending status if contract explicitly returns current state.
- [ ] Audit dailyBooking on customer list/detail, worker offer/list/detail/schedule, supervised worker detail, supervisor home/agenda/detail. Verify actual endpoints with artisan route:list. Keep dailyBooking absent/null for genuine event/historical group; ordinary new daily jobs must get non-null canonical projection. Freeze field types, nullable semantics, enum mapping, bookingId/executionId relation, date/time/elapsed minutes, accepted/required counts, searchActive, clock, priceRevision, serverNow and allowedActions. No customer finance or identity in worker coarse response.
- [ ] Build per-role action matrix including held/searching/covered/travel/arrived/verification/ready/running/pause pending/paused/handover/completed/cancelled/disputed/reschedule; include verification request/confirm/start, pause request/withdraw/decision/resume, extension, handover, review, support/cancellation/revision only when backed by actual route and permission. Check action predicates match write guards (including time/expiry, crew, funding and terminal state). No fabricated capabilities. Keep unknown states read-only; explicitly state blocked reasons where supported.
- [ ] Save versioned sanitized JSON fixtures and API contract markdown under plan directory or established contract docs; include envelope shape, example HTTP status/errors, commands and scope keys, response version/compatibility and each client parser test. Generate from actual authenticated test responses, remove secrets/private GPS/customer data. No hand-authored pretend evidence. Backend plus each mobile agent review fixture agreement. G1 artifact also documents projection provenance and rollback/flag behavior.

Existing provisional routes to verify, not invent:
- POST /api/v1/cleaning/v3/bookings -> data.bookings.
- GET /api/v1/user/cleaning/orders/{booking}; GET /api/v1/cleaning-bookings/{booking}/schedule.
- GET /api/v1/cleaning-bookings/{booking}; GET /api/v1/cleaning/supervised-worker/bookings/{booking}?sessionId={executionId}.
- GET /api/v1/supervisor/bookings/{booking}; actual agenda/home endpoints from routes.
- GET /api/v1/cleaning/v3/bookings/{booking}/revision-draft; POST /api/v1/user/cleaning/orders/{booking}/cancel.
- Existing execution-scoped /api/v1/cleaning-bookings/{booking}/sessions/{execution} and /api/v1/supervisor/bookings/{booking}/visits/{execution} commands; audit methods/suffixes before exposing buttons.

Exit: receipt slice verified and ready for P2-02; final G1 waits for role/action/consumer conformance and P2-04 dispositions.

### 7. P2-02 — Repair customer confirmation (U1, P0)

Repository dllni-user-app. Targets lib/features/cl_main/data/models/cleaning_v3_cash_quote.dart:165-231; data/source/cl_main_remote_data_source.dart; view/screens/cleaning_v3_catalog_preview_screen.dart:931-937; existing pending revision screen and tests.

- [ ] Add typed independent booking receipt rows using verified contract; validate unique bookingId, number, executionId, occurrence and appointment, exact money and matching signed quote revision when supplied by contract. Cross-check full quote coverage and total with exact decimal logic; do not reprice. For explicitly legacy/event receipts preserve old visit parser; if a daily response contains invalid bookings[], fail closed, never fall back silently to first alias.
- [ ] Distinguish committed server success with parse/network failure from rejected creation. Reconcile using existing quote replay/lookup mechanism and same confirmation identity before retry; no new quote/idempotency key solely because response lost. Show neutral recovery message and retry safely; no duplicate submissions. Support replay after admission/cancellation under approved receipt semantics.
- [ ] Success screen: one result -> its detail; multiple -> concise number of created bookings and حجوزاتي with independent cards. No series details or false payment state. Verify back navigation and refreshed list.
- [ ] Add parser tests for actual signed three-day response including legacy alias count one; single/event/old response, wrong/duplicate/missing row, amount/revision mismatch, approved replay, failed network and timeout. Widget tests that committed success routes correctly and does not show Booking receipt mismatch; verify true errors remain errors.
Exit: three-date API success accepted and displayed correctly; malformed receipts rejected; replay doesn't duplicate. Report fixture evidence to backend G1 owner. No flag enablement.

### 8. P2-03 — Worker requirements, role capabilities and races (B3/B4)

Targets Dllni_backend Modules/Cleaning/app/Services/CleaningV3WorkerBookingReadProjection.php, CleaningV3SupervisorVisitRoster.php, SupervisedTeamService.php, CleaningBookingSessionAcceptanceService.php, CleaningWorkerCustomerDataDisclosurePolicy.php, WorkerBookingScheduleConflictService.php, CleaningV3SupervisedVisitService.php, CleaningSupervisorAssignmentService.php; API/SupervisedWorkerBookingController.php, SupervisorAppController.php, SupervisorWorkflowController.php; Support/WorkerBookingRequirementsPresenter.php. Verify actual call sites and routes.

- [ ] Resolve requirementsAvailable=false without exposing raw customer property notes, sibling requirements or private coordinates. Identify authoritative execution-scoped service/room/task lines from signed snapshot and assignment; reuse existing safe presenter. Keep distinct stable line IDs even equal labels; disclose only authorized fields appropriate to offer/accepted phase. Never interpret unavailable as no work, never expose unauthorized item/price. If data cannot be safely projected, retain explicit unavailable state and block work requiring it until resolved.
- [ ] Keep myMembership.executionId/sessionAssignmentId separate from legacy assignmentId; V3 legacy attendance flags remain false. Explicit sessionId + active travel + exact authorized accepted assignment for precise destination; deny unrelated worker/wrong day and avoid location before travel or after arrival under current policy. Retain coarse location fallback and sanitized errors.
- [ ] Make allowedActions complete for existing supported daily commands, align independent vs supervised roles with actual guards. Supervisor verification stages and crew readiness must match write service; no local status-only inference. Verify home/agenda date, actual duration (not divided twice), current supervisor after reassignment and denial to old supervisor; retain historical records.
- [ ] Add real concurrent last-slot acceptance with distinct authorized eligible workers and independent MySQL connections; prove only one fills last slot, no duplicate assignment, conflicts/deposit/eligibility preserved. Test retry, release/replacement if already supported, stale state, unrelated day, and zero/partial/full search states. Ensure approved sibling cannot unlock held day; use safe test harness.
- [ ] Test notification/realtime keys per booking/execution/assignment and revision; no duplicate prompt or sibling invalidation. Reconnect or lost events trigger authoritative refresh. Preserve existing rate-limit buckets and privacy serialization.

Exit: safe requirements projection or explicit blocker, role action matrix matches server, concurrency proof and privacy regression green; submit G1 fixtures. Do not loosen guards for convenience.

### 9. P2-04 — Financial and cancellation closure (B2, P0)

Targets UserCleaningOrderCancelController.php; CleaningV3PendingRevisionService.php; CleaningV3DailyCouponCancellation.php; CleaningV3CashSettlementService.php; CleaningV3OperationalApprovalService.php; CleaningV3OperationalFundingService.php and actual treasury/custody services found via Rider; SupervisorCashAccountingController.php and corresponding service; existing financial/operations reports. Follow existing exact signed quote resolver.

- [ ] Preserve verified held cancellation, revision, simulated cash settlement and signed approval. Map missing cancellation stages (admitted search zero/partial/full, travelling, arrived, active, terminal) to existing policy. Identify whether safe existing authorized path exists, otherwise record DEC-01 and do not implement unsupported refund/fee. Keep rejection safe and provide correct user capability; no unrestricted cancel action.
- [ ] If authorized policy supports admitted cancellation, implement one booking/execution transaction: validate current revision/actor/state, calculate fee/entitlement from approved policy, close only selected dispatch, release only selected assignments/reservations/funding with correct custody state, reverse coupon once when eligible, post immutable audit and authorized accounting; rollback on failure, idempotent retries. Do not close siblings. Explicit negative tests for active work, received equipment, posted cash, unresolved funds and conflicting commands.
- [ ] Verify real signed test-only funding reservation/approval and release paths, proof expiry/replay/tamper/wrong booking, insufficiency and competing reservation. Validate no unapproved dispatch or overspend. Equipment allocate, issue, acknowledge, return/damage exception per existing authorized rules; no unsafe release or reservation ID confusion. Verify worker deposit/account balances and exact per-role settlement using real MySQL transactions, not fabricated ledger fixtures as end-to-end proof.
- [ ] Extend prior cash scenario: completed unpaid, supervisor declaration, independent reviewer/receipt, actual authorized evidence in QA, treasury/settlement status progression, replay and duplicate/negative/unauthorized cases. Confirm each amount and ledger posting, no duplicate redemption or sibling mutation; distinction earned margin vs verified cash in reports. Preserve separate actors and signed proof controls. Test exception and recovery cases, including interrupted transaction.
- [ ] Check SYP display formatter and authoritative ledger amounts for real fractional values; follow DEC-02, no silent financial precision change.
Exit: all in-scope approved cancellation/funding/custody/settlement paths verified in isolated MySQL, policy gaps explicitly blocked. G2 cannot pass while mandatory gaps remain.

### 10. G1 — API contract freeze

Required: P2-01 typed receipt and state/action schema plus actual sanitized fixtures, P2-02 customer receipt tests, P2-03 worker/supervisor contract tests and authorization, P2-04 action availability documented. All three consumers confirm supported wire fields, types, identities and error recovery. Freeze version, fixture hashes and compatibility matrix for daily/single/legacy/event. Record commit/diff fingerprint and evidence location. No feature flag rollout. Any unresolved pivotal issue is withheld, logged and blocks affected path; do not mark full G1 if required contract fields or permissions are unknown.

### 11. P2-05 — Customer UX integration (U2-U6)

Repository dllni-user-app. Targets lib/features/orders/view/screens/{orders_screen.dart,cleaning_order_details_screen.dart,multi_day_cleaning_order_details_screen.dart,multi_day_cleaning_order_details_content.dart}; data/models/cleaning_booking_schedule_model.dart; data/source/cleaning_session_remote_data_source.dart; view/widgets/{orders_list_body.dart,orders_list_tab.dart,cleaning_v3_dispatch_progress_card.dart,cleaning_v3_work_clock_card.dart,v7_order_detail_components.dart}; existing profile recurring shortcut, notifications and order BLoC.

- [ ] Parse dailyBooking into typed state, nullable clock, actions, exact execution identity, duration and coverage; propagate through list/detail/schedule refresh. Daily bookings route to same ordinary detail shell; preserve explicit historical/event route. Keep internal IDs out of UI. One list of independent bookings, optional existing recurrence origin filter only; no series management. Status/date/cost and navigation agree with actual booking, pagination/dedup correct.
- [ ] Use existing V7 components, RTL and one primary CTA. Header service/public number/current status, date/time/address/duration, cost once, relevant team and active stage. Remove ملخص الجلسات, إدارة الحجز الدوري, الزيارات for daily jobs; remove السعر المتفق عليه, repeated session price, بانتظار مراجعة الإدارة and internal settlement jargon. Never claim paid solely because complete.
- [ ] Searching only if searchActive && remaining > 0 and nonterminal; simple N من M, concise label. No explanatory escalation paragraphs or manual refresh by default. Full team: team details, not search. Reschedule-needed: only supported next action. Timer hidden before start/cancelled, running/paused per server, pending pause continues, completed static actual duration if measured; no terminal polling. One server-backed clock with monotonic interpolation, foreground reconciliation, stale response guard and timer disposal. Use allowedActions and exact execution command scope for all buttons; unknown state read-only.
- [ ] Central existing formatter integer SYP (grouped digits + ل.س), words/minutes for duration, null not zero. Apply preview/list/detail/extension/cancel; no hidden change to ledger. Test original screenshots' defects via widget assertions; capture actual emulator Arabic RTL at supported narrow widths/text scale, safe bottom/keyboard and long labels.
- [ ] Run focused order/creation/clock/dispatch tests and full Flutter suite, build local QA APK and execute role journey. Preserve existing tests unless explicitly superseded; update stale copy expectations with new contract plus legacy coverage.
Exit: ordinary daily screen used for single and recurring-created bookings; original UI defects absent on daily routes; actual device evidence, no bad confirmation, no sibling mutation.

### 12. P2-06 — Worker integration (W1-W5)

Repository dllni_cleaning_owner_app. Targets lib/features/orders/data/models/{fetch_orders_usecase_model.dart,fetch_order_details_usecase_model.dart,worker_booking_schedule_model.dart,worker_v3_work_clock_model.dart,supervised_worker_booking.dart}; data/source/worker_session_remote_data_source.dart, orders_remote_data_source.dart; view/screens/order_details_screen.dart; view/widgets/{accept_order_bottom_sheet.dart,order_details/multi_day_order_details_body.dart,order_details/worker_v3_work_clock_card.dart}; calendar/realtime/notification/finance consumers; lib/presentation/worker_v2/{contracts,data,features,design_system}. Inspect active route host and CONSUMER_MAP.md before editing; do not activate unrelated rebuild or fork a new V3 UI.

- [ ] Typed daily offer and assigned mission, exact WorkScope booking/execution/assignment. Ordinary persisted single execution no longer routes through visit selection; use current mission shell, keep genuine event/historical branch. Read actual server capability and authorized requirementsAvailable; unavailable visibly distinct from no requirements. Offer shows permitted scope, date, duration, own earning preview and coarse location only.
- [ ] Accept/reject selected job only; no sibling fallback or accept series. Handle 409 last-slot/conflict, stale/offline outcome via refetch. Agenda/Today by actual dates and assigned membership. Use explicit supervised worker V3 endpoint with sessionId and myMembership; keep legacy flags read-only. Address/contact visibility follows server travel policy, no cached private fields. Respect eligibility, deposit, location, SOS, custody and notification invariants.
- [ ] Stage-based mission primary action; independent worker vs supervised worker commands from allowedActions, no supervisor start/pause/handover override. Clock active-only with same elapsed presentation as customer; completed actual duration static, no prestart/terminal network polling. Preserve task line/reservation identity and supported report/extension flows.
- [ ] Earnings per actual assignment, exact authoritative amount, zero vs unavailable, no customer totals or sibling finance. Integer SYP display. Update worker_v2 typed boundary fixtures via central contract owner, run boundary/ownership/hash tools and relevant unit/widget/mission/realtime tests. Actual emulator offer/accept/travel/arrival/code/work/pause/completion and agenda/finance, plus negative access cases.
Exit: correct daily offer and mission on emulator, role/finance/privacy contract preserved, no old visit UI for ordinary jobs, test evidence.

### 13. P2-07 — Supervisor integration (S1-S6)

Repository dllni_supervisor_app. Targets lib/src/{models.dart,api.dart,screens_orders.dart,screens_operations.dart,screens_cash_accounting.dart,v3_clock_card.dart,v3_live_extension_dialog.dart}; existing shell/home, attendance, inventory and design system.

- [ ] Add typed dailyBooking, roster and role capability parsing. Actual dates/elapsed duration and team counts (null distinct from zero) in home/agenda/detail; no first date fallback, raw internal ID or false missing worker. Scope refresh to current assignment; access revoked after reassignment.
- [ ] Replace early V3 visit-card return for daily bookings with coherent task details using existing overview/attendance/mission components; legacy/event fallback remains safe. One service/public reference, date/time, crew and current stage, relevant action. Remove per-visit explanation/IDs and redundant updates. _visitActions must consume verified allowedActions, not status guess; both supported verification stages must display correct action and respect crew readiness/code validity.
- [ ] One elapsed clock in running/paused relevant stage; pending pause decision still counts until approved; reject/resume/extension/handover only authorized. Completed static duration, no running clock or search. Preserve evidence/incident/replacement/report/inventory functions only when actual V3 contract permits; do not expose legacy write actions by inference.
- [ ] Cash accounting remains separate from execution; daily identity and declaration ID, distinguish declared vs received/verified vs settled, separate admin review; no self-verification. Integer display and exact amounts, missing vs zero. Reuse supervisor/worker parity design components. Verify real emulator schedule/assignment/date, verification/clock and cash journey; test unauthorized actor, wrong scope, stale refresh and historical flows.
Exit: daily mission details and agenda actual device evidence, no hidden authorized verification action, no fabricated roster/time/payment, full relevant Flutter regression.

### 14. P2-08 — Filament detailed acceptance (B5)

Targets Dllni_backend app/Filament/Resources/CleaningBookings/{Tables/CleaningBookingsTable.php,Schemas/CleaningBookingInfolist.php,Pages/ViewCleaningBooking.php,Widgets/CleaningBookingStats.php}; CleaningV3VisitDispatches/CleaningV3VisitDispatchResource.php; Modules/Cleaning/app/Services/CleaningOperationsReportingService.php and CleaningBookingTeamPresentation.php.
- [ ] Preserve verified daily list/detail and RTL browser. Test actual filters by date/status/role, pagination/search, held/search zero/partial/covered/escalated/completed/cancelled, no sibling details/duplicated rows. Test authorized supervisor assignment/reassignment, overlap rejection, terminal lock and denied staff role.
- [ ] Organize existing detail components into Booking/Team/Execution/Accounting with contextual allowed actions and existing permission checks. Keep audit evidence visible to authorized role; do not remove proof/second review. Verify equipment/cash/approval/review actions through actual browser, not just resource rendering. Distinguish computed margin, verified treasury cash and company receipts; exact DB reconciliation, exclude superseded/cancelled from active counts. No invented reports/features.
- [ ] Rerun complete legacy supervisor assignment suite (previous fixtures repaired; don't reintroduce roomPeriods), targeted Filament and financial tests. Playwright on dedicated local server: guest protection, correct role, forbidden actions, list/detail, form submission/error, confirmation and real state change, 1440/1024/390 RTL. Capture screenshots and assertions; verify each mutation with backend tests/MySQL, not only UI.
Exit: complete required dashboard matrix green, evidence cross-referenced with P2-04.

### 15. P2-09 — Integrated regression and acceptance ledger (G2)

Create new phase2 ledger under qa/cleaning-v3/evidence/phase2/ (do not overwrite previous 85-case ledger; reconcile applicable old cases and add new ones). Columns: case ID, WP/requirement, actor, fixture/booking/execution, preconditions, action, expected, actual, PASS/FAIL/BLOCKED/NOT_RUN, command/HTTP/DB/screenshots, commit fingerprint, defect and retest. Every required case must have real evidence or explicit blocker; no averaging or fake PASS.

| Case | Required end-to-end or negative proof |
|---|---|
| E01 | Customer creates 3 dates through actual app; one receipt, 3 independent cards/numbers, exact quote sum in MySQL; lost response/replay no duplicates |
| E02 | Single ordinary and genuine event/historical paths remain correct; no unintended conversion |
| E03 | Admin approves selected day only; worker discovery sees eligible day, not held siblings; 0/2,1/2,2/2 progress; search hides when covered |
| E04 | Two competing workers for last slot + schedule conflict; one acceptance, no duplicate assignment; authoritative refresh |
| E05 | Supervisor assigned date 2; future date independently reassigned after earlier completion; old supervisor denied; correct agenda |
| E06 | Worker travel/arrival, location privacy before/after travel, partial crew cannot start; wrong/expired code rejected; proper verification/start |
| E07 | Actual running timer across clients, customer pause pending still ticking, approve/reject, paused frozen, resume, extension only when authorized; reconnect/foreground preserves intervals |
| E08 | Handover/customer confirm, static actual elapsed duration, no search/live clock; evaluation if allowed; independent sibling unchanged |
| E09 | Held revision/cancel affects only selected day; admitted cancellation approved cases or explicit policy blocker; no sibling resource release |
| E10 | Exact signed funding/treasury and equipment issue/custody/return with proper actors, reject replay/insufficient proof/wrong scope; no unverified dispatch |
| E11 | Completed unpaid -> QA cash declaration -> independent admin verification/receipt -> exact ledger reconciliation; duplicate or self-verification denied |
| E12 | Unauthorized role, unrelated booking/execution/assignment, stale revision, revocation, unknown action/state, no leaked customer/finance/GPS data |
| E13 | Notifications/deep links warm/cold, dedupe, reconnect, no wrong booking; scoped rehydration |
| E14 | RTL browser/dashboard details/actions/statistics/role matrix and reports reconcile to MySQL; legacy supervisor tests green |
| E15 | Three app visual state gallery: held/searching/covered/verification/running/paused/handover/completed/cancelled, error/loading/offline; money/duration format and no visit/internal labels on ordinary bookings |
| E16 | Flags disabled: no new rollout, historical accepted booking can still be read/executed according to current policy; old client compatibility and rollback path |

Actual test commands from repo roots (adapt verified project runner and DB configuration; don't invent unavailable paths):
- Backend: php artisan test tests/Feature/Cleaning --filter=CleaningV3; php artisan test tests/Contracts --filter=CleaningV3; targeted CleaningV3IndependentRecurringBookingTest, legacy/role/privacy/Filament/funding/equipment suites, expanded CleaningBookingSupervisorAssignmentActionTest; full applicable regression and project-configured Pint/static analysis. Re-run full V3 features after final privacy changes. Use isolated MySQL for transactional and concurrency tests, not SQLite substitutes.
- Flutter each repo: dart format --output=none --set-exit-if-changed on changed files; flutter analyze; targeted test directories followed by flutter test; local QA debug APK build using existing config. Worker typed boundary tools and fixtures as documented. Verify actual APK/version installed. Do not claim tests from an old APK.
- Artemis: real installed apps and actual authenticated actor journeys; capture screenshot before/after each relevant stage, no credential exposure. Playwright: real local dashboard at documented viewports. MySQL: read-only evidence queries scoped to QA fixture IDs, record quote/booking/assignment/status/interval/equipment/cash aggregates; compare before/after and prove isolation. No raw secrets or private customer data in evidence.
- If tool/device/credential unavailable, record exact blocker and remaining case IDs; execute independent paths. Never mark G2 passed on API-only or mocked UI tests.

### 16. P2-10 — Final gate, handoff and rollout control

G2 release-candidate checklist:
- [ ] G1 frozen and all three consumers compile and pass fixtures.
- [ ] P2-02 receipt mismatch eliminated on actual app, no duplicate booking.
- [ ] All mandatory E01-E16 and applicable prior regressions PASS, or explicitly blocked with release held; no unresolved P0/P1 critical security, financial, data, UI or role regressions.
- [ ] Full final feature/MySQL/Flutter and targeted/expanded Filament regression evidence at same candidate revisions. Confirm test counts, exit codes, logs and screenshot/DB links.
- [ ] Exact money, role scopes, funding, custody, cancellation and clock verified; no invented business policy. Historic compatibility preserved.
- [ ] Update original WP dispositions, phase2 ledger, contract fixtures/API docs, decision/risk/deferred register and final handoff with actual changes, test evidence, blockers and owners. No test or issue silently dropped.
- [ ] Keep CLEANING_V3_INDEPENDENT_DAILY_BOOKINGS_ENABLED and other commercial/admission gates at safe configured values; no general production activation in this task. Existing release readiness audit still required in addition to G2. Separate release authority approves staged rollout and observation; rollback disables new commercial work using existing flags, never deletes accepted/settled history or reverts ledger records. Existing accepted bookings remain operable. Reconcile forward if actual financial writes need correction.

Deliverable statuses: NOT_STARTED / IN_PROGRESS / PARTIALLY_VERIFIED / VERIFIED / BLOCKED / DEFERRED. Record per-WP entry/exit evidence, file list, commands, defects, decisions and next owner; no unsupported percentages. Stop after planning if this document is being authored, not executed.

### 17. Agent instructions and exclusions

Implementation agents: read original role plan plus this continuation and latest evidence. Start from current source, not a blank branch. Implement assigned WPs in coherent slices, run tests after each, protect unrelated staged/unstaged work, record actual verification and handoff to next owner. Use Rider for source investigation and implementation; use Artemis and Playwright for actual acceptance; use existing Foundry product/design system components if available, otherwise existing Flutter/Filament components with emulator/browser QA. Do not claim Foundry rendered scenes without actual tool output. Do not expand scope into new visit/series products, V4, unrelated app redesign, new payment provider, generic workflow engine, historical data migration or production deploy. No changes to price, compensation, cancellation or approval policy without explicit owner decision. This plan is a proposed execution sequence, not approval to bypass financial controls.

### 18. Initial execution ledger

All P2 packages are NOT_STARTED at plan creation; prior verified increments remain baseline evidence only. P2-00 is the first safe action. P2-02 is the first customer-critical code slice once receipt contract is verified; mobile agents can prepare tests in parallel. G1 and G2 remain BLOCKED until criteria are actually met.
