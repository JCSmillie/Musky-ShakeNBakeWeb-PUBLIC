<?php
// ============================================================================
// PANDA_PartPricing.php
// ----------------------------------------------------------------------------
// Manage PANDA parts + per-school-year pricing.
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/NoraConfigStore.php';
require_once __DIR__ . '/PANDA_Functions.php';

panda_require_charges_enabled();

// -----------------------------------------------------------------------------
// User prefs + access gate
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'];
$allowed = $prefs['allowed_tools'];
$email   = $prefs['email'];

panda_require_charge_admin_access($allowed, 'html');

$csrfToken = musky_csrf_token('panda_part_pricing');

$pdo = panda_db();
$errors  = [];
$success = [];

// -----------------------------------------------------------------------------
// Model catalog for part applicability.
// We store REAL device_model strings in DB and show friendly labels in UI.
// -----------------------------------------------------------------------------
function panda_base_part_model_catalog(): array {
    return [
        'iPad6,11'  => 'iPad Gen 5',
        'iPad7,5'   => 'iPad Gen 6',
        'iPad11,6'  => 'iPad Gen 8',
        'iPad12,1'  => 'iPad Gen 9',
        'iPad13,18' => 'iPad Gen 10',
        'iPad15,7'  => 'iPad Gen 11',
        'iPad16,5'  => 'iPad Gen 12',
        'Mac14,2' => 'MacBook Air (M2, 2022)',
        'Mac16,12' => 'MacBook Air (13-inch, M4, 2025)',
        'Mac17,5' => 'MacBook Neo (13-inch, A18 Pro, 2026)',
        'MacBookAir10,1' => 'MacBook Air (M1, 2020)',
        'MacBookPro14,1' => 'MacBook Pro (13-inch, 2017, Two Thunderbolt 3 ports)',
    ];
}

function panda_part_model_hidden_config_group(): string {
    return 'PANDA';
}

function panda_part_model_hidden_config_key(): string {
    return 'HIDDEN_PART_MODELS';
}

function panda_normalize_part_model_id_list(array $models): array {
    $normalized = [];
    foreach ($models as $model) {
        $model = trim((string)$model);
        if ($model === '') {
            continue;
        }
        $normalized[$model] = true;
    }
    ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
    return $normalized;
}

function panda_default_hidden_part_model_ids(): array {
    return panda_normalize_part_model_id_list([
        'iMac13,3',
        'iMac14,3',
        'iMac18,2',
        'iMac21,1',
        'iMac21,2',
        'Mac15,4',
        'Mac16,2',
        'Mac16,3',
    ]);
}

function panda_part_model_catalog(PDO $pdo, array $parts): array {
    $catalog = panda_base_part_model_catalog();
    $queryIgnore = [
        'iPad4,4' => true,
    ];

    try {
        $sql = "
            SELECT ranked.DeviceModel, ranked.ModelName
              FROM (
                    SELECT TRIM(COALESCE(device_model, model, '')) AS DeviceModel,
                           TRIM(COALESCE(model, '')) AS ModelName,
                           COUNT(*) AS Qty
                      FROM devices
                     WHERE TRIM(COALESCE(device_model, model, '')) <> ''
                       AND (
                            TRIM(COALESCE(device_model, model, '')) LIKE 'iPad%'
                         OR TRIM(COALESCE(device_model, model, '')) LIKE 'Mac%'
                         OR TRIM(COALESCE(device_model, model, '')) LIKE 'iMac%'
                       )
                     GROUP BY 1, 2
                  ) ranked
             WHERE ranked.DeviceModel <> ''
               AND (
                    ranked.DeviceModel LIKE 'iPad%'
                 OR ranked.DeviceModel LIKE 'Mac%'
                 OR ranked.DeviceModel LIKE 'iMac%'
               )
             ORDER BY ranked.DeviceModel, ranked.Qty DESC, ranked.ModelName ASC
        ";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $model) {
            $deviceModel = trim((string)($model['DeviceModel'] ?? ''));
            if ($deviceModel === '' || isset($queryIgnore[$deviceModel])) {
                continue;
            }
            if (isset($catalog[$deviceModel])) {
                continue;
            }

            $displayName = trim((string)($model['ModelName'] ?? ''));
            if ($displayName === '') {
                $displayName = $deviceModel;
            }
            $catalog[$deviceModel] = $displayName;
        }
    } catch (Throwable $e) {
        error_log('[PANDA_PartPricing] model catalog query failed: ' . $e->getMessage());
    }

    foreach ($parts as $part) {
        $decoded = json_decode((string)($part['AppliesToModels'] ?? '[]'), true);
        if (!is_array($decoded)) {
            continue;
        }

        foreach ($decoded as $model) {
            $model = trim((string)$model);
            if ($model === '') {
                continue;
            }
            if (!isset($catalog[$model])) {
                $catalog[$model] = $model;
            }
        }
    }

    return $catalog;
}

