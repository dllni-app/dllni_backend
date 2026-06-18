<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings\Schemas;

use App\Support\BookingMorphTypeLabel;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class CleaningTimeWarningInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('booking_type')
                    ->label(__('cleaning_admin.time_warnings.fields.booking_type'))
                    ->formatStateUsing(fn (?string $state): string => BookingMorphTypeLabel::resolve($state)),
                TextEntry::make('booking_id')->label(__('cleaning_admin.time_warnings.fields.booking_id')),
                TextEntry::make('sent_at')->label(__('cleaning_admin.time_warnings.fields.sent_at'))->dateTime('Y-m-d H:i'),
                TextEntry::make('customer_response')->label(__('cleaning_admin.time_warnings.fields.customer_response'))->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextEntry::make('worker_response')->label(__('cleaning_admin.time_warnings.fields.worker_response'))->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextEntry::make('additional_minutes')->label(__('cleaning_admin.time_warnings.fields.additional_minutes'))->placeholder('-'),
                TextEntry::make('worker_reject_message')->label(__('cleaning_admin.time_warnings.fields.worker_reject_message'))->placeholder('-'),
            ]);
    }
}
