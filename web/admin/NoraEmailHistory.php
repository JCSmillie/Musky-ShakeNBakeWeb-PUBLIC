<?php
// ============================================================================
// Musky — Email History Viewer
// ============================================================================

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';

if (!musky_is_admin()) {
    http_response_code(403);
    echo "⛔ Access Denied — Admin Only";
    exit;
}

// DB Fetch
$db = new PDO("mysql:host=$MYSQL_HOST;dbname=$MYSQL_DB", $MYSQL_USER, $MYSQL_PASS);

$stmt = $db->query("
    SELECT ErrandID, Submitter, SubmissionDateTime, ExtraDataField01, ExtraDataField06
    FROM nora_errands
    WHERE Custom = 'Email'
    ORDER BY SubmissionDateTime DESC
    LIMIT 200
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Theme load
$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>NORA Email History</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
body{margin:0;}
.header{padding:12px;border-bottom:1px solid #ccc;display:flex;gap:12px;align-items:center;}
.section{padding:20px;}
table{width:100%;border-collapse:collapse;font-size:0.9rem;}
th,td{padding:8px;border-bottom:1px solid #ddd;}
pre{white-space:pre-wrap;background:#111;color:#9f9;padding:10px;border-radius:10px;}
</style>
</head>

<body class="<?php echo htmlspecialchars($theme); ?>">

<div class="header">
  <a href="../index.php">← Back</a>
  <h1>NORA Email History</h1>
</div>

<div class="section">
<table>
<thead>
<tr>
  <th>ID</th>
  <th>Submitter</th>
  <th>Date</th>
  <th>To</th>
  <th>Subject</th>
  <th>Status</th>
  <th>Details</th>
</tr>
</thead>
<tbody>

<?php foreach ($rows as $r):
    $payload = json_decode($r['ExtraDataField01'], true);
    $result  = json_decode($r['ExtraDataField06'], true);
?>
<tr>
  <td><?php echo $r['ErrandID']; ?></td>
  <td><?php echo htmlspecialchars($r['Submitter']); ?></td>
  <td><?php echo $r['SubmissionDateTime']; ?></td>
  <td><?php echo htmlspecialchars($payload['to'] ?? ''); ?></td>
  <td><?php echo htmlspecialchars($payload['subject'] ?? ''); ?></td>
  <td><?php echo $result['ok'] ? '✔ Sent' : '❌ Failed'; ?></td>
  <td>
    <details>
      <summary>View</summary>
      <pre><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)); ?></pre>
    </details>
  </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

</body>
</html>
