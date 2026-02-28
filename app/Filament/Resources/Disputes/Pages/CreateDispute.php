<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Pages;

use App\Filament\Resources\Disputes\DisputeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDispute extends CreateRecord
{
    protected static string $resource = DisputeResource::class;
}
