<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AvailabilityType;
use App\Enums\GenderPreference;
use App\Enums\UserModuleType;
use App\Enums\WorkerPreferredWorkType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerTrustLog;
use App\Models\WorkerZone;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class WorkerSeeder extends Seeder
{
    public function run(): void
    {
        $timelineNow = CarbonImmutable::now();
        $workers = self::workers($timelineNow);

        $depositSetting = CleaningDepositSetting::query()->firstOrCreate([], []);
        $depositSetting->forceFill(self::onlyExistingColumns('cleaning_deposit_settings', [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 100000,
            'restriction_threshold_percent' => 100,
            'trust_minimum_for_dispatch' => 0,
            'is_enabled' => true,
        ]))->save();

        foreach ($workers as $index => $data) {
            $phone = $data['phone'] ?? sprintf('+9639441201%02d', $index + 1);
            $user = User::query()->where('email', $data['email'])->orWhere('phone', $phone)->first();

            if (! $user instanceof User) {
                $user = User::query()->create([
                    'name' => $data['first_name'].' عامل تنظيف',
                    'email' => $data['email'],
                    'phone' => $phone,
                    'module_type' => UserModuleType::CleaningWorker,
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]);
            }

            $user->forceFill([
                'name' => $data['first_name'].' عامل تنظيف',
                'email' => $data['email'],
                'phone' => $phone,
                'module_type' => UserModuleType::CleaningWorker,
                'password' => bcrypt('password'),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'phone_verified_at' => now(),
            ])->save();

            $worker = Worker::firstOrCreate(['user_id' => $user->id]);
            $workerUpdates = [
                'first_name' => $data['first_name'],
                'gender' => $data['gender'],
                'bio' => $data['bio'],
                'average_rating' => $data['average_rating'],
                'total_completed_jobs' => $data['total_completed_jobs'],
                'trust_score' => $data['trust_score'],
                'acceptance_rate' => $data['acceptance_rate'],
                'cancellation_rate' => $data['cancellation_rate'],
                'open_disputes_count' => 0,
                'is_verified' => true,
                'is_featured' => $index === 0,
                'is_active' => true,
                'is_suspended' => false,
                'security_deposit_status' => 'active',
                'home_address' => $data['address'],
                'home_latitude' => $data['lat'],
                'home_longitude' => $data['lng'],
                'default_working_hours' => self::defaultWorkingHours(),
            ];

            if (Schema::hasColumn('workers', 'birthday')) {
                $workerUpdates['birthday'] = $data['birthday'];
            }
            if (Schema::hasColumn('workers', 'preferred_work_type')) {
                $workerUpdates['preferred_work_type'] = $data['preferred_work_type'];
            }
            $worker->forceFill($workerUpdates)->save();

            CleaningDepositTransaction::query()
                ->where('worker_id', $worker->id)
                ->where('reference', 'like', 'seed-%')
                ->delete();

            CleaningWorkerDeposit::query()->updateOrCreate(
                ['worker_id' => $worker->id],
                self::onlyExistingColumns('cleaning_worker_deposits', [
                    'current_balance' => $data['deposit_balance'],
                    'debt_balance' => $data['debt_balance'],
                    'deposited_total' => $data['deposited_total'],
                    'withdrawn_total' => $data['withdrawn_total'],
                    'minimum_required' => 0,
                    'max_negative_balance' => 100000,
                    'is_active' => true,
                ]),
            );

            foreach ($data['deposit_transactions'] as $transactionData) {
                self::upsertDepositTransaction($worker, $transactionData);
            }

            WorkerTrustLog::firstOrCreate(
                ['worker_id' => $worker->id, 'reason' => $data['trust_reason']],
                ['score_delta' => 5],
            );

            WorkerZone::updateOrCreate(
                ['worker_id' => $worker->id, 'name' => 'المنطقة الأساسية'],
                [
                    'polygon' => [
                        ['lat' => $data['lat'] - 0.05, 'lng' => $data['lng'] - 0.05],
                        ['lat' => $data['lat'] + 0.05, 'lng' => $data['lng'] - 0.05],
                        ['lat' => $data['lat'] + 0.05, 'lng' => $data['lng'] + 0.05],
                        ['lat' => $data['lat'] - 0.05, 'lng' => $data['lng'] + 0.05],
                    ],
                    'is_active' => true,
                ],
            );

            for ($i = 0; $i < 7; $i++) {
                WorkerAvailability::firstOrCreate(
                    ['worker_id' => $worker->id, 'availability_date' => now()->addDays($i)],
                    [
                        'availability_type' => AvailabilityType::Available->value,
                        'start_time' => '09:00',
                        'end_time' => '18:00',
                    ],
                );
            }

            SeederMedia::ensureSingleMedia(
                $worker,
                'avatar',
                'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=512&q=80',
                "worker-{$worker->id}-avatar",
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function workers(CarbonImmutable $now): array
    {
        return [
            [
                'email' => 'worker1@dllni.sy', 'phone' => '+963944100001',
                'gender' => GenderPreference::Female->value, 'first_name' => 'رنا',
                'bio' => 'عاملة تنظيف محترفة بخبرة أكثر من 5 سنوات، متخصصة في التنظيف العميق واستخدام المنتجات الصديقة للبيئة.',
                'address' => 'حلب - الجميلية - شارع فيصل', 'lat' => 36.2127, 'lng' => 37.1456,
                'birthday' => '1993-04-12', 'preferred_work_type' => WorkerPreferredWorkType::Cleaning->value,
                'average_rating' => 4.9, 'total_completed_jobs' => 18, 'trust_score' => 96,
                'acceptance_rate' => 94.5, 'cancellation_rate' => 1.2,
                'deposit_balance' => 800000, 'debt_balance' => 0,
                'deposited_total' => 1180000, 'withdrawn_total' => 80000,
                'trust_reason' => 'سجل حجوزات مكتملة بدون شكاوى خلال آخر شهر.',
                'deposit_transactions' => self::runaDepositTimeline($now),
            ],
            [
                'email' => 'worker2@dllni.sy', 'gender' => GenderPreference::Male->value, 'first_name' => 'أحمد',
                'bio' => 'عامل موثوق ومنضبط، لديه خبرة في مساعدة المناسبات والتجمعات الكبيرة.',
                'address' => 'حلب - الحمدانية - شارع القدس', 'lat' => 36.1795, 'lng' => 37.1082,
                'birthday' => '1990-09-03', 'preferred_work_type' => WorkerPreferredWorkType::Events->value,
                'average_rating' => 4.7, 'total_completed_jobs' => 11, 'trust_score' => 91,
                'acceptance_rate' => 90.0, 'cancellation_rate' => 2.5,
                'deposit_balance' => 1100000, 'debt_balance' => 0,
                'deposited_total' => 1350000, 'withdrawn_total' => 50000,
                'trust_reason' => 'التزام جيد بمواعيد قبول الحجوزات.',
                'deposit_transactions' => self::ahmadDepositTimeline($now),
            ],
            [
                'email' => 'worker3@dllni.sy', 'gender' => GenderPreference::Female->value, 'first_name' => 'ليلى',
                'bio' => 'عاملة تنظيف تهتم بالتفاصيل، متاحة للتنظيف الدوري والتنظيف لمرة واحدة.',
                'address' => 'حلب - السريان الجديدة - شارع تشرين', 'lat' => 36.2168, 'lng' => 37.1317,
                'birthday' => '1996-01-25', 'preferred_work_type' => WorkerPreferredWorkType::Both->value,
                'average_rating' => 4.8, 'total_completed_jobs' => 15, 'trust_score' => 94,
                'acceptance_rate' => 93.0, 'cancellation_rate' => 1.8,
                'deposit_balance' => 800000, 'debt_balance' => 0,
                'deposited_total' => 950000, 'withdrawn_total' => 50000,
                'trust_reason' => 'تقييمات عملاء مرتفعة وثابتة.',
                'deposit_transactions' => self::lailaDepositTimeline($now),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function runaDepositTimeline(CarbonImmutable $now): array
    {
        return [
            self::transaction('deposit', 300000, 0, 300000, 0, 0, 'seed-runa-opening-deposit', 'إيداع افتتاحي.', $now->subDays(20)),
            self::transaction('commission', 400000, 300000, 0, 0, 100000, 'seed-runa-platform-commission', 'عمولة منصة استهلكت الإيداع وأنشأت مديونية للجزء المتبقي.', $now->subDays(15)),
            self::transaction('settlement', 100000, 0, 0, 100000, 0, 'seed-runa-debt-settlement', 'تسوية كامل المديونية.', $now->subDays(10), 100000),
            self::transaction('deposit', 880000, 0, 880000, 0, 0, 'seed-runa-top-up', 'إيداع جديد بعد تصفير المديونية.', $now->subDays(5)),
            self::transaction('refund', 80000, 880000, 800000, 0, 0, 'seed-runa-refund', 'استرداد جزء من رصيد الإيداع.', $now->subDay()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function ahmadDepositTimeline(CarbonImmutable $now): array
    {
        return [
            self::transaction('deposit', 1000000, 0, 1000000, 0, 0, 'seed-ahmad-opening-deposit', 'إيداع افتتاحي.', $now->subDays(18)),
            self::transaction('commission', 200000, 1000000, 800000, 0, 0, 'seed-ahmad-platform-commission', 'خصم عمولة المنصة من الإيداع.', $now->subDays(12)),
            self::transaction('deposit', 350000, 800000, 1150000, 0, 0, 'seed-ahmad-top-up', 'تعزيز رصيد الإيداع.', $now->subDays(6)),
            self::transaction('refund', 50000, 1150000, 1100000, 0, 0, 'seed-ahmad-refund', 'استرداد جزئي من الإيداع.', $now->subDays(2)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function lailaDepositTimeline(CarbonImmutable $now): array
    {
        return [
            self::transaction('deposit', 750000, 0, 750000, 0, 0, 'seed-laila-opening-deposit', 'إيداع افتتاحي.', $now->subDays(22)),
            self::transaction('commission', 100000, 750000, 650000, 0, 0, 'seed-laila-platform-commission', 'خصم عمولة المنصة من الإيداع.', $now->subDays(14)),
            self::transaction('deposit', 200000, 650000, 850000, 0, 0, 'seed-laila-top-up', 'تعزيز رصيد الإيداع.', $now->subDays(9)),
            self::transaction('refund', 50000, 850000, 800000, 0, 0, 'seed-laila-refund', 'استرداد جزء من الإيداع.', $now->subDays(4)),
        ];
    }

    /** @return array<string, mixed> */
    private static function transaction(
        string $type,
        float $amount,
        float $depositBefore,
        float $depositAfter,
        float $debtBefore,
        float $debtAfter,
        string $reference,
        string $notes,
        CarbonImmutable $createdAt,
        float $debtSettledAmount = 0,
    ): array {
        return [
            'type' => $type,
            'amount' => $amount,
            'debt_settled_amount' => $debtSettledAmount,
            'balance_before' => $depositBefore,
            'balance_after' => $depositAfter,
            'debt_balance_before' => $debtBefore,
            'debt_balance_after' => $debtAfter,
            'reference' => $reference,
            'notes' => $notes,
            'created_at' => $createdAt,
        ];
    }

    /** @param array<string, mixed> $transactionData */
    private static function upsertDepositTransaction(Worker $worker, array $transactionData): void
    {
        $createdAt = $transactionData['created_at'] ?? now();
        $transaction = CleaningDepositTransaction::query()->updateOrCreate(
            ['worker_id' => $worker->id, 'reference' => $transactionData['reference']],
            self::onlyExistingColumns('cleaning_deposit_transactions', [
                'created_by_admin_id' => $transactionData['created_by_admin_id'] ?? null,
                'type' => $transactionData['type'],
                'amount' => $transactionData['amount'],
                'debt_settled_amount' => $transactionData['debt_settled_amount'] ?? 0,
                'balance_before' => $transactionData['balance_before'],
                'balance_after' => $transactionData['balance_after'],
                'debt_balance_before' => $transactionData['debt_balance_before'] ?? 0,
                'debt_balance_after' => $transactionData['debt_balance_after'] ?? 0,
                'notes' => $transactionData['notes'] ?? null,
            ]),
        );

        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function onlyExistingColumns(string $table, array $values): array
    {
        return array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function defaultWorkingHours(): array
    {
        return [
            'monday' => ['09:00', '18:00'], 'tuesday' => ['09:00', '18:00'],
            'wednesday' => ['09:00', '18:00'], 'thursday' => ['09:00', '18:00'],
            'friday' => ['09:00', '18:00'], 'saturday' => ['10:00', '16:00'],
        ];
    }
}
