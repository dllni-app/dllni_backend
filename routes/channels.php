<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('vote.{voteId}', function (User $user, int $voteId): bool {
    // Authorize any authenticated user to listen to vote updates
    // In a more restrictive scenario, you could check if the user is part of a group voting
    return (int) $user->id !== 0;
}, ['guards' => ['sanctum']]);
