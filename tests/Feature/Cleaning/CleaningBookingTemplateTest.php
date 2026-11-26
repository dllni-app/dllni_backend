<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

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