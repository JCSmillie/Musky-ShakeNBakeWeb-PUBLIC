<?php
// ============================================================================
// PANDA_ChargeDecision_InsuranceJig.php
// ----------------------------------------------------------------------------
// Debug JIG for Insurance + Skyward coverage logic used by PANDA_ChargeDecision.
//
// • Input:  GET ?ChargeID=123
// • Does NOT change any data.
// • Mirrors the same logic as PANDA_ChargeDecision:
//     - SchoolYear → CoverageYear (panda_schoolyear_to_coverage_year)
//     - owner_id lookup
//     - Skyward_ThisnThat queries
//     - Skyward status label + detail text
//     - Stage B auto-correct rules (simulated only, not applied)
//     - Coverage tier selection (INS_PAID / INS_BASIC / NO_INS)
//
// • Everything is dumped to the screen in a VERY verbose way so we can see
//   EXACTLY why "Skyward Coverage (context)" would show Unknown / Has Coverage etc.
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/PANDA_Functions.php';

panda_require_charges_enabled();

// -----------------------------------------------------------------------------
// Access control (same variants as ChargeDecision)
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'] ?? 'musky';
$allowed = $prefs['allowed_tools'] ?? [];
$email   = $prefs['email'] ?? 'PANDA_InsuranceJig';

panda_require_charge_admin_access($allowed, 'html');

// -----------------------------------------------------------------------------
// DB + load charge (same SELECT as ChargeDecision)
// -----------------------------------------------------------------------------
$pdo = panda_db();

$chargeId = (int)($_GET['ChargeID'] ?? 0);
if ($chargeId <= 0) {
    http_response_code(400);
    echo "Missing or invalid ChargeID.";
    exit;
}

