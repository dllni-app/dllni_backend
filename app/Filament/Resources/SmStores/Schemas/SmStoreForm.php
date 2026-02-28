<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class SmStoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('is_active')->label(__('supermarket_admin.form.is_active'))->default(true),
                Toggle::make('is_featured')->label(__('supermarket_admin.form.is_featured'))->default(false),
                DateTimePicker::make('suspension_until')->label(__('supermarket_admin.form.suspension_until'))->nullable(),
            ]);
    }
}
