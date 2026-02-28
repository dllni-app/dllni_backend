<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('الاسم')->required(),
                TextInput::make('email')->label('البريد')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('phone')->label('الهاتف'),
                Select::make('role_id')
                    ->label('الدور')
                    ->options(
                        Role::where('guard_name', 'web')->orderBy('name')->pluck('name', 'id')
                    )
                    ->searchable()
                    ->dehydrated(true),
                TextInput::make('password')
                    ->label('كلمة المرور')
                    ->password()
                    ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : $state)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),
            ]);
    }
}
