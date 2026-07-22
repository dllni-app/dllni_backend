<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'العناوين';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('التسمية')
                    ->placeholder('—'),
                TextColumn::make('city')
                    ->label('المدينة')
                    ->placeholder('—'),
                TextColumn::make('neighborhood')
                    ->label('الحي')
                    ->placeholder('—'),
                TextColumn::make('street')
                    ->label('الشارع')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('mobile')
                    ->label('الجوال')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_default')
                    ->label('افتراضي')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
