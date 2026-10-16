# Cleaning time-period API (contractVersion 2)

Authenticated existing endpoints: `POST /api/v1/user/cleaning/orders/estimate-price` and `POST /api/v1/user/cleaning/orders`.

The discriminator is integer `contractVersion: 2`. Legacy requests without it keep their existing behavior, including the previous `roomPeriods` compatibility payload. A room is never a period in this contract.

Request header retains `propertyType`, `propertyDetails`, address fields, `genderPreference`, `assignmentMode`, `termsAccepted` (create), and existing safety confirmation. `propertyDetails.room_size_breakdown` must match the booking-wide rooms. Dates use the business timezone `config('cleaning_booking_v2.timezone')`, default Asia/Damascus; returned startAt/endAt are ISO8601 with offset.

```json
{"contractVersion":2,"propertyType":"apartment","propertyDetails":{"address":"عنوان العميل","room_size_breakdown":{"bedroom":{"medium":1},"bathroom":{"small":1}}},"addressLatitude":33.5138,"addressLongitude":36.2765,"rooms":[{"roomKey":"bedroom.1","roomType":"bedroom","size":"medium","cleaningMode":"deep"},{"roomKey":"bathroom.1","roomType":"bathroom","size":"small","cleaningMode":"regular"}],"visits":[{"visitKey":"visit.1","sequence":1,"roomSchedules":[{"scheduleKey":"schedule.1","roomKey":"bedroom.1","scheduledDate":"2026-10-20","scheduledTime":"09:00","numberOfWorkers":2},{"scheduleKey":"schedule.2","roomKey":"bathroom.1","scheduledDate":"2026-10-20","scheduledTime":"09:00","numberOfWorkers":1}],"timePeriods":[]}]}
```

Room types and prices come from the existing room catalog, not these illustrative IDs. Dates in examples must be replaced with future service dates.

Estimate may initially omit `timePeriods` or send `[]`. Server derives exact groups. Response `visits` contains the original visitKey/sequence, `roomSchedules` including estimatedWorkMinutes, estimatedDurationMinutes, startAt, endAt; and `timePeriods` containing periodKey, roomScheduleKeys, startAt, endAt, materials, specialServices. Default periodKey is deterministic `period.` plus the first 16 SHA256 characters of sorted schedule keys joined by `|`. Client may replace it with any stable period key when sending exact memberships.

Each input period has `periodKey`, `roomScheduleKeys`, `materials: {choice: customer_all|customer_partial|platform_all, materialTypeIds: []}`, and `specialServices: [{serviceKey,specialServiceId,targetRoomKeys,quantity,dirtinessLevel}]`. Each target is a room in that period. `light` and `heavy` are the only V2 dirtiness values; null for services without dirtiness. Quantity applies once to each explicit target room. Same service on distinct targets is retained independently. Catalog-defined unit and duration are authoritative. Active catalog has no room-eligibility field; V2 does not invent restrictions.

Response additionally contains `pricing: {totalPrice,currency,isPricingFinal:true}`, `size`, `requiredSimultaneousWorkers`, `materialsPreview`, `specialServicesCatalog`, `schedulingPolicy`, `pricingAlgorithm`, `quoteId`, `quoteSignature`, `quoteExpiresAt`. Only the aggregate customer total is exposed; server keeps accounting lines privately.

Create sends the identical draft plus `quoteId`, `quoteSignature`, UUID `idempotencyKey`, `termsAccepted:true`, existing address/safety fields. Header scheduledDate/time may be omitted for V2 (derived earliest start). Response 201 has `order`, `contractVersion:2`, `rooms`, `visits`, `pricing`; repeated identical idempotency request returns the same booking. Reusing a key with changed draft yields 422.

Exact same visit/start/end => one period with many schedules. Partial overlap => separate periods. Intervals are half-open; end events precede starts at a shared endpoint. Every room has exactly one allocation per visit; schedule and period keys are visit-scoped. Every explicit period must equal a complete canonical group. Stale memberships are rejected with Arabic validation, so a UI split/merge decision cannot be bypassed.

Existing rules: 1–20 workers per room, maximum 20 simultaneous slots, maximum eight hours per worker. No configured opening-hour window exists in the audited backend; V2 rejects past starts and intervals crossing midnight. Multiple visits are explicit independently dated occurrences; legacy recurrence generator/monthly rules remain untouched. Preferred-worker assignment retains existing backend coverage validation and must have sufficient explicit workers for peak concurrency.

