<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Workers;

use App\Filament\CleaningAdmin\Resources\Workers\Pages\CreateWorker;
use App\Filament\CleaningAdmin\Resources\Workers\Pages\EditWorker;
use App\Filament\CleaningAdmin\Resources\Workers\Pages\ListWorkers;
use App\Filament\CleaningAdmin\Resources\Workers\Pages\ViewWorker;
use App\Filament\CleaningAdmin\Resources\Workers\RelationManagers\CustomerRatingsRelationManager;
use App\Filament\CleaningAdmin\Resources\Workers\RelationManagers\CustomerReviewsRelationManager;
use App\Filament\CleaningAdmin\Resources\Workers\Schemas\WorkerForm;
use App\Filament\CleaningAdmin\Resources\Workers\Schemas\WorkerInfolist;
use App\Filament\CleaningAdmin\Resources\Workers\Tables\WorkersTable;
use App\Models\Worker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class WorkerResource extends Resource
{
    protected static ?string $model = Worker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'العمّال';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 4;

    public static function getNavigationTooltip(): ?string
    {
        return 'قائمة مقدمي الخدمة: الاسم، الصورة، نقاط الثقة، المهام المكتملة، متوسط التقييم، الحالة؛ عرض الملف وتعليق الحساب.';
    }

    public static function getModelLabel(): string
    {
        return 'عامل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'العمال';
    }

    public static function form(Schema $schema): Schema
    {
        return WorkerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CustomerReviewsRelationManager::class,
            CustomerRatingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkers::route('/'),
            'create' => CreateWorker::route('/create'),
            'view' => ViewWorker::route('/{record}'),
            'edit' => EditWorker::route('/{record}/edit'),
        ];
    }
}
