# Cleaning Suite — Agent B Final Implementation Handoff

Date: 2026-09-07

## Scope

Agent B owns the bounded Cleaning Suite implementation for:

- Initial Cleaning Materials
- Special Services
- Open-Time Worker Requests
- Laravel backend integration
- Filament configuration/operational resources
- Flutter User main/dev continuation branches
- Flutter Worker main/dev continuation branches
- feature-specific regression coverage and Agent A handoff

This work intentionally preserves branch-specific Agent A / recurring / multi-day work. No blind Flutter main-to-dev merge was used.

---

## Branches and Verified Implementation Heads

### Backend

Repository: `dllni-app/dllni_backend`

Branch: `feature/cleaning-suite-agent-b-continue`

Last source implementation/test commit before this handoff update:

`9062cb2613da9626f184507f31af56c3b305c0a0`

### User App — Main Continuation

Repository: `dllni-app/dllni-user-app`

Branch: `feature/cleaning-suite-agent-b-main-continue`

Verified head:

`d5432ad5e9e6479f8e2f60e649759a0706f2ad11`

### User App — Dev Continuation

Repository: `dllni-app/dllni-user-app`

Branch: `feature/cleaning-suite-agent-b-dev-continue`

Verified head:

`44a1e297430a4dd67a3afab13f7e0501161d83ab`

### Worker App — Main Continuation

Repository: `dllni-app/dllni_cleaning_owner_app`

Branch: `feature/cleaning-suite-agent-b-main-continue`

Verified head:

`eae6f4d41e6bfd5e4984597e9cc06e610af064f8`

### Worker App — Dev Continuation

Repository: `dllni-app/dllni_cleaning_owner_app`

Branch: `feature/cleaning-suite-agent-b-dev-continue`

Verified head:

`defa5c95dd2273fbf53afe4dc5107ae345e509df`

---

# Implemented Features

## 1. Cleaning Materials

Implemented behavior:

1. Server derives required quantities from configured material quantity rules.
2. Booking creation reserves material stock.
3. Reservation is idempotent and cannot decrement the same booking-material line twice.
4. Insufficient stock fails before a reservation movement can be committed.
5. When work enters `in_progress`, reserved lines become consumed exactly once.
6. Cancelling before consumption releases the reservation and restores reserved stock.
7. Cancelling after consumption does not restore already consumed stock.
8. Inventory movement history remains the audit source for reserve / release / consume transitions.
9. Low-stock notification logic triggers only when inventory crosses from above the configured threshold to at-or-below it.
10. Additional reservations while inventory is already below the threshold do not create another threshold-crossing notification decision.

Low-stock threshold evaluation is isolated in:

`Modules/Cleaning/app/Services/CleaningMaterialLowStockPolicy.php`

The existing post-commit dashboard notification delivery architecture remains unchanged.

---

## 2. Special Services

Implemented behavior:

- Dedicated Agent B Special Service catalog.
- Active/inactive service support.
- Configurable pricing unit.
- Configurable base unit price.
- Per-service server-configured dirtiness rules.
- Dirtiness multiplier pricing.
- Required equipment relations.
- Optional customer notes.
- Immutable booking financial/operational snapshots.
- Duplicate request rejection.
- Inactive service rejection.
- Uploaded image support through Filament.

### Special Service Images

Filament supports actual image upload:

- disk: `public`
- directory: `cleaning-special-services`
- field: `cleaning_special_services.image_path`

Migration:

`Modules/Cleaning/database/migrations/2026_09_06_190000_add_image_path_to_cleaning_special_services_table.php`

Backward compatibility is retained through the legacy external `image_url` field.

Resolution rule:

1. uploaded `image_path`
2. legacy external `image_url`
3. `null`

The resolved image is now used consistently in:

- Special Service catalog responses
- Special Service estimate/quote lines
- persisted User cleaning order details/list responses

This closes the earlier mismatch where uploaded Filament images could appear in the catalog but quote/order payloads still read the legacy URL directly.

