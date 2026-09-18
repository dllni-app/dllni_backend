<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Tables;

use App\Enums\WorkerCustomerRatingType;
use App\Enums\WorkerPreferredWorkType;
use App\Filament\Resources\CleaningWorkers\Support\WorkerDepositActions;
use App\Filament\Resources\Workers\Support\WorkerSuspensionActions;
use App\Models\CleaningDepositSetting;
use App\Models\Worker;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Services\WorkerFinancialAccountStatusService;

final class CleaningWorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder('ابحث باسم العامل، رقم الهاتف أو الحي')
            ->columns([
                TextColumn::make('first_name')
                    ->label('اسم العامل')
                    ->state(fn (Worker $record): string => $record->user?->name ?: $record->first_name ?: '-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applyWorkerNameSearch($query, $search))
                    ->wrap(),
                TextColumn::make('user.phone')
                    ->label(__('cleaning_admin.workers.fields.phone'))
                    ->searchable()
                    ->copyable()
                    ->extraAttributes(['class' => 'fi-phone-ltr', 'dir' => 'ltr', 'style' => 'unicode-bidi:isolate;text-align:left']),
                TextColumn::make('neighborhood_names')
                    ->label('الأحياء')
                    ->state(fn (Worker $record): string => self::neighborhoodSummary($record))
                    ->tooltip(fn (Worker $record): ?string => self::neighborhoodTooltip($record))
                    ->placeholder('-')
                    ->wrap()
                    ->extraAttributes(['style' => 'max-width:18rem'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applyNeighborhoodSearch($query, $search))
                    ->toggleable(),
                TextColumn::make('gender')
                    ->label(__('cleaning_admin.workers.fields.gender'))
                    ->formatStateUsing(fn (?string $state): string => self::genderLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::genderColor($state))
                    ->sortable(),
                TextColumn::make('preferred_work_type')
                    ->label('الخدمات التي يعمل بها')
                    ->state(fn (Worker $record): string => self::preferredWorkTypeValue($record))
                    ->formatStateUsing(fn (?string $state): string => WorkerPreferredWorkType::options()[$state] ?? '-')
                    ->badge()
                    ->color('info'),
                TextColumn::make('average_rating')
                    ->label(__('cleaning_admin.workers.fields.average_rating'))
                    ->state(function (Worker $record): float {
                        $record->loadMissing('customerRatings');

                        $ratings = $record->customerRatings
                            ->filter(fn ($rating): bool => (string) ($rating->rating_type?->value ?? $rating->rating_type) === WorkerCustomerRatingType::CustomerToWorker->value);

                        if ($ratings->isEmpty()) {
                            return (float) ($record->average_rating ?? 0);
                        }

                        return round((float) $ratings->avg('rating'), 1);
                    })
                    ->formatStateUsing(function (mixed $state): string {
                        $rating = (float) ($state ?? 0);
                        $clamped = max(0, min(5, (int) round($rating)));

                        return str_repeat('★', $clamped).str_repeat('☆', 5 - $clamped).' ('.number_format($rating, 1).')';
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('total_completed_jobs')
                    ->label(__('cleaning_admin.workers.fields.total_completed_jobs'))
                    ->sortable(),
                TextColumn::make('financial_account_status')
                    ->label('استقبال طلبات جديدة')
                    ->state(fn (Worker $record): string => app(WorkerFinancialAccountStatusService::class)->status($record))
                    ->formatStateUsing(fn (?string $state): string => self::depositStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::depositStatusColor($state)),
            ])
            ->filters([
                SelectFilter::make('gender')
                    ->label(__('cleaning_admin.workers.fields.gender'))
                    ->options([
                        'male' => __('cleaning_admin.workers.gender_options.male'),
                        'female' => __('cleaning_admin.workers.gender_options.female'),
                    ]),
                SelectFilter::make('preferred_work_type')
                    ->label('الخدمات التي يعمل بها')
                    ->options(WorkerPreferredWorkType::options()),
                SelectFilter::make('neighborhood_id')
                    ->label('الحي')
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => CleaningNeighborhood::query()
                        ->active()
                        ->orderBy('sort_order')
                        ->orderBy('name_ar')
                        ->get(['id', 'name_ar', 'name_en'])
                        ->mapWithKeys(fn (CleaningNeighborhood $neighborhood): array => [
                            $neighborhood->id => $neighborhood->name_ar ?: $neighborhood->name_en ?: '#'.$neighborhood->id,
                        ])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $neighborhoodId = $data['value'] ?? null;

                        return filled($neighborhoodId)
                            ? $query->whereHas('zones', fn (Builder $zoneQuery): Builder => $zoneQuery->where('neighborhood_id', $neighborhoodId))
                            : $query;
                    }),
                SelectFilter::make('financial_account_status')
                    ->label('استقبال طلبات جديدة')
                    ->options([
                        WorkerFinancialAccountStatusService::ACTIVE => 'متاح لاستقبال الطلبات',
                        WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE => 'غير متاح - الرصيد غير كافٍ',
                        WorkerFinancialAccountStatusService::SUSPENDED => 'موقوف من الإدارة',
                        WorkerFinancialAccountStatusService::INACTIVE => 'غير نشط',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        return is_string($status)
                            ? self::applyFinancialStatusFilter($query, $status)
                            : $query;
                    }),
            ])
            ->persistFiltersInSession()
            ->recordActions([
                ViewAction::make()->label('عرض'),
                ...WorkerSuspensionActions::make(),
                EditAction::make()->label('تعديل'),
                ...WorkerDepositActions::make(),
            ]);
    }

    private static function applyWorkerNameSearch(Builder $query, string $search): Builder
    {
        $term = mb_trim($search);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $nameQuery) use ($term): void {
            $nameQuery
                ->where('first_name', 'like', "%{$term}%")
                ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$term}%"));
        });
    }

    private static function neighborhoodSummary(Worker $worker): string
    {
        $names = self::neighborhoodNames($worker);

        if ($names === []) {
            return '-';
        }

        $visible = array_slice($names, 0, 2);
        $remaining = count($names) - count($visible);

        return implode('، ', $visible).($remaining > 0 ? ' + '.$remaining.' أحياء' : '');
    }

    private static function neighborhoodTooltip(Worker $worker): ?string
    {
        $names = self::neighborhoodNames($worker);

        return $names === [] ? null : implode('، ', $names);
    }

    /** @return list<string> */
    private static function neighborhoodNames(Worker $worker): array
    {
        $worker->loadMissing('zones.neighborhood');

        return $worker->zones
            ->map(fn ($zone): ?string => $zone->neighborhood?->name_ar ?: $zone->neighborhood?->name_en)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function preferredWorkTypeValue(Worker $worker): string
    {
        if ($worker->preferred_work_type instanceof WorkerPreferredWorkType) {
            return $worker->preferred_work_type->value;
        }

        return is_string($worker->preferred_work_type)
            ? $worker->preferred_work_type
            : WorkerPreferredWorkType::Both->value;
    }

    private static function applyNeighborhoodSearch(Builder $query, string $search): Builder
    {
        $term = mb_trim($search);
        if ($term === '') {
            return $query;
        }

        return $query->whereHas('zones.neighborhood', function (Builder $neighborhoodQuery) use ($term): void {
            $neighborhoodQuery->where(function (Builder $nameQuery) use ($term): void {
                $nameQuery
                    ->where('name_ar', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('normalized_name', 'like', "%{$term}%");
            });
        });
    }

    private static function applyFinancialStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            WorkerFinancialAccountStatusService::SUSPENDED => $query->where('is_suspended', true),
            WorkerFinancialAccountStatusService::INACTIVE => $query->where('is_active', false),
            WorkerFinancialAccountStatusService::ACTIVE => $query
                ->where('is_active', true)
                ->where('is_suspended', false)
                ->whereHas('deposit', fn (Builder $deposit): Builder => self::applyDepositCapacityFilter($deposit, true)),
            WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE => $query
                ->where('is_active', true)
                ->where('is_suspended', false)
                ->where(function (Builder $financialQuery): void {
                    $financialQuery
                        ->whereDoesntHave('deposit')
                        ->orWhereHas('deposit', fn (Builder $deposit): Builder => self::applyDepositCapacityFilter($deposit, false));
                }),
            default => $query,
        };
    }

    private static function applyDepositCapacityFilter(Builder $deposit, bool $hasCapacity): Builder
    {
        $minimumRequired = max(0.0, (float) (CleaningDepositSetting::query()->value('minimum_deposit_amount') ?? 0));

        if ($hasCapacity) {
            return $deposit
                ->where('is_active', true)
                ->where(function (Builder $capacity) use ($minimumRequired): void {
                    $capacity
                        ->where(function (Builder $depositBalance) use ($minimumRequired): void {
                            $depositBalance->whereRaw('COALESCE(current_balance, 0) > 0');

                            if ($minimumRequired > 0) {
                                $depositBalance->whereRaw('COALESCE(current_balance, 0) >= ?', [$minimumRequired]);
                            }
                        })
                        ->orWhere(function (Builder $allowance): void {
                            $allowance
                                ->whereRaw('COALESCE(current_balance, 0) <= 0')
                                ->whereRaw('COALESCE(debt_balance, 0) < COALESCE(max_negative_balance, 0)');
                        });
                });
        }

        return $deposit->where(function (Builder $capacity) use ($minimumRequired): void {
            $capacity
                ->where('is_active', false)
                ->orWhere(function (Builder $depositBalance) use ($minimumRequired): void {
                    $depositBalance
                        ->whereRaw('COALESCE(current_balance, 0) > 0')
                        ->whereRaw('COALESCE(current_balance, 0) < ?', [$minimumRequired]);
                })
                ->orWhere(function (Builder $allowance): void {
                    $allowance
                        ->whereRaw('COALESCE(current_balance, 0) <= 0')
                        ->whereRaw('COALESCE(debt_balance, 0) >= COALESCE(max_negative_balance, 0)');
                });
        });
    }

    private static function genderLabel(?string $gender): string
    {
        return match ($gender) {
            'male' => __('cleaning_admin.workers.gender_options.male'),
            'female' => __('cleaning_admin.workers.gender_options.female'),
            default => '-',
        };
    }

    private static function genderColor(?string $gender): string
    {
        return match ($gender) {
            'male' => 'info',
            'female' => 'warning',
            default => 'gray',
        };
    }

    private static function depositStatusLabel(?string $status): string
    {
        return match ($status) {
            WorkerFinancialAccountStatusService::ACTIVE => 'متاح لاستقبال الطلبات',
            WorkerFinancialAccountStatusService::SUSPENDED => 'موقوف من الإدارة',
            WorkerFinancialAccountStatusService::INACTIVE => 'غير نشط',
            WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE => 'الرصيد غير كافٍ',
            default => 'غير محدد',
        };
    }

    private static function depositStatusColor(?string $status): string
    {
        return match ($status) {
            WorkerFinancialAccountStatusService::ACTIVE => 'success',
            WorkerFinancialAccountStatusService::SUSPENDED => 'warning',
            WorkerFinancialAccountStatusService::INACTIVE,
            WorkerFinancialAccountStatusService::INSUFFICIENT_BALANCE => 'danger',
            default => 'gray',
        };
    }
}
