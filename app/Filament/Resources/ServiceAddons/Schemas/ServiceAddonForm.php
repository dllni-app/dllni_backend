<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\AddonPricingType;

final class ServiceAddonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('cleaning_admin.service_addons.fields.name'))
                    ->required(),
                TextInput::make('slug')
                    ->label(__('cleaning_admin.service_addons.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true),
                Select::make('pricing_type')
                    ->label(__('cleaning_admin.service_addons.fields.pricing_type'))
                    ->options(collect(AddonPricingType::cases())->mapWithKeys(fn (AddonPricingType $type): array => [$type->value => $type->label()])->all())
                    ->required(),
                TextInput::make('price_value')
                    ->label(__('cleaning_admin.service_addons.fields.price_value'))
                    ->numeric()
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('cleaning_admin.service_addons.fields.is_active'))
                    ->default(true),
            ]);
    }
}
