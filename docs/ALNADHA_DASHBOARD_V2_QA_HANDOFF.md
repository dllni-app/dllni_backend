# Al Nadha — Dashboard V2 implementation and QA handoff

Date: 2026-10-17  
Local backend: http://127.0.0.1:8000  
Filament panel: http://127.0.0.1:8000/admin  
Implementation specification: `docs/ALNADHA_FILAMENT_PRICING_ROOMS_INVENTORY_IMPLEMENTATION.md`

## Verified work

1. Added an administrative **hourly supervisor rate** and backend pricing snapshot. A supervised group is charged once for its actual non-overlapping supervisor time, independent of the number of workers.
2. Added `CleaningRoomType` catalog and Filament resource under Cleaning Catalog. Room names, icons, ordering, enable/disable status, per-mode hourly rate and size/mode suggested duration are now managed by backend.
3. Added a customer-facing room discovery endpoint `GET /api/v1/user/cleaning/room-types` and `data.roomTypes` in the suite configuration response.
4. Changed new cleaning room calculation to selected hours multiplied by the relevant hourly rate. Suggestions can be overridden per room and those values flow through signed estimate/create.
5. Extended cleaning materials with quantity-level reconciliation and equipment with supervisor/worker custody, guarded issue, return, loss, damage, stock audit and admin Filament actions.
6. Added role-scoped inventory read and supervisor receipt/return endpoints. Flutter integration is a separate acceptance gate.
7. Added read-only supervisor, material-kit and supervisor-price summary fields to the Filament order detail infolist.
8. Added an authorized Filament booking header action **إسناد المشرف / تغييره** backed by a shared supervisor assignment service. Eligible group bookings can be assigned/reassigned by administrative roles after checking every visit for schedule conflicts; active material/equipment custody and in-flight work block unsafe transfers. Changes are audited, and customer support cannot invoke the action.

## Verified local commands and evidence

- **PHP migrations:** `php artisan migrate --force` on the local MySQL development database. Two new migrations marked Ran. Inventory migration initially failed due MySQL's identifier length limit; the migration was corrected and safely reran without resetting data.
- **Backend feature tests:** `php artisan test tests/Feature/UserModule/CleaningRoomHourlyPricingTest.php tests/Feature/UserModule/CleaningTimePeriodV2Test.php tests/Feature/UserModule/CleaningRoomPeriodContractPhase1Test.php tests/Feature/Cleaning/CleaningConsumablesAndGroupEquipmentLifecycleTest.php --stop-on-failure` — **61 passing tests, 413 assertions** when first run after migrations (later suites expanded).
- **Unit pricing tests:** `php artisan test tests/Unit/UserModule/UserCleaningOrderEstimationServiceTest.php --stop-on-failure` — **12 passing tests, 59 assertions**.
- **Inventory API + lifecycle:** `php artisan test tests/Feature/Cleaning/CleaningConsumablesAndGroupEquipmentLifecycleTest.php --stop-on-failure` — **8 passing tests, 73 assertions**, including role-scoped API and equipment checkout/return.
- **Supervisor workflow:** `php artisan test tests/Feature/Cleaning/SupervisorWorkflowTest.php --stop-on-failure` — **13 passing tests, 130 assertions**.
- **Authenticated Filament server-side UI:** `php artisan test tests/Feature/Filament/CleaningRoomCatalogDashboardTest.php --stop-on-failure` — **4 passing tests, 13 assertions** covering authenticated room catalog list/create/edit rendering, real Filament room creation through a Livewire action (separate mode rates and default minutes persisted), persistence of the supervisor hourly rate through Financial Settings, and safe deletion only for room codes without historical bookings. This is a server-side Filament component test, not a signed-in browser walkthrough.
- **Supervisor assignment + operations regression:** `php artisan test --compact tests/Feature/Filament/CleaningBookingSupervisorAssignmentActionTest.php tests/Feature/Filament/CleaningBookingDashboardUiTest.php tests/Feature/Cleaning/SupervisorScheduledV2ReadOnlyTest.php tests/Feature/Cleaning/SupervisorApiTest.php tests/Feature/Cleaning/SupervisorWorkflowTest.php tests/Feature/Cleaning/CleaningConsumablesAndGroupEquipmentLifecycleTest.php` — **53 passing tests, 516 assertions**, including administrator vs support authorization, forged action denial, active/invalid supervisor selection, all-visit overlap checks, handover/return custody, reasons and audits. This does **not** prove a signed-in browser action walkthrough.
- **Browser smoke:** `node tests/browser/cleaning-dashboard-smoke.cjs`. Uses local Microsoft Edge in headless mode and captures screenshot/JSON evidence at `storage/app/qa/cleaning-dashboard`. Public landing and Filament login return HTTP 200, protected pages redirect to login, and no JS page errors or HTTP 500 responses observed. Admin CRUD pages were **not** authenticated for this smoke.
- **Flutter user app:** dynamic catalog room identity, backend ordering/active status, custom-code booking payload serialization, estimated hours override and mode reset — **9 passing focused tests**; touched-file Flutter analyzer passed.
- **Flutter supervisor app:** inventory model/API route and action mapping, Arabic RTL cards, 403/retry, duplicate-submit prevention, material receipt, equipment acknowledgement/return and damage reason — **10 passing focused tests**, touched-file Flutter analyzer passed.
- **Flutter worker app:** inventory parser tests passed, analyzer on touched inventory files passed after import fix; displays role-scoped consumables and borrowed assets in solo and supervised worker order details, with Arabic lifecycle labels.

