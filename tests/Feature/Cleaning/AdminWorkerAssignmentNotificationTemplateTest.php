<?php

declare(strict_types=1);

use App\Notifications\Core\NotificationTemplateResolver;

it('renders customer admin assignment notification with remaining workers for a multi-worker booking', function (): void {
    $copy = app(NotificationTemplateResolver::class)->resolve('cleaning.booking.worker_assigned', [
        'booking_number' => 'CLN-1001',
        'actor_role' => 'admin',
        'target_role' => 'customer',
        'required_workers' => 3,
        'remaining_workers' => 2,
    ]);

    expect($copy['title'])->toBe('تم تعيين عامل لطلب التنظيف')
        ->and($copy['body'])->toBe('تم تعيين عامل لطلب التنظيف رقم CLN-1001. المتبقي لإكمال الفريق: 2 من أصل 3 عامل.');
});

it('renders worker admin assignment notification with remaining workers for a multi-worker booking', function (): void {
    $copy = app(NotificationTemplateResolver::class)->resolve('cleaning.booking.worker_assigned', [
        'booking_number' => 'CLN-1002',
        'actor_role' => 'admin',
        'target_role' => 'worker',
        'required_workers' => 4,
        'remaining_workers' => 1,
    ]);

    expect($copy['title'])->toBe('تم تعيينك لطلب تنظيف')
        ->and($copy['body'])->toBe('تم تعيينك لتنفيذ طلب التنظيف رقم CLN-1002. المتبقي لإكمال الفريق: 1 من أصل 4 عامل.');
});

it('does not add remaining-worker copy for a single-worker admin assignment', function (): void {
    $copy = app(NotificationTemplateResolver::class)->resolve('cleaning.booking.worker_assigned', [
        'booking_number' => 'CLN-1003',
        'actor_role' => 'admin',
        'target_role' => 'customer',
        'required_workers' => 1,
        'remaining_workers' => 0,
    ]);

    expect($copy['body'])->toBe('تم تعيين عامل لطلب التنظيف رقم CLN-1003.')
        ->and($copy['body'])->not->toContain('المتبقي لإكمال الفريق');
});
