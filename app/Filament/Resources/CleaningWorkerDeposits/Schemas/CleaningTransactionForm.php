<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Schemas;

use App\Enums\UserModuleType;
use App\Models\Worker;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Throwable;

final class CleaningTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('cleaning_finance_guidance.form.worker_section'))
                    ->description(__('cleaning_finance_guidance.form.worker_section_description'))
                    ->columns(2)
                    ->schema([
                        Select::make('worker_id')
                            ->label(__('cleaning_admin.transactions.fields.worker'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.worker'))
                            ->options(fn (): array => self::workerOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('suggested_amount', null);
                                $set('amount', null);
                            }),
                        Select::make('type')
                            ->label(__('cleaning_admin.transactions.fields.type'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.type'))
                            ->options([
                                'deposit' => __('cleaning_admin.transactions.types.deposit'),
                                'debt' => __('cleaning_finance.types.debt'),
                                'refund' => __('cleaning_admin.transactions.types.refund'),
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('suggested_amount', null);
                                $set('amount', null);
                            }),
                    ]),
                Section::make(__('cleaning_finance_guidance.form.financial_summary'))
                    ->description(app()->isLocale('ar')
                        ? 'الإيداع والمديونية رصيدان منفصلان، ولا يمكن أن يكون كلاهما موجباً في الوقت نفسه.'
                        : 'Deposit and debt are separate balances and cannot both be positive at the same time.')
                    ->visible(fn (Get $get): bool => self::worker($get('worker_id')) instanceof Worker)
                    ->columns(['default' => 2, 'md' => 3, 'xl' => 5])
                    ->schema([
                        self::moneyMetric('deposit_balance', 'depositBalance', app()->isLocale('ar') ? 'رصيد الإيداع' : 'Deposit balance'),
                        self::moneyMetric('debt_balance', 'debtBalance', app()->isLocale('ar') ? 'المديونية الحالية' : 'Current debt'),
                        self::moneyMetric('allowed_debt_limit', 'allowedDebtLimit', app()->isLocale('ar') ? 'حد المديونية المسموح' : 'Allowed debt limit'),
                        self::moneyMetric('total_revenue', 'totalRevenue', app()->isLocale('ar') ? 'إجمالي الإيرادات' : 'Total revenue'),
                        self::moneyMetric('admin_commission_balance', 'adminCommissionBalance', app()->isLocale('ar') ? 'إجمالي عمولة الإدارة' : 'Administration commission balance'),
                    ]),
                Section::make(__('cleaning_finance_guidance.form.transaction_section'))
                    ->description(__('cleaning_finance_guidance.form.transaction_section_description'))
                    ->columns(2)
                    ->schema([
                        Select::make('suggested_amount')
                            ->label(__('cleaning_finance_guidance.fields.suggested_amount'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.suggested_amount'))
                            ->options(fn (Get $get): array => self::suggestedAmounts($get('worker_id'), $get('type')))
                            ->visible(fn (Get $get): bool => $get('type') !== 'refund' && self::suggestedAmounts($get('worker_id'), $get('type')) !== [])
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                if (is_numeric($state)) {
                                    $set('amount', (float) $state);
                                }
                            }),
                        TextInput::make('amount')
                            ->label(__('cleaning_admin.transactions.fields.amount'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.amount'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required(fn (Get $get): bool => $get('type') !== 'refund')
                            ->visible(fn (Get $get): bool => $get('type') !== 'refund')
                            ->live(debounce: 400)
                            ->helperText(fn (Get $get): string => self::amountHelper($get('worker_id'), $get('type')))
                            ->rules(fn (Get $get): array => [self::amountRule($get('worker_id'), $get('type'))]),
                        Placeholder::make('amount_validation')
                            ->label(__('cleaning_finance_guidance.fields.validation_result'))
                            ->content(fn (Get $get): string => self::validationResult($get('worker_id'), $get('type'), $get('amount')))
                            ->visible(fn (Get $get): bool => $get('type') !== 'refund')
                            ->columnSpanFull(),
                        Placeholder::make('automatic_refund_summary')
                            ->label(app()->isLocale('ar') ? 'نتيجة الاسترداد التلقائي' : 'Automatic refund result')
                            ->content(fn (Get $get): string => self::automaticRefundSummary($get('worker_id')))
                            ->visible(fn (Get $get): bool => $get('type') === 'refund')
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('cleaning_admin.transactions.fields.notes'))
                            ->placeholder(__('cleaning_finance_guidance.placeholders.notes'))
                            ->required(fn (Get $get): bool => $get('type') === 'debt')
                            ->helperText(fn (Get $get): ?string => $get('type') === 'debt'
                                ? (app()->isLocale('ar') ? 'سبب المديونية اليدوية مطلوب.' : 'A reason is required for manual debt.')
                                : null)
                            ->rows(4)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function moneyMetric(string $name, string $snapshotKey, string $label): Placeholder
    {
        return Placeholder::make($name.'_metric')
            ->label($label)
            ->content(fn (Get $get): string => self::money((float) (self::snapshot($get('worker_id'))[$snapshotKey] ?? 0)));
    }

    private static function workerOptions(): array
    {
        return Worker::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('module_type', UserModuleType::CleaningWorker))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Worker $worker): array => [$worker->id => ($worker->first_name ?: ('#'.$worker->id)).' (#'.$worker->id.')'])
            ->all();
    }

    private static function worker(mixed $workerId): ?Worker
    {
        if (! is_numeric($workerId) || (int) $workerId <= 0) {
            return null;
        }

        $workerId = (int) $workerId;
        $cacheKey = 'cleaning-transaction-form.worker.'.$workerId;
        if (request()->attributes->has($cacheKey)) {
            $cached = request()->attributes->get($cacheKey);

            return $cached instanceof Worker ? $cached : null;
        }

        try {
            $worker = app(AdminCleaningTransactionService::class)->findWorker($workerId);
            request()->attributes->set($cacheKey, $worker);

            return $worker;
        } catch (Throwable) {
            request()->attributes->set($cacheKey, null);

            return null;
        }
    }

    private static function snapshot(mixed $workerId): array
    {
        if (! is_numeric($workerId) || (int) $workerId <= 0) {
            return [];
        }

        $workerId = (int) $workerId;
        $cacheKey = 'cleaning-transaction-form.snapshot.'.$workerId;
        if (request()->attributes->has($cacheKey)) {
            $cached = request()->attributes->get($cacheKey);

            return is_array($cached) ? $cached : [];
        }

        $worker = self::worker($workerId);
        $snapshot = $worker instanceof Worker ? app(AdminCleaningTransactionService::class)->snapshot($worker) : [];
        request()->attributes->set($cacheKey, $snapshot);

        return $snapshot;
    }

    private static function suggestedAmounts(mixed $workerId, mixed $type): array
    {
        $worker = self::worker($workerId);
        if (! $worker instanceof Worker || ! is_string($type) || $type === '') {
            return [];
        }

        return app(AdminCleaningTransactionService::class)->suggestedAmounts($worker, $type);
    }

    private static function amountRule(mixed $workerId, mixed $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($workerId, $type): void {
            $worker = self::worker($workerId);
            if (! $worker instanceof Worker) {
                $fail(__('cleaning_finance_guidance.validation.worker_required'));

                return;
            }
            if (! is_numeric($value)) {
                $fail(__('cleaning_finance_guidance.validation.amount_positive'));

                return;
            }

            $message = app(AdminCleaningTransactionService::class)->validationMessage($worker, (string) $type, (float) $value);
            if ($message !== null) {
                $fail($message);
            }
        };
    }

    private static function amountHelper(mixed $workerId, mixed $type): string
    {
        $snapshot = self::snapshot($workerId);
        if ($snapshot === [] || ! is_string($type) || $type === '') {
            return __('cleaning_finance_guidance.fields.amount_helper_default');
        }

        return match ($type) {
            'deposit' => app()->isLocale('ar')
                ? 'يسدد الإيداع المديونية أولاً. المديونية الحالية: '.self::money((float) $snapshot['debtBalance']).'.'
                : 'The deposit settles debt first. Current debt: '.self::money((float) $snapshot['debtBalance']).'.',
            'debt' => app()->isLocale('ar')
                ? 'يخصم من الإيداع أولاً، ثم ينشئ مديونية فقط للجزء غير المغطى.'
                : 'Consumes the deposit first, then creates debt only for the uncovered amount.',
            default => __('cleaning_finance_guidance.fields.amount_helper_default'),
        };
    }

    private static function validationResult(mixed $workerId, mixed $type, mixed $amount): string
    {
        $worker = self::worker($workerId);
        if (! $worker instanceof Worker) {
            return __('cleaning_finance_guidance.validation.select_worker_first');
        }
        if (! is_string($type) || $type === '') {
            return __('cleaning_finance_guidance.validation.select_type_first');
        }
        if (! is_numeric($amount) || (float) $amount <= 0) {
            return __('cleaning_finance_guidance.validation.enter_amount_first');
        }

        $service = app(AdminCleaningTransactionService::class);
        $message = $service->validationMessage($worker, $type, (float) $amount);
        if ($message !== null) {
            return __('cleaning_finance_guidance.validation.invalid_prefix', ['message' => $message]);
        }

        return __('cleaning_finance_guidance.validation.valid_with_balance', [
            'balance' => self::money((float) $service->projectedBalance($worker, $type, (float) $amount)),
        ]);
    }

    private static function automaticRefundSummary(mixed $workerId): string
    {
        $snapshot = self::snapshot($workerId);
        if ($snapshot === []) {
            return __('cleaning_finance_guidance.validation.select_worker_first');
        }

        $deposit = self::money((float) ($snapshot['depositBalance'] ?? 0));
        $commission = self::money((float) ($snapshot['adminCommissionBalance'] ?? 0));

        return app()->isLocale('ar')
            ? "سيتم استرداد كامل رصيد الإيداع ({$deposit}) وتحويل عمولة الإدارة ({$commission}) إلى إيرادات الإدارة المسحوبة، ثم يصبح الرصيدان صفراً."
            : "The full deposit balance ({$deposit}) will be refunded and the administration commission ({$commission}) will be moved to withdrawn administration revenue. Both balances will then be zero.";
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2).' '.config('app.currency', 'SYP');
    }
}
