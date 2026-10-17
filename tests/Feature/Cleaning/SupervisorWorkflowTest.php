<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;

function workflowPeopleAndBooking(int $arrived = 3): array
{
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'booking_kind' => 'supervised_team',
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 3, 'estimated_hours' => 6,
        'scheduled_date' => '2026-10-18', 'scheduled_time' => '09:00',
    ]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'assigned_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    for ($i = 0; $i < 3; $i++) {
        $booking->workerAssignments()->create([
            'worker_id' => Worker::factory()->create()->id,
            'status' => 'accepted', 'accepted_at' => now(),
            'arrived_at' => $i < $arrived ? now() : null,
        ]);
    }

    return [$supervisor, $customer, $booking];
}

it('does not redirect unauthenticated mobile API requests to a nonexistent web login', function () {
    $this->get('/api/v1/supervisor/me', ['Accept' => 'text/html'])->assertUnauthorized();
});

it('blocks verification when the team is incomplete and protects customer secret', function () {
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking(2);
    Sanctum::actingAs($supervisor);
    $prefix = "/api/v1/supervisor/bookings/{$booking->id}";
    $this->postJson("$prefix/verification-requests")->assertStatus(409);
    $this->getJson("$prefix/verification/status")->assertOk()->assertJsonPath('data.status', 'not_requested');
    $this->assertDatabaseCount('cleaning_supervisor_verifications', 0);
    $this->getJson("/api/v1/supervised-team/customer/bookings/{$booking->id}/verification-code")
        ->assertNotFound();
    $booking->workerAssignments()->whereNull('arrived_at')->update(['arrived_at' => now()]);
    $this->postJson("$prefix/verification-requests")->assertOk()->assertJsonPath('data.status', 'pending')
        ->assertDontSee('customer_code_ciphertext');
    $this->postJson("$prefix/verification-requests")->assertOk();
    $this->assertDatabaseCount('cleaning_supervisor_verifications', 1);
    $this->getJson("$prefix/verification/status")->assertOk()
        ->assertJsonPath('data.entryActor', 'supervisor')->assertJsonPath('data.codeRecipient', 'customer')
        ->assertJsonPath('data.teamState', 'verification_pending')->assertJsonMissingPath('data.code');
    $this->getJson("/api/v1/supervised-team/customer/bookings/{$booking->id}/verification-code")
        ->assertNotFound();
    Sanctum::actingAs($customer);
    $this->getJson("/api/v1/supervised-team/customer/bookings/{$booking->id}/verification-code")
        ->assertOk()->assertJsonStructure(['data' => ['code', 'expiresAt']]);
});

it('completes verified team execution, customer decisions and a team-level report', function () {
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking();
    $supervisorUrl = "/api/v1/supervisor/bookings/{$booking->id}";
    $customerUrl = "/api/v1/supervised-team/customer/bookings/{$booking->id}";
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/start")->assertStatus(409);
    $this->postJson("$supervisorUrl/verification-requests")->assertOk();
    Sanctum::actingAs($customer);
    $code = $this->getJson("$customerUrl/verification-code")->assertOk()->json('data.code');
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/verification/confirm", ['code' => '000000'])->assertUnprocessable();
    $this->postJson("$supervisorUrl/verification/confirm", ['code' => $code])->assertOk()
        ->assertJsonPath('data.status', 'verified');
    $this->getJson("$supervisorUrl")->assertJsonPath('data.teamState', 'verified');
    $this->postJson("$supervisorUrl/start")->assertOk()->assertJsonPath('data.teamState', 'in_progress');
    $this->postJson("$supervisorUrl/start")->assertOk(); // idempotent
    $this->postJson("$supervisorUrl/extension-requests", [
        'minutes' => 30, 'reason' => 'الفريق بحاجة إلى نصف ساعة إضافية',
    ])->assertCreated()->assertJsonPath('data.status', 'pending');
    $extensionId = DB::table('cleaning_supervisor_extensions')->value('id');
    $originalEnd = $booking->fresh()->supervised_scheduled_end_at;
    Sanctum::actingAs($customer);
    $this->postJson("$customerUrl/extensions/$extensionId/decision", ['decision' => 'approved'])
        ->assertOk()->assertJsonPath('data.status', 'approved');
    expect($booking->fresh()->supervised_scheduled_end_at->gt($originalEnd))->toBeTrue();
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/handover-requests")->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->postJson("$supervisorUrl/reports", ['summary' => 'فريقنا أنهى العمل بشكل سليم'])->assertStatus(409);
    Sanctum::actingAs($customer);
    $this->postJson("$customerUrl/handover-decision", ['decision' => 'confirmed'])
        ->assertOk()->assertJsonPath('data.status', 'confirmed');
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/reports", ['summary' => 'فريقنا أنهى العمل بشكل سليم'])
        ->assertCreated()->assertJsonPath('data.status', 'submitted');
    $this->postJson("$supervisorUrl/reports", ['summary' => 'فريقنا أنهى العمل بشكل سليم'])->assertOk();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id, 'status' => 'completed', 'supervised_state' => 'completed',
    ]);
    $this->assertDatabaseCount('cleaning_supervisor_reports', 1);
    expect($booking->workerAssignments()->where('status', 'completed')->count())->toBe(3);
    expect($booking->workerAssignments()->whereNull('work_finished_at')->count())->toBe(0);
});

