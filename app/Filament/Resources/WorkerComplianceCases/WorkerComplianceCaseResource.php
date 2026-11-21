<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkerComplianceCases;

use App\Filament\Resources\WorkerComplianceCases\Pages\ListWorkerComplianceCases;
use App\Filament\Resources\WorkerComplianceCases\Pages\ViewWorkerComplianceCase;
use App\Filament\Resources\WorkerComplianceCases\Schemas\WorkerComplianceCaseInfolist;
use App\Filament\Resources\WorkerComplianceCases\Tables\WorkerComplianceCasesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\WorkerComplianceReviewCase;

final class WorkerComplianceCaseResource extends Resource
{
    protected static ?string $model = WorkerComplianceReviewCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 22;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('worker_compliance.nav');
    }

    public static function getModelLabel(): string
    {
        return __('worker_compliance.case');
    }

    public static function getPluralModelLabel(): string
    {
        return __('worker_compliance.cases');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', WorkerComplianceReviewCase::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof WorkerComplianceReviewCase
            && (auth()->user()?->can('view', $record) ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkerComplianceCaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkerComplianceCasesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('worker.user', static fn (Builder $query): Builder => $query->where('module_type', 'cleaning_worker'))
            ->with(['worker.user', 'events.actor'])
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END")
            ->orderBy('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkerComplianceCases::route('/'),
            'view' => ViewWorkerComplianceCase::route('/{record}'),
        ];
    }
}
