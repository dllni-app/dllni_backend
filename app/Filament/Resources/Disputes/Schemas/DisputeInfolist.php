<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class DisputeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $yesNo = fn ($state) => $state ? __('cleaning_admin.boolean.yes') : __('cleaning_admin.boolean.no');

        return $schema
            ->components([
                Section::make(__('cleaning_admin.disputes.sections.ticket'))
                    ->schema([
                        TextEntry::make('ticket_number')->label(__('cleaning_admin.disputes.fields.ticket_number')),
                        TextEntry::make('description')->label(__('cleaning_admin.disputes.fields.description')),
                        TextEntry::make('category')->label(__('cleaning_admin.disputes.fields.category'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('status')->label(__('cleaning_admin.disputes.fields.status'))->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('resolution')->label(__('cleaning_admin.disputes.fields.resolution'))->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('worker_earnings_frozen')->label(__('cleaning_admin.disputes.fields.worker_earnings_frozen'))->formatStateUsing($yesNo),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.disputes.sections.booking'))
                    ->schema([
                        TextEntry::make('booking.booking_number')->label(__('cleaning_admin.disputes.fields.booking_number'))->placeholder('-'),
                        TextEntry::make('booking.customer.name')->label(__('cleaning_admin.disputes.fields.customer'))->placeholder('-'),
                        TextEntry::make('booking.worker.first_name')->label(__('cleaning_admin.disputes.fields.worker'))->placeholder('-'),
                    ])
                    ->columns(3)
                    ->visible(fn ($record) => $record->booking),
                Section::make(__('cleaning_admin.disputes.sections.messages'))
                    ->schema([
                        RepeatableEntry::make('messages')
                            ->label('')
                            ->schema([
                                TextEntry::make('sender_id')->label(__('cleaning_admin.disputes.fields.sender'))->formatStateUsing(fn ($state, $record) => $record->sender?->name ?? (string) $state),
                                TextEntry::make('body')->label(__('cleaning_admin.disputes.fields.body')),
                                TextEntry::make('created_at')->label(__('cleaning_admin.workers.fields.date'))->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
