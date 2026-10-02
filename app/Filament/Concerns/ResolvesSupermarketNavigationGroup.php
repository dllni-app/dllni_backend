<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use UnitEnum;

trait ResolvesSupermarketNavigationGroup
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return \App\Filament\Support\AdminNavigationGroup::supermarkets();
    }
}
