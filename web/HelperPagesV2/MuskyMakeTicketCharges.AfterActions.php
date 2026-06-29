<?php
// ============================================================================
// HelperPagesV2/MuskyMakeTicketCharges.AfterActions.php
// ----------------------------------------------------------------------------
// PURPOSE
//   This page is the post-submit "grand central station" for PANDA charge
//   after-actions. Musky opens it in a dedicated window immediately after
//   charge rows are created.
//
// WHAT THIS PAGE DOES
//   1. Reads a session-backed after-actions bundle created by
//      MuskyMakeTicketCharges.php.
//   2. Submits the follow-up Nora errands needed after PANDA charges exist:
//        • Slack notification
//        • IIQ internal ticket note
//        • IIQ resolution action
//        • Nora INV_LOOKUP
//        • Nora IIQUSERSYNC
//   3. Polls those errands and renders a user-friendly progress screen.
//   4. Keeps a visible placeholder for INVENTORYACTION so future work can land
//      here without redesigning the workflow again.
//
// DESIGN NOTES
//   • The page is intentionally self-contained. Both the HTML UI and the JSON
//     API endpoints live here so the after-actions window can function on its
//     own with no extra routing glue.
//   • All errand creation is done server-side through Musky's Nora API helper.
//     That keeps auth, host resolution, and payload formatting consistent.
//   • Session data is used instead of query-string JSON so ticket/charge
//     details do not get sprayed into browser history.
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../../Functions/Inventory.php';
require_once __DIR__ . '/../PANDA/PANDA_Functions.php';
require_once __DIR__ . '/../_tool_guard.php';

date_default_timezone_set('America/New_York');

$pandaAfterActionsApi = $_GET['api'] ?? $_POST['api'] ?? null;
panda_require_charges_enabled($pandaAfterActionsApi !== null ? 'json' : 'html');

// -----------------------------------------------------------------------------
// USER PREFS + ACCESS
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'] ?? 'light';
$email   = $prefs['email'] ?? 'MuskyAfterActions';

$tool_required_variants = [
    'PANDA_CASHIER',
    'PANDA_ADMIN',
    'PANDA_MANAGER'
];

$allowed = musky_require_general_admin_access(
    $prefs['allowed_tools'] ?? [],
    $tool_required_variants,
    [
        'response' => 'html',
        'status' => 403,
        'message' => '⛔ Access Denied — Missing Required Tool Variant.',
    ]
);

// -----------------------------------------------------------------------------
// BASIC REQUEST STATE
// -----------------------------------------------------------------------------
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$storeKey = 'musky_make_ticket_charge_after_actions';

// -----------------------------------------------------------------------------
// HELPER: LOAD / SAVE SESSION BUNDLE
// -----------------------------------------------------------------------------
function v2_after_actions_load_bundle(string $storeKey, string $token): ?array {
    if ($token === '') {
        return null;
    }

    $store = $_SESSION[$storeKey] ?? null;
    if (!is_array($store)) {
        return null;
    }

    $bundle = $store[$token] ?? null;
    return is_array($bundle) ? $bundle : null;
}

function v2_after_actions_save_bundle(string $storeKey, string $token, array $bundle): void {
    if (!isset($_SESSION[$storeKey]) || !is_array($_SESSION[$storeKey])) {
        $_SESSION[$storeKey] = [];
    }

    $_SESSION[$storeKey][$token] = $bundle;
}

// -----------------------------------------------------------------------------
// HELPER: SMALL FORMATTERS
// -----------------------------------------------------------------------------
function v2_after_actions_money(float $amount): string {
    return '$' . number_format($amount, 2);
}

function v2_after_actions_possible_total(array $bundle): float {
    return (float)($bundle['possible_total'] ?? 0);
}

function v2_after_actions_ticket_number(array $bundle): string {
    $ticketNumber = trim((string)($bundle['ticket_number'] ?? ''));
    if ($ticketNumber !== '') {
        return $ticketNumber;
    }

    $lookupInput = trim((string)($bundle['ticket_lookup_input'] ?? ''));
    if ($lookupInput !== '') {
        return $lookupInput;
    }

    return trim((string)($bundle['ticket_id'] ?? ''));
}

function v2_after_actions_ticket_guid(array $bundle): string {
    $ticketGuid = trim((string)($bundle['ticket_guid'] ?? ''));
    if ($ticketGuid !== '') {
        return $ticketGuid;
    }

    return trim((string)($bundle['ticket_id'] ?? ''));
}

function v2_after_actions_inventory_locations(): array {
    return [
        'GHS_HDK' => 'GHS HDK',
        'GMS_HDK' => 'GMS HDK',
    ];
}

// -----------------------------------------------------------------------------
// HELPER: DETERMINE WHICH CREATED CHARGES SHOULD HIT STOCK
// -----------------------------------------------------------------------------
// Business rule from the user:
//   • If PartCode starts with AGI- => do NOT subtract local stock here.
//   • Everything else should count against site inventory the same way,
//     regardless of whether the charge reason was vandalism, refurb, etc.
//
// We aggregate by PartID so the final inventory action can subtract one clean
// quantity per part instead of spraying multiple tiny transactions.
// -----------------------------------------------------------------------------
function v2_after_actions_inventory_candidates(array $bundle): array {
    $grouped = [];

    foreach (($bundle['charges'] ?? []) as $charge) {
        $partId = (int)($charge['part_id'] ?? 0);
        $partCode = trim((string)($charge['part_code'] ?? ''));
        $partDescription = trim((string)($charge['part_description'] ?? 'Unknown Part'));

        if ($partId <= 0 || $partCode === '') {
            continue;
        }

        if (stripos($partCode, 'AGI-') === 0) {
            continue;
        }

        if (!isset($grouped[$partId])) {
            $grouped[$partId] = [
                'part_id'           => $partId,
                'part_code'         => $partCode,
                'part_description'  => $partDescription,
                'quantity'          => 0,
            ];
        }

        $grouped[$partId]['quantity']++;
    }

    return array_values($grouped);
}

function v2_after_actions_inventory_note(array $bundle, string $locationLabel): string {
    return sprintf(
        '[PANDA Stock Pull] Ticket #%s, asset %s, stock pulled from %s.',
        v2_after_actions_ticket_number($bundle) ?: 'UNKNOWN',
        (string)($bundle['asset_tag'] ?? 'UNKNOWN'),
        $locationLabel
    );
}

