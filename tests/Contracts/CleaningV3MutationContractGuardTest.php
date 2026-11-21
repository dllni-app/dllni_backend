<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3MutationContractGuard;

test('V3 old clients receive 426 without selecting any visit', function (): void {
    $result = (new CleaningV3MutationContractGuard)->decide('v3', false, null, [10, 11]);
    expect($result)->toBe(['allowed' => false, 'httpStatus' => 426, 'code' => 'UPDATE_REQUIRED']);
});

test('V3 capable client must identify one exact visit, no automatic sibling selection', function (): void {
    $result = (new CleaningV3MutationContractGuard)->decide('v3', true, null, [10, 11]);
    expect($result)->toBe(['allowed' => false, 'httpStatus' => 409, 'code' => 'VISIT_REQUIRED']);
});

test('V3 authorization is narrowed to current visit association without revealing sibling identity', function (): void {
    $guard = new CleaningV3MutationContractGuard;
    expect($guard->decide('v3', true, 11, [10])['code'])->toBe('VISIT_NOT_FOUND')
        ->and($guard->decide('v3', true, 10, [10])['allowed'])->toBeTrue()
        ->and($guard->decide('v3', true, -1, [10])['httpStatus'])->toBe(404);
});

test('V2 unmodified contract remains outside V3-only mutations', function (): void {
    $guard = new CleaningV3MutationContractGuard;
    expect($guard->decide('v2', false, null, [])['code'])->toBe('V2_UNCHANGED')
        ->and($guard->decide('', false, null, [])['allowed'])->toBeTrue();
});
