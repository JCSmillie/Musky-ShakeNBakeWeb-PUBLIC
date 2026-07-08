<?php
// ============================================================================
// NORA - NoraWeb.ConfigStore.php
// ----------------------------------------------------------------------------
// Admin-only configuration store for provider keys, tokens, paths, and JSON.
// Uses MariaDB-backed nora_config_store with support for multiple named sets
// per group/key and one active set at a time.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
date_default_timezone_set('America/New_York');

$tool_required = 'ADMIN_PANEL';
$allowed = explode(',', $_SESSION['musky_user']['allowed_tools'] ?? '');

if (!in_array($tool_required, $allowed, true) && !in_array('ALL_TOOLS', $allowed, true)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$tool_required}";
    exit;
}

if (!isset($_SESSION['musky_user'])) {
    http_response_code(403);
    echo "⛔ Access Denied — Not logged in.";
    exit;
}

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$email = $prefs['email'] ?? ($_SESSION['musky_user']['email'] ?? '');

$configPath = dirname(__DIR__, 2) . '/nora_config.json';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo "<h1>❌ Missing nora_config.json</h1>";
    exit;
}

$config = json_decode(file_get_contents($configPath), true);
if (!$config || !isset($config['host'], $config['user'], $config['pass'], $config['name'])) {
    http_response_code(500);
    echo "<h1>❌ Invalid nora_config.json</h1>";
    exit;
}

try {
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        $config['host'],
        $config['port'] ?? 3306,
        $config['name']
    );
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $t) {
    error_log('[NoraWeb.ConfigStore] DB connection failed: ' . $t->getMessage());
    http_response_code(500);
    echo "<h1>❌ DB connection failed.</h1><p>Check the server logs for details.</p>";
    exit;
}

