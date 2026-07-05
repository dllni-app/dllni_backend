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

        $workers = [
            [
                'email' => 'worker1@dllni.sy',
                'phone' => '+963944100001',
                'gender' => GenderPreference::Female->value,
                'first_name' => 'رنا',
                'bio' => 'عاملة تنظيف محترفة بخبرة أكثر من 5 سنوات، متخصصة في التنظيف العميق واستخدام المنتجات الصديقة للبيئة.',
                'address' => 'حلب - الجميلية - شارع فيصل',
                'lat' => 36.2127,
                'lng' => 37.1456,
                'birthday' => '1993-04-12',
                'preferred_work_type' => WorkerPreferredWorkType::Cleaning->value,
                'deposit_balance' => 827500,
                'deposited_total' => 1000000,
                'withdrawn_total' => 0,
                'trust_reason' => 'سجل حجوزات مكتملة بدون شكاوى خلال آخر شهر.',
                'deposit_transactions' => self::runaDepositTimeline($timelineNow),
            ],
            [
                'email' => 'worker2@dllni.sy',
                'gender' => GenderPreference::Male->value,
                'first_name' => 'أحمد',
                'bio' => 'عامل موثوق ومنضبط، لديه خبرة في مساعدة المناسبات والتجمعات الكبيرة.',
                'address' => 'حلب - الحمدانية - شارع القدس',
                'lat' => 36.1795,
                'lng' => 37.1082,
                'birthday' => '1990-09-03',
                'preferred_work_type' => WorkerPreferredWorkType::Events->value,
                'deposit_balance' => 5000000,
                'trust_reason' => 'التزام جيد بمواعيد قبول الحجوزات.',
            ],
            [
                'email' => 'worker3@dllni.sy',
                'gender' => GenderPreference::Female->value,
                'first_name' => 'ليلى',
                'bio' => 'عاملة تنظيف تهتم بالتفاصيل، متاحة للتنظيف الدوري والتنظيف لمرة واحدة.',
                'address' => 'حلب - السريان الجديدة - شارع تشرين',
                'lat' => 36.2168,
                'lng' => 37.1317,
                'birthday' => '1996-01-25',
                'preferred_work_type' => WorkerPreferredWorkType::Both->value,
                'deposit_balance' => 5000000,
                'trust_reason' => 'تقييمات عملاء مرتفعة وثابتة.',
            ],
        ];

        $depositSetting = CleaningDepositSetting::query()->firstOrCreate([], []);
        $depositSetting->forceFill(self::onlyExistingColumns('cleaning_deposit_settings', [
            'minimum_deposit_amount' => 50000,
            'default_max_negative_balance' => 0,
            'restriction_threshold_percent' => 80,
            'trust_minimum_for_dispatch' => 0,
            'is_enabled' => true,
        ]))->save();

        foreach ($workers as $index => $data) {
            $phone = $data['phone'] ?? sprintf('+9639441201%02d', $index + 1);

            $user = User::query()
                ->where('email', $data['email'])
                ->orWhere('phone', $phone)
                ->first();

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

            $worker = Worker::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $data['first_name'],
                    'gender' => $data['gender'],
                    'bio' => $data['bio'],
                    'average_rating' => fake()->randomFloat(2, 4.2, 5.0),
                    'total_completed_jobs' => fake()->numberBetween(50, 300),
                    'trust_score' => fake()->numberBetween(80, 100),
                    'acceptance_rate' => fake()->randomFloat(2, 85, 99),
                    'cancellation_rate' => fake()->randomFloat(2, 0, 5),
                    'open_disputes_count' => 0,
                    'is_active' => true,
                    'is_suspended' => false,
                    'home_address' => $data['address'],
                    'home_latitude' => $data['lat'],
                    'home_longitude' => $data['lng'],
                    'default_working_hours' => self::defaultWorkingHours(),
                ]
            );

            $workerUpdates = [
                'first_name' => $data['first_name'],
                'gender' => $data['gender'],
                'bio' => $data['bio'],
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

            CleaningWorkerDeposit::updateOrCreate(
                ['worker_id' => $worker->id],
                self::onlyExistingColumns('cleaning_worker_deposits', [
                    'current_balance' => $data['deposit_balance'],
                    'deposited_total' => $data['deposited_total'] ?? $data['deposit_balance'],
                    'withdrawn_total' => $data['withdrawn_total'] ?? 0,
                    'minimum_required' => 50000,
                    'max_negative_balance' => 0,
                    'is_active' => true,
                ])
            );

            foreach ($data['deposit_transactions'] ?? [] as $transactionData) {
                self::upsertDepositTransaction($worker, $transactionData);
            }

            WorkerTrustLog::firstOrCreate(
                [
                    'worker_id' => $worker->id,
                    'reason' => $data['trust_reason'],
                ],
                ['score_delta' => 5]
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
                ]
            );

            for ($i = 0; $i < 7; $i++) {
                WorkerAvailability::firstOrCreate(
                    [
                        'worker_id' => $worker->id,
                        'availability_date' => now()->addDays($i),
                    ],
                    [
                        'availability_type' => AvailabilityType::Available->value,
                        'start_time' => '09:00',
                        'end_time' => '18:00',
                    ]
                );
            }

            SeederMedia::ensureSingleMedia(
                $worker,
                'avatar',
                'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=512&q=80',
                "worker-{$worker->id}-avatar"
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function runaDepositTimeline(CarbonImmutable $now): array
    {
        return [
            [
                'type' => 'deposit',
                'amount' => 500000,
                'balance_before' => 0,
                'balance_after' => 500000,
                'reference' => 'seed-runa-opening-deposit',
                'notes' => 'إيداع التأمين الافتتاحي لعامل التنظيف.',
                'created_at' => $now->subDays(14)->setTime(10, 15),
            ],
            [
                'type' => 'deposit',
                'amount' => 500000,
                'balance_before' => 500000,
                'balance_after' => 1000000,
                'reference' => 'seed-runa-second-deposit',
                'notes' => 'تعزيز رصيد التأمين قبل استقبال طلبات إضافية.',
                'created_at' => $now->subDays(10)->setTime(12, 30),
            ],
            [
                'type' => 'admin_fee',
                'amount' => 45000,
                'balance_before' => 1000000,
                'balance_after' => 955000,
                'reference' => 'seed-runa-admin-fee-1',
                'notes' => 'مديونية عمولة الإدارة عن طلب تنظيف مكتمل.',
                'created_at' => $now->subDays(7)->setTime(16, 10),
            ],
            [
                'type' => 'admin_fee',
                'amount' => 57500,
                'balance_before' => 955000,
                'balance_after' => 897500,
                'reference' => 'seed-runa-admin-fee-2',
                'notes' => 'مديونية عمولة الإدارة عن طلب تنظيف عميق.',
                'created_at' => $now->subDays(4)->setTime(18, 5),
            ],
            [
                'type' => 'admin_fee',
                'amount' => 70000,
                'balance_before' => 897500,
                'balance_after' => 827500,
                'reference' => 'seed-runa-admin-fee-3',
                'notes' => 'مديونية عمولة الإدارة عن طلب مناسبة مكتمل.',
                'created_at' => $now->subDay()->setTime(20, 20),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $transactionData
     */
    private static function upsertDepositTransaction(Worker $worker, array $transactionData): void
    {
        $createdAt = $transactionData['created_at'] ?? now();

        $transaction = CleaningDepositTransaction::query()->updateOrCreate(
            [
                'worker_id' => $worker->id,
                'reference' => $transactionData['reference'],
            ],
            self::onlyExistingColumns('cleaning_deposit_transactions', [
                'cleaning_booking_id' => $transactionData['cleaning_booking_id'] ?? null,
                'created_by_admin_id' => $transactionData['created_by_admin_id'] ?? null,
                'type' => $transactionData['type'],
                'amount' => $transactionData['amount'],
                'balance_before' => $transactionData['balance_before'],
                'balance_after' => $transactionData['balance_after'],
                'notes' => $transactionData['notes'] ?? null,
            ])
        );

        $transaction->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
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
            'monday' => ['09:00', '18:00'],
            'tuesday' => ['09:00', '18:00'],
            'wednesday' => ['09:00', '18:00'],
            'thursday' => ['09:00', '18:00'],
            'friday' => ['09:00', '18:00'],
            'saturday' => ['10:00', '16:00'],
        ];
    }
}
