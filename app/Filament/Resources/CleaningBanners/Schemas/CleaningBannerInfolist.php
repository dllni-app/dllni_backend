<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Models\CleaningBanner;

final class CleaningBannerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cleaning_admin.cleaning_banners.sections.content'))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                ImageEntry::make('image')
                                    ->label('')
                                    ->getStateUsing(fn (CleaningBanner $record): ?string => $record->imageUrl()),
                                TextEntry::make('title')
                                    ->label(__('cleaning_admin.cleaning_banners.fields.title')),
                                TextEntry::make('subtitle')
                                    ->label(__('cleaning_admin.cleaning_banners.fields.subtitle'))
                                    ->placeholder('—'),
                                TextEntry::make('target_url')
                                    ->label(__('cleaning_admin.cleaning_banners.fields.target_url'))
                                    ->placeholder('—'),
                            ]),
                    ]),
                Section::make(__('cleaning_admin.cleaning_banners.sections.visibility'))
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('sort_order')
                                    ->label(__('cleaning_admin.cleaning_banners.fields.sort_order')),
                                TextEntry::make('is_active')
                                    ->label(__('cleaning_admin.cleaning_banners.fields.is_active'))
                                    ->formatStateUsing(fn (?bool $state): string => $state ? __('cleaning_admin.boolean.yes') : __('cleaning_admin.boolean.no')),
                                TextEntry::make('starts_at')
                                    ->label(__('cleaning_admin.cleaning_banners.fields.starts_at'))
                                    ->dateTime('Y-m-d H:i')
                                    ->placeholder('—'),
                                TextEntry::make('ends_at')
                                    ->label(__('cleaning_admin.cleaning_banners.fields.ends_at'))
                                    ->dateTime('Y-m-d H:i')
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }
}
