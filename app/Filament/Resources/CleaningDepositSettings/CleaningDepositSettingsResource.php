<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningDepositSettings;

use App\Models\CleaningDepositSetting;
use Filament\Resources\Resource;

final class CleaningDepositSettingsResource extends Resource
{
    protected static ?int $navigationSort = 33;

    protected static ?string $model = CleaningDepositSetting::class;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('admin_resources.cleaning_deposit_setting.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.cleaning_deposit_setting.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.cleaning_deposit_setting.plural');
    }
}
