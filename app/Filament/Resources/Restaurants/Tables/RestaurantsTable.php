<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\Tables;

use App\Enums\RestaurantAdminReadinessFilter;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class RestaurantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('city')->label('المدينة')->searchable(),
                TextColumn::make('district')->label('الحي')->searchable(),
                TextColumn::make('reputation_score')->label('نقاط الثقة')->sortable(),
                TextColumn::make('warning_count')->label('التحذيرات')->sortable(),
                TextColumn::make('visibility_score')->label('درجة الظهور')->sortable(),
                TextColumn::make('average_rating')->label('متوسط التقييم')->sortable(),
                IconColumn::make('is_active')->boolean()->label('نشط'),
                TextColumn::make('suspension_until')->label('تعليق حتى')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('readiness')
                    ->label(__('restaurant_admin.filters.readiness_label'))
                    ->options(RestaurantAdminReadinessFilter::options())
                    ->placeholder(__('restaurant_admin.filters.readiness_placeholder'))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        $filter = RestaurantAdminReadinessFilter::tryFrom($value);

                        return match ($filter) {
                            RestaurantAdminReadinessFilter::MissingOperatingHours => $query->adminMissingOperatingHours(),
                            RestaurantAdminReadinessFilter::MissingCuisineTypes => $query->adminMissingCuisineTypes(),
                            RestaurantAdminReadinessFilter::MissingAvailableProducts => $query->adminMissingAvailableProducts(),
                            RestaurantAdminReadinessFilter::MissingActiveOffers => $query->adminMissingActiveOffers(),
                            RestaurantAdminReadinessFilter::MissingActiveCoupons => $query->adminMissingActiveCoupons(),
                            default => $query,
                        };
                    }),
                SelectFilter::make('temp_closure')
                    ->label(__('restaurant_admin.filters.temp_closure_label'))
                    ->options([
                        '1' => __('restaurant_admin.filters.temp_closure_yes'),
                    ])
                    ->placeholder(__('restaurant_admin.filters.temp_closure_placeholder'))
                    ->query(function (Builder $query, array $data): Builder {
                        if (($data['value'] ?? null) !== '1') {
                            return $query;
                        }

                        return $query->where('is_temporarily_closed', true);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
