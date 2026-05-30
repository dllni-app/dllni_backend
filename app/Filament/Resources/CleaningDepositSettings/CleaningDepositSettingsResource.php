<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningDepositSettings;

use App\Models\CleaningDepositSetting;
use Filament\Resources\Resource;

final class CleaningDepositSettingsResource extends Resource
{
    protected static ?string $model = CleaningDepositSetting::class;
}
