# Filament Company Panel: Delivery Operations Guide

## 1. Panel Entry

- Panel ID: `company`
- URL path: `/company`
- Provider: `App\Providers\Filament\CompanyPanelProvider`
- Registration: `bootstrap/providers.php`

The panel uses Filament authentication middleware and requires a valid dashboard user session.

## 2. Company Scoping Model

All Delivery company pages/resources are scoped by the authenticated company context:

- Company owner: matched by `delivery_companies.owner_user_id`
- Company staff: matched by `delivery_company_staff.user_id` where `is_active = true`

Primary scoping services/traits:

- `Modules\Delivery\Services\DeliveryCompanyContextService`
- `App\Filament\Company\Concerns\InteractsWithDeliveryCompany`
- `App\Filament\Company\Concerns\ScopesDeliveryCompanyDisputes`

This prevents cross-company visibility for drivers, orders, and disputes.

## 3. Navigation Map

### Dashboard

- Page: `CompanyDashboard`
- Widget: `DeliveryKpiStatsWidget`
- KPI cards:
  - total orders
  - active orders
  - stopped orders
  - completed today

### Operations

- Resource: `DeliveryOrderResource`
- Pages:
  - list: `ListDeliveryOrders`
  - create: `CreateDeliveryOrder`
  - view: `ViewDeliveryOrder`

### Drivers

- Resource: `DeliveryDriverResource`
- Pages:
  - list: `ListDeliveryDrivers`
  - create: `CreateDeliveryDriver`
  - view: `ViewDeliveryDriver`

### Disputes

- Resource: `DeliveryDisputeResource` (`App\Models\Dispute` with `booking_type = delivery_order`)
- Pages:
  - list: `ListDeliveryDisputes`
  - create: `CreateDeliveryDispute`
  - view: `ViewDeliveryDispute`

### Financial

- Page: `DeliveryFinancialPage`
- Shows company account state + latest transactions.

### Notifications

- Page: `DeliveryNotificationsPage`
- Supports unread filter, per-item read, and mark-all-read.

### Reports

- Page: `DeliveryReportsPage`
- Period options: 7/30/90 days.

## 4. Permission Gates

Panel access is permission-driven via `PermissionGroup` values:

- `delivery_orders.*`
- `delivery_drivers.*`
- `delivery_disputes.*`
- `delivery_financial.view`
- `delivery_reports.view`

Examples:

- Orders list/create/view: `delivery_orders.view`, `delivery_orders.create`
- Drivers list/create/view: `delivery_drivers.view`, `delivery_drivers.create`
- Disputes list/create/view: `delivery_disputes.view`, `delivery_disputes.create`
- Financial page: `delivery_financial.view`
- Reports page: `delivery_reports.view`

## 5. Operational Actions

### Orders

From list/view pages:

- `retry_dispatch`
  - allowed on `stopped` or `dispatching`
  - calls `DeliveryOrderService::retryDispatch()`
- `cancel`
  - blocked for `delivered`, `completed`, `cancelled`
  - requires `cancel_reason`
  - calls `DeliveryOrderService::cancel()`
- `open_dispute`
  - opens pre-filled dispute create page for the selected order

### Drivers

From driver view page:

- `suspend` (manual reason + optional `suspended_until`)
- `unsuspend` (blocked if suspension reason is `financial`)
- `activate`
- `deactivate`

All driver actions are handled by `DriverManagementService`.

### Disputes

- Create dispute from order or dispute page
  - forces:
    - `booking_type = delivery_order`
    - `status = open`
    - generated `ticket_number` (`DEL-DSP-*`)
- Add internal message in dispute view
  - stored in `dispute_messages`

## 6. Create Flow Notes

### Create Delivery Order

`CreateDeliveryOrder` delegates to `DeliveryOrderService::create()` and maps form fields to driver-app payload keys:

- `customer_name -> customerName`
- `pickup_latitude -> pickupLatitude`
- `dropoff_longitude -> dropoffLongitude`
- etc.

This ensures dispatch/pricing/events are executed through the same service used by API flows.

## 7. Known Constraints

- Order/driver/dispute records are **view + create oriented** in panel resources.
- Direct edit/delete is intentionally disabled on delivery resources.
- Financial suspension logic is service-driven and can auto-impact driver availability.

## 8. Source of Truth

- Panel provider: `app/Providers/Filament/CompanyPanelProvider.php`
- Company pages: `app/Filament/Company/Pages/*`
- Company resources: `app/Filament/Company/Resources/*`
- Dashboard widget: `app/Filament/Company/Widgets/DeliveryKpiStatsWidget.php`
- Scoping traits: `app/Filament/Company/Concerns/*`
- Context service: `Modules/Delivery/app/Services/DeliveryCompanyContextService.php`