## Required acceptance gates before declaring release ready

1. Authenticated browser QA of Filament financial settings, room CRUD, **supervisor assignment**, inventory actions, action confirmation modals, records persistence and RTL layout (test admin session required). Filament server-side authenticated Livewire tests are green, but browser UI is not covered.
2. The customer emulator **did complete a real 7-step booking creation** using a backend-added room code (`toilet`). Still required: run the same complete flow with a deliberately modified number of hours, check accepted worker tasks, and verify cancellation, recurrence and error-handling variants. Dynamic Flutter model tests are green.
3. Live-device supervisor app receipt and return behavior with actual assigned booking and stock consistency. Supervisor Flutter widget and contract tests are green, but they use stubbed API responses.
4. End-to-end **supervisor-led workflow** for V2 multi-worker bookings remains incomplete. The new role-scoped `/api/v1/supervisor/v2/bookings` APIs and Flutter tab now expose scheduled visits, task lists and *persisted* attendance read-only. V2 execution mutations intentionally return HTTP 422 because V2 still saves `booking_kind=standard` and requires a visit-specific workflow; the legacy `supervised_team` state machine cannot safely be reused as booking-wide start/finish for all visits. Design, implement and test a session/visit-aware supervisor workflow with customer decisions and settlement.
5. Broad backend and Flutter regression suites including existing user, worker and supervisor flows; no production readiness claim without these.

## Operational safeguards

- Preserve unrelated existing uncommitted backend and Flutter work.
- Do not `migrate:fresh` or reset real data.
- Avoid direct Filament mutation of booking statuses or stock; use validated domain services.
- Historical pricing snapshots must not be overwritten when changing configuration.
- Use backend for pricing and inventory state; mobile views present authoritative server responses.

## Continued verification — October 17

The following additional checks were performed against the current uncommitted development workspace:

### Customer app and real Android emulator

