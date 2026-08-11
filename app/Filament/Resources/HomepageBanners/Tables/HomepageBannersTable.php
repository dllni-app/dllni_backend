<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Tables;

use App\Filament\Resources\HomepageBanners\HomepageBannerResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

final class HomepageBannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->square()
                    ->defaultImageUrl('https://ui-avatars.com/api/?name=B&background=random')
                    ->getStateUsing(fn (MarketingOffer $record): ?string => $record->getFirstMediaUrl(MarketingOffer::IMAGE_COLLECTION) ?: null),
                TextColumn::make('title')
                    ->label(app()->isLocale('ar') ? 'العنوان' : 'Title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('discount_label')
                    ->label(app()->isLocale('ar') ? 'نص العرض' : 'Offer Label')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('promo_code')
                    ->label(app()->isLocale('ar') ? 'رمز العرض' : 'Promo Code')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('theme')
                    ->label(app()->isLocale('ar') ? 'اللون' : 'Theme')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::themeLabel($state)),
                TextColumn::make('sort_order')
                    ->label(app()->isLocale('ar') ? 'الترتيب' : 'Sort Order')
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(app()->isLocale('ar') ? 'بداية الظهور' : 'Starts At')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(app()->isLocale('ar') ? 'نهاية الظهور' : 'Ends At')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(app()->isLocale('ar') ? 'نشط' : 'Active')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(app()->isLocale('ar') ? 'الحالة' : 'Status')
                    ->trueLabel(app()->isLocale('ar') ? 'النشطة فقط' : 'Active only')
                    ->falseLabel(app()->isLocale('ar') ? 'غير النشطة فقط' : 'Inactive only'),
                SelectFilter::make('theme')
                    ->label(app()->isLocale('ar') ? 'اللون' : 'Theme')
                    ->options([
                        MarketingOfferTheme::Orange->value => app()->isLocale('ar') ? 'برتقالي' : 'Orange',
                        MarketingOfferTheme::Green->value => app()->isLocale('ar') ? 'أخضر' : 'Green',
                        MarketingOfferTheme::Gold->value => app()->isLocale('ar') ? 'ذهبي' : 'Gold',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(app()->isLocale('ar') ? 'عرض' : 'View')
                    ->url(fn (MarketingOffer $record): string => HomepageBannerResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label(app()->isLocale('ar') ? 'تعديل' : 'Edit')
                    ->url(fn (MarketingOffer $record): string => HomepageBannerResource::getUrl('edit', ['record' => $record])),
            ])
            ->modifyQueryUsing(fn ($query) => $query->orderBy('sort_order')->orderBy('id'));
    }

    private static function themeLabel(mixed $state): string
    {
        $value = $state instanceof MarketingOfferTheme ? $state->value : (string) $state;

        return match ($value) {
            MarketingOfferTheme::Orange->value => app()->isLocale('ar') ? 'برتقالي' : 'Orange',
            MarketingOfferTheme::Green->value => app()->isLocale('ar') ? 'أخضر' : 'Green',
            MarketingOfferTheme::Gold->value => app()->isLocale('ar') ? 'ذهبي' : 'Gold',
            default => $value,
        };
    }
}