// -----------------------------------------------------------------------------
// HELPER: APPLY INVENTORYACTION
// -----------------------------------------------------------------------------
// This is the "real" final after-action. The page waits for the user to choose
// which physical site supplied the parts, unless they explicitly check override.
// When inventory is applied, we subtract quantity from that site using the same
// shared inventory engine the Inventory dashboard already trusts.
// -----------------------------------------------------------------------------
function v2_after_actions_apply_inventory_action(
    array $bundle,
    string $siteCode,
    bool $overrideStock,
    string $actorEmail
): array {
    $locations = v2_after_actions_inventory_locations();
    $inventoryItems = v2_after_actions_inventory_candidates($bundle);

    if (!isset($bundle['after_actions']['steps']['inventory_action'])) {
        $bundle['after_actions']['steps']['inventory_action'] = [];
    }

    if (!$inventoryItems) {
        $bundle['after_actions']['steps']['inventory_action'] = [
            'kind'   => 'local_action',
            'label'  => 'Inventory Action',
            'detail' => 'No stock-tracked parts were used, so no site inventory change was needed.',
            'status' => 'complete',
            'items'  => [],
        ];
        return $bundle;
    }

    if (!$overrideStock && !isset($locations[$siteCode])) {
        throw new InvalidArgumentException('A valid inventory site is required unless override is checked.');
    }

    if ($overrideStock) {
        $bundle['after_actions']['steps']['inventory_action'] = [
            'kind'          => 'local_action',
            'label'         => 'Inventory Action',
            'detail'        => 'Stock subtraction was intentionally overridden by the user.',
            'status'        => 'complete',
            'items'         => $inventoryItems,
            'override_used' => true,
        ];
        return $bundle;
    }

    $pdo = nora_connect();
    $locationLabel = $locations[$siteCode];
    $results = [];

    foreach ($inventoryItems as $item) {
        $results[] = inventory_adjust(
            $pdo,
            $siteCode,
            (int)$item['part_id'],
            -1 * (int)$item['quantity'],
            null,
            'USE',
            v2_after_actions_inventory_note($bundle, $locationLabel),
            $actorEmail,
            true
        );
    }

    $bundle['after_actions']['steps']['inventory_action'] = [
        'kind'            => 'local_action',
        'label'           => 'Inventory Action',
        'detail'          => 'Subtracted used stock from ' . $locationLabel . '.',
        'status'          => 'complete',
        'items'           => $inventoryItems,
        'site_code'       => $siteCode,
        'site_label'      => $locationLabel,
        'inventory_results'=> $results,
    ];

    return $bundle;
}

// -----------------------------------------------------------------------------
// HELPER: BUILD HUMAN-READABLE SUMMARIES
// -----------------------------------------------------------------------------
function v2_after_actions_part_lines(array $bundle): array {
    $lines = [];

    foreach (($bundle['charges'] ?? []) as $charge) {
        $lines[] = sprintf(
            '- %s — %s | Reason: %s | Possible Charge: %s',
            (string)($charge['part_code'] ?? 'UNKNOWN'),
            (string)($charge['part_description'] ?? 'Unknown Part'),
            (string)($charge['reason'] ?? 'Other'),
            v2_after_actions_money((float)($charge['possible_amount'] ?? 0))
        );
    }

    return $lines;
}

function v2_after_actions_build_ticket_note(array $bundle): string {
    $displayTicket = v2_after_actions_ticket_number($bundle);
    $lines = [];
    $lines[] = 'PANDA charge request created in Musky.';
    $lines[] = 'Ticket: ' . ($displayTicket !== '' ? $displayTicket : 'UNKNOWN');
    $lines[] = 'Device: Asset ' . (string)($bundle['asset_tag'] ?? 'UNKNOWN')
        . ' / Serial ' . (string)($bundle['device_serial'] ?? 'UNKNOWN');
    $lines[] = 'Owner: ' . (string)($bundle['owner_name'] ?? 'Unknown Owner')
        . ' <' . (string)($bundle['owner_email'] ?? 'unknown@example.invalid') . '>';
    $lines[] = 'Submitted By: ' . (string)($bundle['submitter'] ?? 'Musky');
    $lines[] = '';
    $lines[] = 'Parts entered into PANDA:';

    foreach (v2_after_actions_part_lines($bundle) as $partLine) {
        $lines[] = $partLine;
    }

    $lines[] = '';
    $lines[] = 'Possible total currently entered: '
        . v2_after_actions_money(v2_after_actions_possible_total($bundle));
    $lines[] = 'All charges are pending further review for discounts and Tech Insurance coverage.';

    return implode("\n", $lines);
}

function v2_after_actions_build_slack_message(array $bundle): string {
    $displayTicket = v2_after_actions_ticket_number($bundle);
    $partCodes = [];
    foreach (($bundle['charges'] ?? []) as $charge) {
        $code = trim((string)($charge['part_code'] ?? ''));
        if ($code !== '') {
            $partCodes[] = $code;
        }
    }

    if (!$partCodes) {
        $partCodes[] = 'No Parts Listed';
    }

    return implode("\n", [
        ':receipt: *PANDA charge request created*',
        'Ticket: `' . ($displayTicket !== '' ? $displayTicket : 'UNKNOWN') . '`',
        'Asset: `' . (string)($bundle['asset_tag'] ?? 'UNKNOWN') . '`',
        'Owner: ' . (string)($bundle['owner_name'] ?? 'Unknown Owner'),
        'Possible Total: *' . v2_after_actions_money(v2_after_actions_possible_total($bundle)) . '*',
        'Parts: ' . implode(', ', $partCodes),
        '_Final charges remain pending discounts and Tech Insurance review._',
    ]);
}

// -----------------------------------------------------------------------------
// HELPER: SUBMIT ONE ERRAND THROUGH MUSKY'S NORA API
// -----------------------------------------------------------------------------
function v2_after_actions_submit_errand(string $label, string $detail, array $payload): array {
    $response = musky_nora_api_post_json('/errand/create', $payload);

    $errandId = 0;
    if (is_array($response)) {
        if (!empty($response['errand_id'])) {
            $errandId = (int)$response['errand_id'];
        } elseif (!empty($response['ErrandID'])) {
            $errandId = (int)$response['ErrandID'];
        }
    }

    if ($errandId > 0) {
        return [
            'kind'            => 'errand',
            'label'           => $label,
            'detail'          => $detail,
            'status'          => 'submitted',
            'errand_id'       => $errandId,
            'submit_response' => $response,
        ];
    }

    return [
        'kind'            => 'errand',
        'label'           => $label,
        'detail'          => 'Failed to submit errand.',
        'status'          => 'failed',
        'errand_id'       => null,
        'submit_response' => $response,
    ];
}

