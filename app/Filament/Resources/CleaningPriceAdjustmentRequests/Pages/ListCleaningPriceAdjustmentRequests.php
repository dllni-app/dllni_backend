<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningPriceAdjustmentRequests\Pages;

use App\Filament\Resources\CleaningPriceAdjustmentRequests\CleaningPriceAdjustmentRequestResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListCleaningPriceAdjustmentRequests extends ListRecords
{
    protected static string $resource = CleaningPriceAdjustmentRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'طلبات تعديل السعر';
    }

    public function getSubheading(): ?string
    {
        return 'مراجعة طلبات تعديل السعر الواردة من العمال قبل بدء العمل واعتماد السعر النهائي أو إغلاق الطلب بدون تعديل.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
