<?php

declare(strict_types=1);

namespace App\Support;

final class SupermarketOwnerPermissionCatalog
{
    public const string GROUP = 'supermarket_owner';

    /** @var list<string> */
    public const array NAMES = [
        'so.products',
        'so.offers_coupons',
        'so.orders',
        'so.staff_register',
        'so.store_hours',
        'so.warehouse',
    ];

    private function __construct()
    {
    }
}
