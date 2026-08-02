<?php

declare(strict_types=1);

use App\Notifications\Core\NotificationPayloadBuilder;
use App\Notifications\Core\NotificationTypeRegistry;

it('builds configured cleaning notifications', function (
    string $canonicalType,
    string $legacyType,
    string $title,
    string $body,
): void {
    $registry = app(NotificationTypeRegistry::class);
    $payload = app(NotificationPayloadBuilder::class)->makeDatabasePayload(
        canonicalType: $canonicalType,
        templateContext: ['booking_number' => 'CL-100'],
        extraData: ['bookingId' => 100],
        locale: 'en',
    );

    expect($payload['type'])->toBe($legacyType)
        ->and($payload['canonical_type'])->toBe($canonicalType)
        ->and($payload['module'])->toBe('cleaning')
        ->and($payload['category'])->toBe('orders')
        ->and($payload['priority'])->toBe('high')
        ->and($payload['title'])->toBe($title)
        ->and($payload['body'])->toBe($body);

    expect($registry->canonicalFromLegacy($legacyType))->toBe($canonicalType)
        ->and($registry->definition($canonicalType)['channels'])->toBe(['database', 'push']);
})->with([
    'worker rejected' => [
        'cleaning.booking.worker_rejected',
        'worker_rejected',
        'Worker rejected order',
        'The service provider rejected booking CL-100.',
    ],
    'preferred worker rejected' => [
        'cleaning.booking.preferred_worker_rejected',
        'preferred_worker_rejected',
        'Preferred worker declined',
        'The preferred worker declined the order. We changed it to a public request and are looking for another worker.',
    ],
    'accepted' => [
        'cleaning.booking.time_extension_accepted',
        'time_extension_accepted',
        'Time extension accepted',
        'The time extension was accepted for cleaning booking CL-100.',
    ],
    'rejected' => [
        'cleaning.booking.time_extension_rejected',
        'time_extension_rejected',
        'Time extension rejected',
        'The time extension was rejected for cleaning booking CL-100.',
    ],
]);
