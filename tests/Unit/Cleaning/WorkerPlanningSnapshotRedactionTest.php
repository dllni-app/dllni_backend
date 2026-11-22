<?php

declare(strict_types=1);

namespace Tests\Unit\Cleaning;

use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WorkerPlanningSnapshotRedactionTest extends TestCase
{
    public function test_nested_customer_data_is_removed_without_losing_room_and_schedule_structure(): void
    {
        $resource = new CleaningBookingResource(new CleaningBooking);
        $redact = new ReflectionMethod(CleaningBookingResource::class, 'safeWorkerPlanningSnapshot');

        $planning = [
            'rooms' => [
                [
                    'id' => 42,
                    'type' => 'kitchen',
                    'name' => 'مطبخ',
                    'contactPhone' => 'private',
                    'notes' => 'private free text',
                    'siteCoordinates' => ['lat' => 36.2, 'lng' => 37.1],
                    'tasks' => [['type' => 'deep', 'durationMinutes' => 90]],
                ],
            ],
            'appointments' => [
                [
                    'date' => '2026-11-25',
                    'scheduledTime' => '10:00',
                    'fullAddress' => 'private home',
                    'location' => ['latitude' => 36.2, 'longitude' => 37.1],
                    'roomSchedules' => [['roomId' => 42, 'durationMinutes' => 90]],
                    'customer' => ['name' => 'private', 'phoneNumber' => 'private'],
                    'geoPoint' => 'private',
                ],
            ],
        ];

        self::assertSame([
            'rooms' => [
                [
                    'id' => 42,
                    'type' => 'kitchen',
                    'name' => 'مطبخ',
                    'tasks' => [['type' => 'deep', 'durationMinutes' => 90]],
                ],
            ],
            'appointments' => [
                [
                    'date' => '2026-11-25',
                    'scheduledTime' => '10:00',
                    'roomSchedules' => [['roomId' => 42, 'durationMinutes' => 90]],
                ],
            ],
        ], $redact->invoke($resource, $planning));
    }

    public function test_numeric_indices_and_non_sensitive_nested_values_are_preserved(): void
    {
        $resource = new CleaningBookingResource(new CleaningBooking);
        $redact = new ReflectionMethod(CleaningBookingResource::class, 'safeWorkerPlanningSnapshot');

        self::assertSame(
            [['roomId' => 7, 'serviceType' => 'regular']],
            $redact->invoke($resource, [[
                'roomId' => 7,
                'serviceType' => 'regular',
                'mapUrl' => 'private',
                'customerEmail' => 'private',
            ]]),
        );
    }
}
