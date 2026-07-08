<?php
// ============================================================================
// PANDA_ChargeHistory.php
// ----------------------------------------------------------------------------
// Browse/search PANDA financial charges + "Last 10 Completed" summary.
// All rows are now clickable and open PANDA_ChargeDecision.php.
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/PANDA_Functions.php';

panda_require_charges_enabled();

// -----------------------------------------------------------------------------
// User prefs + access gate
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'];
$allowed = $prefs['allowed_tools'];
$email   = $prefs['email'];

panda_require_charge_view_access($allowed, 'html');

$canManageCharges = panda_user_can_manage_charges($allowed);
$canAdminCharges = panda_user_can_admin_charges($allowed);

// -----------------------------------------------------------------------------
// Build search filters
// -----------------------------------------------------------------------------
$pdo = panda_db();

$search_active = false;
$where  = [];
$params = [];

// Status
$status = $_GET['status'] ?? '';
if ($status !== '') {
    $search_active = true;
    $where[]       = 'c.Status = :status';
    $params[':status'] = $status;
}

// Date range
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
if ($date_from !== '') {
    $search_active = true;
    $where[]       = 'COALESCE(c.DecidedAt,c.CreatedAt) >= :df';
    $params[':df'] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $search_active = true;
    $where[]       = 'COALESCE(c.DecidedAt,c.CreatedAt) <= :dt';
    $params[':dt'] = $date_to . ' 23:59:59';
}

// School Year
$school_year = trim($_GET['school_year'] ?? '');
if ($school_year !== '') {
    $search_active = true;
    $where[]       = 'c.SchoolYear = :school_year';
    $params[':school_year'] = $school_year;
}

// Ticket
$ticket = trim($_GET['ticket'] ?? '');
if ($ticket !== '') {
    $search_active = true;
    $where[]       = 'c.TicketID LIKE :ticket';
    $params[':ticket'] = '%' . $ticket . '%';
}

// Serial
$serial = trim($_GET['serial'] ?? '');
if ($serial !== '') {
    $search_active = true;
    $where[]       = 'd.serial_number LIKE :serial';
    $params[':serial'] = '%' . $serial . '%';
}

// Owner email
$owner_email = trim($_GET['owner_email'] ?? '');
if ($owner_email !== '') {
    $search_active = true;
    $where[]       = 'o.email LIKE :owner_email';
    $params[':owner_email'] = '%' . $owner_email . '%';
}

// Submitter
$submitter = trim($_GET['submitter'] ?? '');
if ($submitter !== '') {
    $search_active = true;
    $where[]       = 'c.Submitter LIKE :submitter';
    $params[':submitter'] = '%' . $submitter . '%';
}

// Part Code
$part_code = trim($_GET['part_code'] ?? '');
if ($part_code !== '') {
    $search_active = true;
    $where[]       = 'c.PartCode LIKE :part_code';
    $params[':part_code'] = '%' . $part_code . '%';
}

// Insurance
$ins = $_GET['insurance'] ?? '';
if ($ins !== '') {
    $search_active = true;
    $where[]       = 'c.HasInsurance = :ins';
    $params[':ins'] = $ins;
}

// Vandalism
$vand = $_GET['vandalism'] ?? '';
if ($vand !== '') {
    $search_active = true;
    $where[]       = 'c.IsVandalism = :vand';
    $params[':vand'] = $vand;
}

// -----------------------------------------------------------------------------
// Queries
// -----------------------------------------------------------------------------
$search_rows = [];
$last10_rows = [];

if ($search_active) {
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "
        SELECT
            c.*,
            d.serial_number AS DeviceSerial,
            d.device_model  AS DeviceName,
            o.email         AS OwnerEmail,
            o.full_name     AS OwnerName
        FROM panda_charges c
        JOIN devices d ON c.DeviceID = d.id
        JOIN owners  o ON c.OwnerID = o.id
        $where_sql
        ORDER BY COALESCE(c.DecidedAt,c.CreatedAt) DESC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $search_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$sql_last10 = "
    SELECT
        c.*,
        d.serial_number AS DeviceSerial,
        d.device_model  AS DeviceName,
        o.email         AS OwnerEmail,
        o.full_name     AS OwnerName
    FROM panda_charges c
    JOIN devices d ON c.DeviceID = d.id
    JOIN owners  o ON c.OwnerID = o.id
    WHERE c.Status IN ('approved','denied','rejected','cancelled','waived','other_approved')
    ORDER BY COALESCE(c.DecidedAt,c.CreatedAt) DESC
    LIMIT 10
";
$last10_rows = $pdo->query($sql_last10)->fetchAll(PDO::FETCH_ASSOC);


// For dropdowns
$status_options = [
    ''               => '(any)',
    'submitted'      => 'submitted',
    'approved'       => 'approved',
    'denied'         => 'denied',
    'rejected'       => 'rejected',
    'hold'           => 'hold',
    'cancelled'      => 'cancelled',
    'waived'         => 'waived',
    'other_approved' => 'other_approved',
];

$insurance_options = [
    ''        => '(any)',
    'YES'     => 'YES',
    'NO'      => 'NO',
    'UNKNOWN' => 'UNKNOWN',
];

$vandalism_options = $insurance_options;

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA — Charge History</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

<style>
body { background-color: var(--background-color); color: var(--text-color); }
.table-wrapper { max-height: 60vh; overflow-y: auto; }
#historyTable th, #last10Table th {
    position: sticky;
    top: 0;
    background: var(--table-header-bg);
    z-index: 3;
}

