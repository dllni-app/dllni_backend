# Release 2 / Phase 4 — Special-service customer journeys

Date: 2026-10-10
Scope: incremental implementation of source-roadmap Phase 4, not full acceptance.

## Source fidelity
The locally available Release 2 roadmap lists SS-01, SS-02, SS-04–SS-12, SS-23–SS-27 and AC-16–AC-20 under special-service standalone and add-on booking, customer approval, item details and audit. The original Release 2 SRS was not available in this checked workspace, so none of these requirement IDs is declared wholly accepted.

## Changes implemented
- Customer PATCH request for cleaning booking now accepts the same itemized special-service contract as creation/estimation (multiple item quantities, dirtiness-level IDs, notes and before/after attachment paths). Legacy single-quantity payload remains valid.
- Requoting due to unrelated customer-editable pricing inputs reconstructs and preserves every existing special-service item. Previously, it reconstructed just the top-level aggregate quantity and dirtiness and discarded per-item detail and photos.
- Replacing special-service lines deletes their item records transactionally and inserts the canonical quote snapshots. Existing assigned equipment and session-linked services are blocked from replacement instead of being silently destroyed.
- Server-side validation prohibits an empty list on a standalone `special_service` booking.
- An accepted worker assignment (parent or session) prevents direct mutation of special services, materials and cleaning service pricing. A status recheck is performed under booking row lock to avoid stale-model terminal changes.
- Customer-initiated special-service changes are audited in `cleaning_operational_action_audits` with before/after quote snapshots and actor (the booking's customer).
- User booking API adds `beforeImageUrls`/`afterImageUrls` to each special-service item while retaining existing raw `beforeImages`/`afterImages` for compatibility. Relative paths are resolved on the public disk and traversal patterns are excluded.
- Flutter customer booking details now show each service item's quantity, dirtiness, note, item amount and before/after image counts, with thumbnails when public HTTP(S) image URLs are present. RTL widget test covers the itemized view.

## Verified
- Targeted backend `CleaningSpecialServiceCustomerEditPhase4Test`, `CleaningV2PublicContractTest`, `UserCleaningOrderEditabilityTest`: 10 passing tests, 56 assertions on the local test database. A focused post-hardening Phase 4 retest passed 3 tests, 29 assertions (overlapping coverage).
- Flutter customer model/widget tests: **10 passed**, including the new item details and before/after photo counts, with no physical device or emulator. Existing test-only localization-key warnings were printed. Dart analyzer did not return a reliable terminal result during this session; do not mark static analysis as passed.
- No device/emulator tests were run.

## Not yet complete
- Worker/admin-initiated *post-assignment* change proposal with customer accept/reject and financial allocation settlement is **not implemented**; direct mutations are explicitly blocked rather than bypassing consent.
- Re-quoting session-linked multi-day/event special-service lines requires the dedicated schedule-change approval/reconciliation lifecycle.
- Signed upload flow, storage permissions, evidence moderation, customer-facing proof gallery deep-linking, photo upload retries and removal of orphan media are not acceptance-verified.
- Full edge-case coverage: worker assignment/payment splits, equipment overlap concurrency on MySQL, realtime notifications and all 116 original controls.
- Full release remains blocked until original SRS can be mapped and all P0/P1 gates verified.

## Rollout
Backend `dev` and customer Flutter `dev` should be deployed together after CI review. Phase 3 migration is a prerequisite for the audit table. Do not run destructive migrations or test against a live app.
