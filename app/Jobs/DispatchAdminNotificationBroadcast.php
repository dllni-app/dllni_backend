<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdminNotificationBroadcast;
use App\Models\User;
use App\Notifications\AdminBroadcastNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchAdminNotificationBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const EXCLUDED_ROLES = [
        'admin',
        'Super Admin',
        'Cleaning Ops Manager',
        'Customer Support',
        'Onboarding Specialist',
        'Accountant',
        'delivery_company_admin',
        'delivery_company_staff',
    ];

    public function __construct(public readonly int $broadcastId) {}

    public function handle(): void
    {
        $broadcast = AdminNotificationBroadcast::query()->with('users:id')->find($this->broadcastId);
        if (! $broadcast) {
            return;
        }

        $query = User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn (Builder $query): Builder => $query->whereIn('name', self::EXCLUDED_ROLES));

        if ($broadcast->audience_type === AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES) {
            $this->scopeToModuleTypes($query, $broadcast->module_types ?? []);
        } elseif ($broadcast->audience_type === AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS) {
            $query->whereKey($broadcast->users->modelKeys());
        }

        $recipientsCount = 0;

        $query->select(['id', 'name', 'email', 'phone', 'fcm_token', 'module_type'])
            ->chunkById(500, function ($users) use ($broadcast, &$recipientsCount): void {
                foreach ($users as $user) {
                    $user->notify(new AdminBroadcastNotification($broadcast));
                    $recipientsCount++;
                }
            });

        $broadcast->forceFill([
            'recipients_count' => $recipientsCount,
            'sent_at' => now(),
        ])->saveQuietly();
    }

    /**
     * @param  array<int, string>  $moduleTypes
     */
    private function scopeToModuleTypes(Builder $query, array $moduleTypes): void
    {
        $query->where(function (Builder $query) use ($moduleTypes): void {
            foreach ($moduleTypes as $moduleType) {
                if ($moduleType === 'customer') {
                    $query->orWhereNull('module_type');

                    continue;
                }

                $query->orWhere('module_type', $moduleType);
            }
        });
    }
}