.clickable-row { cursor: pointer; }
.clickable-row:hover { filter: brightness(1.05); }

.badge-status { font-size: 0.8em; }
.badge-status.submitted      { background:#9e9e9e; }
.badge-status.approved       { background:#4caf50; }
.badge-status.denied         { background:#e91e63; }
.badge-status.rejected       { background:#f44336; }
.badge-status.hold           { background:#ff9800; }
.badge-status.cancelled      { background:#607d8b; }
.badge-status.waived         { background:#3f51b5; }
.badge-status.other_approved { background:#673ab7; }
</style>
</head>

<body class="<?= htmlspecialchars($theme) ?>">
<div class="container-fluid mt-3">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <button class="btn btn-secondary" onclick="window.location='../index.php'">⬅ Back</button>
      <a href="PANDA_ChargeQueue.php" class="btn btn-outline-primary">Queue</a>
      <?php if ($canManageCharges): ?>
        <a href="../HelperPagesV2/MuskyMakeTicketCharges.php" class="btn btn-outline-primary">Create Charge</a>
      <?php endif; ?>
      <?php if ($canAdminCharges): ?>
        <a href="PANDA_PartPricing.php" class="btn btn-outline-primary">Part Pricing</a>
      <?php endif; ?>
    </div>
    <h3>
      PANDA — Charge History
      <?php if (!$canManageCharges): ?>
        <span class="badge bg-secondary align-middle">View only</span>
      <?php endif; ?>
    </h3>
    <div></div>
  </div>

  <!-- SEARCH FORM -->
  <form class="card mb-3 p-3" method="get">
    <div class="row g-2 mb-2">
      <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach ($status_options as $k => $label): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $status === $k ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">School Year</label>
        <input type="text" name="school_year" class="form-control" placeholder="2025-26"
               value="<?= htmlspecialchars($school_year) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Insurance</label>
        <select name="insurance" class="form-select">
          <?php foreach ($insurance_options as $k => $label): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $ins === $k ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Vandalism</label>
        <select name="vandalism" class="form-select">
          <?php foreach ($vandalism_options as $k => $label): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $vand === $k ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row g-2 mb-2">
      <div class="col-md-2">
        <label class="form-label">Ticket ID</label>
        <input type="text" name="ticket" class="form-control" value="<?= htmlspecialchars($ticket) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Serial</label>
        <input type="text" name="serial" class="form-control" value="<?= htmlspecialchars($serial) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Owner Email</label>
        <input type="text" name="owner_email" class="form-control" value="<?= htmlspecialchars($owner_email) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Submitter</label>
        <input type="text" name="submitter" class="form-control" value="<?= htmlspecialchars($submitter) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Part Code</label>
        <input type="text" name="part_code" class="form-control" value="<?= htmlspecialchars($part_code) ?>">
      </div>

      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-primary me-2">🔍 Search</button>
        <a href="PANDA_ChargeHistory.php" class="btn btn-secondary">Clear</a>
      </div>
    </div>
  </form>

  <!-- SEARCH RESULTS -->
  <?php if ($search_active): ?>
  <div class="card mb-3">
    <div class="card-header">
      Search Results (<?= count($search_rows) ?>)
    </div>

    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="table table-striped table-hover table-sm" id="historyTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Status</th>
              <th>Ticket</th>
              <th>Device</th>
              <th>Owner</th>
              <th>Part</th>
              <th>Qty</th>
              <th>Cost Type</th>
              <th>Total</th>
              <th>Ins</th>
              <th>Vand</th>
              <th>School Year</th>
              <th>Decided</th>
              <th>Submitter</th>
            </tr>
          </thead>

          <tbody>
          <?php if (!$search_rows): ?>
            <tr><td colspan="14" class="text-center text-muted">No matches.</td></tr>
          <?php else: ?>
            <?php foreach ($search_rows as $r): ?>
              <tr class="clickable-row"
                  data-charge-id="<?= (int)$r['ChargeID'] ?>">

                <td>#<?= (int)$r['ChargeID'] ?></td>
                <td><span class="badge badge-status <?= htmlspecialchars($r['Status']) ?>"><?= htmlspecialchars($r['Status']) ?></span></td>

                <td><?= htmlspecialchars($r['TicketSystem']) ?><br>
                    <small><?= htmlspecialchars($r['TicketID']) ?></small></td>

                <td><?= htmlspecialchars($r['DeviceSerial']) ?><br>
                    <small><?= htmlspecialchars($r['DeviceName']) ?></small></td>

                <td><?= htmlspecialchars($r['OwnerEmail']) ?><br>
                    <small><?= htmlspecialchars($r['OwnerName']) ?></small></td>

                <td><?= htmlspecialchars($r['PartCode']) ?><br>
                    <small><?= htmlspecialchars($r['PartDescription']) ?></small></td>

                <td><?= (int)$r['Quantity'] ?></td>
                <td><?= htmlspecialchars($r['CostType']) ?></td>
                <td>$<?= number_format((float)$r['TotalCost'], 2) ?></td>
                <td><?= htmlspecialchars($r['HasInsurance']) ?></td>
                <td><?= htmlspecialchars($r['IsVandalism']) ?></td>
                <td><?= htmlspecialchars($r['SchoolYear']) ?></td>
                <td><?= htmlspecialchars($r['DecidedAt'] ?: $r['CreatedAt']) ?></td>
                <td><?= htmlspecialchars($r['Submitter']) ?></td>

              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>


  <!-- LAST 10 COMPLETED -->
  <div class="card">
    <div class="card-header">
      Last 10 Completed Charges
    </div>

    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="table table-striped table-hover table-sm" id="last10Table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Status</th>
              <th>Ticket</th>
              <th>Device</th>
              <th>Owner</th>
              <th>Part</th>
              <th>Qty</th>
              <th>Cost Type</th>
              <th>Total</th>
              <th>Ins</th>
              <th>Vand</th>
              <th>School Year</th>
              <th>Decided</th>
              <th>Submitter</th>
            </tr>
          </thead>

          <tbody>
          <?php if (!$last10_rows): ?>
            <tr><td colspan="14" class="text-center text-muted">No completed charges yet.</td></tr>

          <?php else: ?>
            <?php foreach ($last10_rows as $r): ?>
              <tr class="clickable-row"
                  data-charge-id="<?= (int)$r['ChargeID'] ?>">

                <td>#<?= (int)$r['ChargeID'] ?></td>
                <td><span class="badge badge-status <?= htmlspecialchars($r['Status']) ?>"><?= htmlspecialchars($r['Status']) ?></span></td>

                <td><?= htmlspecialchars($r['TicketSystem']) ?><br>
                    <small><?= htmlspecialchars($r['TicketID']) ?></small></td>

                <td><?= htmlspecialchars($r['DeviceSerial']) ?><br>
                    <small><?= htmlspecialchars($r['DeviceName']) ?></small></td>

                <td><?= htmlspecialchars($r['OwnerEmail']) ?><br>
                    <small><?= htmlspecialchars($r['OwnerName']) ?></small></td>

                <td><?= htmlspecialchars($r['PartCode']) ?><br>
                    <small><?= htmlspecialchars($r['PartDescription']) ?></small></td>

                <td><?= (int)$r['Quantity'] ?></td>
                <td><?= htmlspecialchars($r['CostType']) ?></td>
                <td>$<?= number_format((float)$r['TotalCost'], 2) ?></td>

                <td><?= htmlspecialchars($r['HasInsurance']) ?></td>
                <td><?= htmlspecialchars($r['IsVandalism']) ?></td>
                <td><?= htmlspecialchars($r['SchoolYear']) ?></td>

                <td><?= htmlspecialchars($r['DecidedAt'] ?: $r['CreatedAt']) ?></td>
                <td><?= htmlspecialchars($r['Submitter']) ?></td>

              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>

        </table>
      </div>
    </div>
  </div>

  <?= panda_render_charge_permission_footer($allowed) ?>

</div>

<!-- CLICK HANDLER FOR BOTH TABLES -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.clickable-row').forEach(function (row) {
        row.addEventListener('click', function () {
            const id = this.dataset.chargeId;
            if (!id) return;
            const url = 'PANDA_ChargeDecision.php?ChargeID=' + encodeURIComponent(id);
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    });
});
</script>

</body>
</html>
