<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SmStoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('supermarket_admin.hub.stores'))
                    ->schema([
                        TextEntry::make('name')->label(__('supermarket_admin.infolist.name')),
                        TextEntry::make('owner.name')->label(__('supermarket_admin.infolist.owner'))->placeholder('—'),
                        TextEntry::make('trust_score')->label(__('supermarket_admin.infolist.trust_score'))->suffix(' / 100'),
                        TextEntry::make('warning_count')->label(__('supermarket_admin.infolist.warning_count')),
                        TextEntry::make('average_rating')->label(__('supermarket_admin.infolist.average_rating')),
                        TextEntry::make('total_reviews')->label(__('supermarket_admin.infolist.total_reviews')),
                        TextEntry::make('is_active')->label(__('supermarket_admin.form.is_active'))->formatStateUsing(fn (?bool $s) => $s ? __('supermarket_admin.enums.boolean.yes') : __('supermarket_admin.enums.boolean.no'))->badge(),
                        TextEntry::make('is_featured')->label(__('supermarket_admin.form.is_featured'))->formatStateUsing(fn (?bool $s) => $s ? __('supermarket_admin.enums.boolean.yes') : __('supermarket_admin.enums.boolean.no'))->badge(),
                        TextEntry::make('suspension_until')->label(__('supermarket_admin.form.suspension_until'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    ])
                    ->columns(3),
            ]);
    }
}
