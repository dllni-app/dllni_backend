<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;
}
