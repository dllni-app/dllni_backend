<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits;

use App\Models\CleaningWorkerDeposit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class CleaningWorkerDepositsResource extends Resource
{
    protected static ?string $model = CleaningWorkerDeposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات';

    protected static ?int $navigationSort = 51;

    protected static ?string $navigationLabel = 'إدارة التأمينات';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('worker.user.name')->label('اسم العامل')->searchable(),
                TextColumn::make('current_balance')->label('الرصيد الحالي')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
