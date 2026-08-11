<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\Pages\EditCleaningWorker;
use App\Filament\Resources\Workers\Pages\EditWorker;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    Storage::fake((string) config('media-library.disk_name', 'public'));
});

it('replaces the worker avatar from the cleaning worker edit page', function (): void {
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
    ]);

    $oldMedia = $worker
        ->addMedia(UploadedFile::fake()->image('old-worker.jpg'))
        ->toMediaCollection('avatar');

    Livewire::test(EditCleaningWorker::class, ['record' => $worker->getRouteKey()])
        ->assertActionExists('changeAvatar')
        ->callAction('changeAvatar', data: [
            'avatar' => UploadedFile::fake()->image('new-worker.jpg'),
        ])
        ->assertHasNoFormErrors();

    $avatarMedia = $worker->fresh()->getMedia('avatar');

    expect($avatarMedia)->toHaveCount(1)
        ->and($avatarMedia->first()?->getKey())->not->toBe($oldMedia->getKey());
});

it('exposes the same avatar replacement action on the general worker edit page', function (): void {
    $worker = Worker::factory()->create();

    Livewire::test(EditWorker::class, ['record' => $worker->getRouteKey()])
        ->assertActionExists('changeAvatar');
});
