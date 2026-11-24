# QA — dev branches — 2026-11-24

## Scope and constraints

- Connected Redmi Note 10 Pro Android 13: `17f882ee`, running via ADB.
- Local branches of `dllni_backend`, `dllni_cleaning_owner_app`, `dllni-user-app`, `dllni_supervisor` checked as `dev`.
- Preserved unrelated uncommitted changes in worker app (app, update modules, Windows Flutter generated files), user app (V3 draft models/data source), and backend (V3 readiness service/routes).
- Customer app observed on its **login page**, not authenticated. The previous phone session also had worker/supervisor login pages; no verified authorized end-to-end request transaction took place in this run.
- No worker GPS permission was granted through ADB and no real worker geolocation was accessed without consent.
- Device and local automated test outcomes do **not** prove location collection or administrative notification delivery on deployed infrastructure.

## Automated results (latest dev during this run)

| Component | Scope | Result |
| --- | --- | --- |
| Backend | Worker compliance: ingestion, site matching, pilot enrollment, telemetry-health alerts, public notice, Filament review/access | **32 passed; 139 assertions** |
| Cleaning worker Flutter | encrypted sample queue, worker account/permissions and access | **25 passed** |
| Supervisor Flutter | full `flutter test` suite, including arrival and team actions | **87 passed** |
| Customer Flutter | `cleaning_v3_catalog_renderer_test.dart` | **8 passed after test assertion correction** |
| Backend | All 20 `CleaningV3*Test.php` feature suites | **108 passed; 788 assertions** |
| Backend | V2 cleaning session/team lifecycle, team travel/arrival, worker location persistence, customer/worker lifecycle notifications | **37 passed; 429 assertions** |

Total **297 successful automated test cases across these six non-overlapping batches**. Earlier separate 19-case V3 subset was rerun within the 108-case complete V3 batch and is not counted twice.

## Defect found and corrected

The customer V3 preview test expected `step_mismatch` for value `20.001` when published Laravel and Flutter validators explicitly restrict numeric quantities to **two decimal places**, returning `invalid_number` before testing increments. Added a *valid-precision* mismatch example (`22.5` with min=10/step=5), and updated the over-precision assertion to `invalid_number`. Flutter test rerun passed 8/8. Pushed to customer `dev` in commit `7f113a4bdabaeabb7aa502f0cfa1436d9fb4ad0b`.

## Unverified on physical phone

1. Worker sign-in and voluntary opt-in / decline for background location monitoring.
2. Permission grant/revocation UX, GPS background samples and lease renewal, offline/reboot/force-stop.
3. Backend detection of historic served sites and a real Filament dashboard warning/case.
4. Supervisor/customer/worker live booked journey and Android push notifications.
5. Published server `dev` deployment/configuration parity; an accessible privacy URL is not enough to prove flags.
6. iOS tracking support (Android-only test handset).
7. Cleaning V3 financial booking creation — deliberately guarded until approved funding/integrity checks are live; preview tests do not unlock production booking.

## Next device execution requirements

- Log into each mobile application with the correct test role on the phone and ensure APIs target the intended dev deployment.
- Confirm staging rollout flags and the individual pilot enrollment in Filament.
- Do not begin extra-visit monitoring or override location permissions before showing the disclosure and obtaining the worker's authorization.
- Capture screen recordings, device logs and permission snapshots, and verify server-side database rows/Filament notifications without exposing customer coordinates.

Saved screenshots and the prior phone evidence remain under:
`D:\my_projects\dllni_com\QA\worker_compliance_phone_20261124\`.