// -----------------------------------------------------------------------------
// HELPER: START ALL AFTER-ACTIONS ONCE
// -----------------------------------------------------------------------------
function v2_after_actions_start(array $bundle): array {
    // If we already created the errand steps once, return the bundle as-is.
    if (!empty($bundle['after_actions']['steps']) && is_array($bundle['after_actions']['steps'])) {
        return $bundle;
    }

    $submitter = trim((string)($bundle['submitter'] ?? 'MuskyAfterActions'));
    if ($submitter === '') {
        $submitter = 'MuskyAfterActions';
    }

    $ownerEmail = trim((string)($bundle['owner_email'] ?? ''));
    // The after-actions need two ticket values:
    //   1. the readable ticket number for UI copy and notes
    //   2. the real IIQ TicketId GUID for Nora activity endpoints
    $ticketGuid = v2_after_actions_ticket_guid($bundle);
    $deviceSerial = trim((string)($bundle['device_serial'] ?? ''));
    $possibleTotal = number_format(v2_after_actions_possible_total($bundle), 2, '.', '');
    $ticketNoteText = v2_after_actions_build_ticket_note($bundle);
    $slackMessage = v2_after_actions_build_slack_message($bundle);

    $steps = [];

    // This step is informational only. The actual PANDA charge rows already
    // exist before the after-actions window opens.
    $steps['charge_records'] = [
        'kind'   => 'local',
        'label'  => 'Charge Records Created',
        'detail' => 'PANDA charges have already been written successfully.',
        'status' => 'complete',
    ];

    $steps['slack_notice'] = v2_after_actions_submit_errand(
        'Slack Notification',
        'Tell the team that a PANDA charge request was created.',
        [
            'serial'    => 'N/A',
            'udid'      => 'SYSTEM_TASK',
            'submitter' => $submitter,
            'owner'     => $ownerEmail !== '' ? $ownerEmail : null,
            'priority'  => 5,
            'slack'     => 'TRUE',
            'extra1'    => json_encode(
                ['custom_message' => $slackMessage],
                JSON_UNESCAPED_SLASHES
            ),
        ]
    );

    $steps['ticket_note'] = v2_after_actions_submit_errand(
        'IIQ Ticket Note',
        'Append the PANDA summary note to the IncidentIQ ticket.',
        [
            'serial'    => 'N/A',
            'udid'      => 'SYSTEM_TASK',
            'submitter' => $submitter,
            'owner'     => $ownerEmail !== '' ? $ownerEmail : null,
            'priority'  => 5,
            'iiq'       => 'TRUE',
            'extra1'    => $ticketNoteText,
            'extra2'    => $ticketGuid,
            'extra5'    => 'ADDNOTE-MUSKYCHARGES',
        ]
    );

    $steps['resolution_action'] = v2_after_actions_submit_errand(
        'IIQ Resolution Action',
        'Post the PANDA resolution activity to the IncidentIQ ticket.',
        [
            'serial'    => 'N/A',
            'udid'      => 'SYSTEM_TASK',
            'submitter' => $submitter,
            'owner'     => $ownerEmail !== '' ? $ownerEmail : null,
            'priority'  => 5,
            'iiq'       => 'TRUE',
            'extra2'    => $ticketGuid,
            'extra3'    => $possibleTotal,
            'extra5'    => 'RESOLUTE-MUSKYCHARGES',
        ]
    );

    if ($deviceSerial !== '') {
        $steps['inventory_lookup'] = v2_after_actions_submit_errand(
            'Inventory Lookup',
            'Tell Nora to refresh device inventory data for this serial.',
            [
                'serial'    => $deviceSerial,
                'udid'      => 'SYSTEM_TASK',
                'submitter' => $submitter,
                'owner'     => $ownerEmail !== '' ? $ownerEmail : null,
                'priority'  => 5,
                'nora'      => 'TRUE',
                'extra1'    => 'INV_LOOKUP',
                'extra2'    => $deviceSerial,
            ]
        );
    } else {
        $steps['inventory_lookup'] = [
            'kind'   => 'local',
            'label'  => 'Inventory Lookup',
            'detail' => 'Skipped because no device serial was available.',
            'status' => 'failed',
        ];
    }

    if ($ownerEmail !== '') {
        $steps['user_sync'] = v2_after_actions_submit_errand(
            'Owner IIQ Sync',
            'Ask Nora to refresh the owner District ID from IncidentIQ.',
            [
                'serial'    => 'N/A',
                'udid'      => 'SYSTEM_TASK',
                'submitter' => $submitter,
                'owner'     => $ownerEmail,
                'priority'  => 5,
                'nora'      => 'TRUE',
                'extra1'    => 'IIQUSERSYNC',
                'extra2'    => $ownerEmail,
            ]
        );
    } else {
        $steps['user_sync'] = [
            'kind'   => 'local',
            'label'  => 'Owner IIQ Sync',
            'detail' => 'Skipped because the owner email was blank.',
            'status' => 'failed',
        ];
    }

    // InventoryAction is intentionally held for the user here. The Nora errands
    // can run immediately, but we stop short of declaring the whole workflow
    // done until the user either chooses which site stock should be consumed
    // from, or checks the explicit override box.
    $inventoryItems = v2_after_actions_inventory_candidates($bundle);
    if ($inventoryItems) {
        $steps['inventory_action'] = [
            'kind'      => 'local_action',
            'label'     => 'Inventory Action',
            'detail'    => 'Select which site supplied these parts, or explicitly override stock subtraction.',
            'status'    => 'needs_input',
            'items'     => $inventoryItems,
            'locations' => v2_after_actions_inventory_locations(),
        ];
    } else {
        $steps['inventory_action'] = [
            'kind'   => 'local_action',
            'label'  => 'Inventory Action',
            'detail' => 'No stock-tracked parts were used, so no inventory subtraction is needed.',
            'status' => 'complete',
            'items'  => [],
        ];
    }

    $bundle['after_actions'] = [
        'started_at'    => date('c'),
        'ticket_note'   => $ticketNoteText,
        'slack_message' => $slackMessage,
        'steps'         => $steps,
    ];

    return $bundle;
}

