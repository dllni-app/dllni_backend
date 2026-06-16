<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workers', 'birthday')) {
            Schema::table('workers', function (Blueprint $table): void {
                $table->date('birthday')->nullable()->after('gender');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workers', 'birthday')) {
            Schema::table('workers', function (Blueprint $table): void {
                $table->dropColumn('birthday');
            });
        }
    }
};
