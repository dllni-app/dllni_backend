# User Module Cleaning and Shared API Gap Report and Update Plan

Date: 2026-04-06
Scope: User module cleaning section plus shared pages across restaurant, supermarket, and cleaning.
Reference: postman/Dllni-User-Module.postman_collection.json

## Summary
This report audits cleaning user flows and shared pages against current code and the provided Postman collection. It focuses on missing data, fake or placeholder behavior, request or response prop gaps, and security boundaries.

## Implementation Progress (Current Pass)

- Added user-namespace notification read endpoint: `PATCH /api/v1/user/notifications/{id}/read`.
- Added wrapper controller to reuse existing notification read logic under user routes.
- Added favorites index pagination parity validation (`page` in request rules).
- Strengthened restaurant product endpoints to return only products from active restaurants.
- Ensured restaurant product endpoints eager-load media for stable image fields in resources.
- Aligned restaurant orders list and detail to eager-load `orderStatusLogs` for timeline parity.
- Added feature coverage for user supermarket search endpoint:
  - pagination response shape,
  - search query filtering,
  - exclusion of unavailable products and inactive stores.
- Synced Postman collection with newly exposed/validated user endpoints:
  - `PATCH /api/v1/user/notifications/{notificationId}/read`,
  - `GET /api/v1/user/supermarket/products/search`.

## Coverage Snapshot

### Cleaning section currently available
- User cleaning orders: create, list, show, update, cancel.
- Worker booking lifecycle: accept, reject, start travel, location update, arrive, start work, complete, cancel, security code.
- Worker homepage and account APIs: profile, status, work areas, working hours, password update, transactions.
- Time warning flow: list and show, accept extension, reject extension.

### Shared pages currently available
- Profile and account: me, account show and update, password change.
- Addresses: list, create, show, patch, update, set default, delete.
- Notifications: list with unread filter.

## Findings

### Critical

1. Cleaning pricing is client-trusted and can be tampered
- User cleaning create and update accept price fields from request (`basePrice`, `travelFee`, `addonsTotal`, `totalPrice`).
- Service layer persists these values directly or re-computes totals from client-provided parts.
- This allows fake pricing and total manipulation from the client.

2. Cleaning module routes are over-exposed for any authenticated user
- `/api/v1/cleaning-bookings`, `/api/v1/cleaning-services`, `/api/v1/cleaning-billing-policies`, and nested pricing routes are all under `auth:sanctum` without role middleware.
- Controllers do not enforce admin-only access for create or update or delete paths on service and policy resources.
- Risk: non-worker user tokens can access or mutate operational cleaning data.

3. Cleaning booking data leakage risk on generic endpoints
- Generic cleaning bookings endpoints can list booking data with customer details.
- `filter[forCurrentWorker]` exists but is optional.
- Without mandatory worker scoping, authenticated users can query broader booking data than intended.

### High

4. User cleaning order payload is missing server-owned quoting contract
- There is no dedicated quote endpoint or signed quote token.
- Pricing and fee calculation happens effectively on the client payload path.
- Figma checkout totals cannot be trusted end to end without server quote ownership.

5. Missing user-facing selection APIs for cleaning booking flow
- User booking accepts `preferredWorkerId`, but there is no user-scoped endpoint to fetch worker candidates or availability slots for booking UI.
- Cleaning services and pricing resources exist in the cleaning module but are not documented in user flow Postman and are not safely role-scoped for mobile user consumption.

6. Cleaning order response envelope is inconsistent
- User cleaning list and show return resource collection or resource (`data`).
- User cleaning create and update and cancel return `{ "order": ... }`.
- This creates avoidable frontend branch logic.

7. Shared notification lifecycle is incomplete in user namespace
- User routes expose `GET /api/v1/user/notifications` only.
- Mark-as-read exists in shared controller and in non-user route (`/api/v1/notifications/{id}/read`) and worker account route, but not in `/api/v1/user/...` namespace.

8. Cleaning timeline response is weaker than lifecycle events
- Status transitions are logged via observer, but cleaning booking resource does not expose status logs.
- Tracking screens get timestamps (`startedTravelAt`, `arrivedAt`, etc.) but not a full timeline list.

### Medium

