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
                TextColumn::make('cleaning_booking_id')
                    ->label('Booking')
                    ->formatStateUsing(fn ($state): string => $state ? '#'.$state : '—')
                    ->placeholder('—')
                    ->toggleable(),
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
                    ->relationship('worker', 'first_name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label(__('cleaning_admin.transactions.fields.type'))
                    ->options(self::typeOptions()),
                Filter::make('has_booking')
                    ->label('Linked to booking')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('cleaning_booking_id')),
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
            'debt' => self::typeLabel('debt'),
            'settlement' => self::typeLabel('settlement'),
            'refund' => self::typeLabel('refund'),
            'adjustment' => self::typeLabel('adjustment'),
            'admin_fee' => self::typeLabel('admin_fee'),
            'withdrawal' => self::typeLabel('withdrawal'),
        ];
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

        if (preg_match('/^admin_fee_booking_(\d+)$/', $reference, $matches) === 1) {
            return __('cleaning_admin.transactions.references.admin_fee_booking', ['id' => $matches[1]]);
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
            'debt' => 'warning',
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
                __('cleaning_finance.fields.debt_settled_amount') => (float) ($tx->debt_settled_amount ?? 0),
                __('cleaning_admin.transactions.fields.balance_before') => (float) $tx->balance_before,
                __('cleaning_admin.transactions.fields.balance_after') => (float) $tx->balance_after,
                'Booking' => $tx->cleaning_booking_id,
                __('cleaning_admin.transactions.fields.reference') => self::referenceLabel($tx->reference),
                __('cleaning_admin.transactions.fields.date') => $tx->created_at?->format('Y-m-d H:i'),
                __('cleaning_admin.transactions.fields.notes') => $tx->notes,
                __('cleaning_admin.transactions.fields.created_by') => $tx->createdByAdmin?->name ?? '—',
            ])
            ->all();
    }
}
