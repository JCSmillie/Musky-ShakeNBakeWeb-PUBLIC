<?php
// ============================================================================
// NORA - NoraWeb.TagDecode.php
// ----------------------------------------------------------------------------
// Simple admin UI for DeviceManager tag decode mappings stored in
// nora_config_store under:
//   ConfigGroup = TagDecode
//   ConfigSet   = DEFAULT
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/MuskyConfig.php';
require_once __DIR__ . '/../../Functions/MuskyTagDecode.php';

date_default_timezone_set('America/New_York');

$toolRequired = 'ADMIN_PANEL';
$allowed = explode(',', (string)($_SESSION['musky_user']['allowed_tools'] ?? ''));
if (!in_array($toolRequired, $allowed, true) && !in_array('ALL_TOOLS', $allowed, true)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$toolRequired}";
    exit;
}

$prefs = musky_get_logged_in_user_prefs();
$theme = (string)($prefs['theme'] ?? 'musky-mode');
$currentUser = trim((string)(
    $prefs['email']
    ?? ($_SESSION['musky_user']['email'] ?? $_SESSION['musky_user']['username'] ?? 'unknown')
));
if ($currentUser === '') {
    $currentUser = 'unknown';
}

function h(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES);
}

function tagdecode_activity(string $action, array $extra = []): void
{
    if (!function_exists('musky_activity_log')) {
        return;
    }

    musky_activity_log([
        'event_type' => 'ACTION',
        'action_name' => $action,
        'page_path' => '/admin/NoraWeb.TagDecode.php',
        'extra' => $extra,
    ]);
}

