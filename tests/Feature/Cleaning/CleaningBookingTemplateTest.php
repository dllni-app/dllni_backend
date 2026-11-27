<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('property address link is owner scoped, reflects edits and survives deletion without copying data', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $other = User::factory()->create(['is_active' => true]);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $customer->id, 'label' => 'Home', 'neighborhood' => 'Midan', 'street' => 'First street',
    ]);
    $foreign = \Modules\User\Models\UserAddress::factory()->create(['user_id' => $other->id]);
    Sanctum::actingAs($customer);
    $url = '/api/v1/cleaning/booking-templates';
    $inputs = ['source' => 'v2', 'propertyKey' => 'apartment', 'addressId' => $address->id];
    $payload = ['name' => 'Home', 'creationKey' => (string) Str::uuid(), 'inputs' => $inputs];
    $id = $this->postJson($url, $payload)->assertCreated()
        ->assertJsonPath('data.addressId', $address->id)
        ->assertJsonPath('data.addressAvailable', true)
        ->assertJsonPath('data.addressLabel', 'Home')->json('data.id');
    $address->update(['street' => 'Corrected street']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.addressText', 'Midan، Corrected street');
    $this->patchJson("$url/$id", ['revision' => 1, 'inputs' => [...$inputs, 'addressId' => $foreign->id]])
        ->assertUnprocessable();
    $address->delete();
    $this->getJson("$url/$id")->assertOk()->assertJsonPath('data.addressAvailable', false)
        ->assertJsonPath('data.addressLabel', null)->assertJsonPath('data.inputs.addressId', $address->id);
});

test('customer templates are partial, idempotent, private and revisioned', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $url = '/api/v1/cleaning/booking-templates';
    $payload = [
        'name' => 'البيت',
        'creationKey' => (string) Str::uuid(),
        'inputs' => ['source' => 'v2', 'propertyKey' => 'apartment', 'rooms' => [
            ['key' => 'bedroom.1', 'type' => 'bedroom', 'index' => 1],
        ], 'workerCount' => 2, 'recurring' => true, 'occurrences' => 4],
    ];
    $id = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson($url, [...$payload, 'name' => 'المكتب'])->assertConflict();
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("$url/$id")->assertOk()->assertJsonPath('data.inputs', $payload['inputs']);
    $this->patchJson("$url/$id", ['name' => 'المكتب', 'revision' => 1])
        ->assertOk()->assertJsonPath('data.revision', 2);
    $this->patchJson("$url/$id", ['name' => 'قديم', 'revision' => 1])->assertConflict();
    $this->deleteJson("$url/$id", ['revision' => 1])->assertConflict();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("$url/$id")->assertNotFound();
    $this->patchJson("$url/$id", ['name' => 'غير مسموح', 'revision' => 2])->assertNotFound();
    $this->deleteJson("$url/$id", ['revision' => 2])->assertNotFound();
    Sanctum::actingAs($customer);
    $this->deleteJson("$url/$id", ['revision' => 2])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
});

test('template storage rejects booking state and foreign addresses', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $url = '/api/v1/cleaning/booking-templates';
    $base = ['name' => 'البيت', 'creationKey' => (string) Str::uuid()];
    $inputs = ['source' => 'v3', 'propertyKey' => 'apartment'];
    foreach (['scheduledDate', 'couponCode', 'termsAccepted', 'price', 'bookingId'] as $key) {
        $this->postJson($url, [...$base, 'inputs' => [...$inputs, $key => 'invalid']])->assertUnprocessable();
    }
    $this->postJson($url, [...$base, 'inputs' => [...$inputs, 'addressId' => 999999]])->assertUnprocessable();
    $this->postJson($url, [...$base, 'inputs' => [...$inputs, 'answers' => ['room' => ['price' => 1]]]])->assertUnprocessable();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
});

test('template rejects non-property services and stores recurrence preferences without dates', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $url = '/api/v1/cleaning/booking-templates';
    $base = ['name' => 'Home', 'creationKey' => (string) Str::uuid()];
    foreach (['event_assistance', 'hourly_worker'] as $key) {
        $this->postJson($url, [...$base, 'inputs' => ['source' => 'v3', 'propertyKey' => $key]])->assertUnprocessable();
    }
    $inputs = ['source' => 'v3', 'propertyKey' => 'apartment',
        'recurring' => true, 'recurrencePattern' => 'weekly', 'recurrenceWeekdays' => [1, 4],
        'recurrenceIntervalWeeks' => 2, 'occurrences' => 4,
        'visitPreferences' => [['index' => 1, 'durationMinutes' => 120, 'workerCount' => 2]]];
    $this->postJson($url, [...$base, 'inputs' => [...$inputs, 'recurrenceWeekdays' => [8]]])->assertUnprocessable();
    $this->postJson($url, [...$base, 'inputs' => $inputs])->assertCreated()->assertJsonPath('data.inputs', $inputs);
});
test('template is deleted when account is anonymized', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $url = '/api/v1/cleaning/booking-templates';
    $id = $this->postJson($url, [
        'name' => 'Private home notes', 'creationKey' => (string) Str::uuid(),
        'inputs' => ['source' => 'v2', 'propertyKey' => 'apartment', 'notes' => 'private'],
    ])->assertCreated()->json('data.id');
    app(\Modules\User\Services\UserAccountService::class)->deleteAccount($customer->fresh());
    $this->assertDatabaseMissing('cleaning_booking_templates', ['id' => $id]);
});

test('template property summary uses authoritative home type', function (): void {
    $property = \Modules\Cleaning\Models\CleaningHomeType::query()->create([
        'section' => 'property', 'code' => 'template_test_property',
        'booking_value' => 'template_test_property', 'title' => 'Test property',
        'is_active' => true, 'sort_order' => 0,
    ]);
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson('/api/v1/cleaning/booking-templates', [
        'name' => 'Home', 'creationKey' => (string) Str::uuid(),
        'inputs' => ['source' => 'v2', 'propertyKey' => 'template_test_property'],
    ])->assertCreated()->assertJsonPath('data.propertySourceId', $property->id)
        ->assertJsonPath('data.propertyBookingValue', 'template_test_property')
        ->assertJsonPath('data.propertyLabel', 'Test property');
});
test('V3 templates keep relative grouping but reject calendar data and invalid group indexes', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $url = '/api/v1/cleaning/booking-templates';
    $rows = [
        ['index' => 1, 'appointmentIndex' => 1, 'durationMinutes' => 120, 'workerCount' => 2],
        ['index' => 2, 'appointmentIndex' => 1, 'durationMinutes' => 90, 'workerCount' => 1],
        ['index' => 3, 'appointmentIndex' => 2, 'durationMinutes' => 150, 'workerCount' => 3],
    ];
    $input = ['source' => 'v3', 'propertyKey' => 'apartment', 'recurring' => false, 'visitPreferences' => $rows];
    $payload = ['name' => 'Grouped', 'creationKey' => (string) Str::uuid(), 'inputs' => $input];
    $this->postJson($url, [...$payload, 'inputs' => [...$input, 'visitPreferences' => [
        [...$rows[0], 'appointmentIndex' => 31],
    ]]])->assertUnprocessable();
    foreach (['date', 'scheduledTime', 'occurrenceKey', 'appointmentKey'] as $key) {
        $this->postJson($url, [...$payload, 'inputs' => [...$input, 'visitPreferences' => [
            [...$rows[0], $key => 'forbidden'],
        ]]])->assertUnprocessable();
    }
    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.inputs.visitPreferences', $rows);
});