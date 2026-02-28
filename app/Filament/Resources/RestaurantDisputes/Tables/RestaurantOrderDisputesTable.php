<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RestaurantOrderDisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')->label('رقم التذكرة')->searchable()->sortable(),
                TextColumn::make('order.order_number')->label('رقم الطلب')->searchable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __('restaurant_admin.enums.dispute_status.'.($state ?? 'open'))),
                TextColumn::make('resolution_type')
                    ->label('القرار')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state): string => $state ? __('restaurant_admin.enums.resolution_type.'.$state) : '-'),
                TextColumn::make('payout_hold_status')
                    ->label('حالة التجميد')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __('restaurant_admin.enums.payout_hold_status.'.($state ?? 'held'))),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->since()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
