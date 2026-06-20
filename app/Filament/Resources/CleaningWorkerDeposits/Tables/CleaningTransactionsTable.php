<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Tables;

use App\Models\CleaningDepositTransaction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CleaningTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('cleaning_admin.transactions.fields.id'))
                    ->sortable(),
                TextColumn::make('worker.first_name')
                    ->label(__('cleaning_admin.transactions.fields.worker'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label(__('cleaning_admin.transactions.fields.type'))
                    ->badge()
                    ->color(fn (string $state): string => self::typeColor($state))
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state)),
                TextColumn::make('amount')
                    ->label(__('cleaning_admin.transactions.fields.amount'))
                    ->money('SYP')
                    ->sortable(),
                TextColumn::make('balance_after')
                    ->label(__('cleaning_admin.transactions.fields.balance_after'))
                    ->money('SYP'),
                TextColumn::make('created_at')
                    ->label(__('cleaning_admin.transactions.fields.date'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('notes')
                    ->label(__('cleaning_admin.transactions.fields.notes'))
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('createdByAdmin.name')
                    ->label(__('cleaning_admin.transactions.fields.created_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('worker_id')
                    ->label(__('cleaning_admin.transactions.fields.worker'))
                    ->relationship('worker', 'first_name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label(__('cleaning_admin.transactions.fields.type'))
                    ->options(self::typeOptions()),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('cleaning_admin.transactions.filters.from'))->native(false),
                        DatePicker::make('to')->label(__('cleaning_admin.transactions.filters.to'))->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['to'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'deposit' => self::typeLabel('deposit'),
            'settlement' => self::typeLabel('settlement'),
            'refund' => self::typeLabel('refund'),
            'adjustment' => self::typeLabel('adjustment'),
            'admin_fee' => self::typeLabel('admin_fee'),
            'withdrawal' => self::typeLabel('withdrawal'),
        ];
    }

    public static function typeLabel(string $type): string
    {
        $key = 'cleaning_admin.transactions.types.'.$type;
        $label = __($key);

        return $label === $key ? $type : $label;
    }

    public static function typeColor(string $type): string
    {
        return match ($type) {
            'deposit' => 'success',
            'settlement' => 'primary',
            'refund', 'withdrawal' => 'warning',
            'admin_fee' => 'danger',
            'adjustment' => 'gray',
            default => 'gray',
        };
    }

    /**
     * @return array<int, mixed>
     */
    public static function exportRows(): array
    {
        return CleaningDepositTransaction::query()
            ->with(['worker', 'createdByAdmin'])
            ->latest()
            ->get()
            ->map(fn (CleaningDepositTransaction $tx): array => [
                __('cleaning_admin.transactions.fields.id') => $tx->id,
                __('cleaning_admin.transactions.fields.worker') => $tx->worker?->first_name ?? '—',
                __('cleaning_admin.transactions.fields.type') => self::typeLabel((string) $tx->type),
                __('cleaning_admin.transactions.fields.amount') => (float) $tx->amount,
                __('cleaning_admin.transactions.fields.balance_after') => (float) $tx->balance_after,
                __('cleaning_admin.transactions.fields.date') => $tx->created_at?->format('Y-m-d H:i'),
                __('cleaning_admin.transactions.fields.notes') => $tx->notes,
                __('cleaning_admin.transactions.fields.created_by') => $tx->createdByAdmin?->name ?? '—',
            ])
            ->all();
    }
}
