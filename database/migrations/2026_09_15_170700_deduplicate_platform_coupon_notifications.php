<?php

declare(strict_types=1);

use App\Notifications\PlatformCouponAvailableNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $seen = [];
        $duplicateIds = [];

        foreach (DB::table('notifications')
            ->where('type', PlatformCouponAvailableNotification::class)
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['id', 'notifiable_type', 'notifiable_id', 'data'])
            ->cursor() as $notification) {
            $data = json_decode((string) $notification->data, true);
            if (! is_array($data)) {
                continue;
            }

            $couponId = data_get($data, 'couponId') ?? data_get($data, 'data.couponId');
            if ($couponId === null || $couponId === '') {
                continue;
            }

            $key = implode(':', [
                (string) $notification->notifiable_type,
                (string) $notification->notifiable_id,
                (string) $couponId,
            ]);

            if (isset($seen[$key])) {
                $duplicateIds[] = (string) $notification->id;

                continue;
            }

            $seen[$key] = true;
        }

        foreach (array_chunk($duplicateIds, 500) as $ids) {
            DB::table('notifications')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // Duplicate notification rows cannot be restored safely.
    }
};
