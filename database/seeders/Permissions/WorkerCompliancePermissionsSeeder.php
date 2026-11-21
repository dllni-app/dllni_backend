<?php

declare(strict_types=1);

namespace Database\Seeders\Permissions;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class WorkerCompliancePermissionsSeeder extends Seeder
{
    private const array Permissions = [
        'cleaning.worker_compliance.overview.view',
        'cleaning.worker_compliance.cases.view',
        'cleaning.worker_compliance.cases.claim',
        'cleaning.worker_compliance.cases.review',
        'cleaning.worker_compliance.cases.confirm',
        'cleaning.worker_compliance.configure',
    ];

    public function run(): void
    {
        $guardName = config('auth.defaults.guard');

        foreach (self::Permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guardName,
            ]);
        }
    }
}
