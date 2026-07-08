<?php
// ======================================================================
// MUSKY - MuskyMakeATicket_iPad.php (Phase 4.3 – LostMode Injection)
// ----------------------------------------------------------------------
//  • Handles one or multiple serials (comma-separated)
//  • Builds payloads dynamically client-side
//  • Adds LOSTMODEON to ExtraWorkCalls.Steps when category = lost
//  • Opens MuskyTicketSubmissionMonitor.php in new window
// ======================================================================

if (!isset($_GET['api'])) {
    include_once __DIR__ . '/../../Functions/Utility/MuskyTestHarness.PRIVATE.php';
}

if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('America/New_York');
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';

$serials     = array_filter(array_map('trim', explode(',', $_GET['serial'] ?? '')));
$adminMode   = isset($_GET['admin']) && $_GET['admin'] === '1';
$devices     = [];
$error       = '';

// -----------------------------------------------------------
// Connect + Device lookup
// -----------------------------------------------------------
try { $pdo = nora_connect(); }
catch (Exception $e) {
    error_log('[MuskyMakeATicket_iPad] NORA connection failed: ' . $e->getMessage());
    $error = "❌ Device data is temporarily unavailable.";
}

if ($serials && !$error) {
    try {
        $stmt = $pdo->prepare("
            SELECT d.serial_number,d.deviceudid,d.owner_id,d.last_seen,d.asset_tag,
                   o.full_name AS owner_name,o.email AS owner_email,
                   o.grade AS owner_grade,o.user_type AS owner_type
              FROM devices d
         LEFT JOIN owners o ON d.owner_id=o.id
             WHERE d.serial_number=? LIMIT 1
        ");
        foreach ($serials as $serial) {
            $stmt->execute([$serial]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['submitter'] = $_SESSION['musky_user']['email'] ?? 'unknown';
                $devices[$serial] = $row;
            }
        }
        if (!$devices) $error = "⚠️ No valid devices found in NORA.";
    } catch (Exception $e) {
        error_log('[MuskyMakeATicket_iPad] Device query failed: ' . $e->getMessage());
        $error = "❌ Device lookup failed. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>📋 iPad Help Request – Musky</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
body { font-family: system-ui,sans-serif;margin:0;padding:0; }
.main-card{background:#fff;border-radius:15px;box-shadow:0 6px 20px rgba(0,0,0,0.25);
max-width:880px;margin:60px auto;padding:40px 50px;}
.device-summary{background:#fafafa;border:1px solid #ccc;border-radius:8px;padding:12px;margin-bottom:12px;}
.device-summary.multi {display:inline-block;width:calc(48% - 10px);vertical-align:top;margin-right:10px;}
h1{text-align:center;margin-bottom:.5em;font-size:1.8em;}
fieldset{border-radius:8px;border:1px solid #ccc;padding:1em;margin-top:1em;}
.hidden{display:none;}
.attention{background:yellow;color:red;font-weight:bold;padding:10px;border:2px dashed red;text-align:center;}
select,textarea,input[type=date]{width:100%;font-size:1em;border-radius:8px;padding:8px;border:1px solid #ccc;margin-top:5px;}
button.submit-btn{margin-top:1.4em;background:#4CAF50;color:white;border:none;border-radius:8px;
padding:12px 20px;cursor:pointer;font-size:1em;}
button.submit-btn:hover{background:#43a047;}
label{margin-top:.8em;display:block;}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const category=document.getElementById('category');
  const sections=document.querySelectorAll('.conditional');
  const form=document.querySelector('form');

  function showSection(id){
    sections.forEach(s=>s.classList.add('hidden'));
    if(id)document.getElementById(id).classList.remove('hidden');
  }

  category.addEventListener('change',()=>{
    showSection('');
    if(category.value==='lost')showSection('sec-lost');
    if(category.value==='home')showSection('sec-home');
    if(category.value==='damage')showSection('sec-damage');
    if(category.value==='uncharged')showSection('sec-uncharged');
    if(category.value==='software')showSection('sec-software');
    if(category.value==='charger')showSection('sec-charger');
  });

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();

    let valid=true;
    document.querySelectorAll('.conditional:not(.hidden) [data-required]').forEach(inp=>{
      if(!inp.value.trim()){alert('Please fill out all required fields.');inp.focus();valid=false;}
    });
    if(!category.value){alert('Please select a category.');valid=false;}
    if(!valid)return;

    // --- Build payload(s) dynamically from embedded device data ---
    const devices=JSON.parse(document.getElementById('device-data').textContent||'[]');
    const vals=Object.fromEntries(new FormData(form).entries());

    function getIssue(cat){
      switch(cat){
        case 'lost': return 'Replacement needed > Device Lost or Stolen';
        case 'home': return 'Replacement needed > Forgot device';
        case 'battery': return 'Power > Battery Dies Quicker then Expected';
        case 'damage':
          if(vals.damage_liquid==='Yes')return 'Replacement needed > Liquid spill';
          switch(vals.damage_where){
            case 'iPad Itself':return 'Replacement needed > Hardware Damage';
            case 'Protective Case':return 'Replacement needed > Case Missing or Notably Damaged';
            case 'Screen Protector':return 'Replacement needed > Screen protector missing/damaged';
          }
          return 'Replacement needed > Hardware Damage';
        case 'uncharged': return 'Replacement needed > Not charged';
        case 'software': return 'Replacement needed > Software Issue';
        case 'charger': return 'zGATOR IT ONLY > Missing Equipment';
        default: return 'Replacement needed > Unknown';
      }
    }

    const issue=getIssue(category.value);
    const parts=[vals.details||''];
    if(category.value==='lost'){
      if(vals.lost_lastseen)parts.push('Last seen: '+vals.lost_lastseen);
      if(vals.lost_time)parts.push('Time: '+vals.lost_time);
      if(vals.last_seen)parts.push('Time: '+vals.last_seen);
    }
    if(category.value==='damage'){
      if(vals.damage_unusable)parts.push('Unusable: '+vals.damage_unusable);
      if(vals.damage_unsafe)parts.push('Unsafe: '+vals.damage_unsafe);
      if(vals.damage_where)parts.push('Where: '+vals.damage_where);
    }
    const issueDesc=parts.join(' — ');

    // -----------------------------------------------------------------
    // Build NORA errand payloads + include LOSTMODEON/LOSTCHARGER flags
    // -----------------------------------------------------------------
    const payloads = devices.map(d => {
      const basePayload = {
        IIQRequest: 'TRUE',
        Priority: '5',
        Repeat: 'FALSE',
        UDID: d.deviceudid || '',
        DeviceSerial: d.serial_number,
        AssetTag: d.asset_tag || '',
        Submitter: d.submitter,
        DeviceOwner: d.owner_email || '',

        ExtraDataField01: {
          ForUsername: d.owner_email || '',
          AssetTag: d.asset_tag || '',
          Issue: issue,
          IssueDescription: issueDesc
        },

        // Default ExtraWorkCalls container for any later chained tasks
        ExtraWorkCalls: {
          Notes: 'None',
          Steps: []
        }
      };

      // ✅ If category = 'lost', flag that Lost Mode must be turned on
      if (category.value === 'lost') {
        basePayload.ExtraWorkCalls.Steps.push('LOSTMODEON');
      }

      // ✅ If category = 'charger', flag Lost Charger automation
      //    (PANDA auto-charge + notes handled server-side in NORA subhandler)
      if (category.value === 'charger') {
        basePayload.ExtraWorkCalls.Steps.push('LOSTCHARGER');
      }

      return basePayload;
    });

    // --- Store payloads in localStorage + open monitor window ---
    localStorage.setItem('musky_ticket_payload', JSON.stringify(payloads));
    const monitorWin = window.open(
      'MuskyTicketSubmissionMonitor.php',
      '_blank',
      'width=850,height=650,resizable=yes'
    );

    // --- Listen for confirmation from the monitor window ---
    window.addEventListener('message', (event) => {
      if (event.data === 'MUSKY_MONITOR_READY') {
        window.close(); // ✅ Handoff confirmed
      }
    });
  });
});
</script>
</head>
<body>
<div class="main-card">
  <h1>📋 iPad Help Request</h1>
  <p style="text-align:center;">Please describe what’s going on with your iPad 👇</p>

  <?php if($error): ?>
    <p class="attention"><?=$error?></p>
  <?php elseif($devices): ?>

  <?php if(count($devices) === 1): ?>
    <?php $dev = reset($devices); ?>
    <div class="device-summary">
      <p>📱 <strong>Serial:</strong> <?=htmlspecialchars($dev['serial_number'])?></p>
      <p>🏷️ <strong>Asset #:</strong> <?=htmlspecialchars($dev['asset_tag'] ?: 'Unknown')?></p>
      <p>👤 <strong>Assigned To:</strong> <?=htmlspecialchars($dev['owner_name'] ?: 'Unknown')?></p>
      <p>✉️ <strong>Email:</strong> <?=htmlspecialchars($dev['owner_email'] ?: 'Unknown')?></p>
      <p>🎓 <strong>Grade:</strong> <?=htmlspecialchars($dev['owner_grade'] ?: 'Unknown')?> | <?=htmlspecialchars($dev['owner_type'] ?: 'Unknown')?></p>
      <p>📅 <strong>Last Seen:</strong> <?=htmlspecialchars($dev['last_seen'] ?: 'Never')?></p>
    </div>
  <?php else: ?>
    <div style="margin-bottom:20px;">
      <?php foreach($devices as $serial => $dev): ?>
        <div class="device-summary multi">
          <p><strong>📱 <?=htmlspecialchars($serial)?></strong></p>
          <p><small>Owner: <?=htmlspecialchars($dev['owner_email'] ?: 'Unknown')?></small></p>
          <p><small>Asset: <?=htmlspecialchars($dev['asset_tag'] ?: 'Unknown')?> | <?=htmlspecialchars($dev['owner_type'] ?: '-')?></small></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <fieldset>
      <legend>🧠 Issue Category</legend>
      <label for="category">Choose one:</label>
      <select name="category" id="category" required>
        <option value="">-- Select One --</option>
        <option value="lost">Device is Lost or Missing</option>
        <option value="home">Device Forgot at Home</option>
        <option value="damage">Device is Damaged</option>
        <option value="battery">Battery Not Lasting Long Enough</option>
        <option value="uncharged">Device not Charged for School Today</option>
        <option value="software">Device software not working right</option>
        <option value="charger">Lost Charger</option>
      </select>
    </fieldset>

    <fieldset id="sec-lost" class="conditional hidden">
      <legend>🧭 Lost Device Details</legend>
      <label>Last Time Seen:</label>
      <input type="date" name="lost_lastseen" data-required
        value="<?= htmlspecialchars(
            isset($dev['last_seen']) && $dev['last_seen']
              ? date('Y-m-d', strtotime($dev['last_seen']))
              : ''
        ) ?>">
      <label>Time of Day:</label>
      <select name="lost_time" data-required>
        <option value="">-- Select --</option>
        <option>Early</option><option>Mid Day</option><option>Evening</option>
      </select>
      <label>Location Last Seen:</label>
      <select name="last_seen" data-required>
        <option value="">-- Select --</option>
        <option>At Home</option><option>On the School Bus</option><option>In School</option><option>Elsewhere (Off Campus)</option>
      </select>
    </fieldset>

    <fieldset id="sec-damage" class="conditional hidden">
      <legend>💥 Damage Details</legend>
      <label>Was the damage caused by a spill or liquid encounter?</label>
      <select name="damage_liquid" data-required>
        <option value="">-- Select --</option><option>Yes</option><option>No</option>
      </select>
      <label>Does the damage make iPad unusable?</label>
      <select name="damage_unusable" data-required>
        <option value="">-- Select --</option><option>YES</option><option>NO</option>
      </select>
      <label>Does the damage make iPad unsafe to use?</label>
      <select name="damage_unsafe" data-required>
        <option value="">-- Select --</option><option>YES</option><option>NO</option>
      </select>
      <label>Where is the damage?</label>
      <select name="damage_where" data-required>
        <option value="">-- Select --</option>
        <option>iPad Itself</option><option>Protective Case</option><option>Screen Protector</option>
      </select>
    </fieldset>

    <fieldset id="sec-home" class="conditional hidden"><legend>🏠 Forgot at Home</legend><p>No additional questions required.</p></fieldset>
    <fieldset id="sec-uncharged" class="conditional hidden"><legend>🔋 Uncharged</legend><p>No additional questions required.</p></fieldset>
    <fieldset id="sec-software" class="conditional hidden"><legend>💻 Software</legend><p>No additional questions required.</p></fieldset>
    <fieldset id="sec-charger" class="conditional hidden"><legend>🔌 Charger</legend><p>No additional questions required.</p></fieldset>

    <fieldset>
      <legend>📝 Additional Details</legend>
      <textarea name="details" placeholder="Describe what happened or anything you noticed…" required></textarea>
      <div style="text-align:center;">
        <button type="submit" class="submit-btn">Submit</button>
      </div>
    </fieldset>
  </form>

  <?php else: ?>
    <p class="attention">No device data loaded. Enter a serial number above.</p>
  <?php endif; ?>
</div>

<script id="device-data" type="application/json">
<?= json_encode(array_values($devices), JSON_UNESCAPED_UNICODE) ?>
</script>
</body>
</html>