function h(?string $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT 1
          FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function normalize_group(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    return preg_replace('/\s+/', '_', strtoupper($v));
}

function normalize_key(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    return preg_replace('/\s+/', '_', strtoupper($v));
}

function normalize_set(string $v): string {
    $v = trim($v);
    if ($v === '') return 'DEFAULT';
    return preg_replace('/\s+/', '_', strtoupper($v));
}

function set_active_config(PDO $pdo, int $configId, string $updatedBy): void {
    $stmt = $pdo->prepare("SELECT ConfigGroup, ConfigKey FROM nora_config_store WHERE ConfigID = ?");
    $stmt->execute([$configId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Config entry not found.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE nora_config_store
               SET IsActive = 0, UpdatedBy = ?
             WHERE ConfigGroup = ? AND ConfigKey = ?
        ");
        $stmt->execute([$updatedBy, $row['ConfigGroup'], $row['ConfigKey']]);

        $stmt = $pdo->prepare("
            UPDATE nora_config_store
               SET IsActive = 1, UpdatedBy = ?
             WHERE ConfigID = ?
        ");
        $stmt->execute([$updatedBy, $configId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pretty_value_preview(array $row): string {
    $raw = (string)($row['ConfigValue'] ?? '');
    if (!empty($row['IsSecret'])) {
        return str_repeat('•', min(18, max(8, strlen($raw))));
    }
    if (($row['ValueType'] ?? 'text') === 'json') {
        $j = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $raw = json_encode($j, JSON_UNESCAPED_SLASHES);
        }
    }
    return mb_strlen($raw) > 100 ? mb_substr($raw, 0, 100) . '…' : $raw;
}

function active_config_value(PDO $pdo, string $group, array $keys): array {
    foreach ($keys as $key) {
        $stmt = $pdo->prepare("
            SELECT ConfigKey, ConfigSet, ConfigValue
              FROM nora_config_store
             WHERE ConfigGroup = ?
               AND ConfigKey = ?
               AND IsActive = 1
             ORDER BY UpdatedAt DESC, ConfigID DESC
             LIMIT 1
        ");
        $stmt->execute([normalize_group($group), normalize_key($key)]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'found' => true,
                'value' => (string)$row['ConfigValue'],
                'source' => "{$group}.{$row['ConfigKey']} [{$row['ConfigSet']}]",
            ];
        }
    }

    return [
        'found' => false,
        'value' => '',
        'source' => "{$group}." . implode('|', $keys),
    ];
}

function resolve_secret_value(string $value): array {
    $value = trim($value);
    if ($value === '') {
        return ['value' => '', 'source_note' => 'blank'];
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach (['value', 'token', 'api_token', 'apiKey', 'api_key', 'path'] as $key) {
            if (isset($decoded[$key]) && trim((string)$decoded[$key]) !== '') {
                $value = trim((string)$decoded[$key]);
                break;
            }
        }
    }

    if (is_file($value) && is_readable($value)) {
        return [
            'value' => trim((string)file_get_contents($value)),
            'source_note' => 'readable file path',
        ];
    }

    if (strlen($value) > 0 && $value[0] === '/') {
        return [
            'value' => '',
            'source_note' => 'file path not readable',
        ];
    }

    return ['value' => $value, 'source_note' => 'stored value'];
}

function mosyle_form_value(PDO $pdo, string $postedValue, string $label, array $keys): array {
    $postedValue = trim($postedValue);
    if ($postedValue !== '') {
        return [
            'value' => $postedValue,
            'source' => 'tester form',
            'source_note' => 'temporary override',
            'present' => true,
        ];
    }

    $db = active_config_value($pdo, 'MOSYLE', $keys);
    $resolved = resolve_secret_value((string)$db['value']);

    return [
        'value' => $resolved['value'],
        'source' => $db['found'] ? $db['source'] : "missing active {$label}",
        'source_note' => $resolved['source_note'],
        'present' => trim((string)$resolved['value']) !== '',
    ];
}

function run_mosyle_credential_test(PDO $pdo, array $post): array {
    $apiKey = mosyle_form_value($pdo, (string)($post['mosyle_api_key'] ?? ''), 'API key', [
        'API_KEY',
        'MOSYLE_API_KEY',
        'ACCESS_TOKEN',
    ]);
    $username = mosyle_form_value($pdo, (string)($post['mosyle_username'] ?? ''), 'username', [
        'API_USERNAME',
        'MOSYLE_API_USERNAME',
        'USERNAME',
    ]);
    $password = mosyle_form_value($pdo, (string)($post['mosyle_password'] ?? ''), 'password', [
        'API_PASSWORD',
        'MOSYLE_API_PASSWORD',
        'PASSWORD',
    ]);

    $result = [
        'ok' => false,
        'summary' => 'Mosyle test did not run.',
        'inputs' => [
            'api_key' => ['present' => $apiKey['present'], 'source' => $apiKey['source'], 'source_note' => $apiKey['source_note']],
            'username' => ['present' => $username['present'], 'source' => $username['source'], 'source_note' => $username['source_note']],
            'password' => ['present' => $password['present'], 'source' => $password['source'], 'source_note' => $password['source_note']],
        ],
        'login' => null,
        'listusers' => null,
    ];

    if (!$apiKey['present'] || !$username['present'] || !$password['present']) {
        $result['summary'] = 'Missing Mosyle credential value(s).';
        return $result;
    }

    $loginPayload = json_encode([
        'accessToken' => $apiKey['value'],
        'email' => $username['value'],
        'password' => $password['value'],
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://managerapi.mosyle.com/v2/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $loginPayload,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
    ]);
    $loginResponse = curl_exec($ch);
    $loginCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loginError = curl_error($ch);
    curl_close($ch);

    preg_match('/Authorization:\s*Bearer\s*(\S+)/i', is_string($loginResponse) ? $loginResponse : '', $matches);
    $bearer = trim((string)($matches[1] ?? ''));

    $result['login'] = [
        'http_code' => $loginCode,
        'curl_error' => $loginError,
        'bearer_present' => $bearer !== '',
    ];

    if ($loginError !== '' || $bearer === '') {
        $result['summary'] = 'Mosyle login failed or no bearer token was returned.';
        return $result;
    }

    $listPayload = [
        'accessToken' => $apiKey['value'],
        'options' => [
            'page' => 1,
            'page_size' => 1,
            'specific_columns' => ['id', 'name', 'managedappleid', 'type'],
        ],
    ];

    $ch = curl_init('https://managerapi.mosyle.com/v2/listusers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            "Authorization: Bearer {$bearer}",
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'content' => json_encode($listPayload, JSON_UNESCAPED_SLASHES),
        ]),
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
    ]);
    $listResponse = curl_exec($ch);
    $listCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $listError = curl_error($ch);
    curl_close($ch);

    $listJson = is_string($listResponse) ? json_decode($listResponse, true) : null;
    $listStatus = is_array($listJson) ? (string)($listJson['status'] ?? '') : '';
    $userCount = is_array($listJson['users'] ?? null) ? count($listJson['users']) : 0;

    $result['listusers'] = [
        'http_code' => $listCode,
        'curl_error' => $listError,
        'status' => $listStatus,
        'total' => is_array($listJson) ? ($listJson['total'] ?? null) : null,
        'returned_users' => $userCount,
        'raw_preview' => is_string($listResponse) ? mb_substr($listResponse, 0, 500) : '',
    ];

    $result['ok'] = ($listError === '' && $listCode === 200 && strtoupper($listStatus) === 'OK');
    $result['summary'] = $result['ok']
        ? 'Mosyle login and listusers test succeeded.'
        : 'Mosyle login succeeded, but listusers failed.';

    return $result;
}

$tablePresent = table_exists($pdo, 'nora_config_store');
$toast = null;
$toastType = 'success';
$error = null;
$mosyleTest = null;
$csrfToken = musky_csrf_token('nora_config_store');
$currentUser = $_SESSION['musky_user']['email']
    ?? $_SESSION['musky_user']['username']
    ?? 'unknown';

if ($tablePresent && $_SERVER['REQUEST_METHOD'] === 'POST') {
    musky_csrf_require('nora_config_store');
    $action = $_POST['action'] ?? '';
    $group = normalize_group($_POST['config_group'] ?? '');
    $key = normalize_key($_POST['config_key'] ?? '');
    $set = normalize_set($_POST['config_set'] ?? '');
    $valueType = ($_POST['value_type'] ?? 'text') === 'json' ? 'json' : 'text';
    $configValue = (string)($_POST['config_value'] ?? '');
    $description = trim((string)($_POST['description_text'] ?? ''));
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $isSecret = !empty($_POST['is_secret']) ? 1 : 0;

    try {
        if ($action === 'test_mosyle') {
            $mosyleTest = run_mosyle_credential_test($pdo, $_POST);
            $toast = $mosyleTest['ok']
                ? '✅ Mosyle credential test passed.'
                : '⚠️ Mosyle credential test did not pass.';
            $toastType = $mosyleTest['ok'] ? 'success' : 'warning';
        } elseif ($action === 'save') {
            $configId = (int)($_POST['config_id'] ?? 0);
            if ($group === '' || $key === '') {
                throw new RuntimeException('Group and key are required.');
            }
            if ($valueType === 'json') {
                json_decode($configValue, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('JSON value is invalid: ' . json_last_error_msg());
                }
            }

            if ($configId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE nora_config_store
                       SET ConfigGroup = ?, ConfigKey = ?, ConfigSet = ?, ValueType = ?, ConfigValue = ?,
                           IsActive = ?, IsSecret = ?, DescriptionText = ?, UpdatedBy = ?
                     WHERE ConfigID = ?
                ");
                $stmt->execute([
                    $group, $key, $set, $valueType, $configValue,
                    $isActive, $isSecret, ($description !== '' ? $description : null),
                    $currentUser, $configId
                ]);
                if ($isActive) {
                    set_active_config($pdo, $configId, $currentUser);
                }
                $toast = "✅ Updated {$group}.{$key} [{$set}]";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO nora_config_store
                        (ConfigGroup, ConfigKey, ConfigSet, ValueType, ConfigValue, IsActive, IsSecret, DescriptionText, CreatedBy, UpdatedBy)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $group, $key, $set, $valueType, $configValue,
                    $isActive, $isSecret, ($description !== '' ? $description : null),
                    $currentUser, $currentUser
                ]);
                $configId = (int)$pdo->lastInsertId();
                if ($isActive) {
                    set_active_config($pdo, $configId, $currentUser);
                }
                $toast = "✅ Added {$group}.{$key} [{$set}]";
            }
        } elseif ($action === 'activate') {
            $configId = (int)($_POST['config_id'] ?? 0);
            if ($configId > 0) {
                set_active_config($pdo, $configId, $currentUser);
                $toast = "✅ Active credential updated.";
            }
        } elseif ($action === 'delete') {
            $configId = (int)($_POST['config_id'] ?? 0);
            if ($configId > 0) {
                $stmt = $pdo->prepare("DELETE FROM nora_config_store WHERE ConfigID = ?");
                $stmt->execute([$configId]);
                $toast = "🗑️ Config entry deleted.";
            }
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[NoraWeb.ConfigStore] action ' . $action . ' failed: ' . $e->getMessage());
        $error = 'Could not complete the requested config action.';
    }
}

$rows = [];
$groups = [];
$search = trim((string)($_GET['q'] ?? ''));
$groupFilter = normalize_group($_GET['group'] ?? '');
$editId = (int)($_GET['edit'] ?? 0);

if ($tablePresent) {
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = "(ConfigGroup LIKE ? OR ConfigKey LIKE ? OR ConfigSet LIKE ? OR DescriptionText LIKE ? OR ConfigValue LIKE ?)";
        $needle = '%' . $search . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle);
    }
    if ($groupFilter !== '') {
        $where[] = "ConfigGroup = ?";
        $params[] = $groupFilter;
    }

    $sql = "SELECT * FROM nora_config_store";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY ConfigGroup, ConfigKey, IsActive DESC, ConfigSet";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $groups = $pdo->query("
        SELECT ConfigGroup, COUNT(*) AS item_count
          FROM nora_config_store
         GROUP BY ConfigGroup
         ORDER BY ConfigGroup
    ")->fetchAll();
}

