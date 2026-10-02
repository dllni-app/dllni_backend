<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantReputationLog;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreTrustLog;

final class MerchantGovernanceService
{
    public function suspendRestaurant(Restaurant $restaurant, CarbonInterface|string $until, string $reason, User $actor): void
    {
        $this->updateAndAudit(
            $restaurant,
            ['suspension_until' => is_string($until) ? Carbon::parse($until) : $until],
            'restaurant_suspended',
            $reason,
            $actor,
        );
    }

    public function unsuspendRestaurant(Restaurant $restaurant, string $reason, User $actor): void
    {
        $this->updateAndAudit($restaurant, ['suspension_until' => null], 'restaurant_unsuspended', $reason, $actor);
    }

    public function setRestaurantActive(Restaurant $restaurant, bool $active, string $reason, User $actor): void
    {
        $this->updateAndAudit($restaurant, ['is_active' => $active], $active ? 'restaurant_activated' : 'restaurant_deactivated', $reason, $actor);
    }

    public function setRestaurantFeatured(Restaurant $restaurant, bool $featured, string $reason, User $actor): void
    {
        $this->updateAndAudit($restaurant, ['is_featured' => $featured], $featured ? 'restaurant_featured' : 'restaurant_unfeatured', $reason, $actor);
    }

    public function adjustRestaurantReputation(Restaurant $restaurant, int $score, string $reason, User $actor): void
    {
        DB::transaction(function () use ($restaurant, $score, $reason, $actor): void {
            $before = (int) $restaurant->reputation_score;

            $restaurant->update(['reputation_score' => $score]);

            RestaurantReputationLog::query()->create([
                'restaurant_id' => $restaurant->id,
                'score_delta' => $score - $before,
                'reason' => 'admin_manual_adjustment: '.mb_trim($reason),
            ]);

            $this->log(
                $restaurant,
                'restaurant_reputation_adjusted',
                $reason,
                $actor,
                ['reputation_score' => $before],
                ['reputation_score' => $score],
            );
        });
    }

    public function suspendStore(SmStore $store, CarbonInterface|string $until, string $reason, User $actor): void
    {
        $this->updateAndAudit(
            $store,
            ['suspension_until' => is_string($until) ? Carbon::parse($until) : $until],
            'supermarket_store_suspended',
            $reason,
            $actor,
        );
    }

    public function unsuspendStore(SmStore $store, string $reason, User $actor): void
    {
        $this->updateAndAudit($store, ['suspension_until' => null], 'supermarket_store_unsuspended', $reason, $actor);
    }

    public function setStoreActive(SmStore $store, bool $active, string $reason, User $actor): void
    {
        $this->updateAndAudit($store, ['is_active' => $active], $active ? 'supermarket_store_activated' : 'supermarket_store_deactivated', $reason, $actor);
    }

    public function setStoreFeatured(SmStore $store, bool $featured, string $reason, User $actor): void
    {
        $this->updateAndAudit($store, ['is_featured' => $featured], $featured ? 'supermarket_store_featured' : 'supermarket_store_unfeatured', $reason, $actor);
    }

    public function adjustStoreTrust(SmStore $store, int $score, string $reason, User $actor): void
    {
        DB::transaction(function () use ($store, $score, $reason, $actor): void {
            $before = (int) $store->trust_score;

            $store->update(['trust_score' => $score]);

            SmStoreTrustLog::query()->create([
                'store_id' => $store->id,
                'event_type' => 'admin_manual_adjustment',
                'score_delta' => $score - $before,
                'score_after' => $score,
                'notes' => mb_trim($reason),
                'triggered_by_user_id' => $actor->id,
            ]);

            $this->log(
                $store,
                'supermarket_store_trust_adjusted',
                $reason,
                $actor,
                ['trust_score' => $before],
                ['trust_score' => $score],
            );
        });
    }

    private function updateAndAudit(Restaurant|SmStore $record, array $changes, string $event, string $reason, User $actor): void
    {
        DB::transaction(function () use ($record, $changes, $event, $reason, $actor): void {
            $before = array_intersect_key($record->getAttributes(), $changes);
            $record->update($changes);
            $after = array_intersect_key($record->fresh()->getAttributes(), $changes);

            $this->log($record, $event, $reason, $actor, $before, $after);
        });
    }

    private function log(Restaurant|SmStore $record, string $event, string $reason, User $actor, array $before, array $after): void
    {
        activity('merchant_governance')
            ->performedOn($record)
            ->causedBy($actor)
            ->withProperties([
                'reason' => mb_trim($reason),
                'before' => $before,
                'after' => $after,
            ])
            ->log($event);
    }
}
