<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Filament\Resources\MasterProducts\MasterProductResource;
use App\Filament\Resources\SystemAlerts\Pages\ViewSystemAlert;
use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use App\Models\MasterProduct;
use App\Models\MasterProductAlias;
use App\Models\SystemAlert;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmStore;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role=Role::findOrCreate('admin','web');
    $admin=User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('renders a useful system alert detail and handles acknowledge and resolve with audit metadata', function (): void {
    $order=Order::factory()->create(['order_number'=>'REST-ALERT-001']);

    $alert=SystemAlert::query()->create([
        'booking_type'=>'restaurant_order',
        'booking_id'=>$order->id,
        'alert_type'=>AlertType::AnomalyDetected->value,
        'severity'=>AlertSeverity::High->value,
        'status'=>SystemAlertStatus::New->value,
        'payload'=>['message'=>'Order requires admin review'],
    ]);

    $this->get(SystemAlertResource::getUrl('view',['record'=>$alert],isAbsolute:false))
        ->assertSuccessful()
        ->assertSee('REST-ALERT-001')
        ->assertSee('Order requires admin review');

    Livewire::test(ViewSystemAlert::class,['record'=>$alert->getRouteKey()])
        ->assertActionExists('acknowledge')
        ->callAction('acknowledge')
        ->assertHasNoActionErrors();

    $alert->refresh();
    expect($alert->status)->toBe(SystemAlertStatus::Acknowledged)
        ->and($alert->acknowledged_at)->not->toBeNull()
        ->and($alert->acknowledged_by)->not->toBeNull();

    Livewire::test(ViewSystemAlert::class,['record'=>$alert->getRouteKey()])
        ->assertActionExists('resolve')
        ->callAction('resolve',data:['resolution_note'=>'Reviewed and resolved'])
        ->assertHasNoActionErrors();

    $alert->refresh();
    expect($alert->status)->toBe(SystemAlertStatus::Resolved)
        ->and($alert->resolved_at)->not->toBeNull()
        ->and($alert->resolution_note)->toBe('Reviewed and resolved')
        ->and(Activity::query()->where('log_name','system_alerts')->where('subject_id',$alert->id)->count())->toBeGreaterThanOrEqual(2);
});

it('shows master product catalog metadata aliases and linked store products', function (): void {
    $master=MasterProduct::query()->create([
        'name'=>'Reference Milk',
        'barcode'=>'1234567890123',
        'unit'=>'piece',
        'brand'=>'Reference Brand',
        'is_active'=>true,
        'openfoodfacts_url'=>'https://world.openfoodfacts.org/product/1234567890123',
        'openfoodfacts_imported_at'=>now(),
    ]);

    MasterProductAlias::query()->create([
        'master_product_id'=>$master->id,
        'alias'=>'Milk Alias',
    ]);

    $store=SmStore::factory()->create(['name'=>'Linked Store']);
    SmProductFactory::new()->create([
        'store_id'=>$store->id,
        'master_product_id'=>$master->id,
        'name'=>'Local Milk',
        'barcode'=>'1234567890123',
        'price'=>5000,
        'stock_quantity'=>9,
    ]);

    $this->get(MasterProductResource::getUrl('view',['record'=>$master],isAbsolute:false))
        ->assertSuccessful()
        ->assertSee('Reference Milk')
        ->assertSee('1234567890123')
        ->assertSee('Milk Alias')
        ->assertSee('Linked Store')
        ->assertSee('Local Milk');

    $this->get(MasterProductResource::getUrl('index',[],isAbsolute:false))
        ->assertSuccessful()
        ->assertSee('Reference Milk')
        ->assertSee('1234567890123');
});
