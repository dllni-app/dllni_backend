<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\Pages\CreateCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\EditCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\ListCleaningWorkers;
use App\Filament\Resources\CleaningWorkers\Pages\ViewCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Schemas\CleaningWorkerInfolist;
use App\Filament\Resources\CleaningWorkers\Tables\CleaningWorkersTable;
use App\Filament\Resources\Workers\Schemas\WorkerForm;
use App\Models\Worker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CleaningWorkerResource extends Resource
{
    protected static ?string $model = Worker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 32;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.workers.nav_label');
    }

    public static function form(Schema $schema): Schema
    {
        return WorkerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningWorkerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningWorkersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user', 'zones', 'trustLogs', 'deposit'])
            ->whereHas('user', fn (Builder $query): Builder => $query->where('module_type', UserModuleType::CleaningWorker));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningWorkers::route('/'),
            'create' => CreateCleaningWorker::route('/create'),
            'view' => ViewCleaningWorker::route('/{record}'),
            'edit' => EditCleaningWorker::route('/{record}/edit'),
        ];
    }
}
