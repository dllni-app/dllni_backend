<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Cleaning\Enums\ServiceCategory;

final class CleaningServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('cleaning_admin.cleaning_services.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('cleaning_admin.cleaning_services.fields.category'))
                    ->badge()
                    ->color(fn ($state): string => self::categoryColor($state))
                    ->formatStateUsing(fn ($state): string => self::categoryLabel($state)),
                TextColumn::make('description')
                    ->label(__('cleaning_admin.cleaning_services.fields.description'))
                    ->limit(80)
                    ->toggleable(),
                TextColumn::make('price')
                    ->label(__('cleaning_admin.cleaning_services.fields.price'))
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' '.config('app.currency', 'SYP'))
                    ->weight(FontWeight::Bold)
                    ->color('success')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('cleaning_admin.cleaning_services.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('cleaning_admin.cleaning_services.filters.category'))
                    ->options(self::categoryOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('cleaning_admin.cleaning_services.filters.is_active')),
            ])
            ->recordActions([
                ViewAction::make()->label(__('cleaning_admin.shared.actions.view')),
                EditAction::make()->label(__('cleaning_admin.shared.actions.edit')),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function categoryOptions(): array
    {
        return collect(ServiceCategory::cases())
            ->mapWithKeys(fn (ServiceCategory $category): array => [$category->value => $category->label()])
            ->all();
    }

    private static function categoryLabel(ServiceCategory|string|null $category): string
    {
        $category = self::normalizeCategory($category);

        return $category?->label() ?? '-';
    }

    private static function categoryColor(ServiceCategory|string|null $category): string
    {
        return match (self::normalizeCategory($category)) {
            ServiceCategory::Cleaning => 'success',
            ServiceCategory::EventAssistance => 'warning',
            default => 'gray',
        };
    }

    private static function normalizeCategory(ServiceCategory|string|null $category): ?ServiceCategory
    {
        if ($category instanceof ServiceCategory || $category === null) {
            return $category;
        }

        return ServiceCategory::tryFrom($category);
    }
}
