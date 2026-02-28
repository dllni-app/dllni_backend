<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Supermarket\Enums\SmDocumentType;

final class SmStoreDocumentsTable
{
    public static function configure(Table $table): Table
    {
        $documentTypeOptions = collect(SmDocumentType::cases())->mapWithKeys(
            fn (SmDocumentType $c) => [$c->value => __('supermarket_admin.enums.document_type.'.$c->value)]
        )->all();

        $verificationOptions = [
            'pending' => __('supermarket_admin.enums.verification_status.pending'),
            'approved' => __('supermarket_admin.enums.verification_status.approved'),
            'rejected' => __('supermarket_admin.enums.verification_status.rejected'),
        ];

        return $table
            ->columns([
                TextColumn::make('store.name')->label(__('supermarket_admin.infolist.name'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('document_type')
                    ->label(__('supermarket_admin.form.document_type'))
                    ->formatStateUsing(fn ($state) => $state ? __('supermarket_admin.enums.document_type.'.$state->value) : '—')
                    ->sortable(),
                TextColumn::make('verification_status')
                    ->label(__('supermarket_admin.form.verification_status'))
                    ->formatStateUsing(fn (?string $state) => $state ? __('supermarket_admin.enums.verification_status.'.$state) : '—')
                    ->badge()
                    ->sortable(),
                TextColumn::make('verified_at')->label(__('supermarket_admin.form.verified_at'))->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('store'))
            ->filters([
                SelectFilter::make('store_id')
                    ->relationship('store', 'name')
                    ->label(__('supermarket_admin.stores'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('document_type')->label(__('supermarket_admin.form.document_type'))->options($documentTypeOptions),
                SelectFilter::make('verification_status')->label(__('supermarket_admin.form.verification_status'))->options($verificationOptions),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