it('restricts handover decisions to booking customer and retains disputes', function () {
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking();
    $prefix = "/api/v1/supervisor/bookings/{$booking->id}";
    $customerUrl = "/api/v1/supervised-team/customer/bookings/{$booking->id}";
    Sanctum::actingAs($supervisor);
    $this->postJson("$prefix/verification-requests")->assertOk();
    Sanctum::actingAs($customer);
    $code = $this->getJson("$customerUrl/verification-code")->json('data.code');
    Sanctum::actingAs($supervisor);
    $this->postJson("$prefix/verification/confirm", ['code' => $code])->assertOk();
    $this->postJson("$prefix/start")->assertOk();
    $this->postJson("$prefix/handover-requests")->assertCreated();
    $this->postJson("$customerUrl/handover-decision", ['decision' => 'confirmed'])->assertNotFound();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson("$customerUrl/handover-decision", ['decision' => 'confirmed'])->assertNotFound();
    Sanctum::actingAs($customer);
    $this->postJson("$customerUrl/handover-decision", [
        'decision' => 'objected', 'note' => 'جودة الخدمة تحتاج مراجعة',
    ])->assertOk()->assertJsonPath('data.status', 'objected');
    Sanctum::actingAs($supervisor);
    $this->postJson("$prefix/reports", ['summary' => 'ملخص عمل مكتمل وتفصيلي'])->assertStatus(409);
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id, 'status' => 'under_dispute',
    ]);
});

it('keeps supervisor photos private and scoped to the active booking assignment', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking();
    $path = "/api/v1/supervisor/bookings/{$booking->id}/media";
    Sanctum::actingAs($supervisor);
    $this->postJson($path, [
        'purpose' => 'before',
        'photo' => Illuminate\Http\UploadedFile::fake()->image('evidence.jpg'),
    ])->assertCreated()->assertJsonPath('data.purpose', 'before');
    $entry = DB::table('cleaning_supervisor_media')->where('cleaning_booking_id', $booking->id)->first();
    expect($entry)->not->toBeNull();
    Illuminate\Support\Facades\Storage::disk('local')->assertExists($entry->path);
    $this->get("$path/{$entry->id}")->assertOk();
    Sanctum::actingAs($customer);
    $this->get("$path/{$entry->id}")->assertForbidden();
    $this->postJson($path, [
        'purpose' => 'before', 'photo' => Illuminate\Http\UploadedFile::fake()->create('not-image.txt', 5, 'text/plain'),
    ])->assertForbidden();
});

