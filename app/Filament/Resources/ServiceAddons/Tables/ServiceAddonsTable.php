<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Tables;

use App\Filament\Resources\ServiceAddons\ServiceAddonResource;
use App\Models\ServiceAddon;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Cleaning\Enums\AddonPricingType;

final class ServiceAddonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('cleaning_admin.service_addons.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('cleaning_admin.service_addons.fields.slug'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pricing_type')
                    ->label(__('cleaning_admin.service_addons.fields.pricing_type'))
                    ->badge()
                    ->color(fn ($state): string => self::pricingTypeColor($state))
                    ->formatStateUsing(fn ($state): string => self::pricingTypeLabel($state)),
                TextColumn::make('price_value')
                    ->label(__('cleaning_admin.service_addons.fields.price_value'))
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' '.config('app.currency', 'SYP'))
                    ->weight(FontWeight::Bold)
                    ->color('success')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('cleaning_admin.service_addons.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('pricing_type')
                    ->label(__('cleaning_admin.service_addons.filters.pricing_type'))
                    ->options(self::pricingTypeOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('cleaning_admin.service_addons.filters.is_active')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('cleaning_admin.shared.actions.view'))
                    ->url(fn (ServiceAddon $record): string => ServiceAddonResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label(__('cleaning_admin.shared.actions.edit'))
                    ->url(fn (ServiceAddon $record): string => ServiceAddonResource::getUrl('edit', ['record' => $record])),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function pricingTypeOptions(): array
    {
        return collect(AddonPricingType::cases())
            ->mapWithKeys(fn (AddonPricingType $type): array => [$type->value => $type->label()])
            ->all();
    }

    private static function pricingTypeLabel(AddonPricingType|string|null $type): string
    {
        $type = self::normalizePricingType($type);

        return $type?->label() ?? '-';
    }

    private static function pricingTypeColor(AddonPricingType|string|null $type): string
    {
        return match (self::normalizePricingType($type)) {
            AddonPricingType::Fixed => 'info',
            AddonPricingType::Percentage => 'warning',
            default => 'gray',
        };
    }

    private static function normalizePricingType(AddonPricingType|string|null $type): ?AddonPricingType
    {
        if ($type instanceof AddonPricingType || $type === null) {
            return $type;
        }

        return AddonPricingType::tryFrom($type);
    }
}
