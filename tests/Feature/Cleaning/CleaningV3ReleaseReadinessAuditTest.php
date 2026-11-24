<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Services\CleaningV3ReleaseReadinessAuditService;

uses(RefreshDatabase::class);

test('V3 readiness report requires real approved funding and refuses to bless SQLite QA as MySQL', function (): void {
    $service = app(CleaningV3ReleaseReadinessAuditService::class);
    $result = $service->audit();

    expect($result['version'])->toBe(3)
        ->and($result['readOnly'])->toBeTrue()
        ->and($result['readyForCommercialLaunch'])->toBeFalse()
        ->and($result['blockerCodes'])->toContain('signed_v3_financial_admission')
        ->and($result['blockerCodes'])->toContain('mysql_concurrency_and_mobile_e2e_evidence')
        ->and($result['blockerCodes'])->toContain('approved_catalog_digest')
        ->and($result['counts']['blocked'])->toBeGreaterThan(0);

    if (config('database.default') === 'sqlite') {
        expect($result['blockerCodes'])->toContain('mysql_qa');
    }

    foreach (['cleaning_work_intervals', 'cleaning_work_pause_requests'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue()
            ->and($result['blockerCodes'])->not->toContain('table_'.$table);
    }
});

test('an approved looking digest is not itself evidence of a safe published tariff', function (): void {
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('e', 64));

    $result = app(CleaningV3ReleaseReadinessAuditService::class)->audit();

    expect($result['readyForCommercialLaunch'])->toBeFalse()
        ->and($result['blockerCodes'])->toContain('published_catalog_integrity')
        ->and($result['blockerCodes'])->toContain('approved_catalog_coverage_and_funding')
        ->and($result['blockerCodes'])->toContain('signed_v3_financial_admission')
        ->and($result['blockerCodes'])->not->toContain('approved_catalog_digest');
});

test('the Artisan readiness command returns nonzero instead of falsely reporting launch success', function (): void {
    $exit = Artisan::call('cleaning:v3-readiness', ['--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(2)
        ->and($json['readyForCommercialLaunch'])->toBeFalse()
        ->and($json['readOnly'])->toBeTrue()
        ->and($json['counts']['blocked'])->toBeGreaterThan(0);
});

test('readiness audit does not create bookings, catalog versions or funding reservations', function (): void {
    $tables = [
        'cleaning_bookings',
        'cleaning_booking_quotes',
        'cleaning_v3_catalog_versions',
        'cleaning_visit_funding_reservations',
    ];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = Illuminate\Support\Facades\DB::table($table)->count();
    }

    app(CleaningV3ReleaseReadinessAuditService::class)->audit();
    Artisan::call('cleaning:v3-readiness');

    foreach ($tables as $table) {
        expect(Illuminate\Support\Facades\DB::table($table)->count())->toBe($before[$table]);
    }
});
