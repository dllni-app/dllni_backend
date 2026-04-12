<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCoupons\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class SmCouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')->label(__('supermarket_admin.form.code'))->required()->maxLength(255)->disabled(),
                Toggle::make('is_active')->label(__('supermarket_admin.form.is_active'))->default(true),
                DateTimePicker::make('starts_at')->label(__('supermarket_admin.form.starts_at'))->nullable(),
                DateTimePicker::make('ends_at')
                    ->label(__('supermarket_admin.form.ends_at'))
                    ->nullable()
                    ->rules(['nullable', 'date', 'after_or_equal:starts_at']),
            ]);
    }
}
