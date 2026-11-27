# Alnadha — Cleaning V3 Artemis emulator test plan

Date: 2026-11-25
Scope: local development, Android emulator, customer / worker / supervisor apps and real Filament + Laravel + existing local MySQL.
Status: PLANNED — no UI test journey executed in this planning pass.

## 1. Scope and source of truth

This is the emulator-specific execution companion to:
- AGENTS.md (local QA guardrails and real UI requirement).
- docs/cleaning-v3-wp01-operational-admission-status.md (implemented versus open WP-01 gates).
- docs/cleaning-v3-phone-e2e-plan-ar.md (original phone QA matrix and case IDs).
- docs/cleaning-v3-admin-cash-release-ar.md (latest manual cash, fixed supervisor compensation and no-reassignment rules).

The user's explicit emulator request changes the execution medium for this plan, not the separate deferred physical Redmi Note 10 Pro release campaign. It does not authorize production activation or a change to business/financial policies.

Current preflight observations on 2026-11-25:
- Artemis get_ui_hierarchy returned an empty array. get_screen_image returned a 1x1 placeholder, not usable Android UI evidence.
- Rider adb devices -l returned no attached devices; the prior emulator-5554 is no longer connected. The bare emulator executable was not found on Rider's PATH (AVD existence is unverified).
- Prior worker build/install -r/launch passed. Supervisor debug APK built successfully but adb installation failed: device emulator-5554 not found. Customer build/install needs revalidation.
- Latest WP-01 recorded MySQL contract: 1 test, 57 assertions PASS. Targeted Flutter: 10 worker and 13 supervisor tests PASS. These are not equivalent to interactive UI tests or concurrent transactions.
- Real readiness command: 19/24 PASS, exit 2. Five blockers: approved_catalog_digest, published_catalog_integrity, approved_catalog_coverage_and_funding, signed_v3_financial_admission, mysql_concurrency_and_mobile_e2e_evidence.
- Visit-specific supervised start verification, start and handover are not complete. Legacy booking-wide execution is intentionally rejected. WP-02 real-time new offers, WP-03 post-admission amendments, WP-04 shortage/refund and WP-05 overflow extensions remain outstanding.
- Do not enable commercial admission just to make a test pass.

## 2. Tools and responsibilities

| Surface | Tool | Responsibility |
|---|---|---|
| Customer, worker, supervisor Android UI | Artemis | Launch, inspect screenshot and UI hierarchy, tap, input, swipe, back, home, stop/relaunch, notification/deep-link navigation |
| Source, build, installation, ADB, server logs | Rider | Verify branches, hashes, API config, device identity; build debug APKs; install -r; verify process and app package; obtain sanitized evidence |
| Filament administration | Real browser through available browser automation / interactive QA | Login, catalog, operational review, supervisor assignment, dispatch board, cash reports |
| Laravel and local MySQL | Rider | Readiness, test fixtures, requests, migrations, queues, event and row verification, independent concurrent API clients |
| Evidence | QA directory | Store screenshots, hierarchy, sanitized requests/responses, server and DB observations, test result ledger |

Artemis operates its connected screen. Its documented actions do not select an emulator serial, install APKs, launch an AVD, issue shell commands or inspect MySQL. Rider must handle those tasks and confirm both connectors refer to the same authorized emulator. Do not assume that a successful Artemis response identifies a real attached device.

| App | Repository | Android package |
|---|---|---|
| Customer | dllni-user-app | com.alnadha.app |
| Worker | dllni_cleaning_owner_app | com.dllni.clOwner |
| Supervisor | dllni_supervisor_app | com.alnadha.dllni_supervisor_app |

Use one emulator for the sequential multi-role journey; three isolated emulators may be used if supported and verified. Never switch to a physical or production-connected device without authorization. One emulator cannot prove simultaneous API races. Use independent processes/connections for concurrency tests.

## 3. Entry gates and environment recovery

