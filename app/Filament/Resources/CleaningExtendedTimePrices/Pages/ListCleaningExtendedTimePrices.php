<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningExtendedTimePrices\Pages;

use App\Filament\Resources\CleaningExtendedTimePrices\CleaningExtendedTimePriceResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;

final class ListCleaningExtendedTimePrices extends ListRecords
{
    protected static string $resource = CleaningExtendedTimePriceResource::class;

    public function mount(): void
    {
        app(CleaningExtendedTimePricingService::class)->ensureFixedRanges();

        parent::mount();
    }

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.extended_time_prices.title');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.extended_time_prices.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
