<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningRoomTypes\Pages;

use App\Filament\Resources\CleaningRoomTypes\CleaningRoomTypeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningRoomType extends CreateRecord
{
    protected static string $resource = CleaningRoomTypeResource::class;
}
