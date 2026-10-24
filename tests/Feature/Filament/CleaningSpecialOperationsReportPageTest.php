<?php

declare(strict_types=1);

use App\Filament\Pages\CleaningSpecialOperationsReport;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('exposes the specialized operational report only to authorized dashboard viewers', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $guest = User::factory()->create();
    $this->actingAs($guest);
    expect(CleaningSpecialOperationsReport::canAccess())->toBeFalse();

    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    expect(CleaningSpecialOperationsReport::canAccess())->toBeTrue();

    $this->get(CleaningSpecialOperationsReport::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('تقرير تشغيل الخدمات الخاصة')
        ->assertSee('عدالة فرص المتخصصين')
        ->assertSee('استخدام المعدات وحالات الصيانة');
});
