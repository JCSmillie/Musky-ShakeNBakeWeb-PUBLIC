<?php
// ============================================================================
// PANDA_PartCoverageRules.php  (FULL DROP-IN VERSION)
// ----------------------------------------------------------------------------
// Features:
//   • Add new rules (only for parts with NO rules yet)
//   • Add multiple tiers at once (checkboxes: NO_INS, INS_BASIC, INS_PAID)
//   • Auto-calc CostAfterDiscount using panda_part_pricing.CostFull
//   • Existing rules table with Save / Disable / Enable / Delete
//   • Disabled rows appear gray + strikethrough
//   • Works with new IsActive column
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/PANDA_Functions.php';

panda_require_charges_enabled();

// -----------------------------------------------------------------------------
// User prefs / access
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'] ?? 'musky-mode';
$allowed = $prefs['allowed_tools'] ?? [];

panda_require_charge_admin_access($allowed, 'html');

$csrfToken = musky_csrf_token('panda_part_coverage_rules');

// -----------------------------------------------------------------------------
// DB
// -----------------------------------------------------------------------------
$pdo = panda_db();

// Load full part list for pricing auto-calc support
$parts_stmt = $pdo->query("SELECT PartID, PartCode FROM panda_parts ORDER BY PartCode ASC");
$allParts = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get latest pricing per part (current school year)
$currentYear = date('Y') . "-" . substr((date('Y') + 1), -2);
$pricing_stmt = $pdo->prepare("
    SELECT p.PartID, p.PartCode, pr.CostFull
      FROM panda_parts p
 LEFT JOIN panda_part_pricing pr
        ON pr.PartID = p.PartID AND pr.SchoolYear = :y
");
$pricing_stmt->execute([':y' => $currentYear]);
$pricing = [];
foreach ($pricing_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pricing[$r['PartCode']] = (float)($r['CostFull'] ?? 0);
}

// All rules
$rules_stmt = $pdo->query("
    SELECT *
      FROM panda_part_coverage_rules
  ORDER BY PartCode ASC, FIELD(Tier,'NO_INS','INS_BASIC','INS_PAID'), RuleID ASC
");
$rules = $rules_stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine which part codes have *no* rules yet
$partsWithRules = [];
foreach ($rules as $r) $partsWithRules[$r['PartCode']] = true;

$partsWithoutRules = [];
foreach ($allParts as $p) {
    if (!isset($partsWithRules[$p['PartCode']])) {
        $partsWithoutRules[] = $p;
    }
}

// -----------------------------------------------------------------------------
// Handle POST actions
// -----------------------------------------------------------------------------
$message = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    musky_csrf_require('panda_part_coverage_rules');
    $action = $_POST['action'] ?? '';

    try {

        // -------------------------------------------------------------
        // Save an existing rule
        // -------------------------------------------------------------
        if ($action === 'save_rule') {
            $ruleId = (int)$_POST['RuleID'];
            $pc     = trim($_POST['PartCode'] ?? '');
            $desc   = trim($_POST['PartDescription'] ?? '');
            $tier   = trim($_POST['Tier'] ?? 'NO_INS');
            $fq     = max(0, (int)($_POST['FreeQtyPerYear'] ?? 0));
            $dq     = max(0, (int)($_POST['DiscountQtyPerYear'] ?? 0));
            $dtype  = trim($_POST['DiscountAmountType'] ?? 'NONE');
            $dval   = (float)($_POST['DiscountAmountValue'] ?? 0);

            // Auto-calc cost
            $base = $pricing[$pc] ?? 0;
            if ($dtype === 'PERCENT')
                $costAfter = round($base - ($base * ($dval / 100)), 2);
            elseif ($dtype === 'FIXED')
                $costAfter = max(0, round($base - $dval, 2));
            else
                $costAfter = $base;

            $st = $pdo->prepare("
                UPDATE panda_part_coverage_rules
                   SET PartCode = :pc,
                       PartDescription = :pd,
                       Tier = :t,
                       FreeQtyPerYear = :fq,
                       DiscountQtyPerYear = :dq,
                       DiscountAmountType = :dt,
                       DiscountAmountValue = :dv,
                       CostAfterDiscount = :ca
                 WHERE RuleID = :id
                 LIMIT 1
            ");
            $st->execute([
                ':pc'=> $pc, ':pd'=>$desc ?: null, ':t'=>$tier,
                ':fq'=>$fq, ':dq'=>$dq, ':dt'=>$dtype,
                ':dv'=>$dval, ':ca'=>$costAfter,
                ':id'=>$ruleId
            ]);
            $message = "Rule #$ruleId updated.";
        }

        // -------------------------------------------------------------
        // Disable rule
        // -------------------------------------------------------------
        elseif ($action === 'disable_rule') {
            $ruleId = (int)$_POST['RuleID'];
            $pdo->prepare("UPDATE panda_part_coverage_rules
                              SET IsActive='NO'
                            WHERE RuleID=? LIMIT 1")
                ->execute([$ruleId]);
            $message = "Rule #$ruleId disabled.";
        }

        // -------------------------------------------------------------
        // Enable rule
        // -------------------------------------------------------------
        elseif ($action === 'enable_rule') {
            $ruleId = (int)$_POST['RuleID'];
            $pdo->prepare("UPDATE panda_part_coverage_rules
                              SET IsActive='YES'
                            WHERE RuleID=? LIMIT 1")
                ->execute([$ruleId]);
            $message = "Rule #$ruleId enabled.";
        }

        // -------------------------------------------------------------
        // Delete rule entirely
        // -------------------------------------------------------------
        elseif ($action === 'delete_rule') {
            $ruleId = (int)$_POST['RuleID'];
            $pdo->prepare("DELETE FROM panda_part_coverage_rules
                            WHERE RuleID=? LIMIT 1")
                ->execute([$ruleId]);
            $message = "Rule #$ruleId deleted.";
        }

        // -------------------------------------------------------------
        // Add new rules for a part that currently has no rules
        // -------------------------------------------------------------
        elseif ($action === 'add_rules_for_part') {

            $partCode = trim($_POST['NewPartCode'] ?? '');
            $tiers    = $_POST['NewTiers'] ?? [];
            $desc     = trim($_POST['NewDescription'] ?? '');

            if ($partCode === '' || !is_array($tiers) || empty($tiers))
                throw new RuntimeException("Select a part and at least one tier.");

            $base = $pricing[$partCode] ?? 0;

            foreach ($tiers as $tier) {
                $tier = trim($tier);
                if (!in_array($tier, ['NO_INS','INS_BASIC','INS_PAID'], true))
                    continue;

                $fq = max(0, (int)($_POST["FQ_$tier"] ?? 0));
                $dq = max(0, (int)($_POST["DQ_$tier"] ?? 0));
                $dtype = trim($_POST["DT_$tier"] ?? 'NONE');
                $dval = (float)($_POST["DV_$tier"] ?? 0);

                // Auto-calc cost
                if ($dtype === 'PERCENT')
                    $costAfter = round($base - ($base * ($dval / 100)), 2);
                elseif ($dtype === 'FIXED')
                    $costAfter = max(0, round($base - $dval, 2));
                else
                    $costAfter = $base;

                $st = $pdo->prepare("
                    INSERT INTO panda_part_coverage_rules
                        (PartCode, PartDescription, Tier,
                         FreeQtyPerYear, DiscountQtyPerYear,
                         DiscountAmountType, DiscountAmountValue,
                         CostAfterDiscount, IsActive)
                    VALUES
                        (:pc, :pd, :t, :fq, :dq, :dt, :dv, :ca, 'YES')
                ");
                $st->execute([
                    ':pc'=>$partCode, ':pd'=>$desc ?: null, ':t'=>$tier,
                    ':fq'=>$fq, ':dq'=>$dq, ':dt'=>$dtype,
                    ':dv'=>$dval, ':ca'=>$costAfter
                ]);
            }

            $message = "New coverage rules created for part $partCode.";
        }

    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[PANDA_PartCoverageRules] action ' . $action . ' failed: ' . $e->getMessage());
        $error = 'Could not save coverage rule changes.';
    }
}

// Reload rules after changes
$rules = $pdo->query("
    SELECT *
      FROM panda_part_coverage_rules
  ORDER BY PartCode ASC, FIELD(Tier,'NO_INS','INS_BASIC','INS_PAID'), RuleID ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA — Part Coverage Rules</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
.disabled-row {
    background-color: #ddd !important;
    text-decoration: line-through;
    opacity: 0.6;
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">

<div class="container mt-3">

<h3 class="mb-3">PANDA — Part Coverage Rules</h3>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php elseif ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ------------------------------------------------------------- -->
<!-- ADD NEW RULES FOR PARTS WITH NO RULES -->
<!-- ------------------------------------------------------------- -->

<div class="card mb-4">
    <div class="card-header">Add Coverage Rules for a Part (only parts without rules)</div>
    <div class="card-body">
        <?php if (empty($partsWithoutRules)): ?>
            <p class="text-muted">All known parts already have coverage rules.</p>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

            <input type="hidden" name="action" value="add_rules_for_part">

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Select Part</label>
                    <select name="NewPartCode" class="form-select" required>
                        <option value="">— choose —</option>
                        <?php foreach ($partsWithoutRules as $p): ?>
                            <option value="<?= htmlspecialchars($p['PartCode']) ?>">
                                <?= htmlspecialchars($p['PartCode']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-8">
                    <label class="form-label fw-bold">Description (optional)</label>
                    <input type="text" name="NewDescription" class="form-control">
                </div>
            </div>

            <h6 class="mt-3 mb-2">Select Tiers to Add:</h6>

            <?php
            $tiers = [
                'NO_INS'   => 'No Insurance',
                'INS_BASIC'=> 'Insurance (Basic)',
                'INS_PAID' => 'Insurance (Paid)'
            ];
            foreach ($tiers as $key => $label):
            ?>
                <div class="border rounded p-2 mb-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               name="NewTiers[]" value="<?= $key ?>" id="tier_<?= $key ?>">
                        <label class="form-check-label fw-bold" for="tier_<?= $key ?>">
                            <?= $label ?> (<?= $key ?>)
                        </label>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-3">
                            <label class="form-label">Free Qty / Year</label>
                            <input type="number" name="FQ_<?= $key ?>" class="form-control"
                                   value="0" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Discount Qty / Year</label>
                            <input type="number" name="DQ_<?= $key ?>" class="form-control"
                                   value="0" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Discount Type</label>
                            <select name="DT_<?= $key ?>" class="form-select">
                                <option value="NONE">None</option>
                                <option value="PERCENT">Percent</option>
                                <option value="FIXED">Fixed Amount</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Discount Value</label>
                            <input type="number" step="0.01"
                                   name="DV_<?= $key ?>" class="form-control" value="0.00">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-success mt-3">➕ Add Rules</button>
        </form>
        <?php endif; ?>
    </div>
</div>


<!-- ------------------------------------------------------------- -->
<!-- EXISTING RULES TABLE -->
<!-- ------------------------------------------------------------- -->

<div class="card">
    <div class="card-header">Existing Coverage Rules</div>
    <div class="card-body p-0">

        <table class="table table-bordered table-striped table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Part Code</th>
                    <th>Description</th>
                    <th>Tier</th>
                    <th>Free/yr</th>
                    <th>Disc Qty</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Cost After</th>
                    <th>Active?</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

            <?php foreach ($rules as $r): 
                $disabled = ($r['IsActive'] === 'NO');
                $rowClass = $disabled ? 'disabled-row' : '';
            ?>
            <tr class="<?= $rowClass ?>">
                <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                <input type="hidden" name="RuleID" value="<?= (int)$r['RuleID'] ?>">
                <input type="hidden" name="action" value="save_rule">

                <td><code><?= $r['RuleID'] ?></code></td>

                <td><input class="form-control form-control-sm" type="text"
                           name="PartCode" value="<?= htmlspecialchars($r['PartCode']) ?>"></td>

                <td><input class="form-control form-control-sm" type="text"
                           name="PartDescription" value="<?= htmlspecialchars($r['PartDescription'] ?? '') ?>"></td>

                <td>
                    <select name="Tier" class="form-select form-select-sm">
                        <option value="NO_INS"   <?= $r['Tier']==='NO_INS'?'selected':'' ?>>NO_INS</option>
                        <option value="INS_BASIC"<?= $r['Tier']==='INS_BASIC'?'selected':'' ?>>INS_BASIC</option>
                        <option value="INS_PAID" <?= $r['Tier']==='INS_PAID'?'selected':'' ?>>INS_PAID</option>
                    </select>
                </td>

                <td><input class="form-control form-control-sm text-end" type="number"
                           name="FreeQtyPerYear"
                           value="<?= (int)$r['FreeQtyPerYear'] ?>"></td>

                <td><input class="form-control form-control-sm text-end" type="number"
                           name="DiscountQtyPerYear"
                           value="<?= (int)$r['DiscountQtyPerYear'] ?>"></td>

                <td>
                    <select name="DiscountAmountType" class="form-select form-select-sm">
                        <option value="NONE"    <?= $r['DiscountAmountType']==='NONE'?'selected':'' ?>>NONE</option>
                        <option value="PERCENT" <?= $r['DiscountAmountType']==='PERCENT'?'selected':'' ?>>PERCENT</option>
                        <option value="FIXED"   <?= $r['DiscountAmountType']==='FIXED'?'selected':'' ?>>FIXED</option>
                    </select>
                </td>

                <td><input class="form-control form-control-sm text-end"
                           type="number" step="0.01"
                           name="DiscountAmountValue"
                           value="<?= htmlspecialchars($r['DiscountAmountValue']) ?>"></td>

                <td><code><?= number_format($r['CostAfterDiscount'],2) ?></code></td>

                <td><?= $r['IsActive']==='YES'?'YES':'NO' ?></td>

                <td class="text-nowrap">

                    <?php if (!$disabled): ?>
                        <button class="btn btn-sm btn-primary" type="submit">Save</button>
                    <?php endif; ?>

                </form>

                    <!-- Disable -->
                    <?php if (!$disabled): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                            <input type="hidden" name="RuleID" value="<?= $r['RuleID'] ?>">
                            <input type="hidden" name="action" value="disable_rule">
                            <button class="btn btn-sm btn-warning" type="submit">Disable</button>
                        </form>
                    <?php endif; ?>

                    <!-- Enable -->
                    <?php if ($disabled): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                            <input type="hidden" name="RuleID" value="<?= $r['RuleID'] ?>">
                            <input type="hidden" name="action" value="enable_rule">
                            <button class="btn btn-sm btn-success" type="submit">Enable</button>
                        </form>
                    <?php endif; ?>

                    <!-- Delete -->
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Delete this rule?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                        <input type="hidden" name="RuleID" value="<?= $r['RuleID'] ?>">
                        <input type="hidden" name="action" value="delete_rule">
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                    </form>

                </td>
            </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
    </div>
</div>

</div>
</body>
</html>
