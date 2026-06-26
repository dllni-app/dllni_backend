<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Test Cleaning Cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ]
    );

    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Test Cleaning Billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ]
    );
});

function femaleWorkerSafetyOrderPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ], $overrides);
}

it('returns the female worker safety policy for authenticated users', function (): void {
    Sanctum::actingAs(User::factory()->create());

    getJson('/api/v1/user/cleaning/orders/female-worker-safety-policy')
        ->assertOk()
        ->assertJsonPath('data.requiredWhen.field', 'genderPreference')
        ->assertJsonPath('data.requiredWhen.value', 'female')
        ->assertJsonPath('data.pledge.version', app(FemaleWorkerSafetyPolicyService::class)->version())
        ->assertJsonPath('data.options.0.value', FemaleWorkerSafetyPolicyService::BENEFICIARY_FEMALE_PRESENT)
        ->assertJsonPath('data.options.1.value', FemaleWorkerSafetyPolicyService::BENEFICIARY_MALE_ALONE)
        ->assertJsonPath('data.options.1.allowed', false);
});

it('requires safety confirmation when requesting a female worker', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/v1/user/cleaning/orders', femaleWorkerSafetyOrderPayload([
        'genderPreference' => 'female',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'workEnvironmentConfirmation',
            'workEnvironmentConfirmation.beneficiaryPresence',
            'workEnvironmentConfirmation.pledgeAccepted',
            'workEnvironmentConfirmation.pledgeVersion',
        ]);
});

it('blocks female worker bookings when a man is alone at the site', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/v1/user/cleaning/orders', femaleWorkerSafetyOrderPayload([
        'genderPreference' => 'female',
        'workEnvironmentConfirmation' => [
            'beneficiaryPresence' => FemaleWorkerSafetyPolicyService::BENEFICIARY_MALE_ALONE,
            'pledgeAccepted' => true,
            'pledgeVersion' => app(FemaleWorkerSafetyPolicyService::class)->version(),
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workEnvironmentConfirmation.beneficiaryPresence']);

    expect(DB::table('cleaning_bookings')->count())->toBe(0);
});

it('creates female worker bookings after valid safety confirmation and stores the pledge snapshot', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $policy = app(FemaleWorkerSafetyPolicyService::class);

    $response = postJson('/api/v1/user/cleaning/orders', femaleWorkerSafetyOrderPayload([
        'genderPreference' => 'female',
        'workEnvironmentConfirmation' => [
            'beneficiaryPresence' => FemaleWorkerSafetyPolicyService::BENEFICIARY_FEMALE_PRESENT,
            'pledgeAccepted' => true,
            'pledgeVersion' => $policy->version(),
        ],
    ]));

    $response->assertCreated()->assertJsonPath('order.genderPreference', 'female');

    $orderId = (int) $response->json('order.id');

    expect(DB::table('cleaning_bookings')
        ->where('id', $orderId)
        ->where('customer_id', $user->id)
        ->where('gender_preference', 'female')
        ->where('work_environment_beneficiary_presence', FemaleWorkerSafetyPolicyService::BENEFICIARY_FEMALE_PRESENT)
        ->where('female_worker_safety_pledge_accepted', true)
        ->where('female_worker_safety_pledge_version', $policy->version())
        ->where('female_worker_safety_pledge_text', $policy->pledgeText())
        ->whereNotNull('female_worker_safety_pledge_accepted_at')
        ->exists())->toBeTrue();
});

it('keeps male worker bookings backward compatible without safety confirmation', function (): void {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/v1/user/cleaning/orders', femaleWorkerSafetyOrderPayload([
        'genderPreference' => 'male',
    ]))
        ->assertCreated()
        ->assertJsonPath('order.genderPreference', 'male');
});
