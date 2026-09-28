<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners\RelationManagers;

use App\Filament\Resources\SmStores\SmStoreResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class StoresRelationManager extends RelationManager
{
    protected static string $relationship = 'smStores';

    protected static ?string $title = 'المتاجر المرتبطة';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('اسم المتجر')->searchable(),
                TextColumn::make('city')->label('المدينة')->placeholder('—'),
                TextColumn::make('trust_score')->label('نقاط الثقة')->suffix(' / 100'),
                TextColumn::make('staff_count')->label('الموظفون')->counts('staff'),
                IconColumn::make('is_active')->label('فعال')->boolean(),
                TextColumn::make('suspension_until')->label('التعليق حتى')->dateTime('Y-m-d H:i')->placeholder('غير معلق'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('فتح المتجر')
                    ->url(fn ($record): string => SmStoreResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('name');
    }
}
