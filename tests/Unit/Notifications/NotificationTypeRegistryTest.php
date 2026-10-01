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
    'preferred worker rejection decision required' => [
        'cleaning.booking.preferred_worker_rejected_decision_required',
        'preferred_worker_rejection_decision_required',
        'Preferred worker declined',
        'The preferred worker declined the order. Open the app to make it a public request or cancel it without fees.',
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

it('falls back to notification config files when loaded config is stale or incomplete', function (): void {
    config()->set('notification_type_extensions.types', [
        'cleaning.booking.legacy_cached_type' => [
            'legacy_type' => 'legacy_cached_type',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [],
        ],
    ]);
    config()->set('cleaning_repeated_notification_types.types', []);
    config()->set('platform_coupon_notification_types.types', []);

    $registry = new NotificationTypeRegistry();

    expect($registry->definition('cleaning.booking.time_extension_accepted')['legacy_type'])
        ->toBe('time_extension_accepted')
        ->and($registry->definition('cleaning.booking.worker_extension_response_reminder')['legacy_type'])
        ->toBe('cleaning_worker_extension_response_reminder')
        ->and($registry->definition('marketing.coupon.available')['legacy_type'])
        ->toBe('coupon_available')
        ->and($registry->definition('cleaning.booking.legacy_cached_type')['legacy_type'])
        ->toBe('legacy_cached_type');
});

it('keeps canonical Arabic notification copy authoritative over extra data collisions', function (): void {
    $payload = app(NotificationPayloadBuilder::class)->makeDatabasePayload(
        canonicalType: 'supermarket.order.rejected',
        templateContext: ['order_number' => 'SM-QA-001'],
        extraData: [
            'order_id' => 1,
            'message' => 'English text that must not override the canonical Arabic copy.',
            'title' => 'English title',
            'body' => 'English body',
            'type' => 'wrong_type',
        ],
    );

    expect($payload['type'])->toBe('supermarket_order_rejected')
        ->and($payload['canonical_type'])->toBe('supermarket.order.rejected')
        ->and($payload['module'])->toBe('supermarket')
        ->and(preg_match('/\p{Arabic}/u', (string) $payload['title']))->toBe(1)
        ->and(preg_match('/\p{Arabic}/u', (string) $payload['body']))->toBe(1)
        ->and($payload['message'])->toBe($payload['body'])
        ->and($payload['title'])->not->toBe('English title')
        ->and($payload['body'])->not->toBe('English body');
});