// -----------------------------------------------------------------------------
// HELPER: POLL ALL LIVE ERRAND STATUSES
// -----------------------------------------------------------------------------
function v2_after_actions_collect_state(array $bundle): array {
    $steps = $bundle['after_actions']['steps'] ?? [];
    $orderedKeys = [
        'charge_records',
        'slack_notice',
        'ticket_note',
        'resolution_action',
        'inventory_lookup',
        'user_sync',
        'inventory_action',
    ];

    $completeCount = 0;
    $runningCount = 0;
    $failedCount = 0;
    $totalCount = 0;
    $allFinished = true;

    foreach ($orderedKeys as $key) {
        if (!isset($steps[$key]) || !is_array($steps[$key])) {
            continue;
        }

        $step = $steps[$key];
        $totalCount++;

        if (($step['kind'] ?? '') === 'errand' && !empty($step['errand_id'])) {
            // Ask Nora for the verbose status payload here. The lightweight status
            // route only gives us the headline fields, but the verbose payload is
            // where Nora sub-handlers tend to leave the most useful human-readable
            // "what happened" notes in fields like ExtraDataField04.
            $resp = musky_nora_api_get_json(
                '/errand/status/' . (int)$step['errand_id'],
                ['verbose' => 1]
            );
            if (is_array($resp) && !empty($resp['Status'])) {
                $step['status'] = strtolower(trim((string)$resp['Status']));
            } elseif (empty($step['status'])) {
                $step['status'] = 'unknown';
            }

            // Different Nora handlers write their "best explanation" into
            // different fields. We normalize that here so the browser can simply
            // display one live detail string without caring which handler
            // produced it.
            if (is_array($resp)) {
                $liveNotes = '';

                foreach (['Notes', 'ExtraDataField04', 'ExtraDataField06'] as $noteField) {
                    $candidate = trim((string)($resp[$noteField] ?? ''));
                    if ($candidate !== '') {
                        $liveNotes = $candidate;
                        break;
                    }
                }

                if ($liveNotes !== '') {
                    $step['live_notes'] = $liveNotes;
                }

                if (!empty($resp['CompleteDateTime'])) {
                    $step['completed_at'] = (string)$resp['CompleteDateTime'];
                }
            }

            $steps[$key] = $step;
        }

        $status = strtolower(trim((string)($step['status'] ?? 'unknown')));
        if (in_array($status, ['complete', 'waiting_details'], true)) {
            $completeCount++;
        } elseif (in_array($status, ['failed', 'cancelled', 'rejected'], true)) {
            $failedCount++;
        } elseif (in_array($status, ['processing', 'submitted', 'queued', 'unknown', 'needs_input', 'applying'], true)) {
            $runningCount++;
            $allFinished = false;
        } else {
            $allFinished = false;
        }
    }

    $bundle['after_actions']['steps'] = $steps;

    return [
        'bundle'         => $bundle,
        'steps'          => $steps,
        'ordered_keys'   => $orderedKeys,
        'complete_count' => $completeCount,
        'running_count'  => $runningCount,
        'failed_count'   => $failedCount,
        'total_count'    => $totalCount,
        'all_finished'   => $allFinished && $runningCount === 0,
    ];
}

