<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Pages\FinancialSettings;
use App\Filament\Resources\Workers\WorkerResource;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
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

it('shows the worker on OpenStreetMap and uses the same normalized hours as the worker API', function (): void {
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'حلب - الأشرفية - شارع الحديقة',
        'home_latitude' => 36.23080000,
        'home_longitude' => 37.12790000,
        'default_working_hours' => [
            'sunday' => [
                'available' => true,
                'data' => [
                    ['08:00' => '17:00'],
                    ['18:00' => '20:00'],
                ],
            ],
            'monday' => [
                'available' => false,
                'data' => [],
            ],
        ],
    ]);

    $this->get(WorkerResource::getUrl('view', ['record' => $worker], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('OpenStreetMap')
        ->assertSee('www.openstreetmap.org/export/embed.html', false)
        ->assertSee('36.23080000')
        ->assertSee('37.12790000')
        ->assertSee('08:00 ص — 05:00 م')
        ->assertSee('06:00 م — 08:00 م')
        ->assertSee('الإثنين')
        ->assertSee('غير متاح');

    Sanctum::actingAs($workerUser);

    $this->getJson('/api/v1/cleaning/worker/profile')
        ->assertSuccessful()
        ->assertJsonPath('data.homeLatitude', 36.2308)
        ->assertJsonPath('data.homeLongitude', 37.1279)
        ->assertJsonPath('data.defaultWorkingHours.sunday.available', true)
        ->assertJsonPath('data.defaultWorkingHours.sunday.data.0.08:00', '17:00')
        ->assertJsonPath('data.defaultWorkingHours.monday.available', false);

    $this->getJson('/api/v1/cleaning/worker/working-hours')
        ->assertSuccessful()
        ->assertJsonPath('data.defaultWorkingHours.sunday.available', true)
        ->assertJsonPath('data.defaultWorkingHours.sunday.data.1.18:00', '20:00')
        ->assertJsonPath('data.defaultWorkingHours.monday.available', false);
});

it('removes the command-center button and explains every worker-finance field', function (): void {
    $this->get(FinancialSettings::getUrl(isAbsolute: false))
        ->assertSuccessful()
        ->assertDontSee('عرض: مركز القيادة المباشر')
        ->assertSee('عند تعطيل هذا الخيار لن يتم تطبيق قيود التأمين والرصيد')
        ->assertSee('أقل مبلغ تأمين يجب أن يتوفر في حساب العامل')
        ->assertSee('أقصى قيمة رصيد سالب مسموحة افتراضياً')
        ->assertSee('يتم تقييد العامل تلقائياً')
        ->assertSee('عدد النقاط التي تُخصم من درجة ثقة العامل')
        ->assertSee('أدنى درجة ثقة يجب أن يملكها العامل');
});
