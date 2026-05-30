<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_services', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->default(0)->after('description');
        });

        DB::table('cleaning_services')
            ->where('category', 'event_assistance')
            ->update(['category' => 'event_assisent']);

        DB::statement(
            'UPDATE cleaning_services '
            .'SET price = COALESCE(('
            .'  SELECT MIN(base_price) '
            .'  FROM service_pricing '
            .'  WHERE service_pricing.cleaning_service_id = cleaning_services.id'
            .'), price, 0)'
        );
    }

    public function down(): void
    {
        DB::table('cleaning_services')
            ->where('category', 'event_assisent')
            ->update(['category' => 'event_assistance']);

        Schema::table('cleaning_services', function (Blueprint $table): void {
            $table->dropColumn('price');
        });
    }
};
