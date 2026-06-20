<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits;

use App\Filament\Resources\CleaningWorkerDeposits\Pages\ListCleaningWorkerDeposits;
use App\Filament\Resources\CleaningWorkerDeposits\Tables\CleaningTransactionsTable;
use App\Models\CleaningDepositTransaction;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class CleaningWorkerDepositsResource extends Resource
{
    protected static ?string $model = CleaningDepositTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 52;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.transactions.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.transactions.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.transactions.plural');
    }

    public static function table(Table $table): Table
    {
        return CleaningTransactionsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('cleaning_admin.transactions.model'))
                ->columns(2)
                ->schema([
                    TextEntry::make('id')->label(__('cleaning_admin.transactions.fields.id')),
                    TextEntry::make('worker.first_name')->label(__('cleaning_admin.transactions.fields.worker'))->placeholder('—'),
                    TextEntry::make('type')->label(__('cleaning_admin.transactions.fields.type'))
                        ->badge()
                        ->color(fn (string $state): string => CleaningTransactionsTable::typeColor($state))
                        ->formatStateUsing(fn (string $state): string => CleaningTransactionsTable::typeLabel($state)),
                    TextEntry::make('amount')->label(__('cleaning_admin.transactions.fields.amount'))->money('SYP'),
                    TextEntry::make('balance_before')->label(__('cleaning_admin.transactions.fields.balance_before'))->money('SYP'),
                    TextEntry::make('balance_after')->label(__('cleaning_admin.transactions.fields.balance_after'))->money('SYP'),
                    TextEntry::make('reference')->label(__('cleaning_admin.transactions.fields.reference'))->placeholder('—'),
                    TextEntry::make('createdByAdmin.name')->label(__('cleaning_admin.transactions.fields.created_by'))->placeholder('—'),
                    TextEntry::make('notes')->label(__('cleaning_admin.transactions.fields.notes'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('created_at')->label(__('cleaning_admin.transactions.fields.date'))->dateTime('Y-m-d H:i'),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['worker', 'createdByAdmin']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningWorkerDeposits::route('/'),
        ];
    }
}
