<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes\Tables;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Enums\RestaurantDisputeStatus;

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
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof RestaurantDisputeStatus ? $state->value : $state;

                        return __('restaurant_admin.enums.dispute_status.'.($value ?? 'open'));
                    }),
                TextColumn::make('resolution_type')
                    ->label('القرار')
                    ->badge()
                    ->placeholder('-')
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof BackedEnum ? $state->value : $state;

                        return $value ? __('restaurant_admin.enums.resolution_type.'.$value) : '-';
                    }),
                TextColumn::make('payout_hold_status')
                    ->label('حالة التجميد')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof BackedEnum ? $state->value : $state;

                        return __('restaurant_admin.enums.payout_hold_status.'.($value ?? 'held'));
                    }),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('restaurant_admin.filters.dispute_status_label'))
                    ->options(collect(RestaurantDisputeStatus::cases())->mapWithKeys(
                        fn (RestaurantDisputeStatus $case): array => [
                            $case->value => __('restaurant_admin.enums.dispute_status.'.$case->value),
                        ]
                    )->all()),
                SelectFilter::make('open_status')
                    ->label(__('restaurant_admin.filters.dispute_open_queue_label'))
                    ->options([
                        'open_or_review' => __('restaurant_admin.filters.dispute_open_queue_option'),
                    ])
                    ->placeholder(__('restaurant_admin.filters.dispute_open_queue_placeholder'))
                    ->query(function (Builder $query, array $data): Builder {
                        if (($data['value'] ?? null) !== 'open_or_review') {
                            return $query;
                        }

                        return $query->whereIn('status', [
                            RestaurantDisputeStatus::Open->value,
                            RestaurantDisputeStatus::UnderReview->value,
                        ]);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
