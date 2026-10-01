<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders every registered admin resource index and custom navigation page without server errors', function (): void {
    $this->seed(DatabaseSeeder::class);
    app()->setLocale('ar');

    $admin = User::query()->where('email', 'admin@admin.com')->firstOrFail();
    $this->actingAs($admin);

    $panel = Filament::getPanel('admin');
    $endpoints = [];

    foreach ($panel->getResources() as $resource) {
        $pages = $resource::getPages();

        if (array_key_exists('index', $pages)) {
            $endpoints['resource:index:'.$resource] = $resource::getUrl('index', panel: 'admin');
        }

        if (array_key_exists('create', $pages) && $resource::canCreate()) {
            $endpoints['resource:create:'.$resource] = $resource::getUrl('create', panel: 'admin');
        }

        if (! array_key_exists('view', $pages) && ! array_key_exists('edit', $pages)) {
            continue;
        }

        $record = $resource::getEloquentQuery()->first();

        if ($record === null) {
            continue;
        }

        if (array_key_exists('view', $pages) && $resource::canView($record)) {
            $endpoints['resource:view:'.$resource] = $resource::getUrl(
                'view',
                ['record' => $record],
                panel: 'admin',
            );
        }

        if (array_key_exists('edit', $pages) && $resource::canEdit($record)) {
            $endpoints['resource:edit:'.$resource] = $resource::getUrl(
                'edit',
                ['record' => $record],
                panel: 'admin',
            );
        }
    }

    foreach ($panel->getPages() as $page) {
        if (method_exists($page, 'shouldRegisterNavigation') && ! $page::shouldRegisterNavigation()) {
            continue;
        }

        $endpoints['page:'.$page] = $page::getUrl(panel: 'admin');
    }

    expect($endpoints)->not->toBeEmpty();

    $failures = [];

    foreach ($endpoints as $name => $url) {
        $response = $this->get($url);

        if ($response->getStatusCode() !== 200) {
            $failures[$name] = [
                'url' => $url,
                'status' => $response->getStatusCode(),
            ];
        }
    }

    expect($failures)->toBe(
        [],
        'Registered Filament pages did not render successfully: '.json_encode($failures, JSON_UNESCAPED_SLASHES),
    );
});
