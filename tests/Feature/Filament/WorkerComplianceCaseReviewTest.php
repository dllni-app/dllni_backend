<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\WorkerComplianceCases\WorkerComplianceCaseResource;
use App\Models\User;
use App\Models\Worker;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\WorkerComplianceReviewCase;
use Modules\Cleaning\Services\WorkerComplianceCaseReviewService;
use Modules\Cleaning\Services\WorkerComplianceReviewerNotificationService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(filament()->getPanel('admin'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function complianceReviewer(array $permissions = []): User
{
    $role = Role::findOrCreate('Cleaning Ops Manager', 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function complianceCase(): WorkerComplianceReviewCase
{
    $workerUser = User::factory()->create(['module_type' => UserModuleType::CleaningWorker->value]);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    return WorkerComplianceReviewCase::query()->create([
        'worker_id' => $worker->id,
        'site_key' => hash('sha256', 'test-site'),
        'observation_started_at' => now()->subMinutes(15),
        'observation_ended_at' => now(),
        'sample_count' => 5,
        'distance_band' => 'within_25m',
        'accuracy_band' => 'within_10m',
        'rule_version' => 'pilot-v1',
        'reason_code' => 'potential_visit_needs_review',
        'status' => 'new',
        'public_reference' => (string) Illuminate\Support\Str::uuid(),
        'dedupe_key' => hash('sha256', Illuminate\Support\Str::uuid()->toString()),
    ]);
}

it('denies direct queue and case URLs without the dedicated review permission', function (): void {
    $case = complianceCase();
    $reviewer = complianceReviewer();
    $this->actingAs($reviewer);

    $this->get(WorkerComplianceCaseResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(WorkerComplianceCaseResource::getUrl('view', ['record' => $case], isAbsolute: false))->assertForbidden();
});

it('shows only coarse case evidence to authorized reviewers and records detail access', function (): void {
    $case = complianceCase();
    $reviewer = complianceReviewer(['cleaning.worker_compliance.cases.view']);
    $this->actingAs($reviewer);

    $this->get(WorkerComplianceCaseResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee($case->worker->first_name)
        ->assertDontSee($case->site_key);

    $this->get(WorkerComplianceCaseResource::getUrl('view', ['record' => $case], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee((string) $case->sample_count)
        ->assertDontSee($case->site_key);

    $event = $case->events()->where('event_type', 'evidence_viewed')->first();
    expect($event)->not->toBeNull()
        ->and($event->actor_user_id)->toBe($reviewer->id)
        ->and($event->case_reference)->toBe($case->public_reference);
});

it('locks claims, requires the assigned reviewer for resolution, and requires an independent second reviewer to confirm', function (): void {
    $case = complianceCase();
    $case->forceFill(['created_at' => now()->subMinutes(2)])->save();
    $firstReviewer = complianceReviewer([
        'cleaning.worker_compliance.cases.view',
        'cleaning.worker_compliance.cases.claim',
        'cleaning.worker_compliance.cases.review',
        'cleaning.worker_compliance.cases.confirm',
    ]);
    $secondReviewer = complianceReviewer([
        'cleaning.worker_compliance.cases.view',
        'cleaning.worker_compliance.cases.claim',
        'cleaning.worker_compliance.cases.confirm',
    ]);
    $service = app(WorkerComplianceCaseReviewService::class);

    $service->claim($case, $firstReviewer);

    expect(fn () => $service->claim($case->fresh(), $secondReviewer))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->confirmViolation($case->fresh(), $firstReviewer, 'Location proximity only.', 'evidence-42', now()))
        ->toThrow(ValidationException::class);

    $opportunityAt = now()->subMinute()->startOfSecond();
    $service->confirmViolation($case->fresh(), $secondReviewer, 'Independent evidence reviewed.', 'evidence-42', $opportunityAt);

    $case->refresh();
    $event = $case->events()->where('event_type', 'confirmed_violation')->firstOrFail();

    expect($case->status)->toBe('confirmed_violation')
        ->and($case->expires_at)->not->toBeNull()
        ->and($event->actor_user_id)->toBe($secondReviewer->id)
        ->and($event->independent_evidence_reference)->toBe('evidence-42')
        ->and($event->worker_response_opportunity_at->equalTo($opportunityAt))->toBeTrue()
        ->and($event->case_reference)->toBe($case->public_reference)
        ->and($event->expires_at)->not->toBeNull();
});

it('rejects confirmation when the worker response opportunity predates the case', function (): void {
    $case = complianceCase();
    $firstReviewer = complianceReviewer([
        'cleaning.worker_compliance.cases.view',
        'cleaning.worker_compliance.cases.claim',
    ]);
    $secondReviewer = complianceReviewer([
        'cleaning.worker_compliance.cases.view',
        'cleaning.worker_compliance.cases.confirm',
    ]);
    $service = app(WorkerComplianceCaseReviewService::class);
    $service->claim($case, $firstReviewer);

    expect(fn () => $service->confirmViolation(
        $case->fresh(),
        $secondReviewer,
        'Independent evidence reviewed.',
        'evidence-42',
        now()->subDay(),
    ))->toThrow(ValidationException::class);
});

it('notifies only authorized active administrators without exposing locations', function (): void {
    config()->set('cleaning.worker_compliance.notifications_enabled', true);
    $authorized = complianceReviewer(['cleaning.worker_compliance.cases.view']);
    $unauthorized = complianceReviewer();
    $inactive = complianceReviewer(['cleaning.worker_compliance.cases.view']);
    $inactive->forceFill(['is_active' => false])->save();
    $workerUser = User::factory()->create(['module_type' => UserModuleType::CleaningWorker->value]);
    $workerUser->givePermissionTo(Permission::findOrCreate('cleaning.worker_compliance.cases.view', 'web'));
    $reference = (string) Illuminate\Support\Str::uuid();

    app(WorkerComplianceReviewerNotificationService::class)->notifyForNewCase($reference);

    expect($authorized->notifications()->count())->toBe(1)
        ->and($unauthorized->notifications()->count())->toBe(0)
        ->and($inactive->notifications()->count())->toBe(0)
        ->and($workerUser->notifications()->count())->toBe(0);
    $message = json_encode($authorized->notifications()->firstOrFail()->data, JSON_THROW_ON_ERROR);
    expect($message)->toContain($reference)
        ->not->toContain('latitude')
        ->not->toContain('longitude')
        ->not->toContain('site_key');
});
