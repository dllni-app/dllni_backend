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
    $worker->unsetRelation('deposit');

    Sanctum::actingAs($workerUser);
"""
if text.count(marker) != 1:
    raise SystemExit(f"resume acceptance marker: expected 1, found {text.count(marker)}")
text = text.replace(marker, replacement, 1)
path.write_text(text)