### Immutable Booking Snapshot

Once a Special Service is booked, changes made later by an administrator to:

- service name
- catalog base unit price
- dirtiness multiplier
- required equipment names

must not mutate the historical booking snapshot used for that order.

---

## 3. Open-Time Worker Requests

Open-Time billing remains server authoritative.

Authoritative inputs:

- server `work_started_at`
- server finish timestamp
- configured hourly rate
- requested worker count
- configured minimum billable minutes
- configured rounding interval

### Preliminary Pricing

The preliminary amount is calculated from the configured server policy and requested worker count.

It is an estimate only. Flutter does not convert this into a final guaranteed bill.

### Final Pricing

Final Open-Time pricing is prepared before terminal settlement observers can debit commissions/deposits.

Correct sequence:

```text
work finishes
→ authoritative duration resolved
→ minimum/rounding applied
→ final Open-Time labor amount calculated
→ canonical worker assignment snapshots repriced
→ travel/admin margin recomputed
→ canonical coupon allocator reapplied when applicable
→ final booking pricing persisted
→ terminal settlement/commission logic sees final values
```

This prevents the previous risk of a `Completed` observer settling from a preliminary Open-Time amount and repricing afterward.

### Multi-Worker

Open-Time does not own a second worker payout formula.

The implementation:

1. keeps the canonical team-pricing assignment proportions,
2. scales those shares to the final Open-Time service subtotal,
3. obtains per-worker travel/admin values from `CleaningPricingCalculator`,
4. writes final assignment snapshots before settlement.

Required regression case is covered for:

```text
hourly rate = 100
workers = 2
actual duration = 90 minutes
labor base = 300
```

### Customer Completion

Open-Time finalization occurs when the worker completion path establishes the authoritative finish time. A later customer confirmation must not recalculate or mutate the finalized Open-Time values.

### Cancellation

Cancellation before an authoritative finish time does not synthesize a final Open-Time price.

Covered paths include:

- before work starts
- after work starts but before finish

Materials retain their own independent reservation/consumption cancellation rules.

---

# Financial Rules

## Coupons

Agent B reuses the canonical Cleaning coupon allocator.

Order of funding:

1. Administration margin first.
2. Only a remaining coupon amount after admin reaches zero can reduce service value.
3. Travel is never discounted.

Example:

```text
service = 960
admin = 240
total = 1200
10% coupon = 120

customer total = 1080
admin = 120
worker service share = 960
```

The same rule is reapplied after Open-Time actual-duration repricing.

Regression coverage includes both:

- coupon fully absorbed by admin margin
- coupon larger than final admin margin, where only the excess reduces service

## Worker Settlement

Worker amount is based on the canonical worker service share plus worker travel according to the existing financial architecture. Admin margin is not deducted a second time from worker service share.

## Admin Settlement

Terminal commission/deposit handling receives the finalized Open-Time assignment/admin snapshots because Open-Time final repricing runs before terminal settlement observers.

---

# Flutter User App

Implemented on both main-derived and dev-derived continuation branches:

- Request Cleaning Materials toggle.
- Backend calculated material estimate.
- Special Service selection.
- Server-driven dirtiness choices.
- Quantity and optional notes.
- Open-Time toggle and worker count.
- Preliminary Open-Time estimate presentation.
- Read-only Agent B booking details.
- Canonical Open-Time final payload parsing.
- Loading/error/empty/retry behavior retained.
- No client-side final Open-Time billing formula.

## Special Service Catalog Preview

The remaining visual/configuration gap is now implemented.

When a Special Service is selected, User App now presents the available backend catalog metadata:

- image, when available
- pricing unit
- backend-provided base unit price
- required equipment

The preview is informational. Actual booking total continues to come from the backend estimate.

New localization keys were added in Arabic and English for the preview metadata.

## Dirtiness Rules

The app prefers each selected service's active server-defined dirtiness rules.

Legacy fallback:

```text
light / medium / heavy
```

is used only if the server returns no configured rules.

