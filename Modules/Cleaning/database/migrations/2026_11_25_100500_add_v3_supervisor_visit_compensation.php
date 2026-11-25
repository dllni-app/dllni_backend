<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            $table->decimal('v3_supervisor_visit_compensation', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            $table->dropColumn('v3_supervisor_visit_compensation');
        });
    }
};
