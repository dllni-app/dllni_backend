<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_products', function (Blueprint $table): void {
            $table->decimal('package_quantity', 12, 3)->nullable()->after('unit');
            $table->string('package_unit', 32)->nullable()->after('package_quantity');
            $table->string('sell_mode', 32)->default('package')->after('package_unit');
        });
    }

    public function down(): void
    {
        Schema::table('master_products', function (Blueprint $table): void {
            $table->dropColumn(['package_quantity', 'package_unit', 'sell_mode']);
        });
    }
};