9. Request prop naming is mixed in cleaning payloads
- API is mostly camelCase, but cleaning request expects `propertyDetails.location_name` in snake_case.
- This is easy to miss and causes frontend mapping friction.

10. Policy input is not fully constrained for user booking create
- `cancellationPolicyId` validation checks existence only, not module-specific ownership (`module=cleaning`) or active/default validity.

11. Postman contract drift in shared and cleaning account payloads
- User account password appears in two formats across collection sections:
  - `currentPassword + newPassword + newPasswordConfirmation`
  - `currentPassword + password + password_confirmation`
- Backend request validates the first style only.
- Worker work areas sample includes polygon-shaped zones in Postman while backend validates `name` and optional `isActive` only.

12. OTP provider is still a no-op placeholder
- User module binds `SmsOtpProvider` to `NullSmsOtpProvider`.
- OTP send is intentionally no-op outside local cache behavior, so production-grade delivery is still pending.

## Request Props Requiring Update

1. User cleaning order create (`POST /api/v1/user/cleaning/orders`)
- Remove client-owned pricing fields from public contract (`basePrice`, `travelFee`, `addonsTotal`, `totalPrice`).
- Add a server quote reference (example: `quoteId` or signed checksum) and validate it server-side.
- Add normalized service selection props (`services[]`, `addons[]`) if required by Figma booking summary.
- Normalize nested key naming to camelCase (`propertyDetails.locationName` instead of `location_name`).

2. User cleaning order update (`PATCH /api/v1/user/cleaning/orders/{order}`)
- Restrict updates to schedule and editable details only.
- Remove direct price mutation props from user updates.

3. Shared notifications
- Add `/api/v1/user/notifications/{id}/read` in user namespace for consistency with user app routing.

4. Shared account password migration
- During migration window, optionally accept legacy `password/password_confirmation` keys in addition to canonical `newPassword/newPasswordConfirmation`, then deprecate legacy keys.

## Response Props Requiring Update

1. Cleaning order details and tracking
- Add timeline payload (status log entries) for booking detail and optionally list view.
- Include richer worker summary for tracking card (minimum: `id`, `name`, `phone`, avatar URL) when assigned.
- Return server-calculated pricing breakdown fields from quote source, not client pass-through values.

2. Shared envelope normalization
- Standardize addresses and cleaning order responses across index/show/write actions to one envelope style.

3. Shared notifications
- Keep pagination response shape stable and include read-state mutation path under same namespace.

## Planned Changes

### Phase 1: Security and authorization lockdown
- Split cleaning routes by persona:
  - worker-safe read and action routes.
  - admin-only config routes (services, pricing, billing policies).
- Enforce mandatory ownership or worker scoping on cleaning-bookings index and show.
- Add authorization tests for user token attempting restricted cleaning config mutations.

### Phase 2: Pricing integrity and anti-tampering
- Introduce a quote calculation endpoint owned by server business rules.
- Persist booking totals from server-calculated quote only.
- Reject direct client total overrides in create and update.
- Add tests for tampered total rejection.

### Phase 3: Cleaning contract completion for Figma flow
- Add or expose safe user-facing endpoints for service catalog, addon choices, and worker availability where needed.
- Normalize request key naming to camelCase.
- Add status timeline payload in cleaning booking resource.

### Phase 4: Shared pages normalization
- Add user-namespace notification mark-as-read route.
- Normalize response envelopes for addresses and cleaning orders.
- Align password payload compatibility and deprecation messaging.

### Phase 5: Postman and tests sync
- Update Postman examples for cleaning and shared pages to match final request and response contracts.
- Add coverage for:
  - cleaning route authorization boundaries,
  - pricing tamper prevention,
  - notification mark-as-read under `/api/v1/user` namespace,
  - envelope consistency assertions.

## Execution Order Recommendation
1. Phase 1
2. Phase 2
3. Phase 3
4. Phase 4
5. Phase 5

## Notes
- Keep backward compatibility where current mobile clients already consume existing keys.
- If contract-breaking changes are unavoidable, introduce versioned API behavior and a short migration window.
- Prioritize Phase 1 and Phase 2 before UI-parity enhancements to reduce security and billing risk.
