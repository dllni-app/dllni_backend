<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Modules\Resturants\Models\RestaurantGroupOrderParticipant;

Broadcast::channel('vote.{voteId}', function (User $user, int $voteId): bool {
    // Authorize any authenticated user to listen to vote updates
    // In a more restrictive scenario, you could check if the user is part of a group voting
    return (int) $user->id !== 0;
}, ['guards' => ['sanctum']]);

Broadcast::channel('group-order.{groupOrderId}', function (User $user, int $groupOrderId): bool {
    $isOrganizer = \Modules\Resturants\Models\RestaurantGroupOrder::query()
        ->whereKey($groupOrderId)
        ->where('user_id', $user->id)
        ->exists();

    if ($isOrganizer) {
        return true;
    }

    return RestaurantGroupOrderParticipant::query()
        ->where('group_order_id', $groupOrderId)
        ->where('user_id', $user->id)
        ->exists();
}, ['guards' => ['sanctum']]);
