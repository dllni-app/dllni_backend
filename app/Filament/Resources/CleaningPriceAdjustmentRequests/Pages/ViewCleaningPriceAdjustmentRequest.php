<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningPriceAdjustmentRequests\Pages;

use App\Filament\Resources\CleaningPriceAdjustmentRequests\CleaningPriceAdjustmentRequestResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningPriceAdjustmentRequest extends ViewRecord
{
    protected static string $resource = CleaningPriceAdjustmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
