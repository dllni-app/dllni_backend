<?php

declare(strict_types=1);

use Filament\Auth\Pages\Login;
use Livewire\Livewire;

it('serves the filament admin login page for guests', function (): void {
    $this->get(route('filament.admin.auth.login'))
        ->assertSuccessful();
});

it('shows required validation errors for an empty admin login form', function (): void {
    Livewire::test(Login::class)
        ->fillForm([])
        ->call('authenticate')
        ->assertHasFormErrors(['email', 'password']);
});
