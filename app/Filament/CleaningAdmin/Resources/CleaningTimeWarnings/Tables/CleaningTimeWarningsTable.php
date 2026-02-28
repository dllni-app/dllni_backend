<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Tables;

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
                TextColumn::make('booking_type')->label('نوع الحجز')->formatStateUsing(fn (?string $state) => $state === 'cleaning' ? 'تنظيف' : ($state === 'event' ? 'مناسبة' : $state)),
                TextColumn::make('booking_id')->label('رقم الحجز'),
                TextColumn::make('customer_response')->label('رد العميل')->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('worker_response')->label('رد العامل')->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('additional_minutes')->label('دقائق إضافية')->placeholder('-'),
                TextColumn::make('worker_reject_message')->label('رسالة رفض العامل')->limit(40)->placeholder('-'),
                TextColumn::make('sent_at')->label('وقت الإرسال')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer_response')->label('رد العميل')->options($responseOptions),
                SelectFilter::make('worker_response')->label('رد العامل')->options($responseOptions),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