it('lets only the booking customer discover pending team decisions without exposing verification secrets', function () {
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking();
    $customerUrl = "/api/v1/supervised-team/customer/bookings/{$booking->id}";
    $supervisorUrl = "/api/v1/supervisor/bookings/{$booking->id}";
    $this->getJson($customerUrl)->assertUnauthorized();
    Sanctum::actingAs($supervisor);
    $this->getJson($customerUrl)->assertNotFound();
    $this->postJson("$supervisorUrl/verification-requests")->assertOk();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->getJson($customerUrl)->assertNotFound();
    Sanctum::actingAs($customer);
    $this->getJson($customerUrl)->assertOk()
        ->assertJsonPath('data.allowedActions', ['view_verification_code'])
        ->assertJsonMissingPath('data.code')->assertDontSee('code_hash')->assertDontSee('customer_code_ciphertext');
    $code = $this->getJson("$customerUrl/verification-code")->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->json('data.code');
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/verification/confirm", ['code' => $code])->assertOk();
    $this->postJson("$supervisorUrl/start")->assertOk();
    $extension = $this->postJson("$supervisorUrl/extension-requests", [
        'additionalMinutes' => 30, 'reason' => 'نحتاج وقتاً إضافياً لإنهاء العمل',
    ])->assertCreated()->json('data.id');
    Sanctum::actingAs($customer);
    $this->getJson($customerUrl)->assertOk()->assertJsonPath('data.allowedActions', ['decide_extension'])
        ->assertJsonPath('data.extensions.0.id', $extension)->assertJsonPath('data.extensions.0.additionalMinutes', 30);
    $this->postJson("$customerUrl/extensions/$extension/decision", ['decision' => 'rejected'])->assertOk();
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/handover-requests")->assertCreated();
    Sanctum::actingAs($customer);
    $this->getJson($customerUrl)->assertOk()->assertJsonPath('data.allowedActions', ['decide_handover'])
        ->assertJsonPath('data.handover.status', 'pending');
    $this->postJson("$customerUrl/handover-decision", ['decision' => 'confirmed'])->assertOk();
    $this->getJson($customerUrl)->assertOk()->assertJsonPath('data.allowedActions', [])
        ->assertJsonPath('data.handover.status', 'confirmed');
});

it('never revives a cancelled booking with a previously issued verification code', function () {
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking();
    $supervisorUrl = "/api/v1/supervisor/bookings/{$booking->id}";
    $customerUrl = "/api/v1/supervised-team/customer/bookings/{$booking->id}";
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/verification-requests")->assertOk();
    Sanctum::actingAs($customer);
    $code = $this->getJson("$customerUrl/verification-code")->json('data.code');
    $booking->forceFill(['status' => CleaningBookingStatus::Cancelled])->save();
    $this->getJson("$customerUrl/verification-code")->assertNotFound();
    $this->getJson($customerUrl)->assertOk()->assertJsonPath('data.allowedActions', []);
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/verification/confirm", ['code' => $code])->assertConflict();
    $this->assertDatabaseHas('cleaning_bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    $this->assertDatabaseHas('cleaning_supervisor_verifications', ['cleaning_booking_id' => $booking->id, 'status' => 'pending']);
});

it('rejects expired or exhausted verification challenges without starting the team', function (bool $exhaustAttempts) {
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking();
    $supervisorUrl = "/api/v1/supervisor/bookings/{$booking->id}";
    $customerUrl = "/api/v1/supervised-team/customer/bookings/{$booking->id}";
    Sanctum::actingAs($supervisor);
    $this->postJson("$supervisorUrl/verification-requests")->assertOk();
    Sanctum::actingAs($customer);
    $code = $this->getJson("$customerUrl/verification-code")->json('data.code');
    Sanctum::actingAs($supervisor);
    if ($exhaustAttempts) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson("$supervisorUrl/verification/confirm", ['code' => '000000'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('cleaning_supervisor_verifications', ['cleaning_booking_id' => $booking->id, 'attempts' => 5]);
    } else {
        $this->travel(10)->minutes();
        $this->getJson("$supervisorUrl/verification/status")->assertOk()->assertJsonPath('data.status', 'expired');
    }
    $this->postJson("$supervisorUrl/verification/confirm", ['code' => $code])->assertUnprocessable();
    $this->postJson("$supervisorUrl/start")->assertConflict();
    Sanctum::actingAs($customer);
    $this->getJson("$customerUrl/verification-code")->assertNotFound();
    $this->assertDatabaseHas('cleaning_bookings', ['id' => $booking->id, 'work_started_at' => null]);
})->with(['five failures' => true, 'expiry boundary' => false]);

it('rejects legacy customer lifecycle decisions for supervised team bookings', function (string $action, array $payload) {
    [$supervisor, $customer, $booking] = workflowPeopleAndBooking();
    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/$action", $payload)
        ->assertForbidden()->assertJsonPath('code', 'SUPERVISED_TEAM_ACTION_FORBIDDEN');
    $this->assertDatabaseHas('cleaning_bookings', ['id' => $booking->id, 'status' => 'worker_assigned']);
})->with([
    'verification' => ['start-verification/confirm', ['code' => '123456']],
    'confirmation' => ['completion/confirm', []],
    'objection' => ['completion/reject', ['reason' => 'الخدمة تحتاج مراجعة']],
    'extension' => ['completion/extend-time', ['additionalMinutes' => 30]],
]);
