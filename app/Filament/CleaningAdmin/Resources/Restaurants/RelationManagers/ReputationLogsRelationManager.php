<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Restaurants\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ReputationLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'reputationLogs';

    protected static ?string $title = 'سجل تغيّر النقاط';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('score_delta')->label('التغير')->badge(),
                TextColumn::make('reason')->label('السبب')->limit(80),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('التاريخ'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
