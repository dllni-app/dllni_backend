<?php

declare(strict_types=1);

use App\Filament\Pages\FinancialSettings;
use App\Filament\Resources\CleaningRoomTypes\CleaningRoomTypeResource;
use App\Filament\Resources\CleaningRoomTypes\Pages\CreateCleaningRoomType;
use App\Filament\Resources\CleaningRoomTypes\Pages\EditCleaningRoomType;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\User\Services\UserCleaningOrderEstimationService;
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

it('enforces the canonical new room code alphabet and leaves saved codes immutable', function (): void {
    $code = 'a'.str_repeat('b', 60).'-9x';
    expect(mb_strlen($code))->toBe(64);

    Livewire::test(CreateCleaningRoomType::class)
        ->fillForm([
            'code' => $code,
            'name' => 'غرفة رمز طويل',
            'icon_key' => 'office',
            'sort_order' => 30,
            'regular_hourly_rate' => 100,
            'deep_hourly_rate' => 200,
            'is_active' => true,
            'suggested_minutes_by_size' => [
                'small' => ['regular' => 20, 'deep' => 40],
                'medium' => ['regular' => 30, 'deep' => 60],
                'large' => ['regular' => 40, 'deep' => 80],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $room = CleaningRoomType::query()->where('code', $code)->firstOrFail();
    expect($room->code)->toBe($code);
    Livewire::test(EditCleaningRoomType::class, ['record' => $room->getKey()])
        ->assertFormFieldIsDisabled('code');

    foreach (['Uppercase', 'éclair', '9starts', 'has space', 'a/b', 'a'.str_repeat('b', 64)] as $invalid) {
        Livewire::test(CreateCleaningRoomType::class)
            ->fillForm([
                'code' => $invalid,
                'name' => 'رمز غير صالح',
                'regular_hourly_rate' => 100,
                'deep_hourly_rate' => 200,
                'suggested_minutes_by_size' => [
                    'small' => ['regular' => 20, 'deep' => 40],
                    'medium' => ['regular' => 30, 'deep' => 60],
                    'large' => ['regular' => 40, 'deep' => 80],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }
});

it('persists the supervisor hourly rate from the existing settings page', function (): void {
    Livewire::test(FinancialSettings::class)
        ->set('supervisorHourlyRate', 430.50)
        ->call('save')
        ->assertHasNoErrors();

    expect((float) App\Models\CleaningFinancialSetting::query()->latest('id')->value('supervisor_hourly_rate'))
        ->toBe(430.50);
});

it('edits hourly rates and hides an archived type without changing its canonical identity', function (): void {
    $room = CleaningRoomType::query()->create([
        'code' => 'qa_editable_annex',
        'name' => 'غرفة خدمات',
        'icon_key' => 'warehouse',
        'regular_hourly_rate' => 150,
        'deep_hourly_rate' => 315,
        'suggested_minutes_by_size' => [
            'small' => ['regular' => 25, 'deep' => 50],
            'medium' => ['regular' => 45, 'deep' => 90],
            'large' => ['regular' => 60, 'deep' => 120],
        ],
        'sort_order' => 30,
        'is_active' => true,
    ]);

    Livewire::test(EditCleaningRoomType::class, ['record' => $room->getKey()])
        ->assertFormFieldIsDisabled('code')
        ->fillForm([
            'name' => 'غرفة خدمات محدثة',
            'regular_hourly_rate' => 210,
            'deep_hourly_rate' => 420,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $room->refresh();
    expect($room->code)->toBe('qa_editable_annex')
        ->and($room->name)->toBe('غرفة خدمات محدثة')
        ->and((float) $room->regular_hourly_rate)->toBe(210.0)
        ->and((float) $room->deep_hourly_rate)->toBe(420.0)
        ->and($room->is_active)->toBeTrue();

    $catalog = $this->getJson('/api/v1/user/cleaning/room-types')->assertOk();
    $published = collect($catalog->json('data'))->firstWhere('code', 'qa_editable_annex');
    expect($published)->not->toBeNull()
        ->and($published['name'])->toBe('غرفة خدمات محدثة')
        ->and((float) $published['regularHourlyRate'])->toBe(210.0)
        ->and((float) $published['deepHourlyRate'])->toBe(420.0);

    $priced = app(UserCleaningOrderEstimationService::class)->price('apartment', [
        'room_size_breakdown' => ['qa_editable_annex' => ['small' => 1]],
        'room_estimated_hours' => ['qa_editable_annex.small.1' => 1],
    ], null, null);
    expect((float) $priced['basePrice'])->toBe(210.0);

    Livewire::test(EditCleaningRoomType::class, ['record' => $room->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $room->refresh();
    expect($room->code)->toBe('qa_editable_annex')
        ->and($room->is_active)->toBeFalse()
        ->and(CleaningRoomType::availableCodes())->not->toContain('qa_editable_annex');

    $archivedCatalog = $this->getJson('/api/v1/user/cleaning/room-types')->assertOk();
    expect(collect($archivedCatalog->json('data'))->pluck('code'))->not->toContain('qa_editable_annex');
});

it('denies unauthorised users room catalog pricing management', function (): void {
    $room = CleaningRoomType::query()->firstOrFail();
    $this->actingAs(User::factory()->create());

    expect(CleaningRoomTypeResource::canViewAny())->toBeFalse()
        ->and(CleaningRoomTypeResource::canCreate())->toBeFalse()
        ->and(CleaningRoomTypeResource::canEdit($room))->toBeFalse()
        ->and(CleaningRoomTypeResource::canDelete($room))->toBeFalse();
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