Materials are quoted from existing per-room type/size/mode quantity rules, aggregated within each period. Shared package dedupe takes maximum required quantity per type per visit, preserving the preceding RoomPeriodPricingService policy; each period retains its own decision. No catalog material or service IDs are created by this implementation.

Quote ID/signature is user-bound, expires after configured TTL (15 minutes default), includes full draft plus computed pricing. Create locks the quote, rechecks input and authoritative price, then atomically writes booking, visits, stable rooms, schedules, periods/memberships, material decisions/lines, targeted services, inventory and approved price snapshot. A consumed quote cannot create another booking. Customer approved total is frozen through the established approved-total read/pricing adapter.

Validation errors use normal Laravel 422 `errors` field paths: `visits` (schedule/group/capacity), `visits.*.timePeriods` (membership/reconciliation), `quoteId` (expired/consumed), `quoteSignature` (tampered/changed), `idempotencyKey` (changed retry), `rooms` (room identity/catalog), and ordinary header validation fields. Arabic examples: `موعد الغرفة غير متاح` / `حدد عدد العمال المسموح` / `حدّث السعر قبل التأكيد` / `راجع اختيارات الفترة بعد تغيير الموعد`.

Physical storage: existing `cleaning_bookings`, `cleaning_booking_rooms` (add client_key/mode), `cleaning_booking_sessions` (visits), new `cleaning_room_schedules`, `cleaning_time_periods`, `cleaning_time_period_room_schedules`, `cleaning_time_period_materials`, `cleaning_time_period_services`, `cleaning_booking_quotes`. Previous room-period rows remain untouched and readable.


## V2 follow-up: materials preview, recurrence and saved customer details (October 16, 2026)

**Read-only requirements before supplier selection:** authenticated
`POST /api/v1/user/cleaning/orders/materials-preview`. This separate
endpoint does not create an order or quotation and needs no address,
workers, supplier choice or appointment. Body:

```json
{"contractVersion":2,"rooms":[{"roomKey":"bedroom.1","roomType":"bedroom","size":"medium","cleaningMode":"deep"}]}
```

Response: `{"contractVersion":2,"materialsPreview":{"items":[{"materialTypeId":1,"name":"اسم المادة","quantity":1.5,"unit":"liter","unitLabel":"لتر","requiredForRoomKeys":["bedroom.1"],"isRequired":true}],"catalogStatus":"ready","sourceRevision":"..."}}`.
IDs/names/quantities always come from the current material catalog/rules, not these example values. A legitimately empty active catalog yields `catalogStatus:"empty"`; an active catalog with no applicable rules returns `items:[]` and `catalogStatus:"ready"`. Failures are errors rather than a manufactured empty list. Quantities for shared types use the greatest per-room requirement and package quantities round up; the final quote recalculates per actual visits/periods.

**Supply semantics (existing validator):** `customer_all` means no platform material types; `platform_all` means all required types; with `customer_partial`, `materialTypeIds` are the types **the platform must supply**, not the types the customer brings. Repeated IDs are rejected, and the active authoritative catalog is checked. `specialServices:[]` remains a valid explicit empty selection.

**Optional recurrence declaration:** V2 accepts `"recurrence":{"cadence":"once|weekly|monthly|custom"}`. Weekly visits must be seven calendar days apart; monthly visits must fall on the corresponding calendar day in consecutive months. If that day is absent, the request gets a field-keyed 422 rather than shifting it to month end. Existing explicit visits without a cadence declaration remain supported. The recurrence declaration is included in the signed quote request hash.

**Workforce:** The optional booking-wide `numberOfWorkers` must equal the authoritative peak number of simultaneous workers derived from `roomSchedules`. Worker count is *not* capped by the number of rooms.

**Persisted V2 details:** `GET /api/v1/user/cleaning/orders/{order}` uses its established customer-ownership scoping and returns `data.rooms`, `data.visits`, `data.recurrence`, `data.workforce` and `data.pricing` (Laravel API Resource envelope). Material decisions and targeted specialist services come from committed period/material/service rows; the approved quote snapshot only backs `approvedMaterialsPreview`. `pricing.coverage` is `all_visits`, and customer-visible V2 financial fields are aggregate totals, excluding internal commission, transport and inventory accounting. V2 create returns the same persisted `rooms`, `visits`, `recurrence`, `workforce` and `pricing` fields alongside `order`.

**Pending Flutter schema agreement:** The older `assignmentMode: preferred_worker` has not been repurposed as a supervisor-with-team contract. A separately defined team-lead preference and acceptance state require coordinated changes with Flutter and the worker assignment lifecycle; no unconfirmed supervisor should be displayed as assigned.
