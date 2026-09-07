# Multi-Day Event Assistance - Cost Breakdown

The fixed implementation price for the scope defined in `MULTI_DAY_EVENT_ASSISTANCE_IMPLEMENTATION_PLAN.md` is **USD 100**.

| Platform | Cost | Included scope |
|---|---:|---|
| Laravel Backend | USD 40 | Session schema/models, API compatibility, pricing aggregation, worker schedule conflicts, per-session lifecycle, cancellation/financial aggregation, realtime payloads, and backend tests. |
| Flutter User App | USD 25 | Multi-date event scheduling, per-day time/duration input, estimate/create serialization, booking summary/progress, order details, and related tests. |
| Flutter Worker App | USD 25 | Multi-day order rendering, all-session availability/acceptance UX, calendar session expansion, per-session lifecycle actions/timers, homepage updates, and tests. |
| Filament Dashboard | USD 10 | Session-aware booking tables/details, filters, worker assignment eligibility, operational progress, and related admin updates. |
| **Total** | **USD 100** | **Complete implementation of the defined Phase 1 scope.** |

## Commercial Scope Note

The USD 100 price assumes the Phase 1 business rule that **the same accepted worker team is committed to all event sessions**. Features outside the implementation plan, including different worker teams per event day, newly requested business flows, or unrelated redesign work, require separate estimation.
