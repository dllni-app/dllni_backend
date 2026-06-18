<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings\Tables;

use App\Support\BookingMorphTypeLabel;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;

final class CleaningTimeWarningsTable
{
    public static function configure(Table $table): Table
    {
        $responseOptions = collect(CleaningTimeWarningResponse::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();

        return $table
            ->columns([
                TextColumn::make('booking_type')
                    ->label(__('cleaning_admin.time_warnings.fields.booking_type'))
                    ->formatStateUsing(fn (?string $state): string => BookingMorphTypeLabel::resolve($state)),
                TextColumn::make('booking_id')->label(__('cleaning_admin.time_warnings.fields.booking_id')),
                TextColumn::make('customer_response')->label(__('cleaning_admin.time_warnings.fields.customer_response'))->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('worker_response')->label(__('cleaning_admin.time_warnings.fields.worker_response'))->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('additional_minutes')->label(__('cleaning_admin.time_warnings.fields.additional_minutes'))->placeholder('-'),
                TextColumn::make('worker_reject_message')->label(__('cleaning_admin.time_warnings.fields.worker_reject_message'))->limit(40)->placeholder('-'),
                TextColumn::make('sent_at')->label(__('cleaning_admin.time_warnings.fields.sent_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer_response')->label(__('cleaning_admin.time_warnings.fields.customer_response'))->options($responseOptions),
                SelectFilter::make('worker_response')->label(__('cleaning_admin.time_warnings.fields.worker_response'))->options($responseOptions),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
