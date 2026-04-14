<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories\Pages;

use App\Filament\Resources\SmCategories\SmCategoryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSmCategory extends CreateRecord
{
    protected static string $resource = SmCategoryResource::class;
}
