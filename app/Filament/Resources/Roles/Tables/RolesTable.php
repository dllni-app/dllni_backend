<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('اسم الدور')->searchable(),
                TextColumn::make('guard_name')->label('نطاق الصلاحية'),
                TextColumn::make('permissions_count')
                    ->label('عدد الصلاحيات')
                    ->counts('permissions'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->since(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
