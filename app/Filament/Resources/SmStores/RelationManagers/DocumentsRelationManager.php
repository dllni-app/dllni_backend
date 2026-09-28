<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\RelationManagers;

use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'وثائق المتجر';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can('supermarket_stores.view'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')
                    ->label('نوع الوثيقة')
                    ->formatStateUsing(fn ($state): string => $state?->value ?? (string) $state)
                    ->badge(),
                TextColumn::make('verification_status')->label('حالة التحقق')->badge(),
                TextColumn::make('verified_at')->label('تم التحقق في')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('expires_at')
                    ->label('تنتهي في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('بدون انتهاء')
                    ->color(fn ($state): string => $state !== null && now()->greaterThan($state) ? 'danger' : 'gray'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('فتح')
                    ->url(fn ($record): string => SmStoreDocumentResource::getUrl('view', ['record' => $record])),
                Action::make('review')
                    ->label('مراجعة')
                    ->visible(function (): bool {
                        $user = auth()->user();

                        return $user !== null
                            && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can('supermarket_stores.update'));
                    })
                    ->url(fn ($record): string => SmStoreDocumentResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('expires_at');
    }
}
