<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreTrustLogs\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SmStoreTrustLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('supermarket_admin.hub.trust_logs'))
                    ->schema([
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('event_type')->label(__('supermarket_admin.infolist.event_type'))->placeholder('—'),
                        TextEntry::make('score_delta')->label(__('supermarket_admin.infolist.score_delta'))->placeholder('—'),
                        TextEntry::make('score_after')->label(__('supermarket_admin.infolist.score_after'))->placeholder('—'),
                        TextEntry::make('notes')->label(__('supermarket_admin.infolist.notes'))->placeholder('—'),
                        TextEntry::make('triggeredByUser.name')->label(__('supermarket_admin.infolist.triggered_by'))->placeholder('—'),
                        TextEntry::make('created_at')->label(__('supermarket_admin.infolist.created_at'))->dateTime('Y-m-d H:i'),
                    ])
                    ->columns(2),
            ]);
    }
}
