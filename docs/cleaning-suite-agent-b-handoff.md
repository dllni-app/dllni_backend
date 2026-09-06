# Cleaning Suite — Agent B Continuation Handoff

Date: 2026-09-06

## Scope

This handoff covers the Agent B continuation work for:

- Laravel backend + Filament dashboard
- Flutter User app main-line continuation
- Flutter User app dev continuation
- Flutter Worker app main-line continuation
- Flutter Worker app dev continuation

The implementation intentionally preserves newer branch-specific work. No blind main-to-dev merge was used for the Flutter dev ports.

## Branch Coverage

### Backend

Repository: `dllni-app/dllni_backend`

Branch: `feature/cleaning-suite-agent-b-continue`

Last implementation commit before this handoff document:

`864464783766f36399d692993b378b00905bd988`

### User App — Main Continuation

Repository: `dllni-app/dllni-user-app`

Branch: `feature/cleaning-suite-agent-b-main-continue`

Verified head during handoff preparation:

`896fdb32278c30bbc23fe31717f15d37d45d732a`

### User App — Dev Continuation

Repository: `dllni-app/dllni-user-app`

Branch: `feature/cleaning-suite-agent-b-dev-continue`

Verified head during handoff preparation:

`1a9104a5e02d47f38121666bd4d6e46189a98fea`

### Worker App — Main Continuation

Repository: `dllni-app/dllni_cleaning_owner_app`

Branch: `feature/cleaning-suite-agent-b-main-continue`

Verified head during handoff preparation:

`21d15a5aaef9eb2d5b67013d211553317ddb9f81`

### Worker App — Dev Continuation

Repository: `dllni-app/dllni_cleaning_owner_app`

Branch: `feature/cleaning-suite-agent-b-dev-continue`

Verified head during handoff preparation:

`093f9690a82e5f11c677432b1cec8cb4d5763186`

## Implemented Business Behavior

### Materials

- Material quantities are derived from configured quantity rules.
- Reservation reduces available stock exactly once.
- Moving a booking to `in_progress` consumes reserved lines exactly once.
- Cancelling before consumption releases reserved stock.
- Cancelling after consumption does **not** restore already consumed stock.
- Inventory movement history remains the audit source for reserve / consume / release transitions.

### Special Services

- Active services are exposed through the cleaning service catalog.
- Pricing is based on configured pricing unit, base unit price, quantity, and dirtiness multiplier.
- Dirtiness rules are server-driven.
- Required equipment and notes are included in operational snapshots.
- Duplicate or inactive Special Service requests are rejected by backend quoting logic.
- Flutter User main/dev no longer assume only `light / medium / heavy`.
- Legacy `light / medium / heavy` exists only as a fallback when the backend returns no configured rules.
- Invalid dirtiness values are normalized before estimate/create requests are sent.

### Special Service Images

Filament now supports actual file upload instead of relying only on a manually entered URL.

New behavior:

- Uploaded files use the `public` disk.
- Directory: `cleaning-special-services`.
- New nullable DB field: `cleaning_special_services.image_path`.
- Existing `image_url` remains supported as a backward-compatible external URL fallback.
- Uploaded image takes precedence over legacy external URL.
- The API continues exposing a single `image` field containing the resolved absolute URL.

Migration:

`Modules/Cleaning/database/migrations/2026_09_06_190000_add_image_path_to_cleaning_special_services_table.php`

### Open-Time Pricing

Open-Time billing is finalized from authoritative server timestamps:

- `work_started_at`
- final/finish timestamp
- configured hourly rate
- requested worker count
- minimum billable duration
- rounding interval

Final pricing occurs before terminal settlement observers can debit worker/admin balances.

This fixes the previous ordering risk where a `Completed` observer could settle using provisional Open-Time pricing.

### Multi-Worker Open-Time Settlement

Open-Time does not introduce a second worker allocation formula.

The implementation:

1. Uses existing worker assignment service-share proportions produced by canonical team pricing.
2. Scales those canonical shares to the final Open-Time service subtotal.
3. Uses `CleaningPricingCalculator` for per-worker travel and admin margin.
4. Updates assignment snapshots before terminal settlement.

### Coupons

The canonical cleaning coupon rule is reused after final Open-Time repricing:

1. Calculate the customer discount from the configured coupon.
2. Fund the discount from administration margin first.
3. Only after administration reaches zero may the remainder reduce service share.
4. Travel remains a separate undiscounted component.
5. Worker service share therefore remains unchanged while the coupon fits entirely inside administration margin.

No client-side Flutter billing formula was added.

## Flutter User App

Implemented on both continuation branches:

- Materials request UI and estimate presentation.
- Special Service selection and estimate presentation.
- Open-Time request and estimate presentation.
- Server-authoritative pricing data.
- Dynamic Special Service catalog fields:
  - image
  - pricing unit
  - base unit price
  - dirtiness rules
  - required equipment
- Active dirtiness-rule filtering.
- Dirtiness normalization when changing Special Service.
- Safe default dirtiness when adding a Special Service.
- Dev changes were selectively ported without overwriting newer recurring-booking/dev work.

## Flutter Worker App

Implemented on both continuation branches:

