<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\CleaningWorkerSystemNotification;
use Illuminate\Database\Eloquent\Collection;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class CleaningWorkerNotificationService
{
    public function notifyNewNeighborhood(CleaningNeighborhood $neighborhood): void
    {
        $neighborhoodName = mb_trim((string) ($neighborhood->name_ar ?: $neighborhood->name_en));

        User::query()
            ->where('is_active', true)
            ->whereHas('worker')
            ->chunkById(200, function (Collection $users) use ($neighborhood, $neighborhoodName): void {
                foreach ($users as $user) {
                    $user->notify(new CleaningWorkerSystemNotification(
                        canonicalType: 'cleaning.neighborhood.created',
                        templateContext: [
                            'neighborhood_name' => $neighborhoodName,
                        ],
                        extraData: [
                            'neighborhoodId' => (int) $neighborhood->id,
                            'neighborhoodName' => $neighborhoodName,
                            'action' => 'neighborhood_created',
                        ],
                    ));
                }
            });
    }

    public function notifyTrustScoreChanged(
        Worker $worker,
        int $scoreBefore,
        int $scoreAfter,
        string $reason = 'admin_manual_adjustment',
    ): void {
        if ($scoreBefore === $scoreAfter) {
            return;
        }

        $worker->loadMissing('user');
        $user = $worker->user;

        if (! $user instanceof User || ! (bool) $user->is_active) {
            return;
        }

        $delta = $scoreAfter - $scoreBefore;

        $user->notify(new CleaningWorkerSystemNotification(
            canonicalType: 'cleaning.worker.trust_changed',
            templateContext: [
                'score_before' => (string) $scoreBefore,
                'score_after' => (string) $scoreAfter,
                'delta' => ($delta > 0 ? '+' : '').(string) $delta,
            ],
            extraData: [
                'workerId' => (int) $worker->id,
                'trustScore' => $scoreAfter,
                'previousTrustScore' => $scoreBefore,
                'scoreDelta' => $delta,
                'reason' => $reason,
                'action' => 'trust_score_changed',
            ],
        ));
    }
}
