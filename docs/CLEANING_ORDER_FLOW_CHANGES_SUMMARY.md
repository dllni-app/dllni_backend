# Cleaning Order Flow Changes Summary

Date: 2026-04-08
Scope: User module cleaning flow only (`/api/v1/user/cleaning/orders/*`)

## What Changed

### 1. Backend estimation stays source of truth
- Kept existing area/time/price formulas intact.
- Added a unified normalization path used by all cleaning estimation and pricing calls:
  - `normalizePropertyType()`
  - `normalizePropertyDetails()`
  - `pricingSnapshotInput()`
  - `normalizePropertyDetailsForStorage()`
- Added explicit algorithm version constant:
  - `ALGORITHM_VERSION = "2026-04-08-v1"`

Files:
- `Modules/User/app/Services/UserCleaningOrderEstimationService.php`

### 2. Price estimate endpoint now returns quote metadata
- `POST /api/v1/user/cleaning/orders/estimate-price` still returns:
  - `size`
  - `pricing`
- Added additive response object:
  - `quote.quoteId`
  - `quote.expiresAt`
  - `quote.algorithmVersion`

Files:
- `Modules/User/app/Http/Controllers/API/UserCleaningOrderEstimatePriceController.php`

### 3. Quote binding added for order integrity
- Introduced cache-backed quote service:
  - issues quote from normalized input + computed estimation/pricing snapshot
  - validates quote ownership, expiry, algorithm version, and payload signature
- Quote TTL: 15 minutes.
- Quote required date gate:
  - grace period: 2026-04-08 to 2026-04-22
  - enforced from: 2026-04-23 00:00:00 (app timezone)

Files:
- `Modules/User/app/Services/UserCleaningOrderQuoteService.php`

### 4. Create/update now validate quote and recompute server-side
- `UserCleaningOrderService::store()`:
  - normalizes inputs
  - recomputes estimation/pricing server-side
  - validates quote (optional during grace period, required after enforcement)
  - persists normalized values and server-calculated totals
- `UserCleaningOrderService::update()`:
  - fixed `propertyType` update persistence
  - detects price-affecting changes (`propertyType`, `propertyDetails`, location, preferred worker)
  - recalculates pricing server-side
  - requires/validates quote based on enforcement rules
  - schedule-only updates do not require quote

Files:
- `Modules/User/app/Services/UserCleaningOrderService.php`

### 5. Validation rules aligned and tightened
- Restricted `propertyType` to known values (`apartment`, `villa`, `house`, `office`).
- Restricted `living_room_size` to known values (`small`, `medium`, `large`, `very_large`).
- Added `quoteId` to create/update requests.
- Added stronger `propertyDetails` array shape checks.
- Marked calculated fields as prohibited in create/update payloads (`estimatedSqm`, `estimatedHours`, `totalHours`, `basePrice`, `travelFee`, `addonsTotal`, `totalPrice`).

Files:
- `Modules/User/app/Http/Requests/UserCleaningOrderEstimateSizeRequest.php`
- `Modules/User/app/Http/Requests/UserCleaningOrderEstimatePriceRequest.php`
- `Modules/User/app/Http/Requests/UserCleaningOrderStoreRequest.php`
- `Modules/User/app/Http/Requests/UserCleaningOrderUpdateRequest.php`

## API Behavior Summary

### Estimate size
`POST /api/v1/user/cleaning/orders/estimate-size`
- Returns backend-derived area and time estimate.
- Deterministic response shape unchanged:
  - `size.estimatedSqm`
  - `size.sizeTier`
  - `estimation.estimatedHours`
  - `estimation.estimatedMinutes`

### Estimate price
`POST /api/v1/user/cleaning/orders/estimate-price`
- Returns backend-derived pricing breakdown plus quote metadata.

### Create order
`POST /api/v1/user/cleaning/orders`
- During grace period: missing `quoteId` accepted.
- After 2026-04-22: `quoteId` required.
- Invalid/mismatched/expired/cross-user quote returns 422.

### Update order
`PATCH /api/v1/user/cleaning/orders/{order}`
- Schedule-only changes: no quote required.
- Price-affecting changes:
  - grace period: quote optional
  - after 2026-04-22: quote required
- Invalid quote returns 422.

## Tests Added/Updated

### Feature tests
- Extended `tests/Feature/UserModule/UserCleaningOrdersFlowTest.php` with coverage for:
  - deterministic estimate outputs
  - quote payload in estimate-price response
  - create with valid quote
  - create without quote (grace period allowed)
  - create without quote after enforcement (rejected)
  - expired quote rejection
  - mismatched quote rejection
  - cross-user quote rejection
  - schedule-only update without quote after enforcement
  - price-affecting update without quote after enforcement (rejected)
  - propertyType update with valid quote and recalculated totals
  - rejection of client-supplied calculated fields in create/update

### Unit tests
- Added `tests/Unit/UserModule/UserCleaningOrderEstimationServiceTest.php`:
  - deterministic estimation assertions
  - deterministic pricing breakdown assertions

## Postman Updates
- Updated cleaning section examples and descriptions in:
  - `postman/Dllni-User-Module.postman_collection.json`
- Synced estimate-size and estimate-price examples with current backend contract.
- Documented quote migration/enforcement behavior in create/update descriptions.

## Notes
- No DB migration added for quotes (ephemeral cache only).
- Existing core pricing formula constants were not changed.