- Customer Flutter catalog + dynamic room type + override + icon tests: **11 passed**. Analyzer for relevant source files: **no issues**.
- Customer V2 booking screen, room-state and booking-draft regressions: **38 passed** (includes recurring dates, materials, team selection, quote retries and booking navigation).
- The Android emulator `emulator-5554` was online. An ordinary `flutter build apk --debug --no-pub` initially failed on Maven Central HTTP 403 for Gson metadata. **Direct offline** `./gradlew.bat :app:assembleDebug --offline --console=plain` succeeded using cached Gradle dependencies, generating `build/app/outputs/flutter-apk/app-debug.apk`.
- `adb install -r build/app/outputs/flutter-apk/app-debug.apk` succeeded. The live app opened as `com.alnadha.app`.
- The cleaning discovery page initially reported no connection until local development port forwarding was restored using `adb reverse tcp:8000 tcp:8000`. Afterwards the page loaded and cleaning booking navigation worked.
- Initial emulator test reached cleaning landing, room counts, sizes and حوسة/تعزيل. A medium-bedroom suggestion showed **0.58 hours**, and was manually changed to **1.25 hours** on-device. That initial draft itself was not submitted.
- A **second real on-device test completed all seven booking steps and successfully created a booking** for the newly added catalog room `toilet` (Arabic name مرحاض). The configured suggestion was 0.23 hours, material preview returned 9 materials, worker count was set to 2, and the backend quoted **150 SYP**. Backend database verification (read-only PHP bootstrap) confirmed booking `CLN-USER-A9QH4EMV` / id 34, `room_type=toilet`, `number_of_workers=2`, `booking_kind=standard`, 1 session, final price 150 and supervisor pricing snapshot `{fee:0, hourlyRate:0, billableMinutes:7, hours:0.1167}`. Android then showed the created booking details and pending worker search. The **actual supervisor charge is zero because the current configured hourly rate is zero**; set a nonzero business rate in Filament before production. This does not demonstrate supervisor execution.
- In the live booking review, the client initially printed `toilet 1` rather than `مرحاض 1`; the Flutter draft-generation path was patched to stamp backend display names when adding rooms. Specialized-service action labels were also repaired from corrupted Arabic to `إضافة`, `تعديل` and `إزالة الخدمة`. These two post-submit source fixes require a rebuilt APK and visual recheck.
- Screenshots: `C:\laragon\www\Dllni\qa\customer_cleaning_loaded_20261017.png`, `customer_room_selector_emulator_20261017.png`, `customer_mode_hours_emulator_20261017.png`, `customer_hours_override_verified_20261017.png`, `customer_room_catalog_bottom_20261017.png`, `customer_dynamic_toilet_size_20261017.png`, `customer_dynamic_toilet_mode_hours_20261017.png`, `customer_custom_toilet_materials_20261017.png`, `customer_custom_toilet_quote_total_20261017.png`, `customer_custom_toilet_booking_final_20261017.png`.

### Worker/supervisor Flutter

- Supervisor inventory/booking UI/API regression suite: **21 passed**, scoped analyzer: **no issues**.
- Added a dedicated read-only V2 supervisor tab for session/room-period bookings; full supervisor Flutter suite **28 passing tests**, analyzer **no issues**. Read-only V2 backend/supervisor/stock regression suite **38 passing tests, 352 assertions**. No new V2 start/finish action is presented.
- Verified Filament **إسناد المشرف / تغييره** action through server-side Livewire + domain tests, including all scheduled visits, role restrictions, custody, reason and audit. The full supervisor-assignment / cleaning-booking / V2 / inventory suite now passes **53 tests, 516 assertions**.
- Worker booking inventory and existing worker order flow: **18 passed**, scoped analyzer: **no issues**.
- Testing of actual supervisor kit handover/return against a real booking remains outstanding; widget tests use stubbed responses.

### Filament

- Authenticated Filament room-catalog and supervisor workflow component/feature suite: **17 passed, 143 assertions**.
- Enhanced room catalog list uses Filament-native icon preview, sort columns and active/inactive filter; catalog Livewire tests rerun after change.
- Browser-authenticated stock issue/return and catalog edit walkthroughs remain unverified; do not treat code-level tests as screenshots of an authenticated administrator.

### Risks and release decisions

1. **Critical:** do not present V2 multi-worker requests as fully supervisor-operated until the actual session/visit-aware supervisor execution API, worker restrictions, customer decisions and settlement are reconciled. Group supervisor pricing and kit custody alone are not this lifecycle.
2. **Medium:** Gradle online dependency resolution is currently blocked by HTTP 403 on Maven; offline build succeeded, but reproducible clean builds require resolving the upstream repository/network issue.
3. **Medium:** one API-connected customer quote/create case has passed; real-device worker/supervisor equipment/material handover and E2E session completion still need tests.
4. **Configuration:** the local Financial Settings supervisor hourly rate is presently **0 SYP/hour**. The tested two-worker booking therefore stored supervisor billable time correctly but assessed no monetary supervisor charge. Production pricing must use a separately approved nonzero rate, with a repeated quote/create test afterward.

