<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Tables;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')->label('رقم التذكرة')->searchable()->sortable(),
                TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->getStateUsing(fn ($record) => $record->booking?->booking_number ?? '-')
                    ->placeholder('-'),
                TextColumn::make('category')->label('التصنيف')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('resolution')->label('القرار')->placeholder('-')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('created_at')->label('تاريخ الفتح')->since()->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('booking'))
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(collect(DisputeStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('category')->label('التصنيف')->options(collect(DisputeCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('resolution')->label('القرار')->options(collect(DisputeResolution::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
