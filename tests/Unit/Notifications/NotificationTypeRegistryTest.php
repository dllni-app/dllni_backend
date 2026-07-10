<?php

declare(strict_types=1);

use App\Notifications\Core\NotificationPayloadBuilder;
use App\Notifications\Core\NotificationTypeRegistry;

it('builds configured cleaning time extension response notifications', function (
    string $canonicalType,
    string $legacyType,
    string $title,
    string $body,
): void {
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

    expect(app(NotificationTypeRegistry::class)->canonicalFromLegacy($legacyType))->toBe($canonicalType);
})->with([
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
