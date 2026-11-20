<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_room_types')) {
            return;
        }

        // Update the shipped name only; never overwrite an administrator's
        // custom label or change the stable code used by existing bookings.
        DB::table('cleaning_room_types')
            ->where('code', 'shed')
            ->whereIn('name', ['مستودع', 'المستودع'])
            ->update([
                'name' => 'سقيفة',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Customer-visible copy change. Keep later admin customizations intact.
    }
};