// -----------------------------------------------------------------------------
// JSON API
// -----------------------------------------------------------------------------
$apiAction = $_GET['api'] ?? $_POST['api'] ?? null;
if ($apiAction !== null) {
    header('Content-Type: application/json');

    $bundle = v2_after_actions_load_bundle($storeKey, $token);
    if (!$bundle) {
        http_response_code(404);
        echo json_encode(['error' => 'after_actions_bundle_not_found'], JSON_PRETTY_PRINT);
        exit;
    }

    if ($apiAction === 'status') {
        if (isset($_GET['start']) && $_GET['start'] == '1') {
            $bundle = v2_after_actions_start($bundle);
        }

        $state = v2_after_actions_collect_state($bundle);
        v2_after_actions_save_bundle($storeKey, $token, $state['bundle']);

        unset($state['bundle']);
        echo json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    if ($apiAction === 'inventory_action') {
        try {
            $siteCode = trim((string)($_POST['site_code'] ?? ''));
            $overrideStock = !empty($_POST['override_stock']) && $_POST['override_stock'] === '1';

            $bundle = v2_after_actions_start($bundle);

            // Mark the step as applying right away so the UI reflects that the
            // click actually did something, even before the stock adjustments
            // finish and the next poll repaints the window.
            $bundle['after_actions']['steps']['inventory_action']['status'] = 'applying';
            $bundle['after_actions']['steps']['inventory_action']['detail'] = 'Applying inventory change now...';
            v2_after_actions_save_bundle($storeKey, $token, $bundle);

            $bundle = v2_after_actions_apply_inventory_action($bundle, $siteCode, $overrideStock, $email);
            $state = v2_after_actions_collect_state($bundle);
            v2_after_actions_save_bundle($storeKey, $token, $state['bundle']);

            unset($state['bundle']);
            echo json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        } catch (Throwable $e) {
            error_log('[MuskyMakeTicketCharges.AfterActions] inventory action failed: ' . $e->getMessage());
            $bundle['after_actions']['steps']['inventory_action'] = [
                'kind'      => 'local_action',
                'label'     => 'Inventory Action',
                'detail'    => 'Inventory action failed. Check the server logs for details.',
                'status'    => 'needs_input',
                'items'     => v2_after_actions_inventory_candidates($bundle),
                'locations' => v2_after_actions_inventory_locations(),
            ];
            v2_after_actions_save_bundle($storeKey, $token, $bundle);

            http_response_code(400);
            echo json_encode([
                'error'   => 'inventory_action_failed',
                'message' => 'Inventory action failed.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'unsupported_api_action'], JSON_PRETTY_PRINT);
    exit;
}

$bundle = v2_after_actions_load_bundle($storeKey, $token);
if (!$bundle) {
    http_response_code(404);
    echo "Missing or expired after-actions bundle.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA After Actions</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
body {
    min-height: 100vh;
}

body.light-mode {
    --aa-panel-bg: #ffffff;
    --aa-panel-border: #d8dee8;
    --aa-text: #1f2937;
    --aa-muted: #6b7280;
    --aa-accent: #2563eb;
    --aa-accent-soft: #dbeafe;
    --aa-success: #16a34a;
    --aa-success-soft: #dcfce7;
    --aa-warning: #d97706;
    --aa-warning-soft: #ffedd5;
    --aa-danger: #dc2626;
    --aa-danger-soft: #fee2e2;
}

body.dark-mode {
    --aa-panel-bg: #1f2937;
    --aa-panel-border: #374151;
    --aa-text: #f3f4f6;
    --aa-muted: #9ca3af;
    --aa-accent: #60a5fa;
    --aa-accent-soft: rgba(96, 165, 250, 0.14);
    --aa-success: #4ade80;
    --aa-success-soft: rgba(74, 222, 128, 0.12);
    --aa-warning: #f59e0b;
    --aa-warning-soft: rgba(245, 158, 11, 0.14);
    --aa-danger: #f87171;
    --aa-danger-soft: rgba(248, 113, 113, 0.14);
}

body.musky-mode {
    --aa-panel-bg: #143122;
    --aa-panel-border: #2f5c46;
    --aa-text: #edf8ef;
    --aa-muted: #b9d3bf;
    --aa-accent: #ff8800;
    --aa-accent-soft: rgba(255, 136, 0, 0.13);
    --aa-success: #7ee787;
    --aa-success-soft: rgba(126, 231, 135, 0.12);
    --aa-warning: #ffb454;
    --aa-warning-soft: rgba(255, 180, 84, 0.12);
    --aa-danger: #ff8a80;
    --aa-danger-soft: rgba(255, 138, 128, 0.14);
}

body.gator-time-mode {
    --aa-panel-bg: #111111;
    --aa-panel-border: #3a3521;
    --aa-text: #f1e6a7;
    --aa-muted: #bcae72;
    --aa-accent: #c5b358;
    --aa-accent-soft: rgba(197, 179, 88, 0.13);
    --aa-success: #d7d05f;
    --aa-success-soft: rgba(215, 208, 95, 0.10);
    --aa-warning: #d9a441;
    --aa-warning-soft: rgba(217, 164, 65, 0.12);
    --aa-danger: #ff7b72;
    --aa-danger-soft: rgba(255, 123, 114, 0.14);
}

.page-shell {
    width: min(700px, 94vw);
    margin: 0 auto;
    padding: 18px 0 28px;
    color: var(--aa-text);
}

.hero {
    margin-bottom: 14px;
}

.hero-copy {
    background: var(--aa-panel-bg);
    border: 1px solid var(--aa-panel-border);
    border-radius: 16px;
    padding: 16px 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
}

.hero-copy h1 {
    margin: 0 0 4px;
    font-size: 1.25rem;
    line-height: 1.1;
}

.hero-copy p {
    margin: 0;
    color: var(--aa-muted);
    font-size: 0.92rem;
}

.hero-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.hero-badge {
    padding: 6px 10px;
    border-radius: 999px;
    background: var(--aa-accent-soft);
    border: 1px solid var(--aa-panel-border);
    color: var(--aa-text);
    font-size: 0.82rem;
}

.hero-visual {
    display: none;
}

.layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
}

.panel {
    background: var(--aa-panel-bg);
    border: 1px solid var(--aa-panel-border);
    border-radius: 16px;
    padding: 16px 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
}

.panel h2 {
    margin: 0 0 10px;
    font-size: 0.98rem;
}

.panel.debug-only {
    display: none;
}

body.debug-mode .panel.debug-only {
    display: block;
}

body.debug-mode .hero {
    grid-template-columns: 1.05fr .95fr;
    gap: 24px;
}

body.debug-mode .layout {
    grid-template-columns: 1.15fr .85fr;
    gap: 24px;
}

body.debug-mode .hero-visual {
    display: block;
}

body.debug-mode .page-shell {
    width: min(1080px, 94vw);
}

.utility-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.page-kicker {
    font-size: 0.8rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--aa-muted);
}

.debug-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 11px;
    border-radius: 999px;
    background: transparent;
    border: 1px solid var(--aa-panel-border);
    font-size: 0.84rem;
    color: var(--aa-text);
}

.debug-toggle input {
    margin: 0;
}

.compact-summary {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
}

body:not(.debug-mode) .hero-badges,
body:not(.debug-mode) .compact-summary {
    display: none;
}

.compact-box {
    border-radius: 12px;
    padding: 12px 13px;
    background: transparent;
    border: 1px solid var(--aa-panel-border);
}

.compact-box .label {
    font-size: 0.76rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--aa-muted);
    margin-bottom: 4px;
}

.compact-box .value {
    font-size: 0.95rem;
    color: var(--aa-text);
}

.status-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}

body:not(.debug-mode) .status-strip {
    display: none;
}

.workflow-headline {
    margin: 0 0 16px;
    padding: 12px 14px;
    border-radius: 12px;
    background: transparent;
    border: 1px solid var(--aa-panel-border);
    color: var(--aa-text);
}

.workflow-headline strong {
    display: block;
    font-size: 1rem;
    margin-bottom: 4px;
}

.workflow-headline.is-running {
    border-color: var(--aa-accent);
    background: var(--aa-accent-soft);
}

.workflow-headline.is-complete {
    border-color: var(--aa-success);
    background: var(--aa-success-soft);
}

.workflow-headline.is-failed {
    border-color: var(--aa-danger);
    background: var(--aa-danger-soft);
}

.status-pill {
    min-width: 110px;
    padding: 8px 10px;
    border-radius: 12px;
    background: transparent;
    border: 1px solid var(--aa-panel-border);
}

.status-pill strong {
    display: block;
    font-size: 1rem;
}

.steps-list {
    display: grid;
    gap: 8px;
}

.step-card {
    border-radius: 12px;
    padding: 10px 12px;
    background: transparent;
    border: 1px solid var(--aa-panel-border);
}

body:not(.debug-mode) .step-card {
    padding: 12px 14px;
}

.step-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 0;
}

.step-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
}

.step-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex: 0 0 10px;
    background: var(--aa-muted);
}

.step-dot.is-submitted,
.step-dot.is-queued,
.step-dot.is-needs_input {
    background: var(--aa-warning);
}

