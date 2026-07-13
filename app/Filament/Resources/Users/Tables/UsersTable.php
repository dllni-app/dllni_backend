<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Support\ArabicDashboardLabels;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable(),
                TextColumn::make('role_name')
                    ->label('الدور')
                    ->badge()
                    ->state(fn (User $record): string => ArabicDashboardLabels::roleName($record->roles->first()?->name))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'roles',
                        fn (Builder $roleQuery): Builder => $roleQuery->where('name', 'like', "%{$search}%"),
                    )),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->since()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('roles'))
            ->recordActions([
                ViewAction::make()->label('عرض'),
                EditAction::make()->label('تعديل'),
            ]);
    }
}
