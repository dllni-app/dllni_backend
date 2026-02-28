<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules;

use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages\CreateRestaurantAutomationRule;
use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages\EditRestaurantAutomationRule;
use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages\ListRestaurantAutomationRules;
use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Pages\ViewRestaurantAutomationRule;
use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Schemas\RestaurantAutomationRuleForm;
use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Schemas\RestaurantAutomationRuleInfolist;
use App\Filament\CleaningAdmin\Resources\RestaurantAutomationRules\Tables\RestaurantAutomationRulesTable;
use App\Models\RestaurantAutomationRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class RestaurantAutomationRuleResource extends Resource
{
    protected static ?string $model = RestaurantAutomationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'قواعد أتمتة المطاعم';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة قواعد الأتمتة مثل التعليق التلقائي ومنح الشارة المميزة.';
    }

    public static function form(Schema $schema): Schema
    {
        return RestaurantAutomationRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RestaurantAutomationRuleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantAutomationRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurantAutomationRules::route('/'),
            'create' => CreateRestaurantAutomationRule::route('/create'),
            'view' => ViewRestaurantAutomationRule::route('/{record}'),
            'edit' => EditRestaurantAutomationRule::route('/{record}/edit'),
        ];
    }
}
