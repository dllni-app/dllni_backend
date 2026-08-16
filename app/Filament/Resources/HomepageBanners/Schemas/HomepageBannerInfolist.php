<?php

declare(strict_types=1);

namespace App\Filament\Resources\HomepageBanners\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\User\Models\MarketingOffer;

final class HomepageBannerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(app()->isLocale('ar') ? 'صورة البانر' : 'Banner Image')
                    ->schema([
                        ImageEntry::make('image')
                            ->label('')
                            ->getStateUsing(fn (MarketingOffer $record): ?string => $record->getFirstMediaUrl(MarketingOffer::IMAGE_COLLECTION) ?: null),
                    ]),
            ]);
    }
}
