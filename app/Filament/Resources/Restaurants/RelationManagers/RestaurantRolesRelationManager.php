<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\RelationManagers;

use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class RestaurantRolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static ?string $title = 'أدوار المطعم';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('slug')->label('المعرّف')->searchable(),
                TextColumn::make('staff_count')
                    ->label('عدد الموظفين')
                    ->getStateUsing(fn (Model $record) => $record->staff()->count()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->form([
                        TextInput::make('name')->label('الاسم')->required()->maxLength(255),
                        TextInput::make('slug')->label('المعرّف')->maxLength(255)->required(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['restaurant_id'] = $this->getOwnerRecord()->getKey();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        TextInput::make('name')->label('الاسم')->required()->maxLength(255),
                        TextInput::make('slug')->label('المعرّف')->maxLength(255)->required(),
                    ]),
            ])
            ->defaultSort('name');
    }
}
