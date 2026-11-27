# Cleaning V3 backend — continuation evidence, 2026-11-26

Disposition: **VERIFIED INCREMENTS — G1/G2 NOT RELEASED**. Extends CLEANING_V3_BACKEND_DELIVERY_2026-11-26.md and the original implementation plan. This report supersedes earlier failure counts for the tests rerun below, not unexecuted acceptance criteria.

## Completed increments
- Repaired the five stale legacy Filament supervisor assignment tests by modeling actual V2 independent bookings and booking-level assignment/activity records instead of the nonexistent roomPeriods relationship. Retained permission checks, inactive supervisor denial, schedule conflict, idempotency and equipment custody tests. No legacy schema alias or production authorization change was introduced for these fixtures.
- Expanded the rollback-only signed MySQL scenario: both coupon/no-coupon confirmation paths inject a failure after the third quote-link INSERT, proving atomic rollback of booking, execution, link, reservation and redemption counts. Stabilized the test clock rather than relaxing the 180-second lifecycle assertion.
- Completed the signed independent-day lifecycle through simulated supervisor cash declaration, separate attendance proof reviewer, separate authorized receipt, exact collection/worker/supervisor/company reconciliation and replay. Completion alone remains unpaid. Negative authorization and premature receipt are tested. No actual payment evidence or production cash record was created.
- Approved and assigned the revised future day, then reassigned it to another supervisor. Verified new supervisor's calendar date, old supervisor's denial and unchanged settled earlier-day attributes, assignments and accounting.
- Supervised worker detail uses the exact admitted execution and accepted unreleased session assignment; rejected cross-day query and unrelated worker. Explicit session ID and active travel are necessary to show precise destination; before travel and after arrival it is absent. Legacy parent-level action flags are intentionally read-only for V3. Requirements remain explicitly unavailable rather than reconstructed from unsanitized data.
- V3 worker lifecycle mutation responses now omit totalPrice and customer reviewTarget; customer confirmation retains its total and completed-worker review list, and V2 envelope is unchanged.
- Actual simultaneous signed quote confirmation: two distinct PHP processes/MySQL connections waited on the same customer row. First returned 201, second 200/replayed, identical receipts. Exactly 3 bookings, 3 quote links and 1 execution each; 600000.00 SYP total, unpaid, pending operational review, no dispatch permission. Controller-level concurrency test (not two external HTTP clients).
- Verified actual authenticated Arabic RTL Filament list and dispatch board at 1440/1024/390 widths; separately opened each synthetic daily detail and verified sibling booking numbers absent.

## Environment / fixture safeguards
Rider workspace C:\laragon\www\Dllni. Existing dirty changes preserved; no reset, stash, commit, migration:fresh, production deployment or credential changes. Contract tests use verified local MySQL alnadha_worker_test and rollback. Feature tests use SQLite :memory: with rollout controls disabled in the test process. Real concurrency requires committed rows: retained a distinctly named synthetic customer ID 608, quote b70bb9f0-0051-40d7-9600-7720c110ea95, booking IDs 256/257/258, execution IDs 280/281/282 solely in local QA. Dates 2026-11-28/29/30 at 09:00. All held and unpaid. Its random QA signing key was process-scoped and not retained, so these records are inspection fixtures, not actionable signed quotes after the race. No shared price configuration or catalog publication changed. Harness refuses a nonverified local QA database and requires explicit run flag. Browser used separate loopback port 18085 and stopped its own server; existing port 8000 listener remained unchanged.

## Evidence (actual exit 0 unless noted)
| Suite | Tests / checks | Assertions | Evidence |
|---|---:|---:|---|
| Full V3 feature suite before final lifecycle privacy patch | 164 | 1761 | booking-centric-features-continuation.txt |
| Full V3 MySQL contract suite after final patch | 77 | 1009 | booking-centric-contracts-final-continuation.txt |
| Targeted legacy, equipment, funding schema, conflict, Filament | 38 | 398 | booking-centric-compatibility-continuation.txt |
| Targeted lifecycle / V2 and worker privacy after final patch | 51 | 339 | booking-centric-lifecycle-privacy-continuation.txt |
| Legacy supervisor subset (included in 38) | 6 | 71 | booking-centric-supervisor-filament-continuation.txt |
| Concurrent confirmation | 2 DB waiters, one creation + one replay | PASS | independent-daily-confirmation-race.txt |
| Playwright browser | 5 stages | PASS | independent-daily-dashboard-continuation/dashboard.json |

All paths above under workspace qa/cleaning-v3/evidence/. Suite counts overlap. Full feature suite predates the last narrow worker serialization patch; its targeted tests and entire MySQL suite passed afterwards. Browser evidence includes daily-256.png, daily-257.png, daily-258.png, list viewport screenshots and dispatch-board.png. Race script qa/cleaning-v3/independent-daily-confirmation-race.php. No failed suite counted as passed.

## Provisional integration delta (not G1 frozen)
- GET /api/v1/cleaning/supervised-worker/bookings/{booking}?sessionId={executionId}: V3 data.contractVersion=3; data.dailyBooking is canonical ordinary day; myMembership.executionId and sessionAssignmentId are distinct from legacy assignmentId. Destination null unless exact execution is supplied and viewer is traveling on its own accepted, unreleased assignment. 404 for nonmember or wrong execution. Legacy myAttendance mutation booleans false intentionally; V3 client must consume dailyBooking.allowedActions and execution-scoped routes. requirementsAvailable=false marks missing authorized task detail; do not interpret as no requirements.
- V3 worker responses to session lifecycle commands include scoped schedule, no data.totalPrice or data.reviewTarget. Customer responses retain those fields. V2 not changed.
- Continue to use receipt data.bookings[]; singular aliases refer only to first day. No V3 worker or supervisor may infer acceptance/admission/payment from status or UI alone.

## Work packages and release gates
| Package | Disposition |
|---|---|
| B0 | Partial — verified local QA context and selected baseline regressions; full inventory still open. |
| B1 | Core verified — independent confirmation, actual parallel replay and injected transaction rollback. |
| B2 | Core verified — signed pricing, coupon, revision, cancellation and signed simulated cash settlement; full treasury/equipment custody acceptance matrix remains. |
| B3 | Partial — real signed supervised lifecycle, clocks and privacy verified; all state/action/realtime matrix and true concurrent last-slot acceptance remain. |
| B4 | Partial — supervised worker membership/address and supervisor reassignment/agenda verified; worker requirement projection and full mobile consumption remain. |
| B5 | Partial — old supervisor tests fixed, current UI and three daily detail pages verified; full browser accounting/action/filter/role matrix remains. |
| B6 | Partial — API/DB regression and browser acceptance; all three installed apps launched through Artemis, customer was authenticated on historical multi-visit detail, worker and supervisor reached sign-in screens. No new daily booking mobile end-to-end result. Only one emulator connected; dedicated QA app endpoint/authentication and Flutter adoption still require validation. |
| B7 | BLOCKED — G1 API samples/typed Flutter adoption and G2 cross-client acceptance incomplete; no commercial rollout or deployment. |

Flutter source search found no dailyBooking match in the three current app lib trees. App startup is not workflow acceptance. Do not bypass authentication or change worker balances to force the emulator scenario. Next work: execution-authorized requirement projection and finalized G1 fixtures, Flutter implementation and role journeys, true competing workers for last slot, detailed accounting and equipment/treasury and dashboard browser acceptance. Re-run full suites at release candidate; preserve safeguards and existing work.
