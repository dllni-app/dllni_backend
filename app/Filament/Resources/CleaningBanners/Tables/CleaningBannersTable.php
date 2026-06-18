<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Tables;

use App\Filament\Resources\CleaningBanners\CleaningBannerResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningBanner;

final class CleaningBannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->defaultImageUrl('https://ui-avatars.com/api/?name=B&background=random')
                    ->getStateUsing(fn (CleaningBanner $record): ?string => $record->imageUrl()),
                TextColumn::make('title')
                    ->label(__('cleaning_admin.cleaning_banners.fields.title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('cleaning_admin.cleaning_banners.fields.sort_order'))
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(__('cleaning_admin.cleaning_banners.fields.starts_at'))
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('cleaning_admin.cleaning_banners.fields.ends_at'))
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('cleaning_admin.cleaning_banners.fields.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('cleaning_admin.cleaning_banners.filters.is_active')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('cleaning_admin.shared.actions.view'))
                    ->url(fn (CleaningBanner $record): string => CleaningBannerResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label(__('cleaning_admin.shared.actions.edit'))
                    ->url(fn (CleaningBanner $record): string => CleaningBannerResource::getUrl('edit', ['record' => $record])),
            ])
            ->modifyQueryUsing(fn ($query) => $query->orderBy('sort_order')->orderBy('id'));
    }
}
