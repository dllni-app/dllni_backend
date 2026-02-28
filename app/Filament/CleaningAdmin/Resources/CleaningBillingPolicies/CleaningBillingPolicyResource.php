<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies;

use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages\CreateCleaningBillingPolicy;
use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages\EditCleaningBillingPolicy;
use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages\ListCleaningBillingPolicies;
use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Pages\ViewCleaningBillingPolicy;
use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Schemas\CleaningBillingPolicyForm;
use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Schemas\CleaningBillingPolicyInfolist;
use App\Filament\CleaningAdmin\Resources\CleaningBillingPolicies\Tables\CleaningBillingPoliciesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use UnitEnum;

final class CleaningBillingPolicyResource extends Resource
{
    protected static ?string $model = CleaningBillingPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'سياسات الفوترة';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 13;

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة سياسات الفوترة: الاسم، الوصف، طريقة الفوترة (وقت محجوز كامل / وقت عمل فعلي)، الحد الأدنى للدقائق، افتراضي.';
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningBillingPolicyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningBillingPolicyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningBillingPoliciesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBillingPolicies::route('/'),
            'create' => CreateCleaningBillingPolicy::route('/create'),
            'view' => ViewCleaningBillingPolicy::route('/{record}'),
            'edit' => EditCleaningBillingPolicy::route('/{record}/edit'),
        ];
    }
}
