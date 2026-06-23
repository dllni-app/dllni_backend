<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sm_stores MODIFY cover LONGTEXT NULL');
        DB::statement('ALTER TABLE sm_stores MODIFY logo LONGTEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sm_stores MODIFY cover VARCHAR(255) NULL');
        DB::statement('ALTER TABLE sm_stores MODIFY logo VARCHAR(255) NULL');
    }
};
