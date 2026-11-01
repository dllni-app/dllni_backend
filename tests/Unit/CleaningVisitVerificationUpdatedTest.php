<?php

declare(strict_types=1);

use Modules\Cleaning\Events\CleaningVisitVerificationUpdated;

it('broadcasts only non-secret confirmed visit identity metadata', function (): void {
    $event = new CleaningVisitVerificationUpdated(12, 31, 'worker_supervisor', 44);

    expect($event->broadcastAs())->toBe('CleaningVisitVerificationUpdated')
        ->and($event->broadcastWith())->toBe([
            'bookingId' => 12,
            'visitId' => 31,
            'kind' => 'worker_supervisor',
            'subjectId' => 44,
            'status' => 'confirmed',
        ])
        ->and((string) $event->broadcastOn()[0]->name)->toBe('private-cleaning-booking.12');
});
