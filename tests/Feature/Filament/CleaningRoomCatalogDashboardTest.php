<?php

declare(strict_types=1);

use App\Filament\Pages\FinancialSettings;
use App\Filament\Resources\CleaningRoomTypes\CleaningRoomTypeResource;
use App\Filament\Resources\CleaningRoomTypes\Pages\CreateCleaningRoomType;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->setLocale('ar');
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('renders the Arabic room catalog list/create/edit using existing Filament design', function (): void {
    $room = CleaningRoomType::query()->firstOrFail();

    $this->get(CleaningRoomTypeResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful();
    $this->get(CleaningRoomTypeResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful();
    $this->get(CleaningRoomTypeResource::getUrl('edit', ['record' => $room], panel: 'admin'))
        ->assertSuccessful();
});

it('creates a new room with independently managed hourly rates and suggested minutes', function (): void {
    Livewire::test(CreateCleaningRoomType::class)
        ->fillForm([
            'code' => 'utility_annex',
            'name' => 'غرفة خدمات إضافية',
            'icon_key' => 'cleaning_services',
            'sort_order' => 30,
            'regular_hourly_rate' => 150,
            'deep_hourly_rate' => 315,
            'is_active' => true,
            'suggested_minutes_by_size' => [
                'small' => ['regular' => 25, 'deep' => 50],
                'medium' => ['regular' => 45, 'deep' => 90],
                'large' => ['regular' => 60, 'deep' => 120],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $room = CleaningRoomType::query()->where('code', 'utility_annex')->firstOrFail();
    expect((float) $room->regular_hourly_rate)->toBe(150.0)
        ->and((float) $room->deep_hourly_rate)->toBe(315.0)
        ->and(data_get($room->suggested_minutes_by_size, 'large.deep'))->toBe(120);
});

it('persists the supervisor hourly rate from the existing settings page', function (): void {
    Livewire::test(FinancialSettings::class)
        ->set('supervisorHourlyRate', 430.50)
        ->call('save')
        ->assertHasNoErrors();

    expect((float) \App\Models\CleaningFinancialSetting::query()->latest('id')->value('supervisor_hourly_rate'))
        ->toBe(430.50);
});

it('permits deleting never-used room types but protects those referenced by bookings', function (): void {
    $room = CleaningRoomType::query()->create([
        'code' => 'qa_supply_room',
        'name' => 'غرفة تخزين مؤقتة',
        'icon_key' => 'warehouse',
        'regular_hourly_rate' => 100,
        'deep_hourly_rate' => 200,
        'suggested_minutes_by_size' => [
            'small' => ['regular' => 30, 'deep' => 60],
            'medium' => ['regular' => 40, 'deep' => 80],
            'large' => ['regular' => 50, 'deep' => 100],
        ],
        'sort_order' => 99,
        'is_active' => true,
    ]);

    expect(CleaningRoomTypeResource::canDelete($room))->toBeTrue();

    CleaningBooking::factory()->create([
        'property_details' => [
            'room_size_breakdown' => [
                'qa_supply_room' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
    ]);

    expect(CleaningRoomTypeResource::canDelete($room))->toBeFalse();
});