G0 — restore device:
1. From Rider, locate the installed Android SDK and AVD manager, identify an existing authorized AVD and start it if stopped. Use the verified SDK emulator binary rather than assuming it is on PATH.
2. adb devices -l must return an authorized emulator in device state. Record actual serial, Android version, resolution and boot completion. Reject offline/unauthorized or ambiguous device identities.
3. Query Artemis get_ui_hierarchy and get_screen_image. Verify a real screen with usable dimensions or recognizable content; cross-check foreground package via Rider adb. Empty Flutter semantics alone can be handled with a usable screenshot; a 1x1 placeholder cannot.
4. Reconnect/reconfigure the connector if the device/screen cannot be correlated. Mark G0 BLOCKED if unresolved. No blind coordinates.

G1 — local system:
1. Record repository branches, HEAD SHA and dirty diff summary for all four repos. Keep dev and preserve unrelated changes; no pull/reset/commit/push for this campaign without explicit scope.
2. Verify Laravel's effective environment, database driver, configured local host and actual connected database/server/port. Expected prior target: MySQL 8.4 at 127.0.0.1:3306/alnadha_worker_test; verify now, do not assume. No alternative database, SQLite, or remote server. Do not expose credentials.
3. Inspect migrate:status and cleaning:v3-readiness --json. Check API reachability, queue worker, scheduler, Realtime and FCM configuration, and log availability. Record readiness failure as evidence, never modify the audit for a green result.
4. Confirm debug API base in each app is http://10.0.2.2:8000 for the local Laravel host, or an explicitly verified equivalent. Verify actual requests hit local Laravel. If any app points at production, stop before login or mutations.
5. Verify existing backups before any fixture reset. Prefer additive tagged fixtures or rollbackable backend tests. No reset is required just to begin smoke tests. Use existing local QA DB only and preserve prior evidence.
6. Prepare the three current debug APKs with the correct local configuration (inspect each app's supported dart-define; use API_BASE_URL where supported). Example from repo root: flutter build apk --debug --dart-define=API_BASE_URL=http://10.0.2.2:8000. Check successful exit and APK timestamp/hash. Install with adb -s <verified-serial> install -r <apk-path>. Do not clear existing data. If a signature mismatch occurs, record blocker instead of uninstalling without a migration/data decision.
7. Use Artemis launch_app for each exact package, inspect hierarchy/screenshot, verify no crash and known local session/login. Record package/versionCode, API request evidence and launch result.
8. Open real Filament browser and verify local login using existing authorized QA accounts. No passwords/tokens/OTPs in evidence.

G2 — fixtures:
- Account aliases C1 customer, C2 unrelated customer; W1/W2/W3 eligible workers, W4 excluded or overlapping worker; S1 assigned supervisor, S2 unrelated supervisor; A1 authorized admin, A2 unauthorized admin/support role.
- Use existing authorized accounts and synthetic QA records only. Inventory without logging credentials. Confirm active role/worker eligibility, location coverage, scheduling, and supervisor availability.
- QA-V2: existing historical V2 single and multi-session booking, baseline status/prices/assignments.
- QA-HOLD: genuine V3 pending operational review (signed quote if permitted), future visits, unpaid, no admitted dispatch or worker assignments.
- QA-SINGLE: one V3 visit, one eligible worker, signed policy applicable to the chosen type.
- QA-TEAM: V3 task booking with two future visits, two required workers per visit, independent occurrenceKey and appointmentKey; assigned S1 only after required approval.
- QA-EVENT: 12-hour event with valid 8h + 4h split, distinct crews.
- QA-REC: recurring schedule, at least three visits.
- QA-EDGE: signed schedule/price tamper, excluded worker, past start, partial coverage at escalation threshold, missing supervisor, conflicting shift.
- QA-FIN: exact manual-cash team contract, receipt/report/reconciliation; shortage/refund cases reserved until their package is implemented and policy confirmed.
- Do not fabricate admission, signatures, receipts, funding or an approval history row through SQL to unlock a positive UI case. Use a legitimately admitted existing QA booking or the approved application workflow in a guarded local test configuration, after real prerequisites are met. The prior rollback-only test's substituted release-evidence boundary is strictly test-scoped; do not replace or bypass the real service in the running API. If a valid positive fixture cannot exist yet, mark case BLOCKED. Restore any allowed local-only configuration change after test and retain before/after state.

## 4. Artemis execution procedure

For each case:
1. launch_app(package_name), inspect get_ui_hierarchy and get_screen_image. Record active package, current screen and actual dimensions.
2. Identify controls from accessible text/content descriptions and current bounds, or visual inspection when Flutter semantics are incomplete. Tap coordinates derived from the current view, not hardcoded positions from another device.
3. Use focus_and_input_text for synthetic fields; swipe for scrolling; back/press_key for navigation. Wait by re-inspecting expected state with a bounded retry count. Avoid fixed sleeps as proof of success.
4. Capture pre-action, post-action and relevant cross-app state (take_screenshot/get_screen_image; get_ui_hierarchy). Save with case/step identifier; redact synthetic-sensitive address, phone, tokens, security codes and any non-QA data. Store actual screenshot bytes using an available evidence mechanism; if connector cannot export bytes, record that limitation and retain conversation screenshot evidence. Do not invent file paths or screenshots.
5. Rider correlates server request, bookingId, visitId, occurrenceKey, price revision, state and row counts. For admin steps use real browser, not fabricated API-only substitute. A 200 response alone is not PASS.
6. For cross-role checks use app switch via launch_app on the verified package; do not force stop unless case requires restart. Refresh and verify both the UI and authoritative server state. For failure, capture evidence before retrying.
7. For realtime cases keep target app foreground without manual refresh initially, compare background/notification tap and cold start; measure server event / UI observed times, then separately test refresh fallback. Do not treat polling as push. Record actual latency, no invented SLA.
8. For disconnect/background cases use verified Android system controls or Rider ADB for network/permission changes and restore them in a finally/cleanup step. Do not change server or emulator clock to fake business time.

Illustrative sequence (tool arguments, not a pre-recorded action script):
    launch_app(package_name="com.alnadha.app")
    get_ui_hierarchy()
    get_screen_image()
    tap(coordinates=[x_from_current_view,y_from_current_view])
    get_ui_hierarchy()
    take_screenshot()
    launch_app(package_name="com.dllni.clOwner")
    get_screen_image()
    launch_app(package_name="com.alnadha.dllni_supervisor_app")
    get_ui_hierarchy()

Actions must be based on each live screen. No coordinate values are assumed in this plan.

## 5. Priority matrix

P0 = financial, authorization, crash/data-loss, assignment/visit isolation.
P1 = essential workflow/consistency, notifications, recovery.
P2 = presentation/accessibility and usability (escalate if blocking).
READY = runnable once G0/G1 and needed safe fixtures exist.
GATED = requires valid admitted fixture / actual prerequisite; no workaround.
FUTURE = depends on unfinished work package or unresolved policy.
All cases are NOT RUN at plan creation; classifications are execution prerequisites, not results.

### Phase A — startup, regression, catalog and customer scheduling

| ID | P | Role / steps | Expected result / evidence | Gate |
|---|---|---|---|---|
| EMU-01 | P0 | G0: confirm device identity and Artemis screen | Non-placeholder screen, authorized emulator and matching foreground package | READY |
| EMU-02 | P0 | Build/install -r/launch all three; verify server destination | Correct package/versions; no crash; local API requests; preserved app data | READY |
| EMU-03 | P0 | Authenticate C1, W1, S1 and A1; logout/relaunch | Role-correct views; no other account data; no production traffic | READY |
| REG-01 | P0 | Customer/worker/admin open known V2 single and multi-session; run authorized V2 flow | Existing V2 prices, availability and state transitions unchanged; legacy UI not redirected to V3 | READY |
| REG-02 | P1 | Customer navigate addresses, notification feed, other sections; worker profile; supervisor V2 tasks | No regression outside V3; no unrelated data altered | READY |
| CAT-01 | P1 | Filament review/publish valid local catalog if permitted, customer refresh property form | Correct published fields for chosen property; no stale draft; current server digest | GATED for publish; read-only READY |
| CAT-02 | P0 | Admin validate incomplete or overlapping tariff draft | Rejection, unchanged published digest, no incorrect price | READY if admin fixture |
| CAT-03 | P1 | Compare property types and count/area/length/unit/hour inputs; regular/deep and scopes | Inputs and prices follow published catalog; invalid/empty scopes rejected | READY if catalog available |
| UI-01 | P2 | Scroll, type Arabic notes, open keyboard, rotate if supported, back/restore form | RTL/Cairo as existing design; readable labels; no overflow or lost draft | READY |
| GEO-01 | P1 | Test GPS permission allow/deny, synthetic covered/outside address, alternate address | Valid addresses usable; outside coverage refused; no forced unsafe permission | READY |
| SCH-01 | P0 | Pick past vs future, time, gender; validate required fields | Past/missing invalid input rejected; valid future explicit choice accepted | READY if preview available |
| SCH-02 | P1 | Preview single visit, multiple appointments, multiple visits in one appointment | Correct visit identities, no booking created on preview | READY if preview available |
| SCH-03 | P0 | Preview 12h event and three indivisible 250-minute tasks | Event 8+4 with distinct crew requirement; task capacity requires three workers; no >480-minute visit | READY if preview available |
| SCH-04 | P1 | Generate weekly/biweekly recurring plan; edit during load; confirm/cancel replacement | Latest server result only; preserved identities, explicit replacement | READY if preview available |
| SCH-05 | P0 | Try excessive counts, invalid times and >480 minutes | Safe validation; no signed quote or booking from invalid input | READY if preview available |
| QTE-01 | P0 | Compare signed prices for 1/2/3 workers, coupon and transport/equipment | Extra-worker fees 0/150000/300000 SYP per visit under specified contract, coupon capped at company commission; no coupon on protected fees; verify actual server quote | GATED |
| QTE-02 | P0 | Confirm same signed quote twice after simulated lost response; tamper/expire | One booking, rejected stale/tampered price, no client-supplied financial override | GATED |
| QTE-03 | P0 | Attempt unsupported/unfunded approval | Refusal; no collected payment, no dispatch or assignment | READY with legitimate pending fixture |

### Phase B — WP-01 operational admission, worker discovery and supervisor assignment

| ID | P | Role / steps | Expected result / evidence | Gate |
|---|---|---|---|---|
| ADM-01 | P0 | C1 open QA-HOLD; W1 search; A1 review in Filament | Customer sees pending review, no search timer; worker cannot discover/accept; admin blockers visible | READY with QA-HOLD |
| ADM-02 | P0 | A1 with operational switch off; A2 attempts approval; approved API negative cases | No new approval row, hold stays, no dispatch; authorization enforced independently of UI visibility | READY with QA-HOLD |
| ADM-03 | P0 | Change signed scheduled time/duration/crew/price in controlled rollback-only test; review | Signed mismatch rejected; no approval / financial movement | READY backend only, separate evidence |
| ADM-04 | P0 | A1 approves valid booking only when all real gates satisfied; refresh C1/W1 | Audit row binds actor, quote, revision, visits; hold cleared atomically; per-visit dispatch timers; unpaid status unchanged | GATED |
| ADM-05 | P0 | Repeat approval / reload / interrupted reply; transactional failure test in backend | Same original decision; one approval; unchanged deadlines; rollback leaves no partial writes | GATED for UI; rollback test READY |
| DSP-01 | P1 | W1 open visits after legitimate admission, C1 refresh | Only eligible future visits discoverable; no accurate address/contact before authorized acceptance | GATED |
| DSP-02 | P0 | W1 accepts one of QA-TEAM visits; inspect W1, C1, S1, A1 | One assignment to selected visit only; sibling untouched; visit coverage 1/2 vs 0/2 | GATED |
| DSP-03 | P0 | W1 accepts QA-SINGLE; refresh list/detail, stop/relaunch; test old capable/uncapable API | Single visit uses session flow; contractVersion=3 retained; old client 426, parent V3 mutation rejected | GATED for positive, negative READY where fixture permits |
| DSP-04 | P0 | W4 excluded, unavailable, wrong gender or overlaps; another worker accepts last slot | Ineligible visit absent/rejected; no excess seat; server ownership and criteria enforced | GATED |
| DSP-05 | P1 | Age tagged fixture to six-hour threshold via supported dev fixtures; invoke scheduler | One logical shortage notice, admin escalation, search continues for future visit, deadline not reset; no cancellation | GATED |
| DSP-06 | P0 | Complete coverage then remove/replace only where allowed; check old worker | New coverage state and revoked access; no duplicate fee/deadline reset; sibling unchanged | GATED / WP-03 if action unavailable |
| DSP-07 | P0 | Try accept visit whose start is past; tamper sibling visit ID | Rejected, no additional assignment; no private data leak | GATED |
| SUP-01 | P0 | A1 assigns S1 to QA-TEAM before travel; try W1 travel before assignment | Before assignment rejected with no travel time; after assignment eligible travel follows actual time window | GATED |
| SUP-02 | P0 | S1 opens booking; S2 attempts same booking; W1 and W2 acceptance on different visits | S1 sees independent rosters, counts, dates and clocks; S2 denied; no merged legacy crew | GATED |
| SUP-03 | P0 | Attempt supervisor reassignment before/after travel per existing permissions/reason | Valid pre-travel assignment rules; after any travel/execution begins, no change even for sibling visits | GATED |
| SUP-04 | P0 | S1 opens supervised V3 details and invokes old booking-wide start endpoint in controlled negative API check | No legacy start action; old endpoint rejected; no clock/financial mutation | GATED |
| LOC-01 | P0 | Inspect offer, detail, notification, tracking before/after accept and after release/logout | Address, coordinates, phone restricted to entitled role/visit; no leakage via stale cache or realtime | GATED |
| AUTH-01 | P0 | C2/W4/S2 direct unauthorized detail/mutation and cross-booking visit ID through test API | Denied, no data change, no private roster/address disclosure | READY with safe negative fixtures |
| RACE-01 | P0 | Two independent MySQL/API clients concurrently approve same eligible booking | One approval history row; replay or safe conflict; exactly one dispatch record per visit; no collection | GATED |
| RACE-02 | P0 | Two eligible workers compete for last seat; competing requests on siblings | One winner for contested seat, no overbooking or accidental sibling acceptance; lock results logged | GATED |
| RACE-03 | P0 | Concurrent approval vs pending revision/cancel; supervisor assignment vs first travel | Serialized decision, no stale quote dispatch, no unauthorized supervisor change, no partial commit | GATED |

### Phase C — lifecycle and work clock

| ID | P | Role / steps | Expected result / evidence | Gate |
|---|---|---|---|---|
| LIFE-01 | P0 | QA-SINGLE W1 accept -> travel -> arrive -> verified code -> start -> finish -> C1 confirm/review | Correct visit state, one clock interval, no duplicate transition; price/receipt matches actual authorized settlement | GATED |
| LIFE-02 | P0 | Event crew without supervisor: verify leader, accept team, authorized role and leader exit | Correct leader authority; no unrelated worker mutation; new leader per existing policy | GATED |
| LIFE-03 | P0 | S1 and crew perform individual visit attendance/verification, initial start, equipment/handover, completion | Correct visit-specific proof; no parent lifecycle shortcut; no fake administrative attendance evidence | FUTURE (WP-01 closure) |
| CLK-01 | P0 | Customer requests pause, authorized worker/supervisor rejects then accepts second request | Time continues while pending/rejected; stops only when approved; one interval closes | GATED |
| CLK-02 | P0 | Resume paused visit; other supervisor/worker attempts command | Only authorized actor and visit; correct active time, no sibling effect | GATED |
| CLK-03 | P1 | Stop/relaunch all apps, background, temporary network loss during running/paused | UI recovers server clock and revision; no duplicate interval, paused time excluded | GATED |
| CLK-04 | P0 | Try resume with conflict, complete during pause, pending request retry | Server enforces allowed state and no conflicting interval; protected prices remain consistent | GATED |
| CLK-05 | P0 | Compare work intervals, active seconds, final price across all roles | Same authoritative time, event rounding once per visit, no pause fees; do not infer cash receipt | GATED |
| EQU-01 | P0 | Required equipment reservation, handover/return and unavailable asset or overlap | Custody and availability enforced, cannot reuse conflicting equipment; visit-specific handover requires completed path | GATED/FUTURE |

### Phase D — remaining packages, realtime and financial verification

| ID | P | Role / steps | Expected result / evidence | Gate |
|---|---|---|---|---|
| NOT-01 | P1 | Approved new offer while W1 foreground on home and visits; background and cold-start notification | Eligible new visit notification once; opens correct visit; no other sibling or excluded user leak; do not rely on manual refresh | FUTURE WP-02 |
| NOT-02 | P1 | Coverage/escalation, accepted, start/pause/completion notification; read/unread, deep link, stale notification | Correct recipient and visit; no duplicates; revoked access denied; measured observed delivery | GATED per implemented event; WP-02 new offer FUTURE |
| NOT-03 | P1 | Deny notification permission, offline/reconnect, logout/switch account | No private data for wrong account; safe foreground refresh fallback; no false push PASS | GATED |
| REC-01 | P1 | C1 recurring view, accept one visit and open past/future | Occurrence keys stable; independent acceptance and statuses | GATED |
| REC-02 | P0 | Post-approval change date, skip, cancel selected visit with unaffected sibling | Stable occurrence identity; only intended visit changed; no unapproved charge/refund; revalidate eligibility | FUTURE WP-03 |
| REC-03 | P0 | Change/cancel after travel, active work or completion; concurrent accept | Existing approved policy governs; unauthorized/ambiguous cases stop for review, not silently cancelled | FUTURE WP-03/04 |
| FIN-01 | P0 | Completed signed manual-cash team: supervisor reports actual collection/wages, retains fixed per-visit fee; admin records receipt | Exact reconciled amounts, company remainder, no electronic transfer/fictitious wallet credit, no double report/receipt; reported vs received distinguished | GATED |
| FIN-02 | P0 | Shortfall before/after remittance; customer refund request and retry | No automatic wage deduction or invented cash movement; refund never above actual collected net under approved policy; independent evidence and idempotency | FUTURE WP-04; detailed policy confirmation required |
| FIN-03 | P0 | Coupon cap, protected extra worker fee, transport/equipment; compare signed old V2/legacy contracts | Company-funded discount only from signed eligible commission, no protected-fee deduction, historical terms unchanged | GATED |
| FIN-04 | P0 | Replay settlement/receipt and test unrelated actor; independent concurrent requests | No duplicate movement or double collection/refund/receipt; authorized roles only; DB totals conserved | GATED / WP-04 for exception cases |
| EXT-01 | P0 | Paused V3 visit quote +30/60, customer confirms, crew/supervisor approves; reject stale/duplicate offer | Signed price/revision, authorized participants and actual schedule/custody checks; clock does not reset; fixed supervisor visit fee unaffected under new policy | GATED |
| EXT-02 | P0 | 450+30 allowed, 451/480 cannot add 30; test non-step/oversize requests | No invalid quote, no >480-minute visit, no bogus charge | READY contract test; UI GATED |
| EXT-03 | P1 | Request more time than remaining visit capacity | Current behavior rejects overflow. Separate additional priced visit with distinct identity/crew only if approved WP-05 policy implemented; do not silently extend existing visit | FUTURE WP-05 |
| MON-01 | P1 | Worker location permission, app background, revoke permission/logout | Compliant location access and reporting only for authorized visit; emulator limitation documented | GATED |
| UPG-01 | P0 | Install compatible older QA worker APK then upgrade -r with data preserved, test active fixture | Compatible upgrade; old V3 mutation denied; new scoped path; no login/booking loss | GATED; signed production rollout remains separate |
| ROL-01 | P0 | In approved local test context disable new approval/admission after existing legitimate active/paused visit | No new approval, existing admitted visits remain accessible under original contract; no ledger change; restore settings | GATED |
| UI-02 | P2 | Arabic RTL, overflow, dark/light if supported, screen density, loading/error/empty states across roles | Readable status, no fake active actions, navigable lists and clear retry; visual evidence | READY/GATED by screen |

## 6. Golden cross-role scenario (when genuine admission is available)

Use QA-TEAM, V1/V2 meaning visit 1/visit 2 here (not contract version); two required workers each.
- Step 1 C1 in customer app creates/opens genuine signed unpaid booking. Capture quote and two visit IDs; capture held UI.
- Step 2 W1 in worker app searches; held booking must not appear. A1 opens real Filament operational review. Verify default gate refusal first.
- Step 3 A1 legally approves only after release prerequisites. Capture audit and per-visit search rows. C1 sees approved, W1 sees offer, no precise address before authorized acceptance.
- Step 4 W1 selects visit 1 only. C1/Filament show visit1=1/2 and visit2=0/2. W2 accepts another eligible seat; check independent assignments, no automatic sibling commitment.
- Step 5 S1 assigned by A1 before travel; S1 app displays separate rosters; S2 has no access. W1 before-S1 travel attempt must have failed without a timestamp.
- Step 6 If visit-specific supervised execution is implemented, perform arrival/verification/start for the correct crew and visit, then pause/resume, authorized extension within 480 minutes, finish and handover. Otherwise stop and record LIFE-03 BLOCKED; do not use old parent commands.
- Step 7 C1 confirms completion if supported; S1 reports actual QA cash, A1 independently confirms recorded company receipt. Use only synthetic authorized cash ledger records; no real payments. Record actual reconciliation. Unfinished cash exceptions remain blocked.
- Step 8 Verify sibling remains unchanged, future visit remains independently actionable, app reload/restart maintains version and state, V2 baseline unchanged. Repeat same workflow with QA-SINGLE and QA-EVENT where their own policy prerequisites are met.

At every transition compare UI across roles with server state; if mismatch, identify whether API, projection, cache, realtime delivery or UI routing is responsible. If positive gate is blocked, the held/denied journey can still pass independently.

## 7. Backend evidence and independent verification

Run the existing rollback-only test from Dllni_backend after checking local QA identity:
    cmd.exe /c "set DB_CONNECTION=mysql&& set DB_DATABASE=alnadha_worker_test&& set DB_HOST=127.0.0.1&& php vendor/bin/pest tests/Contracts/CleaningV3OperationalApprovalMySqlTest.php --stop-on-failure"

Do not run default RefreshDatabase suites that switch to SQLite, reset another database, or invalidate shared QA data. For race tests, use independently authenticated requests/processes with a synchronized start barrier and separate MySQL connections, isolated tagged fixtures and explicit cleanup. Record winner/loser responses, before/after counts, invariants and deadlock/retry behavior. Sequential UI clicks or a rollback-only test are not concurrency proof.

Read-only evidence queries/projections should cover:
- cleaning_bookings: contract_version, status, property_details admission hold/current quote, search_started_at.
- cleaning_booking_quotes: accepted quote ID/signature validity/revision and digest; avoid dumping secrets or entire PII-bearing snapshot.
- cleaning_booking_sessions: visit identity, occurrence_key, appointment_key, date/time, price_revision, status, coverage, payment status.
- cleaning_v3_operational_approvals: one approval per booking, quote revision, actor and visit IDs.
- cleaning_v3_visit_dispatches: per-visit first search start/deadline, escalation and coverage.
- visit worker assignments and supervisor assignments: correct visit/role/active status, travel/arrival timestamps, no sibling assignment.
- actual work clock/interval/attendance tables, live extension quotes, cash movements, supervisor cash accounts/earnings and receipt references: discover actual schema through source/migrations first; record relevant rows only.
- notification delivery intents, queue/realtime logs, database notifications and actual device appearance.

Verify the core invariants: approval is not collection; accepted visit does not imply sibling acceptance; no admission via header/hold flag alone; no start without verified role/visit; six-hour deadline does not cancel or restart search; 480-minute ceiling; one supervisor after travel; signed prices and protected fees; no duplicate movements or refund greater than collected amount; V2 unchanged.

## 8. Evidence template and issue handling

Use qa/cleaning-lifecycle/phase-1/artemis-emulator-<run-id>/ (new folder per run). Preserve previous QA.
Suggested subdirectories: preflight/, customer/, worker/, supervisor/, admin/, cross-app/, api/, database/, races/, defects/, regressions/.

Per test record:
    caseId:
    originalReference:
    priority:
    status: NOT RUN | PASS | FAIL | BLOCKED | NEEDS CONFIRMATION
    runId / timestamp:
    app package / version / emulator serial:
    role alias:
    bookingId / visitId / occurrenceKey / priceRevision:
    prerequisite / release gate:
    steps and actual UI controls:
    expected:
    actual:
    before/after screenshots and hierarchy references:
    sanitized API request/status/response reference:
    backend log / DB evidence:
    defectId / reproduction:
    cleanup / configuration restoration:
    retest disposition:

PASS requires actual required UI + backend assertions; FAIL is observed mismatch; BLOCKED is unavailable prerequisite; NOT RUN is no attempt; NEEDS CONFIRMATION is ambiguous approved business behavior or incomplete evidence. A missing feature is not a successful negative test unless denial is the explicit expectation.

Defect triage:
- P0: stop affected journey immediately, retain state/evidence, do not alter financial history, report reproducible steps. Resume unrelated safe tests only.
- P1: record and continue independent tests if safe; retest impacted flows after fix.
- P2: record visual/usability issue; no fake pass.
- Fixes are separate implementation tasks; rebuild/install -r and rerun failed plus affected regression cases. Do not modify behavior during this plan-generation pass.

## 9. Execution waves and exit criteria

Wave 0: EMU-01–03, preflight and fixture inventory. Current G0 BLOCKED: no attached device and placeholder screen.
Wave 1: V2, catalog/preview, held booking and authorization negatives, app UI smoke tests. Execute when G0/G1/G2 are satisfied. If catalog/fixture unavailable, record its blocker.
Wave 2: positive WP-01 dispatch/acceptance/supervisor roster and race tests only after valid positive fixture/release prerequisites. No legacy workflow shortcut.
Wave 3: supervised verification/start/handover, work clocks and financial journey after remaining WP-01 and relevant release gates complete.
Wave 4: WP-02/WP-03/WP-04/WP-05 tests as implemented, then targeted regressions.
Wave 5: full authenticated cross-role replay through actual UI and Filament, real concurrent MySQL tests, final readiness and signed APK/physical-device campaign separately as approved.

WP-01 exit: real positive and negative approval/dispatch/acceptance, visit isolation, supervisor verification/start/handover, customer projections, independent concurrency evidence, V2 regression, all three local APK install/launch and cross-app UI evidence; no unresolved P0/P1 affecting scope.
Full Cleaning V3 exit: every required P0/P1 passes after its package is implemented; actual commercial readiness verified, no unresolved required BLOCKED cases; separate signed-device rollout/physical phone verification. Keep release switch off until authorized release. Emulator smoke tests alone do not clear commercial release.

Planning disposition: docs only, no business rule changes, no data writes or new app builds during generation. Current UI test status is NOT RUN except the read-only Artemis/device readiness probe, which establishes an environment blocker rather than an application failure.

