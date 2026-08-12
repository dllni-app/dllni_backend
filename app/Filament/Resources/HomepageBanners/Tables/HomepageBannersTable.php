<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Tables;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\User\Models\MarketingOffer;

final class HomepageBannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                    ->getStateUsing(fn (MarketingOffer $record): ?string => $record->getFirstMediaUrl(MarketingOffer::IMAGE_COLLECTION) ?: null),
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(app()->isLocale('ar') ? 'تاريخ الإضافة' : 'Created At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->label(app()->isLocale('ar') ? 'عرض' : 'View')
                    ->url(fn (MarketingOffer $record): string => HomepageBannerResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label(app()->isLocale('ar') ? 'تعديل الصورة' : 'Edit Image')
                    ->url(fn (MarketingOffer $record): string => HomepageBannerResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
