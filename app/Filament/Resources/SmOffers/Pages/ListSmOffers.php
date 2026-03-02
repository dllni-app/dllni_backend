<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers\Pages;

use App\Filament\Resources\SmOffers\SmOfferResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmOffers extends ListRecords
{
    protected static string $resource = SmOfferResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.offers.list');
    }
}
