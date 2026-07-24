<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Tables;

use App\Enums\UserModuleType;
use App\Filament\Support\AdminUiFormatter;
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
                TextColumn::make('id')->label(__('cleaning_admin.transactions.fields.id'))->sortable(),
                TextColumn::make('worker.first_name')
                    ->label(__('cleaning_admin.transactions.fields.worker'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('worker', function (Builder $workerQuery) use ($search): void {
                            $workerQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%"));
                        });
                    })
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label(__('cleaning_admin.transactions.fields.type'))
                    ->badge()
                    ->color(fn (CleaningDepositTransaction $record): string => self::typeColor($record->publicType()))
                    ->formatStateUsing(fn (CleaningDepositTransaction $record): string => self::typeLabel($record->publicType())),
                TextColumn::make('amount')->label(__('cleaning_admin.transactions.fields.amount'))->formatStateUsing(fn ($state): string => self::money($state))->sortable(),
                TextColumn::make('admin_revenue_withdrawn_amount')
                    ->label(app()->isLocale('ar') ? 'إيرادات الإدارة المسحوبة' : 'Withdrawn administration revenue')
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('balance_before')->label(app()->isLocale('ar') ? 'الإيداع قبل' : 'Deposit before')->formatStateUsing(fn ($state): string => self::money($state))->toggleable(),
                TextColumn::make('balance_after')->label(app()->isLocale('ar') ? 'الإيداع بعد' : 'Deposit after')->formatStateUsing(fn ($state): string => self::money($state)),
                TextColumn::make('debt_balance_before')->label(app()->isLocale('ar') ? 'المديونية قبل' : 'Indebtedness before')->formatStateUsing(fn ($state): string => self::money($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('debt_balance_after')->label(app()->isLocale('ar') ? 'المديونية بعد' : 'Indebtedness after')->formatStateUsing(fn ($state): string => self::money($state)),
                TextColumn::make('debt_settled_amount')->label(app()->isLocale('ar') ? 'الدين الإداري المسترد' : 'Administration loan recovered')->formatStateUsing(fn ($state): string => self::money($state))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('cleaning_admin.transactions.fields.date'))->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('notes')->label(__('cleaning_admin.transactions.fields.notes'))->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('createdByAdmin.name')->label(__('cleaning_admin.transactions.fields.created_by'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('worker_id')->label(__('cleaning_admin.transactions.fields.worker'))->options(fn (): array => self::workerOptions())->searchable()->preload(),
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['to'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

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
        return match ($type) {
            'debt', 'settlement' => __('cleaning_finance.types.debt'),
            'deposit' => __('cleaning_admin.transactions.types.deposit'),
            'refund' => __('cleaning_admin.transactions.types.refund'),
            default => $type,
        };
    }

    public static function referenceLabel(?string $reference): string
    {
        if ($reference === null || $reference === '') {
            return '—';
        }
        if (
            str_starts_with($reference, CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX)
            || str_starts_with($reference, CleaningDepositTransaction::LEGACY_AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX)
            || preg_match('/^admin_fee_booking_\d+$/', $reference) === 1
        ) {
            return app()->isLocale('ar') ? 'استحقاق إدارة تلقائي' : 'Automatic administration due';
        }
        if (str_starts_with($reference, 'cleaning_cancellation_penalty:')) {
            return app()->isLocale('ar') ? 'غرامة إلغاء طلب تنظيف' : 'Cleaning cancellation penalty';
        }
        if ($reference === 'admin_full_account_refund') {
            return app()->isLocale('ar') ? 'إغلاق الحساب المالي بالكامل' : 'Full financial account closure';
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
            'debt', 'settlement' => 'warning',
            'refund' => 'warning',
            default => 'gray',
        };
    }

    public static function exportRows(Builder $query): array
    {
        return $query->with(['worker', 'createdByAdmin'])->get()->map(fn (CleaningDepositTransaction $tx): array => [
            __('cleaning_admin.transactions.fields.id') => $tx->id,
            __('cleaning_admin.transactions.fields.worker') => $tx->worker?->first_name ?? '—',
            __('cleaning_admin.transactions.fields.type') => self::typeLabel($tx->publicType()),
            __('cleaning_admin.transactions.fields.amount') => (int) round($tx->publicAmount()),
            app()->isLocale('ar') ? 'إيرادات الإدارة المسحوبة' : 'Withdrawn administration revenue' => (int) round((float) ($tx->admin_revenue_withdrawn_amount ?? 0)),
            app()->isLocale('ar') ? 'الإيداع قبل' : 'Deposit before' => (int) round((float) $tx->balance_before),
            app()->isLocale('ar') ? 'الإيداع بعد' : 'Deposit after' => (int) round((float) $tx->balance_after),
            app()->isLocale('ar') ? 'المديونية قبل' : 'Indebtedness before' => (int) round((float) ($tx->debt_balance_before ?? 0)),
            app()->isLocale('ar') ? 'المديونية بعد' : 'Indebtedness after' => (int) round((float) ($tx->debt_balance_after ?? 0)),
            app()->isLocale('ar') ? 'الدين الإداري المسترد' : 'Administration loan recovered' => (int) round((float) ($tx->debt_settled_amount ?? 0)),
            __('cleaning_admin.transactions.fields.date') => $tx->created_at?->format('Y-m-d H:i'),
            __('cleaning_admin.transactions.fields.notes') => $tx->notes,
            __('cleaning_admin.transactions.fields.created_by') => $tx->createdByAdmin?->name ?? '—',
        ])->all();
    }

    private static function money(mixed $value): string
    {
        return AdminUiFormatter::formatCurrency((float) ($value ?? 0), 0);
    }

    private static function workerOptions(): array
    {
        return Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('module_type', UserModuleType::CleaningWorker))
            ->orderBy('first_name')
            ->get(['id', 'first_name'])
            ->mapWithKeys(fn (Worker $worker): array => [$worker->id => ($worker->first_name ?: '#'.$worker->id).' (#'.$worker->id.')'])
            ->all();
    }
}
