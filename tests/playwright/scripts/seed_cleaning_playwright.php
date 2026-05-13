<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CancellationPolicy;
use App\Models\User;
use Database\Factories\CleaningBookingFactory;
use Database\Factories\WorkerFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningTimeWarning;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (Schema::hasTable('booking_security_codes')) {
        if (! Schema::hasColumn('booking_security_codes', 'code_hash')) {
            Schema::table('booking_security_codes', static function (Blueprint $table): void {
                $table->string('code_hash', 64)->nullable()->after('code');
            });
        }
        if (! Schema::hasColumn('booking_security_codes', 'attempts')) {
            Schema::table('booking_security_codes', static function (Blueprint $table): void {
                $table->unsignedSmallInteger('attempts')->default(0)->after('code_hash');
            });
        }
        if (! Schema::hasColumn('booking_security_codes', 'last_attempt_at')) {
            Schema::table('booking_security_codes', static function (Blueprint $table): void {
                $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            });
        }
        if (! Schema::hasColumn('booking_security_codes', 'consumed_at')) {
            Schema::table('booking_security_codes', static function (Blueprint $table): void {
                $table->timestamp('consumed_at')->nullable()->after('last_attempt_at');
            });
        }
    }

    $runSuffix = (string) now()->timestamp.'_'.bin2hex(random_bytes(4));

    $cancellationPolicy = CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Playwright Cleaning Cancellation'],
        [
            'description' => 'Playwright generated policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ]
    );

    $billingPolicy = CleaningBillingPolicy::query()
        ->where('is_default', true)
        ->where('is_active', true)
        ->first();

    if (! $billingPolicy) {
        $billingPolicy = CleaningBillingPolicy::create([
            'name' => 'Playwright Cleaning Billing '.$runSuffix,
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    $customer = User::factory()->create([
        'name' => 'PW Cleaning User '.$runSuffix,
        'email' => "pw-cleaning-user-{$runSuffix}@example.test",
    ]);

    $workerUser = User::factory()->create([
        'name' => 'PW Cleaning Worker '.$runSuffix,
        'email' => "pw-cleaning-worker-{$runSuffix}@example.test",
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    $worker = WorkerFactory::new()->create([
        'user_id' => $workerUser->id,
        'first_name' => 'PW Worker '.$runSuffix,
        'is_active' => true,
    ]);
    $worker->zones()->createMany([
        ['name' => 'PW Damascus '.$runSuffix, 'is_active' => true],
        ['name' => 'PW Homs '.$runSuffix, 'is_active' => true],
    ]);

    $wrongRole = User::factory()->create([
        'name' => 'PW Wrong Role '.$runSuffix,
        'email' => "pw-cleaning-wrong-role-{$runSuffix}@example.test",
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $outsiderWorkerUser = User::factory()->create([
        'name' => 'PW Outsider Worker '.$runSuffix,
        'email' => "pw-cleaning-outsider-worker-{$runSuffix}@example.test",
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $outsiderWorker = WorkerFactory::new()->create([
        'user_id' => $outsiderWorkerUser->id,
        'first_name' => 'PW Outsider '.$runSuffix,
        'is_active' => true,
    ]);

    $completedWithWorker = CleaningBookingFactory::new()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed->value,
        'scheduled_date' => now()->subDay()->format('Y-m-d'),
        'work_started_at' => now()->subDay()->subHours(2),
        'work_finished_at' => now()->subDay()->subHour(),
        'customer_confirmed_at' => now()->subDay()->subMinutes(30),
    ]);

    $warningBookingForWorker = CleaningBookingFactory::new()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);

    $warningBookingForOutsider = CleaningBookingFactory::new()->create([
        'customer_id' => $customer->id,
        'worker_id' => $outsiderWorker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);

    $pendingAcceptWarning = CleaningTimeWarning::create([
        'booking_id' => $warningBookingForWorker->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'worker_response' => null,
        'sent_at' => now()->subMinutes(20),
    ]);

    $pendingRejectWarning = CleaningTimeWarning::create([
        'booking_id' => $warningBookingForWorker->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'worker_response' => null,
        'sent_at' => now()->subMinutes(10),
    ]);

    $pendingOutsiderWarning = CleaningTimeWarning::create([
        'booking_id' => $warningBookingForOutsider->id,
        'booking_type' => 'cleaning_booking',
        'customer_response' => 'extend_time',
        'worker_response' => null,
        'sent_at' => now()->subMinutes(5),
    ]);

    $tokenName = 'playwright-cleaning-'.$runSuffix;
    $customerToken = $customer->createToken($tokenName.'-customer')->plainTextToken;
    $workerToken = $workerUser->createToken($tokenName.'-worker')->plainTextToken;
    $wrongRoleToken = $wrongRole->createToken($tokenName.'-wrong-role')->plainTextToken;
    $outsiderWorkerToken = $outsiderWorkerUser->createToken($tokenName.'-outsider-worker')->plainTextToken;

    $payload = [
        'runId' => $runSuffix,
        'actors' => [
            'user' => [
                'id' => $customer->id,
                'token' => $customerToken,
            ],
            'worker' => [
                'id' => $workerUser->id,
                'userId' => $workerUser->id,
                'workerId' => $worker->id,
                'token' => $workerToken,
            ],
            'wrong_role' => [
                'id' => $wrongRole->id,
                'token' => $wrongRoleToken,
            ],
            'outsider_worker' => [
                'id' => $outsiderWorkerUser->id,
                'userId' => $outsiderWorkerUser->id,
                'workerId' => $outsiderWorker->id,
                'token' => $outsiderWorkerToken,
            ],
        ],
        'fixtures' => [
            'policies' => [
                'cancellationId' => $cancellationPolicy->id,
                'billingId' => $billingPolicy->id,
            ],
            'bookings' => [
                'completedWithWorker' => $completedWithWorker->id,
                'warningForWorker' => $warningBookingForWorker->id,
                'warningForOutsider' => $warningBookingForOutsider->id,
            ],
            'warnings' => [
                'pendingAccept' => $pendingAcceptWarning->id,
                'pendingReject' => $pendingRejectWarning->id,
                'pendingOutsider' => $pendingOutsiderWarning->id,
            ],
        ],
        'generatedAt' => now()->toIso8601String(),
    ];

    echo json_encode($payload, JSON_THROW_ON_ERROR);
} catch (\Throwable $throwable) {
    fwrite(STDERR, (string) $throwable);
    exit(1);
}
