<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Tables;

use App\Enums\UserModuleType;
use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
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
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('worker', function (Builder $workerQuery) use ($search): void {
                            $workerQuery
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%"));
                        });
                    })
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label(__('cleaning_admin.transactions.fields.type'))
                    ->badge()
                    ->color(fn (CleaningDepositTransaction $record): string => self::typeColor($record->publicType()))
                    ->formatStateUsing(fn (CleaningDepositTransaction $record): string => self::typeLabel($record->publicType())),
                TextColumn::make('amount')
                    ->label(__('cleaning_admin.transactions.fields.amount'))
                    ->money('SYP')
                    ->sortable(),
                TextColumn::make('debt_settled_amount')
                    ->label(__('cleaning_finance.fields.debt_settled_amount'))
                    ->money('SYP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance_before')
                    ->label(__('cleaning_admin.transactions.fields.balance_before'))
                    ->money('SYP')
                    ->toggleable(),
                TextColumn::make('balance_after')
                    ->label(__('cleaning_admin.transactions.fields.balance_after'))
                    ->money('SYP'),
                TextColumn::make('reference')
                    ->label(__('cleaning_admin.transactions.fields.reference'))
                    ->formatStateUsing(fn (?string $state): string => self::referenceLabel($state))
                    ->placeholder('—')
                    ->toggleable(),
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
                    ->options(fn (): array => self::workerOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label(__('cleaning_admin.transactions.fields.type'))
                    ->options(self::typeOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $type = $data['value'] ?? null;

                        if (! is_string($type) || ! in_array($type, CleaningDepositTransaction::PUBLIC_TYPES, true)) {
                            return $query;
                        }

                        return $query->forPublicType($type);
                    }),
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
        $options = [];

        foreach (CleaningDepositTransaction::PUBLIC_TYPES as $type) {
            $options[$type] = self::typeLabel($type);
        }

        return $options;
    }

    public static function typeLabel(string $type): string
    {
        if ($type === 'debt') {
            return __('cleaning_finance.types.debt');
        }

        $key = 'cleaning_admin.transactions.types.'.$type;
        $label = __($key);

        return $label === $key ? $type : $label;
    }

    public static function referenceLabel(?string $reference): string
    {
        if ($reference === null || $reference === '') {
            return '—';
        }

        if (
            str_starts_with($reference, 'automatic_admin_commission:')
            || preg_match('/^admin_fee_booking_\d+$/', $reference) === 1
        ) {
            return __('cleaning_finance.references.automatic_admin_commission');
        }

        $financeKey = 'cleaning_finance.references.'.$reference;
        $financeLabel = __($financeKey);

        if ($financeLabel !== $financeKey) {
            return $financeLabel;
        }

        $key = 'cleaning_admin.transactions.references.'.$reference;
        $label = __($key);

        return $label === $key ? $reference : $label;
    }

    public static function typeColor(string $type): string
    {
        return match ($type) {
            'deposit' => 'success',
            'settlement' => 'primary',
            'debt' => 'danger',
            'refund' => 'warning',
            default => 'gray',
        };
    }

    /**
     * @return array<int, mixed>
     */
    public static function exportRows(Builder $query): array
    {
        return $query
            ->with(['worker', 'createdByAdmin'])
            ->get()
            ->map(fn (CleaningDepositTransaction $tx): array => [
                __('cleaning_admin.transactions.fields.id') => $tx->id,
                __('cleaning_admin.transactions.fields.worker') => $tx->worker?->first_name ?? '—',
                __('cleaning_admin.transactions.fields.type') => self::typeLabel($tx->publicType()),
                __('cleaning_admin.transactions.fields.amount') => $tx->publicAmount(),
                __('cleaning_finance.fields.debt_settled_amount') => (float) ($tx->debt_settled_amount ?? 0),
                __('cleaning_admin.transactions.fields.balance_before') => (float) $tx->balance_before,
                __('cleaning_admin.transactions.fields.balance_after') => (float) $tx->balance_after,
                __('cleaning_admin.transactions.fields.reference') => self::referenceLabel($tx->reference),
                __('cleaning_admin.transactions.fields.date') => $tx->created_at?->format('Y-m-d H:i'),
                __('cleaning_admin.transactions.fields.notes') => $tx->notes,
                __('cleaning_admin.transactions.fields.created_by') => $tx->createdByAdmin?->name ?? '—',
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function workerOptions(): array
    {
        return Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('module_type', UserModuleType::CleaningWorker))
            ->orderBy('first_name')
            ->get(['id', 'first_name'])
            ->mapWithKeys(fn (Worker $worker): array => [
                $worker->id => ($worker->first_name ?: '#'.$worker->id).' (#'.$worker->id.')',
            ])
            ->all();
    }
}
