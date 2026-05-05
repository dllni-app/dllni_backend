<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProductCategories\Pages;

use App\Filament\Resources\MasterProductCategories\MasterProductCategoryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateMasterProductCategory extends CreateRecord
{
    protected static string $resource = MasterProductCategoryResource::class;
}
