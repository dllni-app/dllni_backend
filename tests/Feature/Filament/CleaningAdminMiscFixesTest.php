<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Filament\Pages\CleaningOverview;
use App\Filament\Resources\Disputes\Pages\CreateDispute;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create(['email' => 'misc-fixes-admin@example.com']);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);

    app()->setLocale('ar');
});

it('renders the overview empty states with real translations, not raw keys', function (): void {
    $this->get(CleaningOverview::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertDontSee('cleaning_admin.overview.empty', escape: false);
});

it('resolves arabic validation messages instead of raw keys', function (): void {
    expect(__('validation.required'))->not->toBe('validation.required')
        ->and(__('validation.regex'))->not->toBe('validation.regex')
        ->and(__('validation.required'))->toContain('مطلوب');
});

it('creates a dispute using the booking type select', function (): void {
    Queue::fake();

    Livewire::test(CreateDispute::class)
        ->fillForm([
            'ticket_number' => 'T-1001',
            'booking_id' => 74,
            'booking_type' => 'cleaning_booking',
            'category' => DisputeCategory::Other->value,
            'status' => DisputeStatus::UnderReview->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Dispute::query()->where('ticket_number', 'T-1001')->value('booking_type'))
        ->toBe('cleaning_booking');
});

it('rejects an invalid booking type on the dispute form', function (): void {
    Queue::fake();

    Livewire::test(CreateDispute::class)
        ->fillForm([
            'ticket_number' => 'T-1002',
            'booking_id' => 74,
            'booking_type' => 'not_a_real_type',
            'category' => DisputeCategory::Other->value,
            'status' => DisputeStatus::UnderReview->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['booking_type']);
});
