# Cleaning Distance Pricing Contract

Date: 2026-05-25  
Scope: Cleaning user + worker booking flow

## Financial Settings Source
- Source of truth: `cleaning_financial_settings`
- Used fields:
1. `travel_per_km`
2. `commission_type` (`percent` | `fixed`)
3. `default_commission_rate`
4. `commission_fixed_amount`
- Distance start point for this flow is locked to `worker_home`.

## Pricing Formulas
1. `deliveryFee = distanceKm * travel_per_km`
2. `subtotal = basePrice + addonsTotal + deliveryFee`
3. `adminMargin =`
   `commission_type=percent ? subtotal * default_commission_rate / 100 : commission_fixed_amount`
4. `totalPrice = subtotal + adminMargin`

Rounding:
1. money values: 2 decimals
2. `distanceKm`: 3 decimals

## Provisional vs Final Pricing
- If `preferredWorkerId` is provided:
1. pricing is finalized immediately (`isPricingFinal=true`)
2. distance is calculated between customer coordinates and preferred worker home coordinates

- If `preferredWorkerId` is missing:
1. pricing is provisional at estimate/create/update (`isPricingFinal=false`)
2. `travelFee=0`, `adminMargin=0`, `distanceKm=null`
3. total is provisional until worker accepts booking

## API Response Fields
The following fields are now included:
- Estimate endpoint (`POST /api/v1/user/cleaning/orders/estimate-price`) inside `pricing`:
1. `distanceKm`
2. `adminMargin`
3. `isPricingFinal`

- Cleaning booking resource (`CleaningBookingResource`):
1. `travelDistanceKm`
2. `adminMargin`
3. `isPricingFinal`

## Accept Booking Finalization
- Endpoint: `POST /api/v1/cleaning-bookings/{id}/accept`
- If booking is provisional:
1. pricing is recalculated using the accepted worker home location
2. persisted fields are updated:
   - `travel_fee`
   - `travel_distance_km`
   - `admin_margin_amount`
   - `total_price`
   - `is_pricing_final=true`

- Acceptance validation failures:
1. worker home location missing
2. customer coordinates missing

