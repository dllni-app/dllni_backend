<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\RelationManagers;

use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Resturants\Models\RestaurantRole;

final class RestaurantStaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staff';

    protected static ?string $title = 'فريق المطعم';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('المستخدم')->searchable(),
                TextColumn::make('user.email')->label('البريد')->searchable(),
                TextColumn::make('role.name')->label('الدور')->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->form([
                        Select::make('user_id')
                            ->label('المستخدم')
                            ->options(User::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Select::make('restaurant_role_id')
                            ->label('الدور')
                            ->options(fn () => RestaurantRole::query()->where('restaurant_id', $this->getOwnerRecord()->getKey())->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['restaurant_id'] = $this->getOwnerRecord()->getKey();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        Select::make('restaurant_role_id')
                            ->label('الدور')
                            ->options(fn () => RestaurantRole::query()->where('restaurant_id', $this->getOwnerRecord()->getKey())->pluck('name', 'id'))
                            ->required(),
                    ]),
            ])
            ->defaultSort('user_id');
    }
}
