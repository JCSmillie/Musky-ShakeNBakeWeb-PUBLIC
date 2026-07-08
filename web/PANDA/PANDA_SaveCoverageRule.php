<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/PANDA_Functions.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

panda_require_charges_enabled('json');

header('Content-Type: application/json');

$prefs = musky_get_logged_in_user_prefs();
$allowed = $prefs['allowed_tools'] ?? [];

panda_require_charge_admin_access($allowed, 'json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !musky_csrf_is_valid('panda_edit_coverage_rule')) {
    http_response_code(403);
    echo json_encode(['status' => 'ERR', 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

$pdo = panda_db();

// Clean inputs
$RuleID    = intval($_POST['RuleID'] ?? 0);
$PartCode  = trim($_POST['PartCode'] ?? '');
$Tier      = trim($_POST['Tier'] ?? '');

if (!$PartCode || !$Tier) {
    echo json_encode(['status'=>'ERR','error'=>'Missing PartCode or Tier']);
    exit;
}

$fields = [
    'PartDescription'    => $_POST['PartDescription'] ?? '',
    'FreeQtyPerYear'     => intval($_POST['FreeQtyPerYear'] ?? 0),
    'DiscountQtyPerYear' => intval($_POST['DiscountQtyPerYear'] ?? 0),
    'DiscountAmountType' => $_POST['DiscountAmountType'] ?? 'NONE',
    'DiscountAmountValue'=> floatval($_POST['DiscountAmountValue'] ?? 0),
    'CostAfterDiscount'  => floatval($_POST['CostAfterDiscount'] ?? 0),
];

if ($RuleID === 0) {
    // INSERT NEW
    $st = $pdo->prepare("
        INSERT INTO panda_part_coverage_rules
            (PartCode, PartDescription, Tier,
             FreeQtyPerYear, DiscountQtyPerYear,
             DiscountAmountType, DiscountAmountValue,
             CostAfterDiscount)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $ok = $st->execute([
        $PartCode,
        $fields['PartDescription'],
        $Tier,
        $fields['FreeQtyPerYear'],
        $fields['DiscountQtyPerYear'],
        $fields['DiscountAmountType'],
        $fields['DiscountAmountValue'],
        $fields['CostAfterDiscount']
    ]);
} else {
    // UPDATE EXISTING
    $st = $pdo->prepare("
        UPDATE panda_part_coverage_rules
        SET PartDescription=?, FreeQtyPerYear=?, DiscountQtyPerYear=?,
            DiscountAmountType=?, DiscountAmountValue=?, CostAfterDiscount=?
        WHERE RuleID=?
        LIMIT 1
    ");
    $ok = $st->execute([
        $fields['PartDescription'],
        $fields['FreeQtyPerYear'],
        $fields['DiscountQtyPerYear'],
        $fields['DiscountAmountType'],
        $fields['DiscountAmountValue'],
        $fields['CostAfterDiscount'],
        $RuleID
    ]);
}

if ($ok) {
    echo json_encode(['status'=>'OK']);
} else {
    echo json_encode(['status'=>'ERR','error'=>'DB error updating rule']);
}
