<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningExtendedTimePrices\Pages;

use App\Filament\Resources\CleaningExtendedTimePrices\CleaningExtendedTimePriceResource;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningExtendedTimePrice extends EditRecord
{
    protected static string $resource = CleaningExtendedTimePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'price' => $data['price'],
        ];
    }
}
