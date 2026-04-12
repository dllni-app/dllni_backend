<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts\Pages;

use App\Filament\Concerns\ListSmRecordsWithSupermarketHubLink;
use App\Filament\Resources\SmProducts\SmProductResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmProducts extends ListRecords
{
    use ListSmRecordsWithSupermarketHubLink;

    protected static string $resource = SmProductResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.products.list');
    }
}
