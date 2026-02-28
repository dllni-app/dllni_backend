<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningAutomationRules;

use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Pages\CreateCleaningAutomationRule;
use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Pages\EditCleaningAutomationRule;
use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Pages\ListCleaningAutomationRules;
use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Pages\ViewCleaningAutomationRule;
use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Schemas\CleaningAutomationRuleForm;
use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Schemas\CleaningAutomationRuleInfolist;
use App\Filament\CleaningAdmin\Resources\CleaningAutomationRules\Tables\CleaningAutomationRulesTable;
use App\Models\CleaningAutomationRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CleaningAutomationRuleResource extends Resource
{
    protected static ?string $model = CleaningAutomationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'قواعد الأتمتة';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 14;

    public static function getNavigationTooltip(): ?string
    {
        return 'قواعد أتمتة الخدمة: تعليق تلقائي حسب نقاط الثقة، منح شارة أو تخفيض عمولة للمتميزين، الشروط والإجراءات.';
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningAutomationRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningAutomationRuleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningAutomationRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningAutomationRules::route('/'),
            'create' => CreateCleaningAutomationRule::route('/create'),
            'view' => ViewCleaningAutomationRule::route('/{record}'),
            'edit' => EditCleaningAutomationRule::route('/{record}/edit'),
        ];
    }
}
