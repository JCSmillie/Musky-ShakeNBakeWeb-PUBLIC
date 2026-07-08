<?php
// ============================================================================
// PANDA_Functions.php
// ----------------------------------------------------------------------------
// Centralized helpers for PANDA charge workflow:
//   • DB connection wrapper (panda_db)
//   • Part catalog helpers
//   • Model compatibility helpers
//   • History logging helpers
//   • Cost formatting
//   • School year utilities
//   • Coverage evaluation engine
//   • FINAL stable mapping table for all PANDA parts
// ============================================================================

// -----------------------------------------------------------------------------
// NORA DB CONNECTION
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../../Functions/MuskyConfig.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';

function panda_db(): PDO {
    return nora_connect();
}

function panda_charges_enabled(?PDO $pdo = null): bool {
    return musky_config_truthy(musky_active_config_value('PANDA_CHARGES_ENABLED', null, $pdo));
}

function panda_disabled_message(): string {
    return 'PANDA is not enabled. Set PANDA_CHARGES_ENABLED to TRUE in Musky config to use PANDA and charge tools.';
}

function panda_require_charges_enabled(string $responseType = 'html', ?PDO $pdo = null): void {
    if (panda_charges_enabled($pdo)) {
        return;
    }

    $message = panda_disabled_message();
    http_response_code(403);

    if ($responseType === 'json') {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'status' => 'ERR',
            'error' => $message,
            'config_key' => 'PANDA_CHARGES_ENABLED',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($responseType === 'text') {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $message;
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>PANDA Disabled</title>
</head>
<body style="font-family:sans-serif;padding:32px;line-height:1.5;">
  <h1>⛔ PANDA is not enabled.</h1>
  <p>{$safeMessage}</p>
</body>
</html>
HTML;
    exit;
}

function panda_user_has_any_tool(array $allowed, array $variants): bool {
    if (in_array('ALL_TOOLS', $allowed, true)) {
        return true;
    }

    foreach ($variants as $variant) {
        if (in_array($variant, $allowed, true)) {
            return true;
        }
    }

    return false;
}

function panda_user_can_view_charges(array $allowed): bool {
    return panda_user_has_any_tool($allowed, [
        'PANDA_CASHIER',
        'PANDA_VIEWER',
        'PANDA_ADMIN',
        'PANDA_MANAGER',
    ]);
}

function panda_user_can_manage_charges(array $allowed): bool {
    return panda_user_has_any_tool($allowed, [
        'PANDA_ADMIN',
        'PANDA_MANAGER',
    ]);
}

function panda_user_is_hold_coordinator(array $allowed): bool {
    if (in_array('ALL_TOOLS', $allowed, true)) {
        return false;
    }

    if (in_array('PANDA_ADMIN', $allowed, true)) {
        return false;
    }

    if (!in_array('PANDA_MANAGER', $allowed, true)) {
        return false;
    }

    return panda_user_has_any_tool($allowed, [
        'HDK-SUPERVISOR-ADMIN',
        'HDK-SUPERVISOR-III',
    ]);
}

function panda_user_can_admin_charges(array $allowed): bool {
    return panda_user_has_any_tool($allowed, [
        'PANDA_ADMIN',
    ]);
}

function panda_deny_charge_access(string $responseType = 'html', bool $redirectToIndex = false): void {
    $message = $redirectToIndex
        ? 'Access denied. Redirecting to index.'
        : 'View-only access: changes are not allowed.';

    http_response_code(403);

    if ($responseType === 'json') {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'status' => 'ERR',
            'error' => $message,
            'redirect' => $redirectToIndex ? '../index.php' : null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($redirectToIndex) {
        header('Location: ../index.php');
        exit;
    }

    if ($responseType === 'text') {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $message;
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    exit;
}

function panda_require_charge_view_access(array $allowed, string $responseType = 'html'): void {
    if (panda_user_can_view_charges($allowed)) {
        return;
    }

    panda_deny_charge_access($responseType, true);
}

function panda_require_charge_manage_access(array $allowed, string $responseType = 'html'): void {
    if (panda_user_can_manage_charges($allowed)) {
        return;
    }

    panda_deny_charge_access($responseType, !panda_user_can_view_charges($allowed));
}

function panda_require_charge_admin_access(array $allowed, string $responseType = 'html'): void {
    if (panda_user_can_admin_charges($allowed)) {
        return;
    }

    panda_deny_charge_access($responseType, !panda_user_can_view_charges($allowed));
}

function panda_charge_page_permission_variants(): array {
    return [
        'ALL_TOOLS',
        'PANDA_ADMIN',
        'PANDA_MANAGER',
        'HDK-SUPERVISOR-ADMIN',
        'HDK-SUPERVISOR-III',
        'PANDA_CASHIER',
        'PANDA_VIEWER',
    ];
}

function panda_render_charge_permission_footer(array $allowed): string {
    $applicable = panda_charge_page_permission_variants();
    $present = [];
    $missing = [];

    foreach ($applicable as $variant) {
        if (in_array($variant, $allowed, true)) {
            $present[] = $variant;
        } else {
            $missing[] = $variant;
        }
    }

    $effectiveAccess = panda_user_can_manage_charges($allowed)
        ? (panda_user_is_hold_coordinator($allowed) ? 'HOLD_COORDINATOR' : 'FULL_ACCESS')
        : (panda_user_can_view_charges($allowed) ? 'VIEW_ONLY' : 'NO_ACCESS');

    $renderList = static function (array $items, string $emptyLabel): string {
        if (!$items) {
            return '<span class="text-muted">' . htmlspecialchars($emptyLabel, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $badges = array_map(
            static fn(string $item): string => '<span class="badge bg-secondary me-1 mb-1">' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</span>',
            $items
        );

        return implode('', $badges);
    };

    $effectiveAccessHtml = htmlspecialchars($effectiveAccess, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="card mt-3">
  <div class="card-header">Page Permissions</div>
  <div class="card-body small">
    <div class="mb-2"><strong>Effective Access:</strong> <code>{$effectiveAccessHtml}</code></div>
    <div class="mb-2"><strong>Present:</strong><br>{$renderList($present, 'None')}</div>
    <div><strong>Missing:</strong><br>{$renderList($missing, 'None')}</div>
  </div>
</div>
HTML;
}

function panda_charge_lock_name(int $chargeId): string {
    return 'panda_charge_' . max(0, $chargeId);
}

function panda_charge_lock_acquire(PDO $pdo, int $chargeId, int $timeoutSeconds = 3): bool {
    if ($chargeId <= 0) {
        return false;
    }

    try {
        $st = $pdo->prepare("SELECT GET_LOCK(?, ?)");
        $st->execute([panda_charge_lock_name($chargeId), max(0, $timeoutSeconds)]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('[PANDA] charge lock acquire failed for ChargeID ' . $chargeId . ': ' . $e->getMessage());
        return false;
    }
}

function panda_charge_lock_release(PDO $pdo, int $chargeId): void {
    if ($chargeId <= 0) {
        return;
    }

    try {
        $st = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $st->execute([panda_charge_lock_name($chargeId)]);
    } catch (Throwable $e) {
        error_log('[PANDA] charge lock release failed for ChargeID ' . $chargeId . ': ' . $e->getMessage());
    }
}


// ============================================================================
// PART CATALOG HELPERS
// ============================================================================
function panda_load_active_parts(PDO $pdo): array {
    return $pdo->query("
        SELECT PartID, PartCode, PartName, PartCategory, AppliesToModels, IsAccessory
        FROM panda_parts
        WHERE IsActive = 'YES'
        ORDER BY PartCategory, PartCode
    ")->fetchAll();
}

function panda_parts_for_model(PDO $pdo, string $model): array {
    $model = trim($model);
    if ($model === '') return [];

    $rows = panda_load_active_parts($pdo);
    $out  = [];

    foreach ($rows as $r) {
        $list = json_decode($r['AppliesToModels'] ?? '[]', true);
        if (!is_array($list)) $list = [];

        if (in_array($model, $list, true)) {
            $out[] = $r;
        }
    }
    return $out;
}

function panda_parts_not_for_model(PDO $pdo, string $model): array {
    $model = trim($model);
    $all   = panda_load_active_parts($pdo);
    $yes   = panda_parts_for_model($pdo, $model);

    $yesIds = [];
    foreach ($yes as $r) $yesIds[$r['PartID']] = true;

    return array_values(
        array_filter($all, fn($r) => !isset($yesIds[$r['PartID']]))
    );
}


// ============================================================================
// COST HELPERS
// ============================================================================
function panda_format_cost($dollars): string {
    return '$' . number_format((float)$dollars, 2);
}


// ============================================================================
// SCHOOL YEAR HELPERS
// ============================================================================
function panda_schoolyear_to_coverage_year(?string $sy): ?string {
    if (!$sy) return null;
    $sy = trim($sy);

    // -----------------------------------------
    // CASE 1: Already YY-YY  (e.g., 25-26)
    // -----------------------------------------
    if (preg_match('/^(\d{2})-(\d{2})$/', $sy, $m)) {
        return "{$m[1]}-{$m[2]}";
    }

    // -----------------------------------------
    // CASE 2: YYYY-YY  (e.g., 2025-26)
    // → output 25-26
    // -----------------------------------------
    if (preg_match('/^(\d{4})-(\d{2})$/', $sy, $m)) {
        $start = (int)$m[1];
        $end2  = (int)$m[2];

        $start2 = $start % 100;   // 2025 → 25

        return sprintf("%02d-%02d", $start2, $end2);
    }

    // -----------------------------------------
    // CASE 3: YYYY-YYYY  (e.g., 2025-2026)
    // → output 25-26
    // -----------------------------------------
    if (preg_match('/^(\d{4})-(\d{4})$/', $sy, $m)) {
        $start2 = ((int)$m[1]) % 100;
        $end2   = ((int)$m[2]) % 100;

        return sprintf("%02d-%02d", $start2, $end2);
    }

    return null;
}

// ============================================================================
// CHARGE HISTORY LOGGING
// ============================================================================
function panda_log_charge_status_change(
    PDO $pdo,
    int $chargeId,
    string $oldStatus,
    string $newStatus,
    string $actorUser,
    string $note = ''
): void {

    $sql = "
        INSERT INTO panda_charge_status_history
            (ChargeID, OldStatus, NewStatus, ActorUser, ActionType, NoteText, CreatedAt)
        VALUES
            (:cid, :old, :new, :actor, 'status_change', :note, NOW())
    ";

    $pdo->prepare($sql)->execute([
        ':cid'   => $chargeId,
        ':old'   => $oldStatus,
        ':new'   => $newStatus,
        ':actor' => $actorUser,
        ':note'  => $note,
    ]);
}

function panda_log_charge_created(PDO $pdo, int $chargeId, string $actorUser, string $note = ''): void {

    $sql = "
        INSERT INTO panda_charge_status_history
            (ChargeID, OldStatus, NewStatus, ActorUser, ActionType, NoteText, CreatedAt)
        VALUES
            (:cid, NULL, 'submitted', :actor, 'create', :note, NOW())
    ";

    $pdo->prepare($sql)->execute([
        ':cid'   => $chargeId,
        ':actor' => $actorUser,
        ':note'  => $note
    ]);
}

function panda_log_charge_comment(PDO $pdo, int $chargeId, string $actorUser, string $noteText): void {

    $st = $pdo->prepare("SELECT Status FROM panda_charges WHERE ChargeID = ?");
    $st->execute([$chargeId]);
    $currentStatus = $st->fetchColumn() ?: 'submitted';

    $pdo->prepare("
        INSERT INTO panda_charge_status_history
            (ChargeID, OldStatus, NewStatus, ActorUser, ActionType, NoteText)
        VALUES
            (:cid, :old, :new, :actor, 'comment', :note)
    ")->execute([
        ':cid'   => $chargeId,
        ':old'   => $currentStatus,
        ':new'   => $currentStatus,
        ':actor' => $actorUser,
        ':note'  => $noteText,
    ]);
}

function panda_load_charge_history(PDO $pdo, int $chargeId): array {
    $st = $pdo->prepare("
        SELECT *
        FROM panda_charge_status_history
        WHERE ChargeID = ?
        ORDER BY CreatedAt ASC
    ");
    $st->execute([$chargeId]);
    return $st->fetchAll();
}


// ============================================================================
// HIGH-LEVEL LOOKUP HELPERS
// ============================================================================
function panda_load_charge_full(PDO $pdo, int $chargeId): ?array {
    $st = $pdo->prepare("
        SELECT
            c.*,
            d.serial_number AS DeviceSerial,
            d.device_model  AS DeviceModel,
            d.asset_tag     AS DeviceAssetTag,
            o.id            AS OwnerRowID,
            o.email         AS OwnerEmail,
            o.full_name     AS OwnerName,
            o.grade         AS OwnerGrade,
            o.district_id   AS OwnerDistrictId
        FROM panda_charges c
        JOIN devices d ON c.DeviceID = d.id
        JOIN owners    o ON c.OwnerID = o.id
        WHERE c.ChargeID = ?
        LIMIT 1
    ");
    $st->execute([$chargeId]);
    return $st->fetch() ?: null;
}

function panda_load_prior_usage(PDO $pdo, int $ownerId, string $schoolYear, int $excludeChargeId = 0): array {

    if ($ownerId <= 0 || $schoolYear === '') return [];

    $st = $pdo->prepare("
        SELECT PartCode, PartDescription, SUM(Quantity) AS TotalQty
        FROM panda_charges
        WHERE OwnerID = :oid
          AND SchoolYear = :sy
          AND ChargeID <> :cid
          AND Status IN ('submitted','hold','approved','other_approved','claim_approved','waived')
        GROUP BY PartCode, PartDescription
        ORDER BY PartCode
    ");

    $st->execute([
        ':oid' => $ownerId,
        ':sy'  => $schoolYear,
        ':cid' => $excludeChargeId,
    ]);

    return $st->fetchAll();
}

function panda_normalize_partcode_key(string $raw): string {
    $c = strtoupper(trim($raw));
    return str_replace(["–", "—"], "-", $c);
}

function panda_is_lcd_digitizer_combo_code(string $rawCode): bool {
    return panda_normalize_partcode_key($rawCode) === 'AGI-GLASSDIGITIZERLCDREPLACEMENT';
}

function panda_load_part_full_cost_for_year(PDO $pdo, string $partCode, string $schoolYear): ?float {
    static $cache = [];
    $partCode = trim($partCode);
    $schoolYear = trim($schoolYear);
    if ($partCode === '' || $schoolYear === '') {
        return null;
    }

    $cacheKey = $partCode . '|' . $schoolYear;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $st = $pdo->prepare("
        SELECT pr.CostFull
        FROM panda_parts p
        JOIN panda_part_pricing pr ON pr.PartID = p.PartID
        WHERE p.PartCode = ?
          AND pr.SchoolYear = ?
        ORDER BY pr.UpdatedAt DESC
        LIMIT 1
    ");
    $st->execute([$partCode, $schoolYear]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !isset($row['CostFull'])) {
        $cache[$cacheKey] = null;
        return null;
    }

    $cache[$cacheKey] = (float)$row['CostFull'];
    return $cache[$cacheKey];
}

function panda_coverage_apply_component_rule(
    ?array $rule,
    float $unitCost,
    int $runningUsed
): array {
    $type = 'FULL';
    $cost = $unitCost;

    if (!$rule) {
        return [
            'type' => $type,
            'cost' => round($cost, 2),
        ];
    }

    $freeLimit = (int)($rule['FreeQtyPerYear'] ?? 0);
    $discLimit = (int)($rule['DiscountQtyPerYear'] ?? 0);
    $discType  = strtoupper(trim((string)($rule['DiscountAmountType'] ?? 'NONE')));
    $discValue = (float)($rule['DiscountAmountValue'] ?? 0);
    $costAfterDiscount = isset($rule['CostAfterDiscount'])
        ? max(0, round((float)$rule['CostAfterDiscount'], 2))
        : null;

    if ($runningUsed <= $freeLimit) {
        $type = 'FREE';
        $cost = 0;
    } elseif ($runningUsed <= $freeLimit + $discLimit) {
        if ($discType === 'PERCENT') {
            $type = 'DISCOUNT';
            $cost = round($unitCost * (100 - $discValue) / 100, 2);
        } elseif ($discType === 'FIXED') {
            $type = 'DISCOUNT';
            $cost = max(0, round($unitCost - $discValue, 2));
        } elseif ($discType === 'NONE' && $costAfterDiscount !== null) {
            if (abs($costAfterDiscount - $unitCost) > 0.0001) {
                $type = 'DISCOUNT';
                $cost = $costAfterDiscount;
            }
        }
    }

    return [
        'type' => $type,
        'cost' => round($cost, 2),
    ];
}

function panda_compute_lcd_digitizer_combo_coverage(
    PDO $pdo,
    array $charge,
    string $tier,
    int $ownerId,
    string $schoolYear,
    int $qty
): array {
    $components = [
        [
            'partCode' => 'AGI-LCDReplacement',
            'usagePartCodes' => ['AGI-LCDReplacement', 'AGI-GlassDigitizerLCDReplacement'],
            'fallbackCost' => 199.00,
        ],
        [
            'partCode' => 'AGI-GlassDigitizerReplacement',
            'usagePartCodes' => ['AGI-GlassDigitizerReplacement', 'AGI-GlassDigitizerLCDReplacement'],
            'fallbackCost' => 119.00,
        ],
    ];

    $componentState = [];
    foreach ($components as $component) {
        $componentCode = $component['partCode'];
        $usageCodes = array_values(array_unique(array_filter($component['usagePartCodes'] ?? [])));
        if (!$usageCodes) {
            $usageCodes = [$componentCode];
        }

        $st = $pdo->prepare("
            SELECT *
            FROM panda_part_coverage_rules
            WHERE PartCode = ?
              AND Tier = ?
              AND IsActive = 'YES'
            LIMIT 1
        ");
        $st->execute([$componentCode, $tier]);
        $rule = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        $alreadyUsed = 0;
        if ($ownerId > 0 && $schoolYear !== '') {
            $placeholders = implode(',', array_fill(0, count($usageCodes), '?'));
            $params = array_merge([$ownerId, $schoolYear], $usageCodes);
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(Quantity),0)
                FROM panda_charges
                WHERE OwnerID = ?
                  AND SchoolYear = ?
                  AND Status IN ('approved','other_approved','claim_approved','waived')
                  AND PartCode IN ($placeholders)
            ");
            $st->execute($params);
            $alreadyUsed = (int)$st->fetchColumn();
        }

        $unitCost = panda_load_part_full_cost_for_year($pdo, $componentCode, $schoolYear);
        if ($unitCost === null) {
            $unitCost = (float)$component['fallbackCost'];
        }

        $componentState[$componentCode] = [
            'partCode' => $componentCode,
            'usagePartCodes' => $usageCodes,
            'rule' => $rule,
            'alreadyUsedQty' => $alreadyUsed,
            'runningUsedQty' => $alreadyUsed,
            'unitCost' => (float)$unitCost,
            'freeApplied' => 0,
            'discountApplied' => 0,
            'fullApplied' => 0,
        ];
    }

    $perUnit = [];
    $total = 0.0;

    for ($i = 0; $i < $qty; $i++) {
        $unitCostTotal = 0.0;
        $unitComponents = [];
        $allFree = true;
        $anyDiscount = false;
        $anyFull = false;

        foreach ($componentState as $componentCode => &$state) {
            $state['runningUsedQty']++;
            $applied = panda_coverage_apply_component_rule(
                $state['rule'],
                (float)$state['unitCost'],
                (int)$state['runningUsedQty']
            );

            $type = $applied['type'];
            $cost = (float)$applied['cost'];
            $unitCostTotal += $cost;

            if ($type === 'FREE') {
                $state['freeApplied']++;
            } elseif ($type === 'DISCOUNT') {
                $state['discountApplied']++;
                $allFree = false;
                $anyDiscount = true;
            } else {
                $state['fullApplied']++;
                $allFree = false;
                $anyFull = true;
            }

            $unitComponents[] = [
                'part_code' => $componentCode,
                'type' => $type,
                'unit_cost' => round($cost, 2),
            ];
        }
        unset($state);

        $unitType = 'FREE';
        if (!$allFree) {
            $unitType = $anyFull ? 'FULL' : ($anyDiscount ? 'DISCOUNT' : 'FULL');
        }

        $perUnit[] = [
            'index' => $i + 1,
            'type'  => $unitType,
            'unit_cost' => round($unitCostTotal, 2),
            'components' => $unitComponents,
        ];

        $total += $unitCostTotal;
    }

    $componentDetails = [];
    foreach ($componentState as $state) {
        $componentDetails[] = [
            'part_code' => $state['partCode'],
            'usage_part_codes' => $state['usagePartCodes'],
            'rule' => $state['rule'],
            'already_used_qty' => (int)$state['alreadyUsedQty'],
            'unit_cost' => round((float)$state['unitCost'], 2),
            'this_charge' => [
                'free' => (int)$state['freeApplied'],
                'discount' => (int)$state['discountApplied'],
                'full' => (int)$state['fullApplied'],
            ],
        ];
    }

    return [
        'tier'              => $tier,
        'rule'              => null,
        'alreadyUsedQty'    => 0,
        'unitCost'          => (float)($charge['UnitCost'] ?? 0),
        'quantity'          => $qty,
        'perUnitBreakdown'  => $perUnit,
        'totalChargeAmount' => round($total, 2),
        'composite' => [
            'label' => 'LCD + Digitizer',
            'source_part_code' => (string)($charge['PartCode'] ?? ''),
            'components' => $componentDetails,
        ],
    ];
}


// ============================================================================
// COVERAGE ENGINE (FINAL)
// ============================================================================
function panda_compute_coverage_for_charge(PDO $pdo, array $charge, ?array $iiqContext = null): array {

    $ownerId    = (int)($charge['OwnerID'] ?? 0);
    $rawCode    = trim((string)($charge['PartCode'] ?? ''));
    $partCode   = panda_map_partcode_to_coverage_code($rawCode) ?? $rawCode;
    $schoolYear = trim((string)($charge['SchoolYear'] ?? ''));
    $qty        = max(1, (int)($charge['Quantity'] ?? 1));
    $unitCost   = (float)($charge['UnitCost'] ?? 0);

    // ----- determine tier -----
    $tier = 'NO_INS';
    $hasIns = strtoupper(trim((string)$charge['HasInsurance']));

    if ($hasIns === 'YES') {
        $sky = $iiqContext['skyward_row'] ?? null;
        if ($sky && (int)$sky['HasCoverage'] === 1) {
            $tier = (strcasecmp($sky['CoveragePaid'], 'True') === 0) ? 'INS_PAID' : 'INS_BASIC';
        }
    }

    if (panda_is_lcd_digitizer_combo_code($rawCode)) {
        return panda_compute_lcd_digitizer_combo_coverage(
            $pdo,
            $charge,
            $tier,
            $ownerId,
            $schoolYear,
            $qty
        );
    }

    // ----- load rule (ONLY active rules) -----
    $st = $pdo->prepare("
        SELECT *
        FROM panda_part_coverage_rules
        WHERE PartCode = ?
          AND Tier = ?
          AND IsActive = 'YES'
        LIMIT 1
    ");
    $st->execute([$partCode, $tier]);
    $rule = $st->fetch(PDO::FETCH_ASSOC);

    if (!$rule) {
        return [
            'tier'              => $tier,
            'rule'              => null,
            'alreadyUsedQty'    => 0,
            'unitCost'          => $unitCost,
            'quantity'          => $qty,
            'perUnitBreakdown'  => array_map(
                fn($i)=>['index'=>$i+1,'type'=>'FULL','unit_cost'=>$unitCost],
                range(0,$qty-1)
            ),
            'totalChargeAmount' => $unitCost * $qty,
        ];
    }

    // ----- count prior usage -----
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(Quantity),0)
        FROM panda_charges
        WHERE OwnerID   = ?
          AND PartCode  = ?
          AND SchoolYear= ?
          AND Status IN ('approved','other_approved','claim_approved','waived')
    ");
    $st->execute([$ownerId, $partCode, $schoolYear]);
    $alreadyUsed = (int)$st->fetchColumn();

    // ----- free / discount logic -----
    $runningUsed = $alreadyUsed;
    $total = 0;
    $perUnit = [];

    for ($i=0; $i<$qty; $i++) {

        $runningUsed++;
        $applied = panda_coverage_apply_component_rule($rule, $unitCost, $runningUsed);
        $type = $applied['type'];
        $cost = (float)$applied['cost'];

        $perUnit[] = [
            'index' => $i+1,
            'type'  => $type,
            'unit_cost' => $cost
        ];

        $total += $cost;
    }

    return [
        'tier'              => $tier,
        'rule'              => $rule,
        'alreadyUsedQty'    => $alreadyUsed,
        'unitCost'          => $unitCost,
        'quantity'          => $qty,
        'perUnitBreakdown'  => $perUnit,
        'totalChargeAmount' => round($total, 2),
    ];
}


// ============================================================================
// FINAL CANONICAL PARTCODE→RULE MAPPING
// ----------------------------------------------------------------------------
// Maps real-world PANDA part codes to coverage rule codes.
// This MUST match panda_part_coverage_rules.PartCode values.
// ============================================================================
function panda_map_partcode_to_coverage_code(string $raw): ?string {

    $c = strtoupper(trim($raw));
    $c = str_replace(["–","—"], "-", $c);

    // ---- SCREEN PROTECTORS ----
    if (str_contains($c, "SP-GLASS") || str_contains($c, "SCREEN") || str_contains($c, "PROTECTOR")) {

        if (str_contains($c, "DAMAG"))     return "SCRN-PROTECTOR-DMG";
        if (str_contains($c, "MISS") ||
            str_contains($c, "MISSING"))   return "SCRN-PROTECTOR-MISS";

        // default screen protector → *missing* rule
        return "SCRN-PROTECTOR-MISS";
    }

    // ---- CASES ----
    if (str_contains($c, "CASE")) {

        if (str_contains($c, "MISS")) return "CASE-MISSING";
        if (str_contains($c, "BROK") ||
            str_contains($c, "DAMAG")) return "CASE-BROKEN";

        // default case event → broken
        return "CASE-BROKEN";
    }

    // ---- USB-C AUDIO MODULE ----
    if (str_contains($c, "USB") && str_contains($c, "AUDIO")) {
        return "IP10-AUDIO-USB-C";
    }

    // ---- CHARGERS (NO COVERAGE RULES TODAY) ----
    if (str_contains($c, "CHARGER") || str_contains($c, "BRICK")) {
        return null;
    }

    // ---- WHOLE DEVICE (LOST / DAMAGED) ----
    if (str_contains($c, "WHOLE")) {
        return "DEVICE-WHOLE";
    }

    return null;
}

// ============================================================================
// COMBO PART RULES
// ----------------------------------------------------------------------------
// Maps sets of PartIDs → combo PartID
// Array keys MUST be sorted lists of component IDs.
// ============================================================================
function panda_get_combo_map(): array {
    return [
        '14,15' => [
            'ChargePartID' => 29,
            'Description'  => 'Screen Assembly',
            'Price'        => 235.00,
            'Components'   => [14, 15],
        ],
    ];
}

// Given an array of selected PartIDs, return a combo rule or null
function panda_detect_combo(array $selectedPartIds): ?array {
    sort($selectedPartIds);
    $key = implode(',', $selectedPartIds);

    $map = panda_get_combo_map();
    return $map[$key] ?? null;
}

function panda_internal_combo_parts(): array {
    // These PartIDs should NEVER appear in the selection UI
    return [29];
}
?>
