from pathlib import Path

path = Path("tests/Feature/Cleaning/RecurringCleaningWorkerContinuityTest.php")
text = path.read_text()

import_marker = "use App\\Models\\CleaningFinancialSetting;\n"
if text.count(import_marker) != 1:
    raise SystemExit(f"CleaningFinancialSetting import marker: expected 1, found {text.count(import_marker)}")
if "use App\\Models\\CleaningWorkerDeposit;\n" not in text:
    text = text.replace(
        import_marker,
        import_marker + "use App\\Models\\CleaningWorkerDeposit;\n",
        1,
    )

worker_marker = """        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
"""
worker_replacement = """        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
        'home_address' => 'Damascus',
        'home_latitude' => 33.5138,
        'home_longitude' => 36.2765,
"""
if text.count(worker_marker) != 1:
    raise SystemExit(f"recurring scenario worker marker: expected 1, found {text.count(worker_marker)}")
text = text.replace(worker_marker, worker_replacement, 1)

booking_marker = """        'customer_id' => $customer->id,
        'property_type' => 'apartment',
"""
booking_replacement = """        'customer_id' => $customer->id,
        'gender_preference' => 'any',
        'property_type' => 'apartment',
        'address_latitude' => 33.5100,
        'address_longitude' => 36.2900,
"""
if text.count(booking_marker) != 1:
    raise SystemExit(f"recurring scenario booking marker: expected 1, found {text.count(booking_marker)}")
text = text.replace(booking_marker, booking_replacement, 1)

marker = """    expect($booking->fresh()->recurring_paused_at)->toBeNull()
        ->and($booking->fresh()->recurring_pause_reason)->toBeNull();

    Sanctum::actingAs($workerUser);
"""
replacement = """    expect($booking->fresh()->recurring_paused_at)->toBeNull()
        ->and($booking->fresh()->recurring_pause_reason)->toBeNull();

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 10000,
            'debt_balance' => 0,
            'deposited_total' => 10000,
            'withdrawn_total' => 0,
            'admin_revenue_withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 0,
            'is_active' => true,
        ],
    );
    $worker->forceFill([
        'default_working_hours' => [
            'monday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'tuesday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'wednesday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'thursday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'friday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'saturday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
            'sunday' => ['available' => true, 'data' => [['00:00' => '23:59']]],
        ],
    ])->save();
    $worker->unsetRelation('deposit');

    Sanctum::actingAs($workerUser);
"""
if text.count(marker) != 1:
    raise SystemExit(f"resume acceptance marker: expected 1, found {text.count(marker)}")
text = text.replace(marker, replacement, 1)

path.write_text(text)