When the selected service changes, invalid dirtiness values are normalized to a valid server-provided level before the next estimate/create request.

## Dev Port

The remaining preview and test changes were selectively reproduced on the dev continuation branch.

Dev-specific existing translations and recurring behavior were preserved.

No main-to-dev merge was performed.

---

# Flutter Worker App

Implemented on both continuation branches:

- Material name / quantity / unit.
- Special Service name / quantity / dirtiness.
- Required equipment.
- Customer notes.
- Open-Time operational state.
- Timer presentation anchored to the backend start timestamp.
- Finalized Open-Time state from refreshed backend payload.
- Actual duration.
- Billable duration.
- Final amount/state representation already supported by the operational model.

The existing Finish Work workflow remains authoritative and already refreshes order details/list data after successful completion.

Regression coverage was extended at the stable order-details mapper boundary to prove that a completion refresh:

- preserves Materials when the refreshed detail payload omits them,
- preserves Special Services when omitted,
- replaces running Open-Time metadata with the finalized server state.

The repository's old Bloc realtime hydration test file remains intentionally disabled, and no new mocking framework was introduced only for Agent B.

---

# Filament Dashboard

Native Agent B Filament configuration/resources are present for:

- Cleaning Materials
- Material Units
- Material Types
- Material quantity rules
- Inventory movements
- Special Services
- Dirtiness rules
- Equipment
- Billing policies / pricing configuration

Special Service image upload now uses native Filament file upload rather than only a URL text field.

Agent B resource access continues to use the existing Cleaning pricing/admin permission architecture rather than broadening dashboard access.

## Runtime QA Still Required

The following require a running Filament browser session and cannot be signed off from GitHub source inspection alone:

- login/navigation visibility
- permission matrix under real roles
- create/edit/delete flows
- validation feedback
- actual image upload/preview over deployed storage
- Arabic/RTL visual rendering
- responsive behavior
- browser console/network errors
- keyboard/accessibility behavior

---

# Cross-Repository Contract Audit

A static Backend ↔ User ↔ Worker contract audit was completed after the final source changes.

## Materials

User response contains customer-facing material information and pricing totals where appropriate.

Worker operational response intentionally exposes operational data only:

```json
{
  "materialId": 1,
  "name": "...",
  "quantity": 1.5,
  "unit": "...",
  "unitLabel": "...",
  "inventoryStatus": "..."
}
```

## Special Services

Canonical semantic information is compatible across the API clients:

```json
{
  "serviceId": 1,
  "name": "...",
  "image": "...",
  "pricingUnit": "...",
  "quantity": 2,
  "dirtinessLevel": "...",
  "equipment": [],
  "notes": "..."
}
```

Estimate lines may use `imageUrl`; User parsing supports the existing aliases.

Worker payload intentionally does not expose unnecessary customer/admin pricing fields.

## Open-Time

Canonical server semantics:

```json
{
  "isOpenTime": true,
  "hourlyRate": 0,
  "requestedWorkerCount": 2,
  "minimumBillableMinutes": 60,
  "roundingMinutes": 30,
  "workStartedAt": "...",
  "workFinishedAt": "...",
  "actualDurationMinutes": 90,
  "billableDurationMinutes": 90,
  "finalAmount": 0,
  "isFinalized": true
}
```

User and Worker parsers consume the canonical semantic fields and supported aliases without calculating final billing locally.

---

# Regression Coverage Added / Audited

## Backend — Materials

Coverage now includes:

1. quantity-rule specificity
2. configured unit
3. reservation behavior
4. insufficient stock atomic failure
5. Start Work consumes reservation
6. duplicate update does not consume twice
7. pre-work cancellation releases reservation
8. post-consumption cancellation does not restore consumed inventory
9. low-stock threshold crossing policy
10. no repeated threshold decision while already low

## Backend — Special Services

Coverage now includes:

