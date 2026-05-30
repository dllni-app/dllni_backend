<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionGroup: string
{
    case Orders = 'orders';
    case Products = 'products';
    case Inventory = 'inventory';
    case Offers = 'offers';
    case Coupons = 'coupons';
    case Stores = 'stores';
    case Categories = 'categories';
    case CommissionRules = 'commission_rules';
    case Staff = 'staff';
    case Reports = 'reports';
    case Settings = 'settings';
    case Bookings = 'bookings';
    case Workers = 'workers';
    case Disputes = 'disputes';
    case SystemAlerts = 'system_alerts';
    case Pricing = 'pricing';
    case Catalog = 'catalog';
    case DeliveryCompanies = 'delivery_companies';
    case DeliveryDrivers = 'delivery_drivers';
    case DeliveryOrders = 'delivery_orders';
    case DeliveryDisputes = 'delivery_disputes';
    case DeliveryFinancial = 'delivery_financial';
    case DeliveryReports = 'delivery_reports';
}
