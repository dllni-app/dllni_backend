<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cleaning_bookings')
            ->select(['id', 'property_details'])
            ->orderBy('id')
            ->chunkById(200, static function ($bookings): void {
                foreach ($bookings as $booking) {
                    $propertyDetails = json_decode((string) $booking->property_details, true);

                    if (! is_array($propertyDetails)) {
                        continue;
                    }

                    if (array_key_exists('kitchens', $propertyDetails)) {
                        continue;
                    }

                    if (! array_key_exists('kitchen_included', $propertyDetails)) {
                        continue;
                    }

                    $propertyDetails['kitchens'] = (bool) $propertyDetails['kitchen_included'] ? 1 : 0;
                    unset($propertyDetails['kitchen_included']);

                    DB::table('cleaning_bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'property_details' => json_encode($propertyDetails, JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('cleaning_bookings')
            ->select(['id', 'property_details'])
            ->orderBy('id')
            ->chunkById(200, static function ($bookings): void {
                foreach ($bookings as $booking) {
                    $propertyDetails = json_decode((string) $booking->property_details, true);

                    if (! is_array($propertyDetails)) {
                        continue;
                    }

                    if (! array_key_exists('kitchens', $propertyDetails)) {
                        continue;
                    }

                    if (array_key_exists('kitchen_included', $propertyDetails)) {
                        continue;
                    }

                    $propertyDetails['kitchen_included'] = ((int) $propertyDetails['kitchens']) > 0;
                    unset($propertyDetails['kitchens']);

                    DB::table('cleaning_bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'property_details' => json_encode($propertyDetails, JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
