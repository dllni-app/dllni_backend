# Worker Compliance / Anti-Bypass — Dev implementation status

**Date:** 2026-11-23  
**Branch:** `dev` only  
**Scope:** Cleaning worker Flutter app, Laravel backend/Filament, consumer/supervisor integration audit.

## Implementation verified from source

The newest `dev` baseline already contained an Android compliance coordinator and foreground service, encrypted worker location samples, expiring and session-bound ingestion, historical site matching, official-booking exclusions, quality/dwell checks, administrative review cases, reviewer permissions, two-person confirmation, and case overview statistics. None of these pre-existing features should be described as newly built by this round.

This round made these changes:

1. Backend `CleaningBookingResource`: recursively redacts private address/phone/customer/coordinates and related fields inside V2 room and appointment snapshots in worker-facing responses. Added unit regressions.
2. Worker `WorkerComplianceSampleQueue`: limits encrypted local retry samples to the backend's 15-minute acceptance window. Adjusted queue tests.
3. Worker location gate: standardizes the app title and Arabic notice branding to **ع الندهة**. iOS now checks the feature rollout state before declaring an unsupported compliance-tracking platform unavailable, so the server-disabled feature doesn't unconditionally block a supported app.
4. Worker app: removed the obsolete working-hours destination, V2 port contracts, adapters, screen/editor and tests. Then removed legacy working-hours domain use cases, endpoints, BLoC events/state, profile JSON model and unused UI widgets, while preserving the customer's appointment and billed service times. Existing unrelated Windows-generated local edits were not staged.
5. Backend compliance activation: will not enable collection unless an HTTPS privacy policy URL is configured; stops any previously active tracking lease when the approval URL is withdrawn. Added feature tests.
6. Filament: added a **read-only, permission-gated Worker Compliance Settings** page exposing collection rollout, matching, threshold and retention settings without revealing secrets or allowing silent changes. Added Arabic/English copy and access tests.
7. Backend location ingestion quality now honors the configured maximum GPS error (rather than a hard-coded 50 metres); regression tests cover the threshold boundary.
8. Existing consumer app rebooking and preferred-worker selection were inspected. Existing supervisor and customer repositories did not require modifications for the above scoped changes.

## Verified tests

- Combined compliance ingestion, matching, case review, monitoring-settings authorization, visit evaluation and planning-redaction suite: **30 passing tests (121 assertions)**.
- The monitoring-settings authorization cases independently passed **2 tests (6 assertions)** and are included in the combined total.
- Existing V2 worker location/visit privacy and operational task tests: **6 passing tests (81 assertions)**.
- Flutter access/account V2, notifications, location sample queue: **29 passing tests**.
- Flutter analyzer on affected worker modules: **no issues**. A separate global analysis surfaced an existing unrelated `curly_braces_in_flow_control_structures` info in `supervised_worker_navigation_card.dart`; it was deliberately not included in this compliance refactor.

## Release and rollout blockers — do not bypass

**The implementation is not approved or validated as unconditional 24/7 employee surveillance.** The collection, matching and notification flags remain disabled by default; no deployment, workplace surveillance or forced GPS collection should begin based solely on this development work.

1. Independent legal/employment/privacy review of continuous location tracking outside assignments, employee rights, notice, storage/access and retention.
2. **iOS:** the current worker compliance coordinator's background tracking service is Android-specific; iOS needs its own approved design, device tests, permission disclosures and noncoercive flow. Apple's current App Review Guidelines explicitly restrict requiring system functionalities such as location services for access to app features or compensation: https://developer.apple.com/app-store/review/guidelines/. This conflicts with the requested hard gate and requires a product decision.
3. **Android:** Google Play requires background location to be essential to approved core functionality, with prominent disclosure and review; refer to https://support.google.com/googleplay/android-developer/answer/9799150. Android force-stop, OS background limits, batteries and connectivity prevent a guarantee of 24/7 data collection.
4. Real-device Android and iOS cases: permission denial, revoke, precise/approximate, air-plane mode, force-stop, reboot, battery optimization, offline recovery, job lifecycle and SOS.
5. The new Filament settings page is **read-only**; editing detection thresholds/TTL values within Filament is not implemented. Reviewed server config is the current source of truth.
6. Consumer/worker incentive features, mediated communication, and new reward or telecommunications products were intentionally not built; they remain separately scoped.
7. Full E2E acceptance of ordinary cleaning, teams, supervisor arrival, completion and renewal remains necessary before release; passing unit/feature tests does not substitute for it.

**Security rules:** No automated penalty based solely on geographic proximity; preserve worker explanation/independent review. Do not expose customer home locations or raw sample coordinates in staff summaries. Retain default-disabled flags until security, policy, platform and privacy gates are approved.

## Android debug-build verification (2026-11-23)

- Initial `flutter build apk --debug` failed at `:app:checkDebugDuplicateClasses` during `JetifyTransform` with `Java heap space` under the previous 1536 MB Gradle heap.
- Increased `android/gradle.properties` Gradle heap to 3072 MB and MaxMetaspaceSize to 768 MB; rebuilt successfully on the development computer (`assembleDebug` exit code 0).
- Artifact on the development computer: `dllni_cleaning_owner_app/build/app/outputs/flutter-apk/app-debug.apk` (173,611,436 bytes). This verifies buildability, **not** on-device GPS functionality or approval for 24/7 tracking.
- Deleted the 1.7 GB Java OOM heap dump generated by the failed build, leaving the two pre-existing unrelated Windows Flutter generated-file changes untouched.
- The final 12-case ingestion suite passed after adding a privacy notice withdrawal regression; the targeted backend compliance suite previously passed 30 tests, and these tests together establish at least 31 successful targeted backend cases after the updates.
