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
use Carbon\Carbon;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

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
        $schema = WorkerInfolist::configure($schema);

        foreach (['home_latitude', 'home_longitude', 'default_working_hours'] as $componentName) {
            $schema->getComponent($componentName)?->hidden();
        }

        $existingComponents = $schema->getComponents(withHidden: true, withOriginalKeys: true);

        return $schema->components([
            ...$existingComponents,
            Section::make('الموقع وأوقات العمل')
                ->description('تعرض هذه البيانات نفس الموقع وساعات العمل التي يعيدها API إلى تطبيق العامل.')
                ->schema([
                    ViewEntry::make('worker_location_map')
                        ->label('موقع العامل على OpenStreetMap')
                        ->getStateUsing(fn (Worker $record): array => self::locationMapState($record))
                        ->view('filament.resources.workers.infolists.location-map')
                        ->columnSpanFull(),
                    ViewEntry::make('worker_working_hours')
                        ->label(__('cleaning_admin.workers.fields.default_working_hours'))
                        ->getStateUsing(fn (Worker $record): array => self::workingHoursState($record))
                        ->view('filament.resources.workers.infolists.working-hours')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
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

    /**
     * @return array{address:?string,latitude:?float,longitude:?float,hasCoordinates:bool}
     */
    private static function locationMapState(Worker $worker): array
    {
        $latitude = $worker->home_latitude !== null ? (float) $worker->home_latitude : null;
        $longitude = $worker->home_longitude !== null ? (float) $worker->home_longitude : null;

        return [
            'address' => $worker->home_address,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hasCoordinates' => $latitude !== null && $longitude !== null,
        ];
    }

    /**
     * @return array<int, array{key:string,label:string,available:bool,ranges:array<int, array{from:string,to:string,label:string}>}>
     */
    private static function workingHoursState(Worker $worker): array
    {
        $normalized = $worker->getNormalizedDefaultWorkingHours();
        $dayLabels = [
            'sunday' => 'الأحد',
            'monday' => 'الإثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
            'saturday' => 'السبت',
        ];

        $days = [];
        foreach ($dayLabels as $day => $label) {
            $dayData = $normalized[$day] ?? ['available' => false, 'data' => []];
            $ranges = [];

            foreach ((array) ($dayData['data'] ?? []) as $period) {
                if (! is_array($period)) {
                    continue;
                }

                $from = isset($period['from']) && is_string($period['from'])
                    ? $period['from']
                    : array_key_first($period);
                $to = isset($period['to']) && is_string($period['to'])
                    ? $period['to']
                    : (is_string($from) ? ($period[$from] ?? null) : null);

                if (! is_string($from) || ! is_string($to)) {
                    continue;
                }

                $ranges[] = [
                    'from' => $from,
                    'to' => $to,
                    'label' => self::formatWorkingTime($from).' — '.self::formatWorkingTime($to),
                ];
            }

            $days[] = [
                'key' => $day,
                'label' => $label,
                'available' => (bool) ($dayData['available'] ?? false) && $ranges !== [],
                'ranges' => $ranges,
            ];
        }

        return $days;
    }

    private static function formatWorkingTime(string $time): string
    {
        foreach (['H:i', 'H:i:s'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $time);

                return str_replace(['AM', 'PM'], ['ص', 'م'], $parsed->format('h:i A'));
            } catch (Throwable) {
                continue;
            }
        }

        return $time;
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
