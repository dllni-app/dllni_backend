<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers;

use App\Filament\Resources\Workers\Pages\CreateWorker;
use App\Filament\Resources\Workers\Pages\EditWorker;
use App\Filament\Resources\Workers\Pages\ListWorkers;
use App\Filament\Resources\Workers\Pages\ViewWorker;
use App\Filament\Resources\Workers\RelationManagers\CustomerRatingsRelationManager;
use App\Filament\Resources\Workers\RelationManagers\CustomerReviewsRelationManager;
use App\Filament\Resources\Workers\RelationManagers\WorkerAvailabilityRelationManager;
use App\Filament\Resources\Workers\Schemas\WorkerForm;
use App\Filament\Resources\Workers\Schemas\WorkerInfolist;
use App\Filament\Resources\Workers\Tables\WorkersTable;
use App\Models\Worker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class WorkerResource extends Resource
{
    protected static ?string $model = Worker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.workers.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.workers.tooltip');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.workers.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.workers.plural');
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'zones', 'trustLogs', 'deposit']);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('workers.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('workers.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('workers.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('workers.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('workers.delete');
    }

    public static function getRelations(): array
    {
        return [
            CustomerReviewsRelationManager::class,
            CustomerRatingsRelationManager::class,
            WorkerAvailabilityRelationManager::class,
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

    private static function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can($permission);
    }
}
