<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SmStoreDocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('supermarket_admin.store_documents'))
                    ->schema([
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('document_type')
                            ->label(__('supermarket_admin.form.document_type'))
                            ->formatStateUsing(fn ($state) => $state ? __('supermarket_admin.enums.document_type.'.$state->value) : '—'),
                        TextEntry::make('verification_status')
                            ->label(__('supermarket_admin.form.verification_status'))
                            ->formatStateUsing(fn (?string $state) => $state ? __('supermarket_admin.enums.verification_status.'.$state) : '—')
                            ->badge(),
                        TextEntry::make('verified_at')->label(__('supermarket_admin.form.verified_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('rejection_reason')->label(__('supermarket_admin.form.rejection_reason'))->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}
