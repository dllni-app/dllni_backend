<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

final class SmOfferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('supermarket_admin.offers'))
                    ->schema([
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('name')->label(__('supermarket_admin.infolist.product_name')),
                        TextEntry::make('promotion_type')->label('آلية العرض')->formatStateUsing(fn ($state) => $state === 'buy_x_get_y' ? 'اشترِ واحصل' : 'خصم منتج'),
                        TextEntry::make('buy_quantity')->label('كمية الشراء')->placeholder('—'),
                        TextEntry::make('reward_quantity')->label('كمية المكافأة')->placeholder('—'),
                        TextEntry::make('reward_type')->label('نوع المكافأة')->placeholder('—'),
                        TextEntry::make('reward_discount_percent')->label('نسبة خصم المكافأة')->suffix('%')->placeholder('—'),
                        TextEntry::make('max_applications_per_order')->label('حد التكرار')->placeholder('دون حد إضافي'),
                        TextEntry::make('rewardProducts.name')->label('منتجات المكافأة')->badge(),
                        TextEntry::make('recorded_usage')->label('طلبات استفادت فعلياً')->state(fn ($record) => DB::table('sm_order_promotion_applications')->where('original_offer_id', $record->id)->where('status', 'applied')->distinct()->count('order_id')),
                        TextEntry::make('is_active')->label(__('supermarket_admin.form.is_active'))->formatStateUsing(fn (?bool $s) => $s ? __('supermarket_admin.enums.boolean.yes') : __('supermarket_admin.enums.boolean.no'))->badge(),
                        TextEntry::make('starts_at')->label(__('supermarket_admin.form.starts_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('ends_at')->label(__('supermarket_admin.form.ends_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}
