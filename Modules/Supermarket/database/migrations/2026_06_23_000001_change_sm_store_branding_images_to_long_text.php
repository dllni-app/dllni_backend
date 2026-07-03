<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sm_stores MODIFY cover LONGTEXT NULL');
            DB::statement('ALTER TABLE sm_stores MODIFY logo LONGTEXT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sm_stores ALTER COLUMN cover TYPE TEXT');
            DB::statement('ALTER TABLE sm_stores ALTER COLUMN logo TYPE TEXT');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sm_stores MODIFY cover VARCHAR(255) NULL');
            DB::statement('ALTER TABLE sm_stores MODIFY logo VARCHAR(255) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sm_stores ALTER COLUMN cover TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE sm_stores ALTER COLUMN logo TYPE VARCHAR(255)');
        }
    }
};