.step-dot.is-processing,
.step-dot.is-applying {
    background: var(--aa-accent);
}

.step-dot.is-complete,
.step-dot.is-waiting_details {
    background: var(--aa-success);
}

.step-dot.is-failed,
.step-dot.is-rejected,
.step-dot.is-cancelled,
.step-dot.is-unknown {
    background: var(--aa-danger);
}

.step-status {
    display: none;
}

.step-detail {
    display: none;
}

.step-meta {
    display: none;
}

body.debug-mode .step-status,
body.debug-mode .step-detail,
body.debug-mode .step-meta {
    display: block;
}

body.debug-mode .step-head {
    margin-bottom: 8px;
}

body.debug-mode .step-detail {
    color: var(--aa-text);
    font-size: 0.95rem;
}

body.debug-mode .step-meta {
    margin-top: 8px;
    font-size: 0.82rem;
    color: var(--aa-muted);
}

.inventory-action-box {
    margin: 0 0 12px;
    padding: 12px 13px;
    border: 1px solid var(--aa-panel-border);
    border-radius: 12px;
    background: transparent;
}

body:not(.debug-mode) .inventory-action-box {
    margin-bottom: 16px;
    background: var(--aa-accent-soft);
}

.inventory-action-box strong {
    display: block;
    margin-bottom: 6px;
}

.inventory-action-box.is-complete {
    background: var(--aa-success-soft);
    border-color: var(--aa-success);
}

.inventory-action-box.is-waiting {
    background: var(--aa-warning-soft);
    border-color: var(--aa-warning);
}

.inventory-action-box.is-failed {
    background: var(--aa-danger-soft);
    border-color: var(--aa-danger);
}

.inventory-item-list {
    margin: 0 0 12px 18px;
    color: var(--aa-text);
}

.inventory-radio-row {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 12px;
}

.inventory-radio-row label,
.inventory-check label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.inventory-check {
    margin-bottom: 12px;
    color: var(--aa-text);
}

.inventory-note {
    font-size: 0.84rem;
    color: var(--aa-muted);
    margin-bottom: 12px;
}

.inventory-submit-row {
    display: flex;
    align-items: center;
    gap: 12px;
}

.inventory-submit-btn {
    border: 0;
    border-radius: 999px;
    padding: 10px 16px;
    background: var(--aa-accent);
    color: #ffffff;
    font-weight: 700;
    cursor: pointer;
}

.inventory-submit-btn[disabled] {
    opacity: 0.65;
    cursor: wait;
}

.summary-list {
    margin: 0;
    padding-left: 18px;
    color: var(--aa-text);
}

.summary-grid {
    display: grid;
    gap: 12px;
}

.summary-box {
    border-radius: 14px;
    padding: 13px 14px;
    background: transparent;
    border: 1px solid var(--aa-panel-border);
}

.summary-box pre {
    margin: 0;
    white-space: pre-wrap;
    font-family: "Courier New", monospace;
    font-size: 0.85rem;
    color: var(--aa-text);
}

.subtle {
    color: var(--aa-muted);
}

