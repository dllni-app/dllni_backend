<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Enums\WorkerCustomerRatingType;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use App\Models\WorkerZone;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $this->actingAs($admin);
});

it('shows the cleaning owner app profile sections and removes financial content', function (): void {
    $workerUser = User::factory()->create([
        'name' => 'رنا أحمد',
        'email' => 'rana.worker@example.com',
        'phone' => '0999000001',
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    $customer = User::factory()->create([
        'name' => 'آلاء محمود',
        'phone' => '0999000002',
    ]);

    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'first_name' => 'رنا',
        'total_completed_jobs' => 42,
        'trust_score' => 91,
        'acceptance_rate' => 87.5,
        'cancellation_rate' => 4.5,
        'open_disputes_count' => 2,
        'default_working_hours' => [
            'sunday' => [
                'available' => true,
                'data' => [
                    ['08:00' => '17:00'],
                ],
            ],
            'monday' => [
                'available' => false,
                'data' => [],
            ],
        ],
    ]);

    WorkerZone::query()->create([
        'worker_id' => $worker->id,
        'name' => 'الأشرفية',
        'is_active' => true,
    ]);

    WorkerCustomerRating::query()->create([
        'booking_id' => 501,
        'booking_type' => CleaningBooking::class,
        'worker_id' => $worker->id,
        'customer_id' => $customer->id,
        'rating_type' => WorkerCustomerRatingType::CustomerToWorker->value,
        'rating' => 5,
        'comment' => 'التزام ممتاز بالمواعيد وجودة العمل.',
    ]);

    WorkerCustomerRating::query()->create([
        'booking_id' => 501,
        'booking_type' => CleaningBooking::class,
        'worker_id' => $worker->id,
        'customer_id' => $customer->id,
        'rating_type' => WorkerCustomerRatingType::WorkerToCustomer->value,
        'rating' => 2,
        'comment' => 'تقييم داخلي يجب ألا يظهر في ملف العامل.',
    ]);

    $this->get(CleaningWorkerResource::getUrl('view', ['record' => $worker], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('إحصائيات')
        ->assertSee('الطلبات المكتملة')
        ->assertSee('التقييمات والتعليقات')
        ->assertSee('من قام بالتقييم')
        ->assertSee('آلاء محمود')
        ->assertSee('تقييم العميل للعامل')
        ->assertSee('التزام ممتاز بالمواعيد وجودة العمل.')
        ->assertSee('طلب تنظيف #501')
        ->assertSee('أوقات العمل')
        ->assertSee('08:00 ص — 05:00 م')
        ->assertSee('الإثنين')
        ->assertSee('غير متاح')
        ->assertSee('مناطق العمل')
        ->assertSee('الأشرفية')
        ->assertDontSee('تقييم داخلي يجب ألا يظهر في ملف العامل.')
        ->assertDontSee('ملخص المبالغ')
        ->assertDontSee('قيمة الدين')
        ->assertDontSee('حالة مبلغ التأمين')
        ->assertDontSee('الإجراءات المالية')
        ->assertDontSee('الإيرادات')
        ->assertDontSee('إجمالي الإيداع');
});
