<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_stores', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('address');
            $table->string('neighborhood')->nullable()->after('city');
            $table->string('cover')->nullable()->after('email');
            $table->string('logo')->nullable()->after('cover');
        });
    }

    public function down(): void
    {
        Schema::table('sm_stores', function (Blueprint $table): void {
            $table->dropColumn([
                'city',
                'neighborhood',
                'cover',
                'logo',
            ]);
        });
    }
};
