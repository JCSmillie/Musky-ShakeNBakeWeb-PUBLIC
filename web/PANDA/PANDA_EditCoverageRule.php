<?php
// ============================================================================
// PANDA_EditCoverageRule.php
// Editor for a single part-coverage rule.
// Called from PANDA_PartCoverage.php
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/PANDA_Functions.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

panda_require_charges_enabled();

$prefs  = musky_get_logged_in_user_prefs();
$theme  = $prefs['theme'];
$allowed = $prefs['allowed_tools'] ?? [];

panda_require_charge_admin_access($allowed, 'html');

$csrfToken = musky_csrf_token('panda_edit_coverage_rule');

$part   = $_GET['PartCode'] ?? '';
$tier   = $_GET['Tier'] ?? '';

if (!$part || !$tier) {
    die("Missing parameters.");
}

$pdo = panda_db();

// Load rule if exists
$st = $pdo->prepare("
    SELECT *
    FROM panda_part_coverage_rules
    WHERE PartCode = ? AND Tier = ?
    LIMIT 1
");
$st->execute([$part, $tier]);
$rule = $st->fetch(PDO::FETCH_ASSOC);

// Default (new rule)
if (!$rule) {
    $rule = [
        'RuleID' => 0,
        'PartCode' => $part,
        'PartDescription' => '',
        'Tier' => $tier,
        'FreeQtyPerYear' => 0,
        'DiscountQtyPerYear' => 0,
        'DiscountAmountType' => 'NONE',
        'DiscountAmountValue' => 0.00,
        'CostAfterDiscount' => 0.00
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Edit Rule — <?= htmlspecialchars($part) ?> / <?= htmlspecialchars($tier) ?></title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="<?= htmlspecialchars($theme) ?> container mt-3">

<h3>PANDA — Edit Coverage Rule</h3>
<p><strong><?= htmlspecialchars($part) ?></strong> — <?= htmlspecialchars($tier) ?></p>

<form id="ruleForm">
<input type="hidden" name="RuleID" value="<?= $rule['RuleID'] ?>">
<input type="hidden" name="PartCode" value="<?= htmlspecialchars($part) ?>">
<input type="hidden" name="Tier" value="<?= htmlspecialchars($tier) ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

<div class="mb-3">
    <label class="form-label">Part Description</label>
    <input type="text" name="PartDescription" class="form-control"
           value="<?= htmlspecialchars($rule['PartDescription']) ?>">
</div>

<div class="row">
    <div class="col-4 mb-3">
        <label class="form-label">Free Qty Per Year</label>
        <input type="number" name="FreeQtyPerYear" class="form-control"
               value="<?= $rule['FreeQtyPerYear'] ?>">
    </div>

    <div class="col-4 mb-3">
        <label class="form-label">Discount Qty Per Year</label>
        <input type="number" name="DiscountQtyPerYear" class="form-control"
               value="<?= $rule['DiscountQtyPerYear'] ?>">
    </div>

    <div class="col-4 mb-3">
        <label class="form-label">Cost After All Discounts</label>
        <input type="number" step="0.01" name="CostAfterDiscount" class="form-control"
               value="<?= $rule['CostAfterDiscount'] ?>">
    </div>
</div>

<div class="row">
    <div class="col-6 mb-3">
        <label class="form-label">Discount Type</label>
        <select name="DiscountAmountType" class="form-select">
            <?php foreach (['NONE','FIXED','PERCENT'] as $t): ?>
                <option value="<?= $t ?>" <?= ($rule['DiscountAmountType']===$t?'selected':'') ?>>
                    <?= $t ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-6 mb-3">
        <label class="form-label">Discount Value</label>
        <input type="number" step="0.01" name="DiscountAmountValue" class="form-control"
               value="<?= $rule['DiscountAmountValue'] ?>">
    </div>
</div>

<div class="mt-3">
    <button type="button" id="btnSave" class="btn btn-primary">💾 Save Rule</button>
    <a href="PANDA_PartCoverage.php" class="btn btn-secondary">Cancel</a>
</div>

<div id="saveStatus" class="mt-2 text-muted small"></div>

</form>

<script>
document.getElementById("btnSave").addEventListener("click", function() {
    const form = document.getElementById("ruleForm");
    const data = new FormData(form);

    fetch("PANDA_SaveCoverageRule.php", {
        method: "POST",
        body: data
    })
    .then(async (r) => {
        const text = await r.text();
        let js = null;
        try {
            js = JSON.parse(text);
        } catch (e) {}

        if (!r.ok) {
            throw new Error(js && js.error ? js.error : (text || 'Request failed'));
        }

        return js;
    })
    .then(js => {
        const s = document.getElementById("saveStatus");
        if (js.status === "OK") {
            s.textContent = "Saved.";
            s.style.color = "green";
        } else {
            s.textContent = "Error: " + js.error;
            s.style.color = "red";
        }
    })
    .catch(err => {
        document.getElementById("saveStatus").textContent = err;
    });
});
</script>

</body>
</html>
