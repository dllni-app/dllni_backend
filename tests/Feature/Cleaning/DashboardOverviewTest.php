<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns dashboard overview with kpis and alerts', function () {
    $response = $this->getJson('/api/v1/cleaning/dashboard/overview');

    $response->assertOk();
    $response->assertJsonStructure([
        'kpis' => [
            'todayCleaningBookings',
            'todayEventBookings',
            'openDisputes',
            'pendingWorkerAssignments',
            'activeSosCount',
        ],
        'alerts',
    ]);
});
