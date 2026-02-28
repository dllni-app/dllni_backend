<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers\Pages;

use App\Filament\Resources\SmOffers\SmOfferResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSmOffer extends EditRecord
{
    protected static string $resource = SmOfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