## Continued integration verification — October 17, 05:09 local

### Supervisor assignment

- `ViewCleaningBooking` now exposes **إسناد المشرف / تغييره** for eligible active legacy supervised-team and standard scheduled multi-worker V2 bookings. It uses the same `CleaningSupervisorAssignmentService` as the admin REST endpoint, rather than bypassing assignment/custody policy through Filament.
- Verified on local Laravel: `php artisan test tests/Feature/Filament/CleaningBookingSupervisorAssignmentActionTest.php tests/Feature/Cleaning/SupervisorScheduledV2ReadOnlyTest.php tests/Feature/Cleaning/SupervisorApiTest.php --compact` — **23 passed, 222 assertions**. Tests include admin/Ops Manager roles, support-staff exclusion, reassignment reason and audit, overlapping visits, material and asset custody checks, V2 422 transition guard, and legacy supervisor API behavior.
- Filament authorization and state changes were tested with authenticated Livewire components. An actual signed-in browser UI interaction remains **unverified**.

### Customer Android recheck

- `flutter test --no-pub test/features/orders/view/widgets/cleaning_team_search_banner_v5_test.dart test/features/cl_main/domain/models/cleaning_dynamic_room_type_test.dart test/features/cl_main/view/screens/cleaning_booking_v2_screen_test.dart` — **32 passed**.
- A second offline Gradle `:app:assembleDebug` completed successfully. The rebuilt APK was installed on `emulator-5554`, and the previously created booking `CLN-USER-A9QH4EMV` was reloaded through the authenticated customer **طلباتي** screen.
- The updated two-worker order card and detail page both correctly show **جار تجهيز فريق التنظيف** rather than the single-worker message. Screenshots: `C:\laragon\www\Dllni\qa\customer_group_orders_loaded_20261017.png` and `customer_group_detail_loaded_20261017.png`.
- The Arabic backend room-label stamping and repaired specialized-service action labels are present in the reinstalled source build, but their specific screens were **not visually rechecked on the rebuilt APK** after the final source patches. Keep this smaller visual acceptance task open.

### Worker Android recheck

- Worker inventory/model/flow focused tests: **18 passed**.
- Debug `AppConfig.baseUrl` now defaults to `http://127.0.0.1:8000` through `adb reverse tcp:8000 tcp:8000`; **release remains `https://alnadha.net`**, and `--dart-define=API_BASE_URL=...` still overrides both. Scoped `flutter analyze --no-pub`: **no issues**.
- Worker offline Gradle `:app:assembleDebug` succeeded (**2m 17s**), creating `C:\laragon\www\Dllni\dllni_cleaning_owner_app\build\app\outputs\flutter-apk\app-debug.apk`. APK installation succeeded on `emulator-5554`, and the worker app opened its Arabic login screen with no observed fatal Flutter crash. Screenshot: `C:\laragon\www\Dllni\qa\worker_login_screen_20261017.png`.
- **Not verified:** actual worker sign-in, worker visibility of `CLN-USER-A9QH4EMV`, acceptance/arrival/task execution and supervisor-linked inventory transfers. Do not conflate an installed login screen with a complete logged-in E2E session.

### Remaining blocking decisions

1. Implement and test **per-visit supervisor execution** for standard multi-worker V2; the read-only tab and existing booking-wide custody intentionally do not permit visit start/finish/settlement.
2. Set and verify an approved, nonzero hourly supervisor rate before financial acceptance.
3. Complete authenticated browser Filament walkthrough and worker/supervisor/customer multi-app E2E, including equipment handover/return.
4. Resolve online Maven Central HTTP 403 to make clean-machine Android builds reproducible; local offline Gradle caches are an interim development workaround.

All repositories retain their previous uncommitted changes. No `migrate:fresh`, git reset or commit was performed during this continuation.
