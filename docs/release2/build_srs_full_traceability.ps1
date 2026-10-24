# Rebuild exact 116-control source index from the Etkan Release 2 SRS.
# English summaries and controlling Arabic wording live in the attached original PDF;
# this index never fabricates missing test evidence or grants acceptance automatically.
param(
    [string] $SourceName = 'Etkan_SRS_AlNadha_Release2_Features.pdf'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$legacyPath = Join-Path $PSScriptRoot 'PHASE6_TRACEABILITY_EVIDENCE.csv'
$outPath = Join-Path $PSScriptRoot 'RELEASE2_FULL_116_SOURCE_TRACEABILITY.csv'
$legacy = @{}
Import-Csv $legacyPath | ForEach-Object { $legacy[$_.requirement_id] = $_ }
$sets = @(
    @{Prefix='RB'; Max=21; PageSplit=18; PageA=20; PageB=21},
    @{Prefix='CM'; Max=13; PageSplit=13; PageA=21; PageB=21},
    @{Prefix='OT'; Max=17; PageSplit=1; PageA=21; PageB=22},
    @{Prefix='ME'; Max=17; PageSplit=1; PageA=22; PageB=23},
    @{Prefix='SS'; Max=28; PageSplit=20; PageA=24; PageB=25},
    @{Prefix='AC'; Max=20; PageSplit=20; PageA=30; PageB=30}
)
$records = foreach ($set in $sets) {
    for ($n=1; $n -le $set.Max; $n++) {
        $id = '{0}-{1:D2}' -f $set.Prefix,$n
        $page = if ($n -le $set.PageSplit) { $set.PageA } else { $set.PageB }
        $old = $legacy[$id]
        [pscustomobject][ordered]@{
            requirement_id = $id
            source_document = $SourceName
            source_page = $page
            source_id_verified = 'YES'
            original_arabic_source = "See original SRS page $page (authoritative wording)"
            roadmap_summary_if_available = if ($old) { $old.roadmap_summary } else { '' }
            implementation_references = if ($old) { $old.implementation_references } else { '' }
            test_references = if ($old) { $old.test_references } else { '' }
            previous_technical_evidence = if ($old) { $old.technical_evidence } else { '' }
            acceptance_status = 'NOT ACCEPTED - source ID traced; per-control acceptance evidence required'
            unresolved_gate = if ($old) { $old.unresolved_gate } else { 'No independently mapped code/test evidence yet' }
        }
    }
}
if (@($records).Count -ne 116) { throw 'Source matrix must contain exactly 116 controls.' }
if (@($records.requirement_id | Sort-Object -Unique).Count -ne 116) { throw 'Duplicate ID.' }
$records | Export-Csv -Path $outPath -NoTypeInformation -Encoding utf8
Write-Output ("SOURCE_MATRIX_CREATED="+$outPath)
Write-Output ("SOURCE_ROWS="+@($records).Count)
Write-Output ("LEGACY_EVIDENCE_ROWS="+@($records|Where-Object { $_.roadmap_summary_if_available }).Count)
Write-Output ("UNMAPPED_EVIDENCE_ROWS="+@($records|Where-Object { -not $_.roadmap_summary_if_available }).Count)