function tagdecode_read_rows(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT ConfigID, ConfigKey, ConfigValue, DescriptionText, IsActive, UpdatedAt, UpdatedBy
          FROM nora_config_store
         WHERE UPPER(ConfigGroup) = UPPER(?)
           AND UPPER(ConfigSet) = UPPER(?)
         ORDER BY ConfigKey
    ");
    $stmt->execute([musky_tag_decode_group(), musky_tag_decode_set()]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pdo = musky_config_pdo();
$tablePresent = musky_tag_decode_table_exists($pdo);
$toast = null;
$toastType = 'success';
$error = null;
$seedResult = null;
$csrfToken = musky_csrf_token('nora_tagdecode_admin');

$editRow = [
    'ConfigID' => 0,
    'ConfigKey' => '',
    'ConfigValue' => '',
    'DescriptionText' => '',
];

if (function_exists('musky_activity_log_page_view')) {
    musky_activity_log_page_view([
        'tool' => 'TAGDECODE_ADMIN',
        'group' => musky_tag_decode_group(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    musky_csrf_require('nora_tagdecode_admin');
    if (!$pdo instanceof PDO || !$tablePresent) {
        $error = 'Config store DB is unavailable.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $tagKey = trim((string)($_POST['tag_key'] ?? ''));
        $tagValue = trim((string)($_POST['tag_value'] ?? ''));
        $descriptionText = trim((string)($_POST['description_text'] ?? ''));
        $configId = (int)($_POST['config_id'] ?? 0);

        try {
            if ($action === 'seed_defaults') {
                $seedResult = musky_tag_decode_seed_defaults($pdo, $currentUser);
                if (!empty($seedResult['errors'])) {
                    $toastType = 'warning';
                    $toast = 'TagDecode default migration finished with warnings.';
                } else {
                    $toast = 'TagDecode defaults migrated to DB.';
                }

                tagdecode_activity('TAGDECODE_MIGRATE_DEFAULTS', [
                    'inserted' => $seedResult['inserted'] ?? 0,
                    'updated' => $seedResult['updated'] ?? 0,
                    'errors' => count($seedResult['errors'] ?? []),
                ]);
            } elseif ($action === 'save') {
                if ($tagKey === '' || $tagValue === '') {
                    throw new RuntimeException('Tag and translation are required.');
                }
                if (str_contains($tagKey, ',')) {
                    throw new RuntimeException('Tag key cannot contain commas.');
                }
                if (mb_strlen($tagKey) > 120) {
                    throw new RuntimeException('Tag key is too long (max 120 chars).');
                }

                if ($configId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE nora_config_store
                           SET ConfigKey = ?,
                               ConfigValue = ?,
                               ValueType = 'text',
                               IsActive = 1,
                               IsSecret = 0,
                               DescriptionText = ?,
                               UpdatedBy = ?
                         WHERE ConfigID = ?
                           AND UPPER(ConfigGroup) = UPPER(?)
                           AND UPPER(ConfigSet) = UPPER(?)
                    ");
                    $stmt->execute([
                        $tagKey,
                        $tagValue,
                        ($descriptionText !== '' ? $descriptionText : null),
                        $currentUser,
                        $configId,
                        musky_tag_decode_group(),
                        musky_tag_decode_set(),
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO nora_config_store
                            (ConfigGroup, ConfigKey, ConfigSet, ValueType, ConfigValue, IsActive, IsSecret, DescriptionText, CreatedBy, UpdatedBy)
                        VALUES
                            (?, ?, ?, 'text', ?, 1, 0, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            ConfigValue = VALUES(ConfigValue),
                            ValueType = VALUES(ValueType),
                            IsActive = VALUES(IsActive),
                            IsSecret = VALUES(IsSecret),
                            DescriptionText = VALUES(DescriptionText),
                            UpdatedBy = VALUES(UpdatedBy)
                    ");
                    $stmt->execute([
                        musky_tag_decode_group(),
                        $tagKey,
                        musky_tag_decode_set(),
                        $tagValue,
                        ($descriptionText !== '' ? $descriptionText : null),
                        $currentUser,
                        $currentUser,
                    ]);
                }

                $toast = "Saved tag '{$tagKey}'.";
                tagdecode_activity('TAGDECODE_SAVE', [
                    'tag_key' => $tagKey,
                    'mode' => ($configId > 0 ? 'edit' : 'upsert'),
                ]);
            } elseif ($action === 'delete') {
                if ($configId <= 0) {
                    throw new RuntimeException('Missing row id for delete.');
                }

                $stmt = $pdo->prepare("
                    SELECT ConfigKey
                      FROM nora_config_store
                     WHERE ConfigID = ?
                       AND UPPER(ConfigGroup) = UPPER(?)
                       AND UPPER(ConfigSet) = UPPER(?)
                ");
                $stmt->execute([$configId, musky_tag_decode_group(), musky_tag_decode_set()]);
                $targetKey = (string)($stmt->fetchColumn() ?: '');

                $stmt = $pdo->prepare("
                    DELETE FROM nora_config_store
                     WHERE ConfigID = ?
                       AND UPPER(ConfigGroup) = UPPER(?)
                       AND UPPER(ConfigSet) = UPPER(?)
                ");
                $stmt->execute([$configId, musky_tag_decode_group(), musky_tag_decode_set()]);

                $toast = 'Tag deleted.';
                tagdecode_activity('TAGDECODE_DELETE', [
                    'tag_key' => $targetKey,
                    'config_id' => $configId,
                ]);
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            tagdecode_activity('TAGDECODE_ERROR', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            error_log('[NoraWeb.TagDecode] action ' . $action . ' failed: ' . $e->getMessage());
            $error = 'Could not save TagDecode changes.';
            tagdecode_activity('TAGDECODE_ERROR', [
                'action' => $action,
                'error' => 'internal_error',
            ]);
        }
    }
}

if ($pdo instanceof PDO && $tablePresent) {
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $stmt = $pdo->prepare("
            SELECT ConfigID, ConfigKey, ConfigValue, DescriptionText
              FROM nora_config_store
             WHERE ConfigID = ?
               AND UPPER(ConfigGroup) = UPPER(?)
               AND UPPER(ConfigSet) = UPPER(?)
             LIMIT 1
        ");
        $stmt->execute([$editId, musky_tag_decode_group(), musky_tag_decode_set()]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($found)) {
            $editRow = $found;
        }
    }
}

$rows = ($pdo instanceof PDO && $tablePresent) ? tagdecode_read_rows($pdo) : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>NORA — Tag Decode Manager</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../theme.css?theme=<?= h($theme) ?>" rel="stylesheet">
<style>
body { min-height: 100vh; }
.topbar { position: sticky; top: 0; z-index: 20; }
.layout-grid { display: grid; grid-template-columns: 380px 1fr; gap: 18px; }
.panel-card {
    border-radius: 14px;
    border: 1px solid rgba(0,0,0,0.08);
    background: rgba(255,255,255,0.92);
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
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
        <a href="NoraWeb.ConfigStore.php" class="btn btn-outline-secondary">🛠 Config Store</a>
        <h3 class="m-0 ms-2 me-auto">🏷️ Tag Decode Manager</h3>
        <span class="badge text-bg-dark"><?= count($rows) ?> tag(s)</span>
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
            Run <code>web/admin/NoraWeb.ConfigStore.install.sql</code> first.
        </div>
    <?php endif; ?>

    <div class="layout-grid">
        <div class="d-flex flex-column gap-3">
            <div class="panel-card p-3">
                <h5 class="mb-3">Migrate Existing decode_tags.php Defaults</h5>
                <p class="text-muted mb-3">
                    Seeds the legacy built-in tag map into DB group <code><?= h(musky_tag_decode_group()) ?></code>.
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="seed_defaults">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" class="btn btn-outline-primary" <?= !$tablePresent ? 'disabled' : '' ?>>
                        Migrate Defaults to DB
                    </button>
                </form>
                <?php if (is_array($seedResult)): ?>
                    <div class="small mt-3">
                        <div>Inserted: <strong><?= (int)($seedResult['inserted'] ?? 0) ?></strong></div>
                        <div>Updated: <strong><?= (int)($seedResult['updated'] ?? 0) ?></strong></div>
                        <div>Total defaults: <strong><?= (int)($seedResult['total_defaults'] ?? 0) ?></strong></div>
                        <?php if (!empty($seedResult['errors'])): ?>
                            <details class="mt-2">
                                <summary>Warnings (<?= count($seedResult['errors']) ?>)</summary>
                                <ul class="mb-0">
                                    <?php foreach ($seedResult['errors'] as $err): ?>
                                        <li><?= h((string)$err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel-card p-3">
                <h5 class="mb-3"><?= !empty($editRow['ConfigID']) ? 'Edit Tag' : 'Add / Update Tag' ?></h5>
                <form method="post" class="d-flex flex-column gap-2">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="config_id" value="<?= (int)($editRow['ConfigID'] ?? 0) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                    <div>
                        <label class="form-label">Tag Key</label>
                        <input class="form-control mono" name="tag_key" maxlength="120" required
                               value="<?= h((string)($editRow['ConfigKey'] ?? '')) ?>"
                               placeholder="Example: ADE">
                    </div>

                    <div>
                        <label class="form-label">Display Text</label>
                        <textarea class="form-control" name="tag_value" rows="4" required
                                  placeholder="Example: ASM Good!"><?= h((string)($editRow['ConfigValue'] ?? '')) ?></textarea>
                    </div>

                    <div>
                        <label class="form-label">Optional Note</label>
                        <input class="form-control" name="description_text"
                               value="<?= h((string)($editRow['DescriptionText'] ?? '')) ?>"
                               placeholder="Optional context for admins">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= !$tablePresent ? 'disabled' : '' ?>>Save Tag</button>
                        <a href="NoraWeb.TagDecode.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel-card p-3">
            <h5 class="mb-3">TagDecode Entries (<?= h(musky_tag_decode_group()) ?>)</h5>
            <div class="table-wrap">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Display Text</th>
                            <th>Description</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="text-muted">No tags stored yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><code><?= h((string)$row['ConfigKey']) ?></code></td>
                            <td><?= h((string)$row['ConfigValue']) ?></td>
                            <td><?= h((string)$row['DescriptionText']) ?></td>
                            <td>
                                <div><?= h((string)$row['UpdatedAt']) ?></div>
                                <small class="text-muted"><?= h((string)$row['UpdatedBy']) ?></small>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-2">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$row['ConfigID'] ?>">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this tag mapping?');">
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
</body>
</html>
