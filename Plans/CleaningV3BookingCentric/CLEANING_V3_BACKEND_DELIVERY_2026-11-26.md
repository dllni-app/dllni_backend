# Cleaning V3 backend continuation — 2026-11-26

Disposition: **PARTIALLY VERIFIED — G1/G2 NOT RELEASED**. Scope: the approved booking-centric backend plan. This is implementation evidence, not a replacement for the original plan.

## Workspace and safety

Rider reconnected to C:\laragon\www\Dllni, backend dev branch. Existing dirty work retained; no reset, stash, destructive migration, production deployment, credential change, or broad rollout flag change. MySQL contract tests used local alnadha_worker_test with transactional fixtures. Feature tests used SQLite :memory: with V3 rollout settings false in the test process. Browser verification used a dedicated loopback server 127.0.0.1:18085 with explicit QA DB configuration and cleanup; existing port 8000 listener unchanged.

## Implemented / verified slices

- B1: Signed recurring confirmation creates three booking IDs, three execution IDs and immutable quote links. Exact amount conservation and identical receipt replay verified.
- B2: Scoped signed-price resolution, rejected cross-occurrence membership and tampering, pending revision of one day, cancellation of another, and once-per-quote coupon redemption/reversal. Signed approval opens only its own dispatch. Full treasury/equipment/cash settlement acceptance remains open.
- B3: Additive dailyBooking state and clock, retained event/legacy group shape, search and terminal handling, scope checks and supervisor admission per execution. Signed two-worker supervised day completed through real APIs: acceptance, assignment, travel, arrival, code, start, pause, resume, handover, confirmation. Actual interval time 180 seconds; amended sibling unchanged; cancelled sibling remains cancelled; no cash movement on completion.
- B4: Worker details use the existing execution visibility and admission gate and coarse location, including supervised independent daily offers. Existing supervised multi-execution route boundary retained. Customer-private fields absent and held sibling rejected. Supervisor roster/clock scoped to admitted execution. Legacy address fallback rejects address-like free text, prefers catalog district; unknown/unsafe labels fall back to unspecified. Separate supervisor verification and attendance rate-limit buckets keep six and twelve requests/minute respectively. 
- B5: Booking list and dispatch board rendered in real Playwright browser; Arabic RTL at 1440, 1024, 390 pixels, guest protection and authenticated admin access passed. Current targeted Filament tests green. Full accounting/action/filter/role browser matrix outstanding.

## Actual regression results

| Suite | Passed | Failed | Assertions | Exit |
|---|---:|---:|---:|---:|
| Feature/Cleaning --filter=CleaningV3 (SQLite) | 160 | 0 | 1734 | 0 |
| Contracts --filter=CleaningV3 (MySQL) | 77 | 0 | 903 | 0 |
| Independent recurring scenario subset (MySQL) | 2 | 0 | 198 | 0 |
| Recurring identity, supervised worker, supervisor API/workflow/team schedule | 52 | 0 | 496 | 0 |
| V2 worker privacy | 3 | 0 | 50 | 0 |
| Filament booking dashboard, dispatch, financial exposure | 12 | 0 | 106 | 0 |
| Expanded Filament including legacy supervisor assignment | 13 | 5 | 116 | 2 |

Subset counts overlap full suites; do not add them as distinct coverage. The five legacy supervisor assignment failures are BadMethodCallException at roomPeriods() fixture calls. Both missing relationship and stale fixture were checked in HEAD. No schema or model compatibility alias invented, no failing tests deleted. The isolated SQLite admission-state fixture explicitly rejects non-test/non-memory/nontransactional environments. It represents an admitted state, not a real quote/funding/payment. Actual admission and signature behavior are tested independently on MySQL.

Evidence under workspace qa/cleaning-v3/evidence:
- independent-daily-mysql-final.txt, independent-daily-features-final.txt, independent-daily-compatibility-final.txt, independent-daily-privacy-final.txt
- independent-daily-filament-current-final.txt, independent-daily-filament-final.txt
- independent-daily-dashboard-isolated/dashboard.json and bookings-1440.png, bookings-1024.png, bookings-390.png, dispatch-board.png
- Earlier independent-daily-features-reconnected.txt and independent-daily-contracts-reconnected.txt reflect earlier executed revisions, not final assertion counts.

## Provisional API integration map (not frozen G1)

| Read/command | Existing path / field |
|---|---|
| Quote confirmation / receipt | POST /api/v1/cleaning/v3/bookings; data.bookings[] (bookingId, bookingNumber, visitId, occurrenceKey, scheduledDate, amount); historical aliases retained |
| Customer detail | GET /api/v1/user/cleaning/orders/{booking}; data.dailyBooking, createdFromRecurrence, isRecurringOrMultiVisit |
| Customer/worker schedule | GET /api/v1/cleaning-bookings/{booking}/schedule; data.schedule.sessions, dailyBooking; customer-only admission/cash summary |
| Worker detail | GET /api/v1/cleaning-bookings/{booking}; execution visibility required, data.dailyBooking additive for eligible current ordinary daily execution; no precise address or private customer identity in coarse response |
| Supervisor detail | GET /api/v1/supervisor/bookings/{booking}; data.dailyBooking, v3Visits and scoped roster |
| Pending revision | GET /api/v1/cleaning/v3/bookings/{booking}/revision-draft; new signed quote and confirmation, exact current revision |
| Cancellation | POST /api/v1/user/cleaning/orders/{booking}/cancel; only eligible held booking; financial locks rechecked |
| Lifecycle | Existing /api/v1/cleaning-bookings/{booking}/sessions/{execution} and /api/v1/supervisor/bookings/{booking}/visits/{execution} commands; exact membership and revision required |

dailyBooking includes bookingId, executionId, status (persisted), state (derived), scheduledDate, scheduledTime, durationMinutes, requiredWorkers, acceptedWorkers, remainingWorkers, dispatchStatus, searchActive, clock (null where unavailable/not started; completed actual interval time only), allowedActions, priceRevision, serverNow. This is a preliminary integration map; action affordances and transitions remain subject to backend checks. No client-side authorization or payment assumptions. Historical multi-execution and event bookings do not acquire a false ordinary single-day projection.

## Work-package status / remaining gates

| Package | Status |
|---|---|
| B0 | IN_PROGRESS — local reconnect, plan, committed mismatch and test evidence checked; full baseline inventory not closed |
| B1 | IN_PROGRESS — core creation and replay verified; concurrent confirm/transaction failure matrix remains |
| B2 | IN_PROGRESS — allocation, cancellation, revision and admission verified; complete settlement/equipment/cash proof workflow remains |
| B3 | IN_PROGRESS — integrated lifecycle verified; all actions/states/races/realtime and G1 fixture matrix remains |
| B4 | IN_PROGRESS — primary role reads/access and worker privacy verified; supervisor reassignment/agenda and dedicated supervised worker V3 client compatibility still require acceptance |
| B5 | IN_PROGRESS — basic browser and targeted tests pass; five stale legacy Filament tests and detailed financial/role browser journeys open |
| B6 | IN_PROGRESS — API/MySQL and browser checks verified; real three-application emulator acceptance and full regression not complete |
| B7 | BLOCKED — G1 and G2 not approved/frozen; no deployment, no commercial release |

Next: reconcile the old roomPeriods fixture/legacy assignment contract without changing historical data; complete role/action/API samples and mobile compatibility; test actual cash and equipment custody, concurrency, full Filament acceptance, and Artemis customer/worker/supervisor journeys. Preserve exact accounting and deployment holds. No production payment evidence, funds, ledger changes or live historical booking conversion performed.
