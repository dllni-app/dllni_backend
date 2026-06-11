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
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->json('cleaning_services')->nullable()->after('property_details');
        });

        if (Schema::hasTable('cleaning_booking_service')) {
            DB::table('cleaning_booking_service')
                ->join('cleaning_services', 'cleaning_services.id', '=', 'cleaning_booking_service.cleaning_service_id')
                ->select('cleaning_booking_service.cleaning_booking_id', 'cleaning_services.name', 'cleaning_booking_service.id')
                ->orderBy('cleaning_booking_service.id')
                ->get()
                ->groupBy('cleaning_booking_id')
                ->each(function ($rows, $bookingId): void {
                    $services = $rows
                        ->pluck('name')
                        ->filter(static fn (mixed $name): bool => is_string($name) && mb_trim($name) !== '')
                        ->map(static fn (string $name): string => mb_trim($name))
                        ->unique()
                        ->values()
                        ->all();

                    DB::table('cleaning_bookings')
                        ->where('id', $bookingId)
                        ->update([
                            'cleaning_services' => $services !== [] ? json_encode($services, JSON_THROW_ON_ERROR) : null,
                        ]);
                });
        }

        DB::table('cleaning_services')
            ->where('category', 'event_assisent')
            ->update(['category' => 'event_assistance']);
    }

    public function down(): void
    {
        DB::table('cleaning_services')
            ->where('category', 'event_assistance')
            ->update(['category' => 'event_assisent']);

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn('cleaning_services');
        });
    }
};
