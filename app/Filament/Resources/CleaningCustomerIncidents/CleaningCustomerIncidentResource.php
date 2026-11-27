<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningCustomerIncidents;

use App\Filament\Clusters\CleaningOperationsCluster;
use App\Filament\Resources\CleaningCustomerIncidents\Pages\ListCleaningCustomerIncidents;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningCustomerIncident;
use Modules\Cleaning\Services\CleaningCustomerIncidentService;

final class CleaningCustomerIncidentResource extends Resource
{
    protected static ?string $model = CleaningCustomerIncident::class;
    protected static ?string $cluster = CleaningOperationsCluster::class;

    public static function getNavigationLabel(): string { return 'بلاغات العملاء'; }
    public static function getModelLabel(): string { return 'بلاغ عميل'; }
    public static function getPluralModelLabel(): string { return 'بلاغات العملاء'; }
    public static function canAccess(): bool { return static::canViewAny(); }
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'Super Admin']) ?? false;
    }
    public static function canCreate(): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('booking_id')->label('رقم الطلب')->searchable()->sortable(),
            TextColumn::make('visit_key')->label('رقم الزيارة')->formatStateUsing(fn ($state) => $state ?: 'طلب مفرد'),
            TextColumn::make('customer_id')->label('رقم العميل')->searchable(),
            TextColumn::make('reporter_user_id')->label('رقم المبلّغ')->searchable(),
            TextColumn::make('reporter_role')->label('صفة المبلّغ')->formatStateUsing(fn ($state) => $state === 'worker' ? 'عامل' : 'مشرف'),
            TextColumn::make('categories')->label('المشاكل')->formatStateUsing(fn ($state) => CleaningCustomerIncidentService::CATEGORIES[$state] ?? $state)->badge(),
            TextColumn::make('description')->label('شرح المشكلة')->wrap(),
            TextColumn::make('created_at')->label('تاريخ البلاغ')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningCustomerIncidents::route('/')];
    }
}
