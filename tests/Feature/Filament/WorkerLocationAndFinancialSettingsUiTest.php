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

it('omits retired mission-start and working-hours settings from worker details and profile', function (): void {
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Old location',
        'home_latitude' => 36.2308,
        'home_longitude' => 37.1279,
        'default_working_hours' => [
            'sunday' => ['available' => true, 'data' => [['08:00' => '17:00']]],
        ],
    ]);

    $this->get(WorkerResource::getUrl('view', ['record' => $worker], isAbsolute: false))
        ->assertSuccessful()
        ->assertDontSee('موقع بدء المهمة')
        ->assertDontSee('الموقع وأوقات العمل')
        ->assertDontSee('www.openstreetmap.org/export/embed.html', false);

    Sanctum::actingAs($workerUser);

    $this->getJson('/api/v1/cleaning/worker/profile')
        ->assertSuccessful()
        ->assertJsonMissingPath('data.homeLatitude')
        ->assertJsonMissingPath('data.homeLongitude')
        ->assertJsonMissingPath('data.defaultWorkingHours');
    $this->getJson('/api/v1/cleaning/worker/working-hours')->assertNotFound();
});

it('removes the command-center button and explains every worker-finance field', function (): void {
    $this->get(FinancialSettings::getUrl(isAbsolute: false))
        ->assertSuccessful()
        ->assertDontSee('عرض: مركز القيادة المباشر')
        ->assertDontSee('تفعيل قواعد مالية العاملين')
        ->assertDontSee('الحد الأقصى الافتراضي للرصيد السالب')
        ->assertSee('القيمة التي يجب أن يمتلكها العامل في رصيد الإيداع حتى يكون حسابه فعالاً عند العمل بالإيداع.')
        ->assertSee('تظهر رسالة تحذير للعامل عندما يتبقى من حد السماح هذه النسبة أو أقل. القيمة الافتراضية 10%.')
        ->assertSee('عدد النقاط التي تُخصم من درجة ثقة العامل')
        ->assertSee('أدنى درجة ثقة يجب أن يملكها العامل حتى يدخل ضمن قائمة العاملين المؤهلين لإرسال الطلبات.');
});
