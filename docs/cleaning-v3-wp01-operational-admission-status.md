# Cleaning V3 — WP-01 operational admission and dispatch

Status: **PARTIAL — release hold retained**.

## Implemented and verified

- A separate disabled-by-default operational approval configuration.
- Administrative approval under the booking lock, with a persistent approval
  row binding the accepted signed quote, revision, actor and visit IDs.
- Repeat approval returns the original decision; rollback removes approval,
  hold removal and every newly created visit dispatch record together.
- Review revalidates the signed catalog, quote, persisted monetary fields,
  canonical visit schedule fingerprint, payment policy, future start,
  client manifest and independent release-readiness evidence.
- Worker discovery and acceptance require persisted admission, not merely
  a missing or false JSON hold. Acceptance is visit-scoped; the capability
  header is a compatibility signal, not authority.
- The worker client preserves V3 identity through detail refresh and uses
  visit acceptance even for a single-visit V3 booking.
- Supervisor assignment uses the admitted booking, and a supervised visit
  cannot begin travel before a supervisor has been assigned. Existing
  supervisor replacement restrictions remain unchanged.
- The assigned supervisor receives separate visit crew projections; sibling
  assignments are not combined. The supervisor client renders separate
  rosters and clock cards instead of the legacy parent execution screen.
- Customer schedule and booking projections expose the authoritative
  admission state and suppress discovery information for unapproved visits.
- Approval does not mark payments received, create fictitious receipts or
  override the signed manual cash policy.

## Verification evidence

Local MySQL 8.4, existing authorized QA database, rollback-only contract test:

    cmd.exe /c "set DB_CONNECTION=mysql&& set DB_DATABASE=alnadha_worker_test&& set DB_HOST=127.0.0.1&& php vendor/bin/pest tests/Contracts/CleaningV3OperationalApprovalMySqlTest.php --stop-on-failure"

Run from the backend directory. Latest observed result: one test, 57 assertions,
PASS. Includes authorization, real signed quote validation, schedule tampering,
rollback, replay, independent six-hour dispatch deadlines, one-visit HTTP
acceptance, rejection of travel before supervisor assignment, assigned supervisor
roster isolation, unrelated supervisor rejection, and customer admission state.
Only the external release-evidence boundary is substituted inside the positive
rollback-only test. The real readiness service remains fail-closed.

Flutter verification: 13 supervisor API/clock tests PASS; 10 targeted worker
acceptance/details-mapping tests PASS. Worker APK build, install -r and launch
on emulator-5554 PASS, using API_BASE_URL=http://10.0.2.2:8000.

Local build/install evidence is under
qa/dynamic-cleaning-v3/remaining-work-20261125/wp01-emulator-status.txt
and wp01-*-build/install/launch logs. Consult the latest file for supervisor
build status. Launch evidence alone is not positive-flow UI verification.

## Open closure gates

- The legacy supervisor parent execution mutations are intentionally rejected
  for V3. Complete the visit-specific attendance proof, customer/supervisor
  start verification, initial start and handover integration before considering
  the supervised execution journey complete. Do not reinterpret administrative
  post-work attendance evidence as initial operational attendance proof.
- Actual independent concurrent approval/last-seat acceptance processes have
  not yet been exercised. The rollback-only sequential test is not race proof.
- Full Filament and authenticated cross-app positive UI journey not run.
- Verify actual production-compatible signed client rollout and actual
  commercial funding/catalog evidence. Do not replace the real readiness audit
  with test doubles or configuration assertions.
- Other remaining work packages retain ownership of instant notification
  delivery, post-admission amendments, cash exceptions/refunds, and extensions.

## Release disposition

Actual readiness result: 19 of 24 checks pass, five blockers remain:
approved_catalog_digest, published_catalog_integrity,
approved_catalog_coverage_and_funding, signed_v3_financial_admission,
mysql_concurrency_and_mobile_e2e_evidence.

Do not enable CLEANING_V3_OPERATIONAL_APPROVAL_ENABLED for commercial use.
No production deployment, commit or push is part of this work.

## Local QA continuation — 2026-11-26

The local QA path now checks the effective MySQL target and uses a reserved synthetic
catalog with independent quote signing. It uses the existing authorization, manual
cash price checks, transactional approval and dispatch. The dedicated QA catalog
published under independent MySQL contention and served through the real catalog
endpoint. The commercial registry and five commercial release blockers remain in
place.

The rollback-only MySQL contract exercised signed-price mismatch rejection, scoped
worker acceptance, supervisor roster isolation, travel and arrival. The shared
visit-code route now persists rejected attempts before returning validation,
enforcing the existing attempt limit without affecting sibling verification.
At that checkpoint, visit-scoped supervisor verification, initial start, handover,
and authenticated cross-application completion were open.

See the [campaign report](../../qa/cleaning-v3/CLEANING_V3_FINAL_QA_REPORT.md).

## Supervised visit execution

The assigned supervisor can request a verification code for a fully arrived visit
crew. The booking customer reads the code on that visit. Confirmation binds the
current supervisor and arrived assignments. Starting the visit opens one interval
through the existing work-clock command service. Handover uses the same visit
completion path as the customer review, including objection and reopening.

The MySQL rollback contract exercises a two-worker crew from travel through
completion and verifies an untouched sibling visit. Payment remains pending for
the existing financial reconciliation. Operational start verification does not
replace independent documentary attendance proof used for settlement.

See the [follow-up evidence](../../qa/cleaning-v3/SUPERVISED_VISIT_FOLLOWUP.md)
for Android startup and login dispositions.