$editRow = [
    'ConfigID' => 0,
    'ConfigGroup' => $groupFilter,
    'ConfigKey' => '',
    'ConfigSet' => 'DEFAULT',
    'ValueType' => 'text',
    'ConfigValue' => '',
    'IsActive' => 1,
    'IsSecret' => 0,
    'DescriptionText' => '',
];

if ($tablePresent && $editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM nora_config_store WHERE ConfigID = ?");
    $stmt->execute([$editId]);
    $found = $stmt->fetch();
    if ($found) $editRow = $found;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>NORA — Config Store</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../theme.css?theme=<?= htmlspecialchars($theme) ?>" rel="stylesheet">
<style>
body { min-height: 100vh; }
.topbar { position: sticky; top: 0; z-index: 20; }
.layout-grid { display: grid; grid-template-columns: 320px 1fr; gap: 18px; }
.panel-card {
    border-radius: 14px;
    border: 1px solid rgba(0,0,0,0.08);
    background: rgba(255,255,255,0.92);
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}
.mono-preview {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 0.85rem;
}
.group-link {
    text-decoration: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 10px;
    border-radius: 10px;
}
.group-link:hover { background: rgba(44,110,242,0.08); }
.table-wrap { overflow: auto; }
@media (max-width: 1100px) {
    .layout-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body class="<?= h($theme) ?>">

<div class="topbar bg-white shadow-sm px-3 py-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <a href="../index.php" class="btn btn-outline-secondary">← Main</a>
        <a href="NoraWeb.Dashboard.php" class="btn btn-outline-secondary">📊 Nora Dashboard</a>
        <h3 class="m-0 ms-2 me-auto">🔐 NORA Config Store</h3>
        <?php if ($tablePresent): ?>
            <span class="badge text-bg-dark"><?= count($rows) ?> item(s)</span>
        <?php endif; ?>
    </div>
</div>

<div class="container-fluid py-3">
    <?php if ($toast): ?>
        <div class="alert alert-<?= h($toastType) ?>"><?= h($toast) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$tablePresent): ?>
        <div class="alert alert-warning">
            <strong>Config table not found.</strong>
            The page is ready, but <code>nora_config_store</code> does not exist in MariaDB.
            Run the install SQL from <code>web/admin/NoraWeb.ConfigStore.install.sql</code> or let me wire the bootstrap back in.
        </div>
    <?php endif; ?>

    <div class="layout-grid">
        <div class="d-flex flex-column gap-3">
            <div class="panel-card p-3">
                <h5 class="mb-3"><?= $editRow['ConfigID'] ? 'Edit Entry' : 'Add Entry' ?></h5>
                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="config_id" value="<?= (int)$editRow['ConfigID'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                    <div class="mb-2">
                        <label class="form-label">Group</label>
                        <input class="form-control" name="config_group" value="<?= h($editRow['ConfigGroup']) ?>" placeholder="MOSYLE / IIQ / SKYWARD" required <?= !$tablePresent ? 'disabled' : '' ?>>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Key</label>
                        <input class="form-control" name="config_key" value="<?= h($editRow['ConfigKey']) ?>" placeholder="API_TOKEN / BASE_URL / CLIENT_JSON" required <?= !$tablePresent ? 'disabled' : '' ?>>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Credential Set / Label</label>
                        <input class="form-control" name="config_set" value="<?= h($editRow['ConfigSet']) ?>" placeholder="DEFAULT / ALT_HELPDESK / PROD" <?= !$tablePresent ? 'disabled' : '' ?>>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label">Value Type</label>
                            <select class="form-select" name="value_type" <?= !$tablePresent ? 'disabled' : '' ?>>
                                <option value="text" <?= ($editRow['ValueType'] ?? 'text') === 'text' ? 'selected' : '' ?>>Text</option>
                                <option value="json" <?= ($editRow['ValueType'] ?? '') === 'json' ? 'selected' : '' ?>>JSON</option>
                            </select>
                        </div>
                        <div class="col-sm-6 d-flex align-items-end">
                            <div class="d-flex flex-column gap-2 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="isActive" name="is_active" value="1" <?= !empty($editRow['IsActive']) ? 'checked' : '' ?> <?= !$tablePresent ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="isActive">Make this the active value</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="isSecret" name="is_secret" value="1" <?= !empty($editRow['IsSecret']) ? 'checked' : '' ?> <?= !$tablePresent ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="isSecret">Secret / sensitive value</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <input class="form-control" name="description_text" value="<?= h($editRow['DescriptionText']) ?>" placeholder="What this setting is used for" <?= !$tablePresent ? 'disabled' : '' ?>>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control mono-preview" name="config_value" rows="10" required <?= !$tablePresent ? 'disabled' : '' ?>><?= h($editRow['ConfigValue']) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit" <?= !$tablePresent ? 'disabled' : '' ?>>💾 Save</button>
                        <a class="btn btn-outline-secondary" href="NoraWeb.ConfigStore.php">➕ New</a>
                    </div>
                </form>
            </div>

            <div class="panel-card p-3">
                <h5 class="mb-2">Mosyle Credential Tester</h5>
                <p class="text-muted small mb-3">
                    Leave fields blank to use the active <code>MOSYLE</code> DB values. Fill any field to test a temporary override without saving it.
                </p>
                <form method="post" class="d-flex flex-column gap-2">
                    <input type="hidden" name="action" value="test_mosyle">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                    <div>
                        <label class="form-label">API Key / accessToken</label>
                        <input class="form-control" type="password" name="mosyle_api_key" autocomplete="off" placeholder="Use active DB value" <?= !$tablePresent ? 'disabled' : '' ?>>
                    </div>

                    <div>
                        <label class="form-label">Username</label>
                        <input class="form-control" type="text" name="mosyle_username" autocomplete="off" placeholder="Use active DB value" <?= !$tablePresent ? 'disabled' : '' ?>>
                    </div>

                    <div>
                        <label class="form-label">Password</label>
                        <input class="form-control" type="password" name="mosyle_password" autocomplete="off" placeholder="Use active DB value" <?= !$tablePresent ? 'disabled' : '' ?>>
                    </div>

                    <button class="btn btn-outline-primary mt-1" type="submit" <?= !$tablePresent ? 'disabled' : '' ?>>Test Mosyle</button>
                </form>

                <?php if ($mosyleTest): ?>
                    <div class="alert <?= !empty($mosyleTest['ok']) ? 'alert-success' : 'alert-warning' ?> mt-3 mb-0">
                        <div class="fw-semibold"><?= h($mosyleTest['summary'] ?? '') ?></div>
                        <div class="small mt-2">
                            <div>
                                API key:
                                <span class="badge <?= !empty($mosyleTest['inputs']['api_key']['present']) ? 'text-bg-success' : 'text-bg-danger' ?>">
                                    <?= !empty($mosyleTest['inputs']['api_key']['present']) ? 'present' : 'missing' ?>
                                </span>
                                <span class="text-muted"><?= h($mosyleTest['inputs']['api_key']['source'] ?? '') ?>, <?= h($mosyleTest['inputs']['api_key']['source_note'] ?? '') ?></span>
                            </div>
                            <div>
                                Username:
                                <span class="badge <?= !empty($mosyleTest['inputs']['username']['present']) ? 'text-bg-success' : 'text-bg-danger' ?>">
                                    <?= !empty($mosyleTest['inputs']['username']['present']) ? 'present' : 'missing' ?>
                                </span>
                                <span class="text-muted"><?= h($mosyleTest['inputs']['username']['source'] ?? '') ?>, <?= h($mosyleTest['inputs']['username']['source_note'] ?? '') ?></span>
                            </div>
                            <div>
                                Password:
                                <span class="badge <?= !empty($mosyleTest['inputs']['password']['present']) ? 'text-bg-success' : 'text-bg-danger' ?>">
                                    <?= !empty($mosyleTest['inputs']['password']['present']) ? 'present' : 'missing' ?>
                                </span>
                                <span class="text-muted"><?= h($mosyleTest['inputs']['password']['source'] ?? '') ?>, <?= h($mosyleTest['inputs']['password']['source_note'] ?? '') ?></span>
                            </div>
                        </div>

                        <?php if (!empty($mosyleTest['login'])): ?>
                            <hr class="my-2">
                            <div class="small">
                                Login HTTP: <code><?= h((string)$mosyleTest['login']['http_code']) ?></code>,
                                bearer:
                                <span class="badge <?= !empty($mosyleTest['login']['bearer_present']) ? 'text-bg-success' : 'text-bg-danger' ?>">
                                    <?= !empty($mosyleTest['login']['bearer_present']) ? 'present' : 'missing' ?>
                                </span>
                                <?php if (!empty($mosyleTest['login']['curl_error'])): ?>
                                    <div class="text-danger"><?= h($mosyleTest['login']['curl_error']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($mosyleTest['listusers'])): ?>
                            <div class="small mt-1">
                                listusers HTTP: <code><?= h((string)$mosyleTest['listusers']['http_code']) ?></code>,
                                status: <code><?= h($mosyleTest['listusers']['status'] ?? '') ?></code>,
                                total: <code><?= h((string)($mosyleTest['listusers']['total'] ?? '')) ?></code>
                                <?php if (!empty($mosyleTest['listusers']['curl_error'])): ?>
                                    <div class="text-danger"><?= h($mosyleTest['listusers']['curl_error']) ?></div>
                                <?php endif; ?>
                                <?php if (empty($mosyleTest['ok']) && !empty($mosyleTest['listusers']['raw_preview'])): ?>
                                    <pre class="mono-preview bg-light border rounded p-2 mt-2 mb-0"><?= h($mosyleTest['listusers']['raw_preview']) ?></pre>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel-card p-3">
                <h5 class="mb-3">Groups</h5>
                <div class="d-flex flex-column gap-1">
                    <a class="group-link" href="NoraWeb.ConfigStore.php">
                        <span>All Groups</span>
                        <span class="badge text-bg-secondary"><?= count($rows) ?></span>
                    </a>
                    <?php foreach ($groups as $g): ?>
                        <a class="group-link" href="?group=<?= urlencode($g['ConfigGroup']) ?>">
                            <span><?= h($g['ConfigGroup']) ?></span>
                            <span class="badge text-bg-secondary"><?= (int)$g['item_count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="panel-card p-3">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <h5 class="m-0 me-auto">Stored Config</h5>
                <form class="d-flex gap-2" method="get">
                    <?php if ($groupFilter !== ''): ?>
                        <input type="hidden" name="group" value="<?= h($groupFilter) ?>">
                    <?php endif; ?>
                    <input class="form-control" type="search" name="q" value="<?= h($search) ?>" placeholder="Search group, key, set, description, value">
                    <button class="btn btn-outline-primary" type="submit">Search</button>
                </form>
            </div>

            <div class="table-wrap">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Key</th>
                            <th>Set</th>
                            <th>Active</th>
                            <th>Type</th>
                            <th>Secret</th>
                            <th>Description</th>
                            <th>Value Preview</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" class="text-muted">No config entries found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><span class="badge text-bg-light border"><?= h($row['ConfigGroup']) ?></span></td>
                            <td><code><?= h($row['ConfigKey']) ?></code></td>
                            <td><span class="badge text-bg-secondary"><?= h($row['ConfigSet']) ?></span></td>
                            <td>
                                <?php if (!empty($row['IsActive'])): ?>
                                    <span class="badge text-bg-success">ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge text-bg-light border">inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($row['ValueType']) ?></td>
                            <td><?= !empty($row['IsSecret']) ? 'Yes' : 'No' ?></td>
                            <td><?= h($row['DescriptionText']) ?></td>
                            <td class="mono-preview"><?= h(pretty_value_preview($row)) ?></td>
                            <td>
                                <div><?= h($row['UpdatedAt']) ?></div>
                                <small class="text-muted"><?= h($row['UpdatedBy']) ?></small>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-2">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$row['ConfigID'] ?>">Edit</a>
                                    <?php if (empty($row['IsActive'])): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="config_id" value="<?= (int)$row['ConfigID'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <button class="btn btn-sm btn-outline-success w-100" type="submit">Make Active</button>
                                    </form>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            onclick="showFullValue(this)"
                                            data-group="<?= h($row['ConfigGroup']) ?>"
                                            data-key="<?= h($row['ConfigKey']) ?>"
                                            data-set="<?= h($row['ConfigSet']) ?>"
                                            data-secret="<?= !empty($row['IsSecret']) ? '1' : '0' ?>"
                                            data-value="<?= h($row['ConfigValue']) ?>">
                                        View
                                    </button>
                                    <form method="post" onsubmit="return confirm('Delete this config entry?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="config_id" value="<?= (int)$row['ConfigID'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <button class="btn btn-sm btn-outline-danger w-100" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="valueModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="valueModalTitle">Config Value</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre id="valueModalBody" class="mono-preview mb-0"></pre>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const valueModal = new bootstrap.Modal(document.getElementById('valueModal'));
const valueTitle = document.getElementById('valueModalTitle');
const valueBody = document.getElementById('valueModalBody');

function showFullValue(btn) {
    const group = btn.dataset.group || '';
    const key = btn.dataset.key || '';
    const set = btn.dataset.set || '';
    const isSecret = btn.dataset.secret === '1';
    const rawValue = btn.dataset.value || '';

    valueTitle.textContent = `${group}.${key} [${set}]`;

    let display = rawValue;
    if (!isSecret) {
        try {
            const parsed = JSON.parse(rawValue);
            display = JSON.stringify(parsed, null, 2);
        } catch (e) {}
    }

    valueBody.textContent = display;
    valueModal.show();
}
</script>
</body>
</html>
