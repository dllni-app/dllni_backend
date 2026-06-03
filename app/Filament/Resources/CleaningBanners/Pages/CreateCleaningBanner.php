<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Pages;

use App\Filament\Resources\CleaningBanners\CleaningBannerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCleaningBanner extends CreateRecord
{
    protected static string $resource = CleaningBannerResource::class;
}
