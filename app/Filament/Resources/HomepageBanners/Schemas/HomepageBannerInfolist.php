<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

final class HomepageBannerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(app()->isLocale('ar') ? 'محتوى البانر' : 'Banner Content')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                ImageEntry::make('image')
                                    ->label('')
                                    ->getStateUsing(fn (MarketingOffer $record): ?string => $record->getFirstMediaUrl(MarketingOffer::IMAGE_COLLECTION) ?: null),
                                TextEntry::make('title')
                                    ->label(app()->isLocale('ar') ? 'العنوان' : 'Title'),
                                TextEntry::make('discount_label')
                                    ->label(app()->isLocale('ar') ? 'نص العرض أو الخصم' : 'Offer or Discount Label'),
                                TextEntry::make('description')
                                    ->label(app()->isLocale('ar') ? 'الوصف' : 'Description')
                                    ->placeholder('—')
                                    ->columnSpan(2),
                                TextEntry::make('promo_code')
                                    ->label(app()->isLocale('ar') ? 'رمز العرض' : 'Promo Code')
                                    ->placeholder('—'),
                                TextEntry::make('theme')
                                    ->label(app()->isLocale('ar') ? 'لون العرض' : 'Theme')
                                    ->badge()
                                    ->formatStateUsing(fn (mixed $state): string => self::themeLabel($state)),
                            ]),
                    ]),
                Section::make(app()->isLocale('ar') ? 'الظهور والترتيب' : 'Visibility and Ordering')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('sort_order')
                                    ->label(app()->isLocale('ar') ? 'الترتيب' : 'Sort Order'),
                                TextEntry::make('is_active')
                                    ->label(app()->isLocale('ar') ? 'نشط' : 'Active')
                                    ->badge()
                                    ->formatStateUsing(fn (?bool $state): string => $state
                                        ? (app()->isLocale('ar') ? 'نعم' : 'Yes')
                                        : (app()->isLocale('ar') ? 'لا' : 'No')),
                                TextEntry::make('starts_at')
                                    ->label(app()->isLocale('ar') ? 'بداية الظهور' : 'Starts At')
                                    ->dateTime('Y-m-d H:i')
                                    ->placeholder('—'),
                                TextEntry::make('ends_at')
                                    ->label(app()->isLocale('ar') ? 'نهاية الظهور' : 'Ends At')
                                    ->dateTime('Y-m-d H:i')
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
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
