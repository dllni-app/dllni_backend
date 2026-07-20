<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Schemas;

use App\Models\Worker;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Services\WorkerStatisticsSummaryService;

final class CleaningWorkerInfolist
{
    /** @var array<int, array<string, mixed>> */
    private static array $statisticsSnapshotCache = [];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ملخص العامل')
                ->columns(3)
                ->schema([
                    ImageEntry::make('avatar_preview')
                        ->label('الصورة')
                        ->getStateUsing(
                            fn (Worker $record): ?string => $record->getFirstMediaUrl('avatar') ?: null,
                        )
                        ->defaultImageUrl(
                            fn (Worker $record): string => self::fallbackAvatarUrl($record),
                        )
                        ->circular()
                        ->imageHeight(88),
                    TextEntry::make('display_name')
                        ->label('الاسم')
                        ->state(
                            fn (Worker $record): string => $record->user?->name
                                ?: $record->first_name
                                ?: '-',
                        )
                        ->weight('bold'),
                    TextEntry::make('account_status')
                        ->label('حالة الحساب')
                        ->state(
                            fn (Worker $record): string => $record->is_suspended
                                ? 'موقوف'
                                : ($record->is_active ? 'نشط' : 'غير نشط'),
                        )
                        ->badge()
                        ->color(
                            fn (string $state): string => match ($state) {
                                'نشط' => 'success',
                                'موقوف' => 'danger',
                                default => 'gray',
                            },
                        ),
                ]),

            Section::make('ملخص المبالغ')
                ->columns(4)
                ->schema([
                    TextEntry::make('statistics_revenue')
                        ->label('الإيرادات')
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['grossInvoicesAmount'],
                            ),
                        ),
                    TextEntry::make('statistics_worker_amount')
                        ->label('تم إيداعه للإدارة')
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['workerAmount'],
                            ),
                        ),
                    TextEntry::make('statistics_admin_amount')
                        ->label('نسبة الإدارة من الأرباح')
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['adminAmount'],
                            ),
                        ),
                    TextEntry::make('statistics_completed_count')
                        ->label('إجمالي عدد الطلبات المكتملة')
                        ->state(
                            fn (Worker $record): string => number_format(
                                (int) self::statisticsSnapshot($record)['completedCount'],
                            ),
                        ),
                ]),

            Section::make('قيمة الدين')
                ->schema([
                    TextEntry::make('statistics_manual_debt')
                        ->hiddenLabel()
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['manualDebtAmount'],
                            ),
                        )
                        ->color('danger')
                        ->weight('bold'),
                ]),

            Section::make('حالة مبلغ التأمين')
                ->columns(5)
                ->schema([
                    TextEntry::make('statistics_deposit_status')
                        ->label('الحالة')
                        ->state(
                            fn (Worker $record): string => self::depositStatusLabel(
                                (string) self::statisticsSnapshot($record)['status'],
                            ),
                        )
                        ->badge()
                        ->color(
                            fn (Worker $record): string => self::depositStatusColor(
                                (string) self::statisticsSnapshot($record)['status'],
                            ),
                        ),
                    TextEntry::make('statistics_current_balance')
                        ->label('الرصيد الحالي')
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['currentBalance'],
                            ),
                        ),
                    TextEntry::make('statistics_minimum_required')
                        ->label('الحد الأدنى المطلوب')
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['minimumRequired'],
                            ),
                        ),
                    TextEntry::make('statistics_deposited_total')
                        ->label('إجمالي الإيداع')
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['depositedTotal'],
                            ),
                        ),
                    TextEntry::make('statistics_withdrawn_total')
                        ->label('إجمالي السحب')
                        ->state(
                            fn (Worker $record): string => self::money(
                                self::statisticsSnapshot($record)['withdrawnTotal'],
                            ),
                        ),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function statisticsSnapshot(Worker $worker): array
    {
        return self::$statisticsSnapshotCache[$worker->id] ??= app(
            WorkerStatisticsSummaryService::class,
        )->summary($worker);
    }

    private static function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 0).' ل.س';
    }

    private static function depositStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'نشط',
            'restricted', 'insufficient_balance' => 'غير نشط',
            'suspended' => 'موقوف',
            'inactive' => 'غير نشط',
            default => 'غير محدد',
        };
    }

    private static function depositStatusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'suspended' => 'danger',
            'restricted', 'insufficient_balance', 'inactive' => 'warning',
            default => 'gray',
        };
    }

    private static function fallbackAvatarUrl(Worker $worker): string
    {
        $name = rawurlencode(
            $worker->user?->name ?: $worker->first_name ?: 'Worker',
        );

        return "https://ui-avatars.com/api/?name={$name}&background=f3f4f6&color=111827";
    }
}
