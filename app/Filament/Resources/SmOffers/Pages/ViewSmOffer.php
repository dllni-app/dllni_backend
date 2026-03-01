<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers\Pages;

use App\Filament\Resources\SmOffers\SmOfferResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSmOffer extends ViewRecord
{
    protected static string $resource = SmOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
