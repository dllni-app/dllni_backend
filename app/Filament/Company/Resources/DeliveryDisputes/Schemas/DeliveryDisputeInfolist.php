<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDisputes\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DeliveryDisputeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('delivery_company.disputes.sections.ticket'))
                    ->schema([
                        TextEntry::make('ticket_number')
                            ->label(__('delivery_company.disputes.fields.ticket_number')),
                        TextEntry::make('category')
                            ->label(__('delivery_company.disputes.fields.category'))
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('status')
                            ->label(__('delivery_company.disputes.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('resolution')
                            ->label(__('delivery_company.disputes.fields.resolution'))
                            ->badge()
                            ->placeholder('—')
                            ->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('description')
                            ->label(__('delivery_company.disputes.fields.description'))
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label(__('delivery_company.disputes.fields.created_at'))
                            ->dateTime('Y-m-d H:i'),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.disputes.sections.order'))
                    ->schema([
                        TextEntry::make('booking.order_number')
                            ->label(__('delivery_company.disputes.fields.order_number'))
                            ->placeholder('—'),
                        TextEntry::make('booking.customer_name')
                            ->label(__('delivery_company.orders.fields.customer_name'))
                            ->placeholder('—'),
                        TextEntry::make('booking.status')
                            ->label(__('delivery_company.orders.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? __('delivery_company.orders.enums.status.'.$state)
                                : '—'),
                    ])
                    ->columns(3)
                    ->visible(fn ($record): bool => $record->booking !== null),
                Section::make(__('delivery_company.disputes.sections.messages'))
                    ->schema([
                        RepeatableEntry::make('messages')
                            ->label('')
                            ->schema([
                                TextEntry::make('sender.name')
                                    ->label(__('delivery_company.disputes.fields.sender'))
                                    ->placeholder('—'),
                                TextEntry::make('body')
                                    ->label(__('delivery_company.disputes.fields.message_body')),
                                TextEntry::make('created_at')
                                    ->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record): bool => $record->messages()->exists()),
            ]);
    }
}