@media (max-width: 960px) {
    .hero,
    .layout,
    body.debug-mode .hero,
    body.debug-mode .layout {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
<div class="page-shell">
  <section class="hero">
    <div class="hero-copy">
      <div class="utility-row">
        <div class="page-kicker">PANDA Follow-Up</div>
        <label class="debug-toggle">
          <input type="checkbox" id="debugViewToggle">
          Debug View
        </label>
      </div>
      <h1>PANDA After Actions</h1>
      <p>
        Charges are already created. Musky is finishing the follow-up work.
      </p>
      <div class="hero-badges">
        <div class="hero-badge">Ticket <?= htmlspecialchars(v2_after_actions_ticket_number($bundle) ?: 'UNKNOWN') ?></div>
        <div class="hero-badge">Asset <?= htmlspecialchars((string)($bundle['asset_tag'] ?? 'UNKNOWN')) ?></div>
        <div class="hero-badge">Possible Total <?= htmlspecialchars(v2_after_actions_money(v2_after_actions_possible_total($bundle))) ?></div>
      </div>
      <div class="compact-summary">
        <div class="compact-box">
          <div class="label">Owner</div>
          <div class="value"><?= htmlspecialchars((string)($bundle['owner_name'] ?? 'Unknown Owner')) ?></div>
        </div>
        <div class="compact-box">
          <div class="label">Device</div>
          <div class="value"><?= htmlspecialchars((string)($bundle['device_serial'] ?? 'UNKNOWN')) ?></div>
        </div>
        <div class="compact-box">
          <div class="label">Inventory</div>
          <div class="value">
            <?php $inventoryCandidates = v2_after_actions_inventory_candidates($bundle); ?>
            <?= $inventoryCandidates ? count($inventoryCandidates) . ' tracked part type(s)' : 'No stock change needed' ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="layout">
    <div class="panel">
      <h2>After Actions</h2>
      <div class="workflow-headline is-running" id="workflowHeadline">
        <strong>Starting after-actions...</strong>
        Musky is submitting the first follow-up tasks now.
      </div>
      <div id="inventoryActionMount"></div>
      <div class="status-strip" id="statusStrip">
        <div class="status-pill"><span class="subtle">Starting</span><strong>...</strong></div>
      </div>
      <div class="steps-list" id="stepsList"></div>
    </div>

    <div class="panel debug-only">
      <h2>Charge Summary</h2>

      <div class="summary-grid">
        <div class="summary-box">
          <div class="subtle">Ticket</div>
          <div><strong>#<?= htmlspecialchars(v2_after_actions_ticket_number($bundle) ?: 'UNKNOWN') ?></strong></div>
          <div class="subtle">IIQ GUID: <?= htmlspecialchars(v2_after_actions_ticket_guid($bundle) ?: 'UNKNOWN') ?></div>
        </div>

        <div class="summary-box">
          <div class="subtle">Device</div>
          <div><strong><?= htmlspecialchars((string)($bundle['asset_tag'] ?? 'UNKNOWN')) ?></strong> / <?= htmlspecialchars((string)($bundle['device_serial'] ?? 'UNKNOWN')) ?></div>
          <div class="subtle"><?= htmlspecialchars((string)($bundle['device_model'] ?? 'Unknown Model')) ?></div>
        </div>

        <div class="summary-box">
          <div class="subtle">Owner</div>
          <div><strong><?= htmlspecialchars((string)($bundle['owner_name'] ?? 'Unknown Owner')) ?></strong></div>
          <div><?= htmlspecialchars((string)($bundle['owner_email'] ?? 'unknown@example.invalid')) ?></div>
        </div>

        <div class="summary-box">
          <div class="subtle">Parts Entered</div>
          <ul class="summary-list">
            <?php foreach (v2_after_actions_part_lines($bundle) as $partLine): ?>
              <li><?= htmlspecialchars($partLine) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="summary-box">
          <div class="subtle">IIQ Ticket Note Preview</div>
          <pre><?= htmlspecialchars(v2_after_actions_build_ticket_note($bundle)) ?></pre>
        </div>

        <div class="summary-box">
          <div class="subtle">Slack Message Preview</div>
          <pre><?= htmlspecialchars(v2_after_actions_build_slack_message($bundle)) ?></pre>
        </div>

        <div class="summary-box">
          <div class="subtle">Inventory-Tracked Parts</div>
          <?php if ($inventoryCandidates): ?>
            <ul class="summary-list">
              <?php foreach ($inventoryCandidates as $inventoryRow): ?>
                <li>
                  <?= htmlspecialchars((string)$inventoryRow['part_code']) ?>
                  × <?= (int)$inventoryRow['quantity'] ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div>No non-AGI stock subtraction needed.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
// ---------------------------------------------------------------------------
// CLIENT POLLING
// ---------------------------------------------------------------------------
// This script keeps the after-actions window alive and informative. The server
// does the real work; the browser simply requests updated state and paints it.
// ---------------------------------------------------------------------------
(function() {
  const token = <?= json_encode($token) ?>;
  const debugToggle = document.getElementById('debugViewToggle');
  const workflowHeadline = document.getElementById('workflowHeadline');
  const statusStrip = document.getElementById('statusStrip');
  const stepsList = document.getElementById('stepsList');
  const inventoryActionMount = document.getElementById('inventoryActionMount');
  const inventoryDraft = {
    siteCode: '',
    overrideStock: false
  };
  const debugStorageKey = 'musky_after_actions_debug_view';

  const orderedKeys = [
    'charge_records',
    'slack_notice',
    'ticket_note',
    'resolution_action',
    'inventory_lookup',
    'user_sync',
    'inventory_action'
  ];

  function applyDebugMode(enabled) {
    document.body.classList.toggle('debug-mode', !!enabled);
    if (debugToggle) {
      debugToggle.checked = !!enabled;
    }
  }

  try {
    applyDebugMode(window.localStorage.getItem(debugStorageKey) === '1');
  } catch (e) {
    applyDebugMode(false);
  }

  if (debugToggle) {
    debugToggle.addEventListener('change', function() {
      const enabled = !!debugToggle.checked;
      applyDebugMode(enabled);
      try {
        window.localStorage.setItem(debugStorageKey, enabled ? '1' : '0');
      } catch (e) {
        // Ignore localStorage failures; the toggle still works for this page load.
      }
    });
  }

  function prettyStatus(raw) {
    const text = String(raw || 'unknown').toLowerCase();
    if (text === 'waiting_details') return 'Waiting Details';
    if (text === 'needs_input') return 'Needs Input';
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  function renderStrip(state) {
    if (!statusStrip) return;

    statusStrip.innerHTML =
      '<div class="status-pill"><span class="subtle">Complete</span><strong>' + state.complete_count + '</strong></div>' +
      '<div class="status-pill"><span class="subtle">Running</span><strong>' + state.running_count + '</strong></div>' +
      '<div class="status-pill"><span class="subtle">Failed</span><strong>' + state.failed_count + '</strong></div>' +
      '<div class="status-pill"><span class="subtle">Total Steps</span><strong>' + state.total_count + '</strong></div>';
  }

  function renderHeadline(state) {
    if (!workflowHeadline) return;

    let headlineClass = 'workflow-headline is-running';
    let title = 'After-actions are running.';
    let detail = 'Musky is still waiting on follow-up work from Nora, IncidentIQ, or Slack.';
    const inventoryStep = state.steps && state.steps.inventory_action ? state.steps.inventory_action : null;

    if (inventoryStep && String(inventoryStep.status || '').toLowerCase() === 'needs_input') {
      detail = 'Everything else can keep moving, but Musky still needs to know which site stock to subtract from for the used parts.';
    } else if (state.running_count > 0) {
      detail = 'Still working through ' + state.running_count + ' active step(s). This window will keep updating automatically.';
    } else if (state.failed_count > 0) {
      headlineClass = 'workflow-headline is-failed';
      title = 'After-actions finished with at least one issue.';
      detail = 'Nothing is hung, but one or more follow-up steps reported a failure. Review the cards below for the exact step and Nora response.';
    } else if (state.all_finished) {
      headlineClass = 'workflow-headline is-complete';
      title = 'All current after-actions are complete.';
      detail = 'Charge creation, Slack, IncidentIQ updates, sync work, and inventory refresh are done for this round.';
    }

    workflowHeadline.className = headlineClass;
    workflowHeadline.innerHTML =
      '<strong>' + title + '</strong>' +
      detail;
  }

  function buildInventoryChoiceMarkup(step) {
    const items = Array.isArray(step.items) ? step.items : [];
    const locations = step.locations || {};

    const itemMarkup = items.map(function(item) {
      return '<li>' +
        String(item.part_code || 'UNKNOWN') +
        ' — ' +
        String(item.part_description || 'Unknown Part') +
        ' × ' +
        String(item.quantity || 0) +
      '</li>';
    }).join('');

    const locationMarkup = Object.keys(locations).map(function(code) {
      const checked = inventoryDraft.siteCode === code ? ' checked' : '';
      return '<label><input type="radio" name="inventory_site" value="' + code + '"' + checked + '> ' + String(locations[code]) + '</label>';
    }).join('');

    return '' +
      '<strong>Inventory Question</strong>' +
      '<div class="inventory-note">Which site should Musky subtract these non-AGI parts from?</div>' +
      '<ul class="inventory-item-list">' + itemMarkup + '</ul>' +
      '<div class="inventory-radio-row">' + locationMarkup + '</div>' +
      '<div class="inventory-check"><label><input type="checkbox" name="override_stock"' + (inventoryDraft.overrideStock ? ' checked' : '') + '> Override: do not subtract stock</label></div>' +
      '<div class="inventory-submit-row">' +
        '<button type="button" class="inventory-submit-btn">Apply Inventory Action</button>' +
        '<span class="subtle">Negative stock is allowed here if needed.</span>' +
      '</div>';
  }

  function renderInventoryAction(state) {
    if (!inventoryActionMount) return;

    const step = state.steps && state.steps.inventory_action ? state.steps.inventory_action : null;
    if (!step) {
      inventoryActionMount.innerHTML = '';
      return;
    }

    const status = String(step.status || 'unknown').toLowerCase();
    const detail = step.live_notes || step.detail || '';
    const safeStatusClass = status.replace(/[^a-z_]/g, '');
    const safeStatusLabel = prettyStatus(status);

    if (status === 'needs_input') {
      inventoryActionMount.innerHTML = '<div class="inventory-action-box is-waiting">' + buildInventoryChoiceMarkup(step) + '</div>';

      const inventoryBox = inventoryActionMount.querySelector('.inventory-action-box');
      const button = inventoryBox ? inventoryBox.querySelector('.inventory-submit-btn') : null;
      const overrideBox = inventoryBox ? inventoryBox.querySelector('input[name="override_stock"]') : null;
      const radioInputs = inventoryBox ? inventoryBox.querySelectorAll('input[name="inventory_site"]') : [];

      Array.from(radioInputs).forEach(function(input) {
        input.addEventListener('change', function() {
          if (input.checked) {
            inventoryDraft.siteCode = input.value;
          }
        });
      });

      if (overrideBox) {
        overrideBox.addEventListener('change', function() {
          inventoryDraft.overrideStock = !!overrideBox.checked;
        });
      }

      if (button) {
        button.addEventListener('click', function() {
          let siteCode = '';
          Array.from(radioInputs).forEach(function(input) {
            if (input.checked) {
              siteCode = input.value;
            }
          });

          const overrideStock = !!(overrideBox && overrideBox.checked);
          inventoryDraft.siteCode = siteCode;
          inventoryDraft.overrideStock = overrideStock;

          if (!overrideStock && !siteCode) {
            window.alert('Choose GHS or GMS, or check the override box.');
            return;
          }

          submitInventoryAction(siteCode, overrideStock, button);
        });
      }

      return;
    }

    let boxClass = 'inventory-action-box';
    if (status === 'complete' || status === 'waiting_details') {
      boxClass += ' is-complete';
    } else if (status === 'failed' || status === 'rejected' || status === 'cancelled') {
      boxClass += ' is-failed';
    }

    inventoryActionMount.innerHTML =
      '<div class="' + boxClass + '">' +
        '<strong>Inventory</strong>' +
        '<div>' + safeStatusLabel + '</div>' +
        (detail ? '<div class="inventory-note" style="margin-top:6px;">' + detail + '</div>' : '') +
      '</div>';
  }

  function renderSteps(state) {
    if (!stepsList) return;

    stepsList.innerHTML = '';

    orderedKeys.forEach(function(key) {
      const step = state.steps[key];
      if (!step) return;
      if (key === 'inventory_action') return;

      const status = String(step.status || 'unknown').toLowerCase();
      const card = document.createElement('div');
      card.className = 'step-card is-' + status.replace(/[^a-z_]/g, '');

      const detail = step.live_notes || step.detail || '';
      let errandText = step.errand_id ? ('Errand #' + step.errand_id) : 'Local Step';
      if (step.completed_at) {
        errandText += ' · Completed ' + step.completed_at;
      }

      card.innerHTML =
        '<div class="step-head">' +
          '<div class="step-title">' +
            '<span class="step-dot is-' + status.replace(/[^a-z_]/g, '') + '"></span>' +
            '<span>' + step.label + '</span>' +
          '</div>' +
          '<div class="step-status">' + prettyStatus(status) + '</div>' +
        '</div>' +
        '<div class="step-detail">' + detail + '</div>' +
        '<div class="step-meta">' + errandText + '</div>';

      stepsList.appendChild(card);
    });
  }

  async function submitInventoryAction(siteCode, overrideStock, button) {
    if (button) {
      button.disabled = true;
      button.textContent = 'Applying...';
    }

    try {
      const payload = new URLSearchParams();
      payload.set('api', 'inventory_action');
      payload.set('token', token);
      payload.set('site_code', siteCode || '');
      payload.set('override_stock', overrideStock ? '1' : '0');

      const resp = await fetch(window.location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: payload.toString()
      });

      const data = await resp.json();
      if (!resp.ok || data.error) {
        throw new Error(data.message || data.error || 'inventory_action_failed');
      }

      renderStrip(data);
      renderHeadline(data);
      renderInventoryAction(data);
      renderSteps(data);

      if (!data.all_finished) {
        window.setTimeout(function() { poll(false); }, 1500);
      }
    } catch (err) {
      window.alert('Inventory action failed: ' + err.message);
      if (button) {
        button.disabled = false;
        button.textContent = 'Apply Inventory Action';
      }
      window.setTimeout(function() { poll(false); }, 500);
    }
  }

  async function poll(startMode) {
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('api', 'status');
      url.searchParams.set('token', token);
      if (startMode) {
        url.searchParams.set('start', '1');
      }

      const resp = await fetch(url.toString(), { credentials: 'same-origin' });
      const data = await resp.json();

      if (!resp.ok || data.error) {
        throw new Error(data.error || 'status_fetch_failed');
      }

      renderStrip(data);
      renderHeadline(data);
      renderInventoryAction(data);
      renderSteps(data);

      if (!data.all_finished) {
        window.setTimeout(function() { poll(false); }, 2500);
      }
    } catch (err) {
      if (statusStrip) {
        statusStrip.innerHTML =
          '<div class="status-pill"><span class="subtle">Status Error</span><strong>Retrying...</strong></div>';
      }

      if (workflowHeadline) {
        workflowHeadline.className = 'workflow-headline is-failed';
        workflowHeadline.innerHTML =
          '<strong>Status check hit a temporary error.</strong>' +
          'Musky will retry automatically in a few seconds.';
      }

      window.setTimeout(function() { poll(false); }, 4000);
    }
  }

  poll(true);
})();
</script>
</body>
</html>
