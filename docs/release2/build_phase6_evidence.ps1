# Requires PowerShell 5.1+. Run from the backend repository:
# powershell -NoProfile -ExecutionPolicy Bypass -File docs/release2/build_phase6_evidence.ps1
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$inputPath = Join-Path $PSScriptRoot 'RELEASE2_TRACEABILITY_ROADMAP_SUBSET.csv'
$outputPath = Join-Path $PSScriptRoot 'PHASE6_TRACEABILITY_EVIDENCE.csv'
$rows = @(Import-Csv -Path $inputPath)
if ($rows.Count -ne 40) { throw "Expected the 40 explicitly named roadmap IDs, found $($rows.Count). Do not fabricate missing SRS IDs." }
$mapping = @{
  '1' = @{
    code = 'Modules/Cleaning/app/Services/RecurringCleaningPauseService.php; Modules/Cleaning/app/Services/CleaningBookingSessionCancellationService.php'
    test = 'tests/Feature/Cleaning/RecurringCleaningPauseResumeRetryTest.php'
    gap = 'Selected Pause date-range, entire session impact preview, worker approval and original RB/AC wording not fully proven'
  }
  '2' = @{
    code = 'Modules/Cleaning/app/Services/CleaningSpecialistAuthorizationService.php; Modules/Cleaning/app/Services/CleaningSpecialistEquipmentReservationService.php'
    test = 'tests/Feature/Cleaning/CleaningSpecialistReservationGateTest.php; tests/Feature/Cleaning/CleaningBookingSessionAcceptanceTest.php'
    gap = 'Multi-connection MySQL reservation race, every reassignment path, original SS/AC wording not fully proven'
  }
  '3' = @{
    code = 'Modules/Cleaning/app/Services/CleaningAdministrativeOperationsService.php; Modules/Cleaning/app/Services/CleaningEquipmentHandoverService.php'
    test = 'tests/Feature/Cleaning/CleaningAdministrativePhase3WorkflowTest.php; tests/Feature/Cleaning/CleaningOperationalExtrasLifecycleTest.php'
    gap = 'Queue and notification fan-out, real settlement edge cases, exact original OT/SS wording unverified'
  }
  '4' = @{
    code = 'Modules/User/app/Services/UserCleaningOrderService.php; Modules/User/app/Http/Resources/UserCleaningBookingResource.php'
    test = 'tests/Feature/UserModule/CleaningSpecialServiceCustomerEditPhase4Test.php; tests/Feature/UserModule/CleaningV2PublicContractTest.php'
    gap = 'Post-assignment worker/admin service change proposal with customer acceptance/rejection and financial reallocation not implemented'
  }
  '5' = @{
    code = 'Modules/Cleaning/app/Services/CleaningSpecialistFairnessRankingService.php; Modules/Cleaning/app/Services/CleaningSpecialOperationsReportService.php'
    test = 'tests/Feature/Cleaning/CleaningPhase5FairnessDurationReportingTest.php; tests/Feature/Filament/CleaningSpecialOperationsReportPageTest.php'
    gap = 'Fairness not integrated with all initial specialist discovery paths; report reconciliation and original SS wording unverified'
  }
}
$output = foreach ($row in $rows) {
  $entry = $mapping[[string]$row.phase]
  if ($null -eq $entry) { throw "Unrecognized phase $($row.phase)" }
  $missingCode = @($entry.code -split '; ' | Where-Object { -not (Test-Path (Join-Path $repo $_)) })
  $missingTests = @($entry.test -split '; ' | Where-Object { -not (Test-Path (Join-Path $repo $_)) })
  [pscustomobject]@{
    requirement_id = $row.requirement_id
    roadmap_phase = $row.phase
    roadmap_summary = $row.roadmap_group_summary
    original_srs_text_available = 'NO'
    implementation_references = $entry.code
    test_references = $entry.test
    code_paths_found = ($missingCode.Count -eq 0)
    test_paths_found = ($missingTests.Count -eq 0)
    technical_evidence = 'PHASE6_BACKEND_BATCH1_63_PASS_567_ASSERTIONS (related subset, NOT one test per requirement)'
    acceptance_assessment = 'NOT ACCEPTED - partial technical evidence; source SRS and gates incomplete'
    unresolved_gate = $entry.gap
  }
}
$output | Export-Csv -Path $outputPath -NoTypeInformation -Encoding UTF8
Write-Output "Created $outputPath with $($output.Count) named controls; remaining 76 require original SRS enumeration."