1. active services returned
2. inactive filtering/rejection
3. dirtiness multiplier
4. invalid dirtiness rejection
5. equipment snapshot
6. duplicate request rejection
7. booking snapshot immutability after later catalog edits
8. uploaded image precedence
9. uploaded image resolution in quote payload
10. uploaded image resolution in persisted user order payload

## Backend — Open-Time

Coverage now includes:

1. preliminary estimate
2. requested worker count
3. authoritative start timestamp
4. authoritative finish timestamp
5. minimum duration
6. rounding
7. actual duration
8. final amount
9. idempotent finalization
10. multi-worker repricing
11. direct terminal/legacy completion ordering
12. normal awaiting-customer completion path
13. customer confirmation preserves finalized pricing
14. cancellation before finish
15. final worker assignment shares
16. final admin margin
17. commission/deposit settlement ordering
18. coupon absorbed by sufficient admin margin
19. coupon exceeding admin margin
20. final User API representation

## User Flutter

Coverage now includes:

- Agent B request serialization
- optional notes/open-time omission
- Material response parsing
- Special Service catalog parsing
- dynamic dirtiness rules
- equipment/base-price/pricing-unit parsing
- canonical and persisted alias parsing
- preliminary Open-Time payload
- finalized Open-Time payload
- minute-to-hour presentation conversion

## Worker Flutter

Coverage includes:

- Material parsing
- Special Service/equipment parsing
- Open-Time parsing
- server-anchored running timer presentation
- finalized state presentation
- order-detail fallback preservation
- completion refresh preserving Agent B operational data while accepting final Open-Time state

---

# Quality Gates / CI Evidence

No local executable shell was available in this GitHub-only implementation session.

Therefore the following commands have **not** been claimed as passed:

## Backend

```bash
composer install
php artisan optimize:clear
php artisan migrate:fresh --seed
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## User App — both continuation branches

```bash
flutter pub get
dart format --set-exit-if-changed .
flutter analyze
flutter test
```

## Worker App — both continuation branches

```bash
flutter pub get
dart format --set-exit-if-changed .
flutter analyze
flutter test
```

GitHub commit statuses/workflow runs must be checked against the final heads separately. Absence of a status/workflow is not a passing result.

---

# Deployment / Runtime Requirements

Before release:

1. Run the new Special Service image-path migration.
2. Ensure Laravel public storage serving / `storage:link` is configured.
3. Execute backend quality gates.
4. Execute User main/dev Flutter gates independently.
5. Execute Worker main/dev Flutter gates independently.
6. Perform Filament runtime QA with real admin roles and upload flow.
7. Fix any Agent B-specific failures found by those checks on the same continuation branches.

---

# Integration Notes for Agent A

- Do not replace User dev with User main; the dev lineage contains newer branch-specific work.
- Do not replace Worker dev with Worker main.
- Agent B dev ports were selective.
- Do not introduce another Open-Time pricing or completion endpoint.
- Consume the backend Open-Time finalization ordering as the shared financial behavior.
- Preserve the canonical admin-first coupon allocator.
- Preserve the `image_path` migration and resolved Special Service image contract.
- Preserve the low-stock threshold policy and existing after-commit notification delivery architecture.
- Re-audit branch heads immediately before cherry-pick/merge integration.

---

# Final Source-Implementation Assessment

No known Agent B **source implementation** blocker remains after the final static business, Laravel, Flutter, UI/UX, and cross-repository contract audit.

However, by the plan's Definition of Done, Agent B must **not** be labeled fully production-complete until:

- backend tests/static/format checks execute successfully,
- User main/dev Flutter analyzer/tests/format execute successfully,
- Worker main/dev Flutter analyzer/tests/format execute successfully,
- Filament runtime QA passes,
- any failures discovered by those executable checks are fixed.

Current classification:

- Backend source implementation: complete; executable verification pending
- User main source implementation: complete; executable verification pending
- User dev source implementation: complete; executable verification pending
- Worker main source implementation: complete; executable verification pending
- Worker dev source implementation: complete; executable verification pending
- Filament source implementation: complete; runtime QA pending
- Agent B overall: verification pending
