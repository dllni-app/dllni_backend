<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_by_role')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_worker_id')->nullable()->after('cancelled_by_user_id')->constrained('workers')->nullOnDelete();
            $table->integer('cancellation_offset_minutes')->nullable()->after('cancelled_by_worker_id');
        });

        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->string('status_before_booking_cancellation')->nullable()->after('status');
            $table->timestamp('booking_cancelled_at')->nullable()->after('status_before_booking_cancellation');
            $table->boolean('cancelled_by_this_worker')->default(false)->after('booking_cancelled_at');
        });

        DB::table('cleaning_bookings')
            ->whereNotNull('cancelled_at')
            ->orderBy('id')
            ->eachById(function (object $booking): void {
                $offset = null;

                try {
                    if ($booking->scheduled_date !== null && $booking->scheduled_time !== null) {
                        $scheduledAt = Carbon::parse(
                            mb_substr((string) $booking->scheduled_date, 0, 10).' '.(string) $booking->scheduled_time,
                            config('app.timezone'),
                        );
                        $cancelledAt = Carbon::parse((string) $booking->cancelled_at, config('app.timezone'));
                        $offset = (int) round(($scheduledAt->getTimestamp() - $cancelledAt->getTimestamp()) / 60);
                    }
                } catch (Throwable) {
                    $offset = null;
                }

                DB::table('cleaning_bookings')
                    ->where('id', $booking->id)
                    ->update(['cancellation_offset_minutes' => $offset]);

                DB::table('cleaning_booking_worker_assignments')
                    ->where('cleaning_booking_id', $booking->id)
                    ->whereNull('status_before_booking_cancellation')
                    ->update([
                        'status_before_booking_cancellation' => DB::raw('status'),
                        'booking_cancelled_at' => $booking->cancelled_at,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->dropColumn([
                'status_before_booking_cancellation',
                'booking_cancelled_at',
                'cancelled_by_this_worker',
            ]);
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_worker_id');
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn('cancellation_offset_minutes');
        });
    }
};
