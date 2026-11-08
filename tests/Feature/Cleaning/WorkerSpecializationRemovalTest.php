<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('does not retain worker specialty assignments or work-type preferences in the schema', function (): void {
    expect(Schema::hasTable('cleaning_worker_special_service_skills'))->toBeFalse()
        ->and(Schema::hasTable('cleaning_worker_equipment_authorizations'))->toBeFalse()
        ->and(Schema::hasColumn('workers', 'preferred_work_type'))->toBeFalse()
        ->and(Schema::hasColumn('workers', 'preferred_work_types'))->toBeFalse()
        ->and(Schema::hasColumn('cleaning_special_services', 'gender_constraint'))->toBeFalse();

    // The service catalogue and equipment inventory remain intact.
    expect(Schema::hasTable('cleaning_special_services'))->toBeTrue()
        ->and(Schema::hasTable('cleaning_special_service_equipment'))->toBeTrue()
        ->and(Schema::hasTable('cleaning_equipment_reservations'))->toBeTrue();
});