function panda_hidden_part_model_ids(PDO $pdo): array {
    if (function_exists('musky_nora_config_store_table_exists')
        && !musky_nora_config_store_table_exists($pdo, 'nora_config_store')) {
        return panda_default_hidden_part_model_ids();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT ConfigValue
              FROM nora_config_store
             WHERE UPPER(ConfigGroup) = UPPER(?)
               AND UPPER(ConfigKey) = UPPER(?)
               AND UPPER(ConfigSet) = 'DEFAULT'
               AND IsActive = 1
             ORDER BY UpdatedAt DESC, ConfigID DESC
             LIMIT 1
        ");
        $stmt->execute([
            panda_part_model_hidden_config_group(),
            panda_part_model_hidden_config_key(),
        ]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[PANDA_PartPricing] hidden model load failed: ' . $e->getMessage());
        return panda_default_hidden_part_model_ids();
    }

    $raw = ($raw !== false && $raw !== null) ? trim((string)$raw) : '';
    if ($raw === '') {
        return panda_default_hidden_part_model_ids();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return panda_default_hidden_part_model_ids();
    }

    return panda_normalize_part_model_id_list($decoded);
}

function panda_save_hidden_part_model_ids(PDO $pdo, array $hiddenIds, string $actor): void {
    if (function_exists('musky_nora_config_store_table_exists')
        && !musky_nora_config_store_table_exists($pdo, 'nora_config_store')) {
        throw new RuntimeException('nora_config_store table is missing.');
    }

    $hiddenIds = panda_normalize_part_model_id_list(array_keys($hiddenIds));
    $models = array_keys($hiddenIds);
    $payload = json_encode(array_values($models), JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Could not encode hidden model list.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO nora_config_store
            (ConfigGroup, ConfigKey, ConfigSet, ValueType, ConfigValue, IsActive, IsSecret, DescriptionText, CreatedBy, UpdatedBy)
        VALUES
            (?, ?, 'DEFAULT', 'json', ?, 1, 0, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ConfigValue = VALUES(ConfigValue),
            ValueType = VALUES(ValueType),
            IsActive = VALUES(IsActive),
            IsSecret = VALUES(IsSecret),
            DescriptionText = VALUES(DescriptionText),
            UpdatedBy = VALUES(UpdatedBy)
    ");
    $stmt->execute([
        panda_part_model_hidden_config_group(),
        panda_part_model_hidden_config_key(),
        $payload,
        'JSON array of PANDA device_model values hidden from the part-pricing applicability picker.',
        $actor,
        $actor,
    ]);
}

function panda_part_model_is_hidden(string $model, array $hiddenIds): bool {
    $model = trim($model);
    return $model !== '' && isset($hiddenIds[$model]);
}

function panda_group_part_model_catalog(array $catalog, array $hiddenIds): array {
    $groups = [
        'iPad Models' => [],
        'Mac Models'  => [],
        'Other Models' => [],
    ];

    foreach ($catalog as $model => $label) {
        if (panda_part_model_is_hidden($model, $hiddenIds)) {
            continue;
        }
        if (str_starts_with($model, 'iPad')) {
            $groups['iPad Models'][$model] = $label;
            continue;
        }
        if (str_starts_with($model, 'Mac') || str_starts_with($model, 'iMac')) {
            $groups['Mac Models'][$model] = $label;
            continue;
        }
        $groups['Other Models'][$model] = $label;
    }

    natcasesort($groups['Mac Models']);
    natcasesort($groups['Other Models']);

    return array_filter($groups);
}

function panda_hidden_part_model_catalog(array $catalog, array $hiddenIds): array {
    $hidden = [];
    foreach ($hiddenIds as $model => $_unused) {
        $hidden[$model] = panda_part_model_label($model, $catalog);
    }
    natcasesort($hidden);
    return $hidden;
}

function panda_part_model_label(string $model, array $catalog): string {
    $model = trim($model);
    if ($model === '') {
        return '';
    }
    return $catalog[$model] ?? $model;
}

function panda_part_model_option_text(string $model, array $catalog): string {
    $label = panda_part_model_label($model, $catalog);
    if ($label === $model) {
        return $model;
    }
    return $label . ' [' . $model . ']';
}

function panda_current_school_year(): string {
    $month = (int)date('n');
    $year = (int)date('Y');
    $startYear = $month >= 7 ? $year : ($year - 1);
    return sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);
}

// -----------------------------------------------------------------------------
// Determine current school year selection + edit target
// -----------------------------------------------------------------------------
$current_year = trim($_GET['school_year'] ?? '');
if ($current_year === '') {
    $current_year = panda_current_school_year();
}

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

// -----------------------------------------------------------------------------
// Handle actions
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    musky_csrf_require('panda_part_pricing');
    $yearPosted = trim($_POST['SchoolYear'] ?? '');
    if ($yearPosted !== '') {
        $current_year = $yearPosted;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_part') {
        $code  = trim($_POST['PartCode'] ?? '');
        $name  = trim($_POST['PartName'] ?? '');
        $cat   = trim($_POST['PartCategory'] ?? '');
        $isAcc = ($_POST['IsAccessory'] ?? 'NO') === 'YES' ? 'YES' : 'NO';
        $newCostFullRaw = trim((string)($_POST['NewCostFull'] ?? ''));
        $newCostDiscRaw = trim((string)($_POST['NewCostInsDiscount'] ?? ''));
        $newCostCovRaw  = trim((string)($_POST['NewCostInsCovered'] ?? ''));
        $hasInitialPricing = ($newCostFullRaw !== '' || $newCostDiscRaw !== '' || $newCostCovRaw !== '');
        $newCostFull = (float)($newCostFullRaw !== '' ? $newCostFullRaw : 0);
        $newCostDisc = (float)($newCostDiscRaw !== '' ? $newCostDiscRaw : 0);
        $newCostCov  = (float)($newCostCovRaw !== '' ? $newCostCovRaw : 0);

        // New: model applicability (stores REAL device_model strings as JSON)
        $models = $_POST['AppliesToModels'] ?? [];
        if (!is_array($models)) {
            $models = [];
        }
        // Clean out empties and reindex
        $models = array_values(array_filter($models, static function($m) {
            return is_string($m) && $m !== '';
        }));
        $modelsJson = $models ? json_encode($models) : null;

        if ($code === '' || $name === '') {
            $errors[] = "PartCode and PartName are required.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO panda_parts 
                        (PartCode, PartName, PartCategory, IsAccessory, AppliesToModels, IsActive, CreatedAt, UpdatedAt)
                    VALUES 
                        (:code, :name, :cat, :acc, :models, 'YES', NOW(), NOW())
                ");
                $stmt->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':cat'    => $cat ?: null,
                    ':acc'    => $isAcc,
                    ':models' => $modelsJson,
                ]);

                $partId = (int)$pdo->lastInsertId();
                if ($hasInitialPricing && $partId > 0) {
                    $pricingStmt = $pdo->prepare("
                        INSERT INTO panda_part_pricing
                            (PartID, SchoolYear, CostFull, CostInsDiscounted, CostInsCovered, CreatedAt, UpdatedAt)
                        VALUES
                            (:id, :sy, :cf, :cd, :cc, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            CostFull          = VALUES(CostFull),
                            CostInsDiscounted = VALUES(CostInsDiscounted),
                            CostInsCovered    = VALUES(CostInsCovered),
                            UpdatedAt         = NOW()
                    ");
                    $pricingStmt->execute([
                        ':id' => $partId,
                        ':sy' => $current_year,
                        ':cf' => $newCostFull,
                        ':cd' => $newCostDisc,
                        ':cc' => $newCostCov,
                    ]);
                }

                $pdo->commit();
                $success[] = $hasInitialPricing
                    ? "Part '$code' added with pricing for $current_year."
                    : "Part '$code' added.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[PANDA_PartPricing] add_part failed: ' . $e->getMessage());
                $errors[] = "Could not add part.";
            }
        }
    }
    elseif ($action === 'hide_model' || $action === 'unhide_model') {
        $model = trim((string)($_POST['VisibilityModel'] ?? ''));
        if ($model === '') {
            $errors[] = 'Select a model first.';
        } else {
            try {
                $hiddenIds = panda_hidden_part_model_ids($pdo);
                if ($action === 'hide_model') {
                    $hiddenIds[$model] = true;
                    $successMessage = "Model '$model' hidden from the picker.";
                } else {
                    unset($hiddenIds[$model]);
                    $successMessage = "Model '$model' restored to the picker.";
                }

                $actor = trim((string)$email) !== '' ? trim((string)$email) : 'PANDA_PartPricing';
                panda_save_hidden_part_model_ids($pdo, $hiddenIds, $actor);
                $success[] = $successMessage;
            } catch (Throwable $e) {
                error_log('[PANDA_PartPricing] model visibility update failed: ' . $e->getMessage());
                $errors[] = 'Could not update model visibility.';
            }
        }
    }
    elseif ($action === 'toggle_part') {
        $partId  = (int)($_POST['PartID'] ?? 0);
        $current = $_POST['IsActive'] ?? 'YES';
        $newStatus = ($current === 'YES') ? 'NO' : 'YES';

        if ($partId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE panda_parts SET IsActive = :s, UpdatedAt = NOW() WHERE PartID = :id");
                $stmt->execute([':s' => $newStatus, ':id' => $partId]);
                $success[] = "Part #$partId set to $newStatus.";
            } catch (Throwable $e) {
                error_log('[PANDA_PartPricing] toggle_part failed: ' . $e->getMessage());
                $errors[] = "Could not update part status.";
            }
        }
    }
    elseif ($action === 'update_part') {
        // Inline "edit row" update for a single part
        $partId = (int)($_POST['EditPartID'] ?? 0);
        $code   = trim($_POST['EditPartCode'] ?? '');
        $name   = trim($_POST['EditPartName'] ?? '');
        $cat    = trim($_POST['EditPartCategory'] ?? '');
        $isAcc  = ($_POST['EditIsAccessory'] ?? 'NO') === 'YES' ? 'YES' : 'NO';
        $isAct  = ($_POST['EditIsActive'] ?? 'YES') === 'NO' ? 'NO' : 'YES';

        $models = $_POST['EditAppliesToModels'] ?? [];
        if (!is_array($models)) {
            $models = [];
        }
        $models = array_values(array_filter($models, static function($m) {
            return is_string($m) && $m !== '';
        }));
        $modelsJson = $models ? json_encode($models) : null;

        if ($partId <= 0) {
            $errors[] = "Invalid PartID for update.";
        } elseif ($code === '' || $name === '') {
            $errors[] = "PartCode and PartName are required for update.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE panda_parts
                    SET PartCode        = :code,
                        PartName        = :name,
                        PartCategory    = :cat,
                        IsAccessory     = :acc,
                        IsActive        = :act,
                        AppliesToModels = :models,
                        UpdatedAt       = NOW()
                    WHERE PartID = :id
                ");
                $stmt->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':cat'    => $cat ?: null,
                    ':acc'    => $isAcc,
                    ':act'    => $isAct,
                    ':models' => $modelsJson,
                    ':id'     => $partId,
                ]);
                $success[] = "Part #$partId updated.";
            } catch (Throwable $e) {
                error_log('[PANDA_PartPricing] update_part failed: ' . $e->getMessage());
                $errors[] = "Could not update that part.";
            }
        }
    }
    elseif ($action === 'save_pricing') {
        $cost_full  = $_POST['CostFull']        ?? [];
        $cost_disc  = $_POST['CostInsDiscount'] ?? [];
        $cost_cov   = $_POST['CostInsCovered']  ?? [];

        try {
            $pdo->beginTransaction();

            foreach ($cost_full as $partIdStr => $v) {
                $partId = (int)$partIdStr;
                if ($partId <= 0) continue;

                $f = (float)($cost_full[$partIdStr] ?? 0);
                $d = (float)($cost_disc[$partIdStr] ?? 0);
                $c = (float)($cost_cov[$partIdStr]  ?? 0);

                // Upsert for that part + year
                $stmt = $pdo->prepare("
                    INSERT INTO panda_part_pricing 
                        (PartID, SchoolYear, CostFull, CostInsDiscounted, CostInsCovered, CreatedAt, UpdatedAt)
                    VALUES 
                        (:id, :sy, :cf, :cd, :cc, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        CostFull          = VALUES(CostFull),
                        CostInsDiscounted = VALUES(CostInsDiscounted),
                        CostInsCovered    = VALUES(CostInsCovered),
                        UpdatedAt         = NOW()
                ");
                $stmt->execute([
                    ':id' => $partId,
                    ':sy' => $current_year,
                    ':cf' => $f,
                    ':cd' => $d,
                    ':cc' => $c,
                ]);
            }

            $pdo->commit();
            $success[] = "Pricing saved for $current_year.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[PANDA_PartPricing] save_pricing failed: ' . $e->getMessage());
            $errors[] = "Could not save pricing.";
        }
    }
}

// -----------------------------------------------------------------------------
// Load parts + pricing maps
// -----------------------------------------------------------------------------
$parts = $pdo->query("SELECT * FROM panda_parts ORDER BY PartCode")->fetchAll(PDO::FETCH_ASSOC);
$part_model_catalog = panda_part_model_catalog($pdo, $parts);
$hidden_part_model_ids = panda_hidden_part_model_ids($pdo);
foreach (array_keys($hidden_part_model_ids) as $hiddenModel) {
    if (!isset($part_model_catalog[$hiddenModel])) {
        $part_model_catalog[$hiddenModel] = $hiddenModel;
    }
}
$part_model_groups = panda_group_part_model_catalog($part_model_catalog, $hidden_part_model_ids);
$hidden_part_model_catalog = panda_hidden_part_model_catalog($part_model_catalog, $hidden_part_model_ids);

// Pricing for currently selected year
$price_stmt = $pdo->prepare("SELECT * FROM panda_part_pricing WHERE SchoolYear = :sy");
$price_stmt->execute([':sy' => $current_year]);
$pricing_rows = $price_stmt->fetchAll(PDO::FETCH_ASSOC);

$price_by_part = [];
foreach ($pricing_rows as $r) {
    $price_by_part[(int)$r['PartID']] = $r;
}

// Gather list of distinct years (for dropdown)
$years = [];
$y_rows = $pdo->query("SELECT DISTINCT SchoolYear FROM panda_part_pricing ORDER BY SchoolYear DESC")->fetchAll(PDO::FETCH_COLUMN);
foreach ($y_rows as $y) $years[] = $y;
if (!in_array($current_year, $years, true)) $years[] = $current_year;

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA — Part Pricing</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body { background-color: var(--background-color); color: var(--text-color); }
.table-wrapper { max-height: 60vh; overflow-y: auto; }
#partsTable th { position: sticky; top: 0; background: var(--table-header-bg); z-index: 3; }

/* small pill bubbles for saved model applicability */
.badge-model {
    margin-right: 4px;
    margin-bottom: 4px;
    font-size: 0.8em;
    padding: 4px 8px;
}
.edit-panel {
    background-color: rgba(0,0,0,0.03);
    border-radius: 4px;
    padding: 10px;
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
<div class="container-fluid mt-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <button class="btn btn-secondary" onclick="window.location='../index.php'">⬅ Back</button>
      <a href="PANDA_ChargeQueue.php" class="btn btn-outline-primary">Queue</a>
      <a href="PANDA_ChargeHistory.php" class="btn btn-outline-primary">History</a>
      <a href="../HelperPagesV2/MuskyMakeTicketCharges.php"   class="btn btn-outline-primary">Create Charge</a>
    </div>
    <h3>PANDA — Part Pricing</h3>
    <div></div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success">
      <ul class="mb-0"><?php foreach ($success as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header">
      Model Visibility
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-6">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="hide_model">
            <input type="hidden" name="SchoolYear" value="<?= htmlspecialchars($current_year) ?>">
            <div class="col-12">
              <label class="form-label">Hide Model</label>
              <select name="VisibilityModel" class="form-select">
                <option value="">Select model to hide...</option>
                <?php foreach ($part_model_groups as $groupLabel => $groupModels): ?>
                  <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                    <?php foreach ($groupModels as $model => $label): ?>
                      <option value="<?= htmlspecialchars($model) ?>">
                        <?= htmlspecialchars(panda_part_model_option_text($model, $part_model_catalog)) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <div class="form-text">
                Hidden models are removed from the applicability picker for new part edits.
              </div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-outline-secondary">Hide Selected Model</button>
            </div>
          </form>
        </div>
        <div class="col-lg-6">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="unhide_model">
            <input type="hidden" name="SchoolYear" value="<?= htmlspecialchars($current_year) ?>">
            <div class="col-12">
              <label class="form-label">Hidden Models</label>
              <select name="VisibilityModel" class="form-select" size="8" <?= $hidden_part_model_catalog ? '' : 'disabled' ?>>
                <?php if (!$hidden_part_model_catalog): ?>
                  <option value="">No hidden models.</option>
                <?php else: ?>
                  <?php foreach ($hidden_part_model_catalog as $model => $label): ?>
                    <option value="<?= htmlspecialchars($model) ?>">
                      <?= htmlspecialchars(panda_part_model_option_text($model, $part_model_catalog)) ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-12">
              <div class="form-text">
                Existing saved selections are preserved until you edit a part and choose otherwise.
              </div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-outline-primary" <?= $hidden_part_model_catalog ? '' : 'disabled' ?>>Restore Selected Model</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">
      Add New Part
    </div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="add_part">
        <input type="hidden" name="SchoolYear" value="<?= htmlspecialchars($current_year) ?>">
        <div class="col-md-3">
          <label class="form-label">Part Code</label>
          <input type="text" name="PartCode" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Part Name</label>
          <input type="text" name="PartName" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Category</label>
          <input type="text" name="PartCategory" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Accessory?</label>
          <select name="IsAccessory" class="form-select">
            <option value="NO">NO</option>
            <option value="YES">YES</option>
          </select>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">Add</button>
        </div>

        <!-- New row: AppliesToModels multi-select -->
        <div class="col-12 mt-2">
          <label class="form-label">Applies To Models</label>
          <select name="AppliesToModels[]" class="form-select" multiple size="8">
            <?php foreach ($part_model_groups as $groupLabel => $groupModels): ?>
              <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                <?php foreach ($groupModels as $model => $label): ?>
                  <option value="<?= htmlspecialchars($model) ?>">
                    <?= htmlspecialchars(panda_part_model_option_text($model, $part_model_catalog)) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <div class="form-text">
            Hold Ctrl (or ⌘ on Mac) to select multiple models. Stored as exact device_model values such as iPad11,6 or MacBookAir10,1.
          </div>
        </div>

        <div class="col-12 mt-2">
          <label class="form-label">Initial Pricing for <?= htmlspecialchars($current_year) ?></label>
        </div>
        <div class="col-md-4">
          <label class="form-label">Full Cost</label>
          <input type="number" step="0.01" min="0" name="NewCostFull" class="form-control" placeholder="0.00">
        </div>
        <div class="col-md-4">
          <label class="form-label">Ins. Discount</label>
          <input type="number" step="0.01" min="0" name="NewCostInsDiscount" class="form-control" placeholder="0.00">
        </div>
        <div class="col-md-4">
          <label class="form-label">Ins. Covered</label>
          <input type="number" step="0.01" min="0" name="NewCostInsCovered" class="form-control" placeholder="0.00">
        </div>
      </form>
    </div>
  </div>

  <form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        Part Pricing for School Year:
        <select name="SchoolYear" onchange="this.form.submit()" class="form-select d-inline-block w-auto">
          <?php foreach ($years as $y): ?>
            <option value="<?= htmlspecialchars($y) ?>" <?= $y === $current_year ? 'selected' : '' ?>>
              <?= htmlspecialchars($y) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button type="submit" name="action" value="save_pricing" class="btn btn-success">💾 Save Pricing</button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="table table-striped table-hover table-sm" id="partsTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Code</th>
              <th>Name</th>
              <th>Category</th>
              <th>Acc?</th>
              <th>Active?</th>
              <th>Full Cost</th>
              <th>Ins. Discount</th>
              <th>Ins. Covered</th>
              <th>Models</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$parts): ?>
            <tr><td colspan="11" class="text-center text-muted">No parts defined.</td></tr>
          <?php else: ?>
            <?php foreach ($parts as $p): 
              $pid = (int)$p['PartID'];
              $pr  = $price_by_part[$pid] ?? ['CostFull'=>0,'CostInsDiscounted'=>0,'CostInsCovered'=>0];

              // Decode model applicability
              $models = [];
              if (!empty($p['AppliesToModels'])) {
                  $decoded = json_decode($p['AppliesToModels'], true);
                  if (is_array($decoded)) {
                      $models = $decoded;
                  }
              }

              $displayModels = [];
              foreach ($models as $m) {
                  $m = trim((string)$m);
                  if ($m === '') {
                      continue;
                  }
                  if (panda_part_model_is_hidden($m, $hidden_part_model_ids)) {
                      continue;
                  }
                  $displayModels[$m] = panda_part_model_label($m, $part_model_catalog);
              }

              $isEditing = ($edit_id === $pid);
              ?>
              <!-- Summary row -->
              <tr<?= $isEditing ? ' class="table-active"' : '' ?>>
                <td><?= $pid ?></td>
                <td><?= htmlspecialchars($p['PartCode']) ?></td>
                <td><?= htmlspecialchars($p['PartName']) ?></td>
                <td><?= htmlspecialchars($p['PartCategory'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['IsAccessory']) ?></td>
                <td><?= htmlspecialchars($p['IsActive']) ?></td>
                <td>
                  <input type="number" step="0.01" class="form-control form-control-sm"
                         name="CostFull[<?= $pid ?>]"
                         value="<?= htmlspecialchars($pr['CostFull']) ?>">
                </td>
                <td>
                  <input type="number" step="0.01" class="form-control form-control-sm"
                         name="CostInsDiscount[<?= $pid ?>]"
                         value="<?= htmlspecialchars($pr['CostInsDiscounted']) ?>">
                </td>
                <td>
                  <input type="number" step="0.01" class="form-control form-control-sm"
                         name="CostInsCovered[<?= $pid ?>]"
                         value="<?= htmlspecialchars($pr['CostInsCovered']) ?>">
                </td>
                <td>
                  <?php if (!$displayModels): ?>
                    <span class="text-muted">—</span>
                  <?php else: ?>
                    <?php foreach ($displayModels as $model => $label): ?>
                      <span class="badge rounded-pill bg-primary badge-model"><?= htmlspecialchars($label) ?></span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex flex-column gap-1">
                    <a href="?school_year=<?= urlencode($current_year) ?>&edit=<?= $pid ?>"
                       class="btn btn-sm btn-outline-primary">
                      Edit
                    </a>
                    <button type="submit" name="action" value="toggle_part"
                            class="btn btn-sm btn-outline-secondary"
                            onclick="this.form.PartID.value='<?= $pid ?>';this.form.IsActive.value='<?= htmlspecialchars($p['IsActive']) ?>';return true;">
                      <?= $p['IsActive'] === 'YES' ? 'Disable' : 'Enable' ?>
                    </button>
                  </div>
                </td>
              </tr>

              <?php if ($isEditing): ?>
                <!-- Inline edit panel row -->
                <tr>
                  <td colspan="11">
                    <div class="edit-panel">
                      <div class="row g-2">
                        <input type="hidden" name="EditPartID" value="<?= $pid ?>">

                        <div class="col-md-3">
                          <label class="form-label">Part Code</label>
                          <input type="text" name="EditPartCode" class="form-control"
                                 value="<?= htmlspecialchars($p['PartCode']) ?>" required>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label">Part Name</label>
                          <input type="text" name="EditPartName" class="form-control"
                                 value="<?= htmlspecialchars($p['PartName']) ?>" required>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label">Category</label>
                          <input type="text" name="EditPartCategory" class="form-control"
                                 value="<?= htmlspecialchars($p['PartCategory'] ?? '') ?>">
                        </div>
                        <div class="col-md-1">
                          <label class="form-label">Accessory?</label>
                          <select name="EditIsAccessory" class="form-select">
                            <option value="NO" <?= $p['IsAccessory'] === 'NO' ? 'selected' : '' ?>>NO</option>
                            <option value="YES" <?= $p['IsAccessory'] === 'YES' ? 'selected' : '' ?>>YES</option>
                          </select>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label">Active?</label>
                          <select name="EditIsActive" class="form-select">
                            <option value="YES" <?= $p['IsActive'] === 'YES' ? 'selected' : '' ?>>YES</option>
                            <option value="NO" <?= $p['IsActive'] === 'NO' ? 'selected' : '' ?>>NO</option>
                          </select>
                        </div>

                        <div class="col-12 mt-2">
                          <label class="form-label">Applies To Models</label>
                          <select name="EditAppliesToModels[]" class="form-select" multiple size="8">
                            <?php foreach ($models as $model): ?>
                              <?php if (!panda_part_model_is_hidden($model, $hidden_part_model_ids)) continue; ?>
                              <option value="<?= htmlspecialchars($model) ?>" selected hidden>
                                <?= htmlspecialchars(panda_part_model_option_text($model, $part_model_catalog)) ?>
                              </option>
                            <?php endforeach; ?>
                            <?php foreach ($part_model_groups as $groupLabel => $groupModels): ?>
                              <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                                <?php foreach ($groupModels as $model => $label): ?>
                                  <option value="<?= htmlspecialchars($model) ?>"
                                    <?= in_array($model, $models, true) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(panda_part_model_option_text($model, $part_model_catalog)) ?>
                                  </option>
                                <?php endforeach; ?>
                              </optgroup>
                            <?php endforeach; ?>
                          </select>
                          <div class="form-text">
                            Options include the curated iPad list plus Apple Mac models already present in the device inventory.
                          </div>
                        </div>

                        <div class="col-12 mt-3 d-flex justify-content-end gap-2">
                          <a href="?school_year=<?= urlencode($current_year) ?>"
                             class="btn btn-outline-secondary">
                            Cancel
                          </a>
                          <button type="submit" name="action" value="update_part"
                                  class="btn btn-success">
                            💾 Save Part Changes
                          </button>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>

            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <input type="hidden" name="PartID" value="">
    <input type="hidden" name="IsActive" value="">
  </form>
</div>
</body>
</html>
