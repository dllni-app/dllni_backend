<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDrivers\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DeliveryDriversTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->label('المندوب')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('الشركة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('availability_status')
                    ->label('التوفر')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? __('delivery_company.drivers.enums.availability.'.$state)
                        : '—')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                IconColumn::make('is_suspended')
                    ->label('موقوف')
                    ->boolean(),
                TextColumn::make('trust_score')
                    ->label('الثقة')
                    ->sortable(),
                TextColumn::make('open_disputes_count')
                    ->label('نزاعات مفتوحة')
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('آخر ظهور')
                    ->dateTime('Y-m-d H:i')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('شركة التوصيل')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('availability_status')
                    ->label('التوفر')
                    ->options([
                        'online' => __('delivery_company.drivers.enums.availability.online'),
                        'available' => __('delivery_company.drivers.enums.availability.available'),
                        'offline' => __('delivery_company.drivers.enums.availability.offline'),
                        'busy' => __('delivery_company.drivers.enums.availability.busy'),
                    ]),
                TernaryFilter::make('is_active')->label('نشط'),
                TernaryFilter::make('is_suspended')->label('موقوف'),
                TernaryFilter::make('stale')
                    ->label('آخر ظهور قديم')
                    ->placeholder('الكل')
                    ->trueLabel('أقدم من 15 دقيقة')
                    ->falseLabel('خلال 15 دقيقة')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                            $inner->whereNull('last_seen_at')
                                ->orWhere('last_seen_at', '<=', now()->subMinutes(15));
                        }),
                        false: fn (Builder $query): Builder => $query->where('last_seen_at', '>', now()->subMinutes(15)),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'company',
                'latestLocation',
            ]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }
}
