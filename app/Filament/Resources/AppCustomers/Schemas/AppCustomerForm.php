<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppCustomers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class AppCustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('رقم الهاتف')
                    ->tel()
                    ->maxLength(32),
            ]);
    }
}
