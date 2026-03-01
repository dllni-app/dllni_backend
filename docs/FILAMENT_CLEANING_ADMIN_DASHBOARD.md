# Filament Cleaning Admin Dashboard

This dashboard is implemented as a dedicated Filament panel at `/cleaning-admin` using direct Eloquent access.

## Scope Implemented

- Dedicated panel provider: `App\Providers\Filament\CleaningAdminPanelProvider`
- Admin access gate: `User::canAccessPanel()` with `admin` role check
- Arabic-first locale middleware for panel requests with `?lang=ar|en`
- Filament theme wiring for panel (`resources/css/filament/cleaning-admin/theme.css`)
- New panel pages:
  - `CleaningOverview` (command center KPI + latest alerts)
  - `FinancialSettings` (singleton settings form)
  - `GeographicCoverage` (coverage table aggregation)
- Section resources generated and wired:
  - Cleaning bookings
  - Event bookings
  - Workers
  - Disputes
  - Cleaning services
  - Service add-ons
  - Travel cost rules
  - Billing policies
  - System alerts
  - Time-end warnings
  - Roles
  - Admin users

## New Data Layer Additions

- Models:
  - `BookingStatusLog`
  - `BookingReview`
  - `WorkerCustomerRating`
  - `CleaningFinancialSetting`
- Migrations:
  - `create_cleaning_financial_settings_table`
  - `add_verification_and_featured_to_workers_table`
- Booking status logging on transitions:
  - `CleaningBookingObserver` now writes to `booking_status_logs`
  - Added `EventBookingObserver` with status logging

## Notes

- This panel does not call internal `/api/v1/...` endpoints.
- Existing API routes remain unchanged.
- Resource labels/navigation can be translated further through Laravel language files for full Arabic copy.
