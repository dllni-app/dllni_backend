<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningEventType;

use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed(CleaningFinancialSettingsSeeder::class);
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Event venue test'],
        ['description' => 'Test policy', 'rules' => ['free_until_hours' => 24], 'is_active' => true, 'is_default' => true],
    );
    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Event venue test billing'],
        ['billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true], 'is_active' => true, 'is_default' => true],
    );
    Sanctum::actingAs(User::factory()->create());
});

it('accepts a configured event venue on quote and creation, and rejects an unconfigured choice', function (): void {
    $eventType = CleaningEventType::query()->create([
        'name' => 'Test venue event',
        'slug' => 'test_venue_contract_'.uniqid(),
        'sort_order' => 998,
        'is_active' => true,
    ]);
    $eventType->fields()->create([
        'key' => 'venue_type',
        'label' => 'نوع المكان',
        'field_type' => 'select',
        'is_required' => true,
        'options' => ['home', 'hall', 'garden'],
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $date = now(config('app.timezone'))->addDays(3)->toDateString();
    $payload = [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus - Test Hall',
            'location_name' => 'Event hall',
            'eventType' => 'family_dinner',
            'guestCount' => 25,
            'venueType' => 'hall',
            'customService' => 'Tables and guest support',
            'hours' => 3,
        ],
        'event' => [
            'eventTypeId' => $eventType->id,
            'dynamicAnswers' => ['venue_type' => 'hall'],
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 2,
        'scheduledDate' => $date,
        'scheduledTime' => '14:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ];
    $quotePayload = [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'event' => $payload['event'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'assignmentMode' => $payload['assignmentMode'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
    ];

    postJson('/api/v1/user/cleaning/orders/estimate-price', $quotePayload)->assertOk();
    postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();

    $quotePayload['propertyDetails']['venueType'] = 'not_configured_here';
    postJson('/api/v1/user/cleaning/orders/estimate-price', $quotePayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['propertyDetails.venueType']);
});
