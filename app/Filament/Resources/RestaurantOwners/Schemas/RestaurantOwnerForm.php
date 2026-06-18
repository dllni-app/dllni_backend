<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOwners\Schemas;

use App\Enums\UserModuleType;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class RestaurantOwnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('الاسم')->required(),
                TextInput::make('email')->label('البريد الإلكتروني')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('phone')->label('رقم الهاتف'),
                Hidden::make('module_type')->default(UserModuleType::RestaurantSeller->value)->dehydrated(true),
                TextInput::make('password')
                    ->label('كلمة المرور')
                    ->password()
                    ->dehydrateStateUsing(fn (?string $state): ?string => blank($state) ? null : $state)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),
            ]);
    }
}