$st = $pdo->prepare("
    SELECT
        c.*,
        d.serial_number AS DeviceSerial,
        d.asset_tag     AS DeviceAssetTag,
        COALESCE(d.device_model, d.model) AS DeviceModel,
        o.id            AS OwnerRowID,
        o.email         AS OwnerEmail,
        o.full_name     AS OwnerName,
        o.grade         AS OwnerGrade,
        o.district_id   AS OwnerDistrictId
    FROM panda_charges c
    JOIN devices d ON c.DeviceID = d.id
    JOIN owners  o ON c.OwnerID = o.id
    WHERE c.ChargeID = ?
    LIMIT 1
");
$st->execute([$chargeId]);
$charge = $st->fetch(PDO::FETCH_ASSOC);

if (!$charge) {
    http_response_code(404);
    echo "Charge #{$chargeId} not found.";
    exit;
}

// -----------------------------------------------------------------------------
// Simple helpers (same look as original)
// -----------------------------------------------------------------------------
function panda_badge_insurance(string $s): string {
    $s = strtoupper(trim($s));
    if ($s === 'YES') return "<span class='badge bg-success'>YES</span>";
    if ($s === 'NO')  return "<span class='badge bg-danger'>NO</span>";
    return "<span class='badge bg-warning text-dark'>UNKNOWN</span>";
}

// -----------------------------------------------------------------------------
// Compute CoverageYear + primary Skyward row (same rules as ChargeDecision)
// -----------------------------------------------------------------------------
$schoolYear   = $charge['SchoolYear'] ?? '';
$ownerRowId   = (int)($charge['OwnerRowID'] ?? 0);
$ownerEmail   = trim((string)($charge['OwnerEmail'] ?? ''));
$districtId   = trim((string)($charge['OwnerDistrictId'] ?? ''));
$hasInsurance = $charge['HasInsurance'] ?? 'UNKNOWN';

$coverageYear = null;
if ($schoolYear !== '') {
    // Use the same function as the real page.
    $coverageYear = panda_schoolyear_to_coverage_year($schoolYear);
}

// Primary row used by the main page:
$primarySkywardRow = null;
$primarySql        = null;
$primaryParams     = null;
$primaryError      = null;

if ($coverageYear && $ownerRowId > 0) {
    $primarySql = "
        SELECT id, owner_id, CoverageYear, HasCoverage, CoveragePaid, created_at, updated_at
        FROM Skyward_ThisnThat
        WHERE owner_id = ? AND CoverageYear = ?
        ORDER BY id DESC
        LIMIT 1
    ";
    $primaryParams = [$ownerRowId, (string)$coverageYear];

    try {
        $st = $pdo->prepare($primarySql);
        $st->execute($primaryParams);
        $primarySkywardRow = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $primaryError = $e->getMessage();
    }
}

// Also pull ALL rows for that owner to see the full picture
$allRows = [];
$allSql  = null;
$allErr  = null;

if ($ownerRowId > 0) {
    $allSql = "
        SELECT id, owner_id, CoverageYear, HasCoverage, CoveragePaid, created_at, updated_at
        FROM Skyward_ThisnThat
        WHERE owner_id = ?
        ORDER BY CoverageYear, id
    ";
    try {
        $st = $pdo->prepare($allSql);
        $st->execute([$ownerRowId]);
        $allRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $allErr = $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// What would the real page LABEL as Skyward Coverage?
// (Mirrors panda_compute_iiq_insurance_context & Stage B logic, but read-only)
// -----------------------------------------------------------------------------
$skyward_status_label  = 'Unknown';
$skyward_status_detail = 'No coverage computation performed yet.';
$skyHasCoverage        = false;
$skyCoveragePaidFlag   = null;

if ($coverageYear && $ownerRowId > 0 && $primarySkywardRow) {
    $skyHasCoverage      = ((int)$primarySkywardRow['HasCoverage'] === 1);
    $skyCoveragePaidFlag = trim((string)$primarySkywardRow['CoveragePaid']);

    if ($skyHasCoverage) {
        // In your importer: FeeHasBalanceDue => CoveragePaid
        //   "True"  => there is still a BALANCE DUE
        //   "False" => no balance due (PAID)
        if (strcasecmp($skyCoveragePaidFlag, 'True') === 0 || $skyCoveragePaidFlag === '1') {
            $skyward_status_label  = 'Has Coverage (Balance Due)';
            $skyward_status_detail = "CoverageYear {$primarySkywardRow['CoverageYear']}, Skyward still shows a balance due.";
        } else {
            $skyward_status_label  = 'Has Coverage (Paid)';
            $skyward_status_detail = "CoverageYear {$primarySkywardRow['CoverageYear']}, no remaining balance due.";
        }
    } else {
        $skyward_status_label  = 'No Coverage Record';
        $skyward_status_detail = "Row exists but HasCoverage=0 for CoverageYear={$primarySkywardRow['CoverageYear']}.";
    }
} elseif ($coverageYear && $ownerRowId > 0) {
    $skyward_status_label  = 'No Entry for Year';
    $skyward_status_detail = "No Skyward_ThisnThat entry for owner_id={$ownerRowId}, CoverageYear={$coverageYear}.";
}

// -----------------------------------------------------------------------------
// Stage B simulation: would HasInsurance be forced to 'NO'?
// (This is what the main page does in "STAGE B — Auto-correct HasInsurance")
// -----------------------------------------------------------------------------
$stageB_wouldFlipToNo = false;
$stageB_reason        = '';

if ($coverageYear && $ownerRowId > 0) {
    if (!$skyHasCoverage) {
        // This matches:
        // if (!$skyHasCoverage && $charge['HasInsurance'] !== 'NO') { ... flip to NO ... }
        if ($hasInsurance !== 'NO') {
            $stageB_wouldFlipToNo = true;
            $stageB_reason =
                "Skyward says NO coverage for owner_id={$ownerRowId}, CoverageYear={$coverageYear}, ".
                "and HasInsurance != 'NO' (currently '{$hasInsurance}'). Main page would auto-correct to 'NO'.";
        } else {
            $stageB_reason =
                "Skyward says NO coverage, but HasInsurance already 'NO' so no correction needed.";
        }
    } else {
        $stageB_reason = "Skyward shows HasCoverage=1, so Stage B would NOT force HasInsurance to NO.";
    }
}

// -----------------------------------------------------------------------------
// Stage C - Coverage tier (INS_PAID / INS_BASIC / NO_INS) for this charge
// -----------------------------------------------------------------------------
$coverageTierExplain = [];
$coverageTier        = 'NO_INS';
$skyRowTier          = $primarySkywardRow; // for readability

$coverageTierExplain[] = "Initial coverage tier: NO_INS";

if ($hasInsurance === 'YES') {
    $coverageTierExplain[] =
        "Charge.HasInsurance is YES → we look at Skyward coverage to decide between INS_PAID vs INS_BASIC.";

    $paid = false;
    if ($skyRowTier) {
        $cp = trim((string)$skyRowTier['CoveragePaid']);
        $coverageTierExplain[] = "Skyward row CoveragePaid = '{$cp}'";

        // Original logic in PANDA_ChargeDecision:
        // paid = NOT (CoveragePaid in ('True','1'))  [because True means balance due]
        if (!(strcasecmp($cp, 'True') === 0 || $cp === '1')) {
            $paid = true;
            $coverageTierExplain[] =
                "CoveragePaid is NOT 'True'/'1' → treat as NO balance due → coverage is PAID.";
        } else {
            $coverageTierExplain[] =
                "CoveragePaid IS 'True'/'1' → treat as balance due → coverage is BASIC but not fully paid.";
        }
    } else {
        $coverageTierExplain[] = "No Skyward row found → cannot prove coverage is paid → stay BASIC.";
    }

    $coverageTier = $paid ? 'INS_PAID' : 'INS_BASIC';
    $coverageTierExplain[] = "Final coverage tier = {$coverageTier}";
} else {
    $coverageTierExplain[] =
        "Charge.HasInsurance is '{$hasInsurance}' (not YES) → final coverage tier remains NO_INS.";
}

// -----------------------------------------------------------------------------
// HTML OUTPUT
// -----------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA Insurance JIG — Charge #<?= (int)$charge['ChargeID'] ?></title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body {
  background-color: var(--background-color);
  color: var(--text-color);
}
.card {
  margin-bottom: 1rem;
}
pre {
  font-size: 0.8rem;
  background: rgba(0,0,0,0.04);
  padding: 0.75rem;
  border-radius: 0.5rem;
}
code {
  font-size: 0.9rem;
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">

<div class="container-fluid mt-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <a href="PANDA_ChargeDecision.php?ChargeID=<?= (int)$charge['ChargeID'] ?>" class="btn btn-secondary btn-sm">
        ← Back to ChargeDecision
      </a>
    </div>
    <div class="text-center flex-grow-1">
      <h3 class="mb-0">PANDA Insurance JIG — Charge #<?= (int)$charge['ChargeID'] ?></h3>
      <small class="text-muted">
        SchoolYear: <code><?= htmlspecialchars($schoolYear) ?></code> •
        CoverageYear (computed): <code><?= htmlspecialchars((string)$coverageYear) ?></code>
      </small>
    </div>
    <div style="width: 120px;"></div>
  </div>

  <div class="row">
    <!-- LEFT: like "IIQ User & Insurance Context" square, but debug heavy -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">
          IIQ / Owner / Charge Insurance Context (DEBUG)
        </div>
        <div class="card-body small">
          <h6>Owner / Charge Summary</h6>
          <ul>
            <li><strong>Owner Email:</strong> <?= htmlspecialchars($ownerEmail) ?></li>
            <li><strong>OwnerRowID (owners.id):</strong> <?= (int)$ownerRowId ?></li>
            <li><strong>District ID:</strong> <?= $districtId !== '' ? htmlspecialchars($districtId) : '<span class="text-warning">NULL/empty</span>' ?></li>
            <li><strong>Charge HasInsurance (stored):</strong> <?= panda_badge_insurance($hasInsurance) ?></li>
          </ul>

          <h6>SchoolYear → CoverageYear</h6>
          <pre><?php
              echo htmlspecialchars(var_export([
                  'SchoolYear_from_charge' => $schoolYear,
                  'panda_schoolyear_to_coverage_year()' => $coverageYear,
              ], true));
          ?></pre>

          <h6>Stage B (Auto-correct HasInsurance) Simulation</h6>
          <ul>
            <li><strong>Would Stage B flip HasInsurance to 'NO'?</strong>
              <?= $stageB_wouldFlipToNo ? '<span class="text-danger fw-bold">YES</span>' : '<span class="text-success fw-bold">NO</span>' ?>
            </li>
          </ul>
          <pre><?= htmlspecialchars($stageB_reason) ?></pre>

          <h6>Coverage Tier (Stage C) Debug</h6>
          <p>
            <strong>Final Tier:</strong>
            <code><?= htmlspecialchars($coverageTier) ?></code>
          </p>
          <pre><?php
              echo htmlspecialchars(implode("\n", $coverageTierExplain));
          ?></pre>
        </div>
      </div>
    </div>

    <!-- RIGHT: like "Skyward Coverage" / Coverage Impact square, but debug heavy -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">
          Skyward_ThisnThat Coverage Resolution (DEBUG)
        </div>
        <div class="card-body small">
          <h6>Skyward Coverage (what ChargeDecision *thinks*)</h6>
          <p>
            <strong>Status Label:</strong>
            <?= htmlspecialchars($skyward_status_label) ?><br>
            <strong>Detail:</strong>
            <span class="text-muted"><?= htmlspecialchars($skyward_status_detail) ?></span>
          </p>

          <hr>

          <h6>Primary Lookup (owner_id + CoverageYear)</h6>
          <p>
            SQL used:
          </p>
<pre><?php
echo htmlspecialchars(trim((string)$primarySql));
?></pre>
          <p>Parameters:</p>
<pre><?php
echo htmlspecialchars(var_export($primaryParams, true));
?></pre>

          <?php if ($primaryError): ?>
            <p class="text-danger">
              <strong>SQL Error:</strong> <?= htmlspecialchars($primaryError) ?>
            </p>
          <?php endif; ?>

          <p>Row returned (this is what the main page uses for context):</p>
<pre><?php
echo htmlspecialchars(var_export($primarySkywardRow, true));
?></pre>

          <hr>

          <h6>All Skyward_ThisnThat rows for this owner</h6>
          <p>SQL:</p>
<pre><?php
echo htmlspecialchars(trim((string)$allSql));
?></pre>

          <?php if ($allErr): ?>
            <p class="text-danger">
              <strong>SQL Error:</strong> <?= htmlspecialchars($allErr) ?>
            </p>
          <?php endif; ?>

          <p>Rows:</p>
<pre><?php
echo htmlspecialchars(var_export($allRows, true));
?></pre>
        </div>
      </div>
    </div>
  </div>
</div>

</body>
</html>
