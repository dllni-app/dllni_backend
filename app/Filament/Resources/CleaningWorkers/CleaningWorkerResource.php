<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\Pages\CreateCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\EditCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\ListCleaningWorkers;
use App\Filament\Resources\CleaningWorkers\Pages\ViewCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Schemas\CleaningWorkerForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CleaningWorkerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 32;

    public static function getNavigationGroup(): ?string
    {
        return 'Cleaning Management';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cleaning Workers';
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningWorkerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('module_type', UserModuleType::CleaningWorker);
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

