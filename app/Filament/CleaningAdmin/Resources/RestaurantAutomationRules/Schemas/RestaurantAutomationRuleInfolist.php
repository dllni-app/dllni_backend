<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class RestaurantAutomationRuleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('القاعدة')
                    ->schema([
                        TextEntry::make('name')->label('اسم القاعدة'),
                        TextEntry::make('type')
                            ->label('النوع')
                            ->formatStateUsing(fn (?string $state): string => __('restaurant_admin.enums.automation_type.'.($state ?? 'suspend'))),
                        TextEntry::make('is_active')
                            ->label('نشطة')
                            ->formatStateUsing(fn (bool $state): string => $state ? __('restaurant_admin.enums.boolean.yes') : __('restaurant_admin.enums.boolean.no')),
                    ])
                    ->columns(3),
            ]);
    }
}