- Operational Materials details.
- Operational Special Service details.
- Required equipment and notes presentation.
- Open-Time operational state.
- Open-Time timer/presentation is based on backend timestamps rather than client billing calculations.
- Main implementation was selectively ported to dev without a main-to-dev merge.

## Filament Dashboard

Agent B resources are present for the operational configuration surface, including:

- Cleaning Materials
- Material inventory/movements
- Special Services
- Dirtiness rules
- Equipment
- Billing policies
- Pricing configuration

Static source review confirmed permissions are routed through existing cleaning pricing permissions for Agent B pricing/configuration resources.

The Special Service resource now includes image upload and legacy URL fallback.

### Runtime QA Still Required

The following cannot be signed off from GitHub source inspection alone and must be tested in a running dashboard session:

- login and navigation visibility
- role/permission access
- create/edit/delete flows
- validation failures
- image upload + image preview
- public storage URL availability
- Arabic/RTL rendering
- responsive behavior
- browser console errors
- network request failures
- keyboard/accessibility behavior

## Tests Added / Extended

### Backend

Existing Agent B tests include:

- material quantity-rule specificity
- Special Service dirtiness/equipment quote snapshots
- duplicate/inactive Special Service rejection
- reserved material consumption exactly once
- cancellation releasing reserved material
- Open-Time finalization from authoritative timestamps
- multi-worker Open-Time repricing before completed commission settlement

Added during continuation:

`tests/Feature/Cleaning/CleaningAgentBRegressionMatrixTest.php`

Covers:

- consumed material is not restored by a later cancellation
- Open-Time actual-duration pricing occurs before coupon allocation
- admin-first coupon funding after Open-Time finalization
- travel remains undiscounted
- worker service share remains gross while discount fits inside admin margin

`tests/Unit/Cleaning/CleaningSpecialServiceImageTest.php`

Covers:

- legacy external image URL compatibility
- uploaded public-disk image precedence and URL resolution

### User App

Added/ported tests cover:

- Special Service catalog parsing
- active/inactive dirtiness rules
- invalid dirtiness normalization
- fallback behavior when rules are absent
- repeated rule deduplication
- equipment/base-price/pricing-unit parsing

### Worker App

Ported Agent B tests cover:

- cleaning booking operational payload parsing
- order-details mapping
- Open-Time presentation behavior

## API Contract Notes

### Special Service Catalog

Backend response remains compatible with Flutter:

```json
{
  "id": 17,
  "name": "Sofa cleaning",
  "category": "special_service",
  "isActive": true,
  "image": "https://host/storage/cleaning-special-services/example.png",
  "pricingUnit": "sofa",
  "baseUnitPrice": 100,
  "dirtinessRules": [
    {
      "level": "heavy",
      "priceMultiplier": 1.5,
      "isActive": true
    }
  ],
  "equipment": [
    {
      "id": 4,
      "name": "Extractor"
    }
  ],
  "pricing": []
}
```

### User Special Service Request

```json
{
  "specialServiceId": 17,
  "quantity": 2,
  "dirtinessLevel": "heavy",
  "notes": "Optional notes"
}
```

Flutter does not submit a calculated Special Service price.

### Open-Time Request

```json
{
  "workerCount": 2
}
```

Final duration and final monetary amount remain server authoritative.

## Quality Gates — Not Yet Executed in This GitHub-Only Session

Do **not** interpret committed tests as a claim that they passed.

Backend commands still required in an executable Laravel environment:

```bash
composer install
php artisan optimize:clear
php artisan migrate:fresh --seed
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

Flutter commands still required for User main/dev and Worker main/dev:

```bash
flutter pub get
dart format --set-exit-if-changed .
flutter analyze
flutter test
```

The new Special Service image migration must also be applied in the target environment before testing dashboard upload.

## Merge / Port Notes

- Do not replace the User dev branch with User main.
- Do not replace the Worker dev branch with Worker main.
- Dev ports were selective because the dev branches contain newer or branch-specific work.
- Preserve recurring-booking work already present in User dev.
- Preserve any later feature commits that may have landed after the SHA snapshot in this document.
- Re-audit branch heads immediately before a final merge/cherry-pick sequence.

## Known Remaining Risks

1. Backend/Flutter tests were written or ported but not executed from this session.
2. Exact monetary assertions must be confirmed by the real Laravel test suite, especially rounding and observer interactions.
3. Filament runtime CRUD, permissions, upload, RTL, responsive, console, and accessibility QA remains open.
4. `storage:link` / public disk serving must be configured in the deployed Laravel environment for uploaded Special Service images.
5. The new `image_path` migration must run before the Filament upload field is used.

## Completion State

Implementation status at handoff-document creation:

- Backend business implementation: substantially complete
- Backend regression coverage: expanded, execution pending
- User main implementation: complete for Agent B scope, execution pending
- User dev implementation: complete for Agent B scope, execution pending
- Worker main implementation: complete for Agent B scope, execution pending
- Worker dev implementation: complete for Agent B scope, execution pending
- Filament source implementation: Agent B resources present; Special Service image upload completed
- Filament runtime QA: pending
- Full quality gates: pending

Agent B should only be marked fully complete after executable backend/Flutter quality gates and Filament runtime QA pass, with any failures fixed on the same continuation branches.
