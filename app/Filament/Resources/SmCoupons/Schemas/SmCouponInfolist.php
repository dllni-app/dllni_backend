<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCoupons\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SmCouponInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('supermarket_admin.coupons'))
                    ->schema([
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('code')->label(__('supermarket_admin.form.code')),
                        TextEntry::make('is_active')->label(__('supermarket_admin.form.is_active'))->formatStateUsing(fn (?bool $s) => $s ? __('supermarket_admin.enums.boolean.yes') : __('supermarket_admin.enums.boolean.no'))->badge(),
                        TextEntry::make('starts_at')->label(__('supermarket_admin.form.starts_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('ends_at')->label(__('supermarket_admin.form.ends_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}
