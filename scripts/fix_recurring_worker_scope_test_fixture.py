from pathlib import Path

# The main patch runs first. Adjust the JSON membership expression so Laravel's
# JSON_CONTAINS semantics are portable across the SQLite CI database and MySQL.
filter_path = Path('Modules/Cleaning/app/Traits/FilterQueries/CleaningBookingFilterQuery.php')
filter_text = filter_path.read_text()
old_membership = "->orWhereJsonContains('specific_worker_ids', (int) $worker->id);"
new_membership = "->orWhereJsonContains('specific_worker_ids', [(int) $worker->id]);"
if filter_text.count(old_membership) != 1:
    raise SystemExit(
        f'expected one specific-worker JSON membership expression, found {filter_text.count(old_membership)}'
    )
filter_path.write_text(filter_text.replace(old_membership, new_membership, 1))

path = Path('tests/Feature/Cleaning/RecurringCleaningWorkerScopeTest.php')
text = path.read_text()

before = '''beforeEach(function (): void {
    CleaningDepositSetting::query()->delete();
    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);
});

'''
if before not in text:
    raise SystemExit('expected global worker-scope beforeEach fixture')
text = text.replace(before, '', 1)

old = '''function makeRecurringScopeEligibleWorker(string $email): array
{
    $user = User::factory()->create(['email' => $email, 'is_active' => true]);
'''
new = '''function makeRecurringScopeEligibleWorker(string $email): array
{
    CleaningDepositSetting::query()->firstOrCreate([], [
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $user = User::factory()->create(['email' => $email, 'is_active' => true]);
'''
if old not in text:
    raise SystemExit('expected worker helper header')
text = text.replace(old, new, 1)

old = '''        'trust_score' => 100,
        'default_working_hours' => $workingHours,
'''
new = '''        'trust_score' => 100,
        'preferred_work_type' => 'both',
        'default_working_hours' => $workingHours,
'''
if old not in text:
    raise SystemExit('expected worker preference fixture')
text = text.replace(old, new, 1)

old = '''        'current_balance' => 100000,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
'''
new = '''        'current_balance' => 100000,
        'debt_balance' => 0,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
'''
if old not in text:
    raise SystemExit('expected deposit fixture')
text = text.replace(old, new, 1)

old = '''        'worker_scope' => CleaningBooking::WORKER_SCOPE_SPECIFIC,
        'specific_worker_ids' => $specificWorkerIds,
        'number_of_workers' => count($specificWorkerIds),
        'status' => CleaningBookingStatus::Pending->value,
'''
new = '''        'worker_scope' => CleaningBooking::WORKER_SCOPE_SPECIFIC,
        'specific_worker_ids' => $specificWorkerIds,
        'number_of_workers' => count($specificWorkerIds),
        'property_type' => 'apartment',
        'neighborhood_id' => null,
        'neighborhood_name' => null,
        'status' => CleaningBookingStatus::Pending->value,
'''
if old not in text:
    raise SystemExit('expected recurring booking scope fixture')
text = text.replace(old, new, 1)

old = '''    [$booking, $session] = makeRecurringSpecificScopeBooking([(int) $selectedWorker->id]);

    $result = app(CleaningBookingSessionWorkerEligibilityService::class)
'''
new = '''    [$booking, $session] = makeRecurringSpecificScopeBooking([(int) $selectedWorker->id]);

    expect(app(\\Modules\\Cleaning\\Services\\DepositService::class)->isWorkerEligibleForNewRequests($selectedWorker->fresh(['deposit'])))->toBeTrue();

    $result = app(CleaningBookingSessionWorkerEligibilityService::class)
'''
if old not in text:
    raise SystemExit('expected eligibility test insertion point')
text = text.replace(old, new, 1)

old = '''    Sanctum::actingAs($selectedUser);
    $selectedIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
'''
new = '''    Sanctum::actingAs($selectedUser);
    expect(CleaningBooking::query()->forCurrentWorker(true)->whereKey($booking->id)->exists())->toBeTrue();
    $selectedSolvency = app(\\Modules\\Cleaning\\Services\\WorkerOrderSolvencyService::class)
        ->solvencyPayloadForBooking($selectedWorker->fresh(['user', 'deposit']), $booking->fresh());
    if (! (bool) ($selectedSolvency['canReceiveOrder'] ?? false)) {
        throw new \\RuntimeException('selected scope solvency: '.json_encode($selectedSolvency));
    }
    $selectedIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
'''
if old not in text:
    raise SystemExit('expected selected listing diagnostic insertion point')
text = text.replace(old, new, 1)

old = '''    [$booking] = makeRecurringSpecificScopeBooking([
        (int) $firstWorker->id,
        (int) $secondWorker->id,
    ]);

    (new NotifyEligibleWorkersNewOrderJob((int) $booking->id))->handle();
'''
new = '''    [$booking] = makeRecurringSpecificScopeBooking([
        (int) $firstWorker->id,
        (int) $secondWorker->id,
    ]);

    $bookingStartsAt = \\Carbon\\Carbon::parse(
        $booking->scheduled_date->format('Y-m-d').' '.mb_trim((string) $booking->scheduled_time),
        config('app.timezone'),
    );
    expect(app(\\Modules\\Cleaning\\Services\\DepositService::class)->isWorkerEligibleForDispatch($firstWorker->fresh(['user', 'deposit'])))->toBeTrue()
        ->and(app(\\Modules\\Cleaning\\Services\\DepositService::class)->isWorkerEligibleForDispatch($secondWorker->fresh(['user', 'deposit'])))->toBeTrue()
        ->and($firstWorker->fresh()->isAvailableAt($bookingStartsAt))->toBeTrue()
        ->and($secondWorker->fresh()->isAvailableAt($bookingStartsAt))->toBeTrue();
    foreach ([$firstWorker, $secondWorker] as $candidate) {
        $candidateSolvency = app(\\Modules\\Cleaning\\Services\\WorkerOrderSolvencyService::class)
            ->solvencyPayloadForBooking($candidate->fresh(['user', 'deposit']), $booking->fresh());
        if (! (bool) ($candidateSolvency['canReceiveOrder'] ?? false)) {
            throw new \\RuntimeException('dispatch scope solvency: '.json_encode($candidateSolvency));
        }
    }

    (new NotifyEligibleWorkersNewOrderJob((int) $booking->id))->handle();
'''
if old not in text:
    raise SystemExit('expected dispatch diagnostic insertion point')
text = text.replace(old, new, 1)

path.write_text(text)
print('worker scope diagnostics and deterministic fixtures adjusted')
