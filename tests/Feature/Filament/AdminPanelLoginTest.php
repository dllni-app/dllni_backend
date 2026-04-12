<?php

declare(strict_types=1);

it('serves the filament admin login page for guests', function (): void {
    $this->get(route('filament.admin.auth.login'))
        ->assertSuccessful();
});
