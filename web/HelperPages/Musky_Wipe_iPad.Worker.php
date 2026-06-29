<?php
// ============================================================================
// MUSKY - Musky_Wipe_iPad.Worker.php
// ---------------------------------------------------------------------------
// PURPOSE:
//   Engine / state machine for "wipe, verify, and (optionally) reassign" flows
//   for one or more iPads using NORA's API + MOSBasic workers.
//
// THIS FILE:
//   - Contains ALL logic, errands, state transitions, and logging
//   - Exposes a single function:
//
//       musky_wipe_worker_run(
//           string $serialParam,
//           bool $noReassign,
//           array $ownerOverrides = [],
//           array $restartRequest = [],
//           array $launchContext = []
//       ): array
//
//   - Returns a data array that the UI renderer can use to display status.
//   - No HTML is emitted here.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';

// -----------------------------------------------------------------------------
// SECURITY BASELINE FOR LIBRARY-STYLE FILES:
//   This worker is meant to be INCLUDED by Musky_Wipe_iPad.php, not accessed
//   directly as a standalone URL endpoint.
//
//   If someone navigates directly to this file:
//     1) Run shared check_access middleware so login rules stay consistent.
//     2) Return an explicit 403 message instead of exposing internal behavior.
//
//   When included from the UI page, this guard does nothing.
// -----------------------------------------------------------------------------
$muskyWipeWorkerDirectAccess = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__;

if ($muskyWipeWorkerDirectAccess) {
    require_once __DIR__ . '/../check_access.php';
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "This worker file is not a standalone endpoint.";
    exit;
}

// ----------------------------------------
// GLOBAL CONFIG
// ----------------------------------------

$MAX_LAST_SEEN_AGE = 8 * 3600;
$POST_WIPE_WAIT   = 420;
$REASSIGN_WAIT    = 3 * 60;
$REFRESH_INTERVAL = 5;
$BASELINE_PREFLIGHT_SETTLE_WAIT = 30;

function compute_clear_cooldown(int $count): int {
    if ($count > 15) return 30;
    if ($count > 5)  return 20;
    return 10;
}

// ----------------------------------------
// NORA API HELPERS
// ----------------------------------------

function nora_api_post_json(string $path, array $payload): ?array {
    return musky_nora_api_post_json($path, $payload, 20);
}

function nora_api_get(string $path, array $query = []): ?array {
    return musky_nora_api_get_json($path, $query, 10);
}

// ----------------------------------------
// STATE + LOG HELPERS
// ----------------------------------------

function sanitize_serial(string $s): string {
    return preg_replace('/[^A-Za-z0-9_]/', '_', $s);
}

function state_file_for_serial(string $serial): string {
    return "/tmp/musky_wipe_state_" . sanitize_serial($serial) . ".json";
}

function log_file_for_serial(string $serial): string {
    return "/tmp/musky_wipe_" . sanitize_serial($serial) . ".log";
}

function musky_wipe_archive_suffix(): string {
    return preg_replace('/[^A-Za-z0-9_]/', '_', uniqid('restart_', true));
}

function musky_wipe_archive_file(string $path, string $suffix): ?string {
    if (!is_file($path)) {
        return null;
    }

    $archivedPath = $path . '.' . $suffix;
    return @rename($path, $archivedPath) ? $archivedPath : null;
}

function musky_wipe_reset_runtime_files(string $serial): array {
    $suffix = musky_wipe_archive_suffix();

    return [
        'state' => musky_wipe_archive_file(state_file_for_serial($serial), $suffix),
        'log'   => musky_wipe_archive_file(log_file_for_serial($serial), $suffix),
    ];
}

function load_state(string $serial): array {
    $f = state_file_for_serial($serial);
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}

function save_state(string $serial, array $state): void {
    file_put_contents(
        state_file_for_serial($serial),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function log_line(string $serial, string $msg): void {
    file_put_contents(
        log_file_for_serial($serial),
        '[' . date('Y-m-d H:i:s') . "] {$msg}\n",
        FILE_APPEND
    );
}

function musky_wipe_parse_timestamp($value): ?int {
    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (ctype_digit($raw)) {
        $intValue = (int)$raw;
        if (strlen($raw) >= 10 && $intValue > 0) {
            return $intValue;
        }
    }

    $ts = strtotime($raw);
    return $ts !== false ? $ts : null;
}

function musky_wipe_format_timestamp($value): string {
    $ts = musky_wipe_parse_timestamp($value);
    if ($ts === null) {
        $raw = trim((string)($value ?? ''));
        return $raw !== '' ? $raw : 'NULL';
    }
    return date('Y-m-d H:i:s', $ts);
}

function musky_wipe_build_baseline(array $raw = []): array {
    return [
        'asset_tag'       => $raw['asset_tag']       ?? null,
        'owner_id'        => $raw['owner_id']        ?? null,
        'owner_mosyle_id' => $raw['owner_mosyle_id'] ?? null,
        'owner_email'     => $raw['owner_email']     ?? null,
        'owner_name'      => $raw['owner_name']      ?? null,
        'tags'            => $raw['tags']            ?? null,
        'last_seen'       => $raw['last_seen']       ?? null,
        'date_enroll'     => musky_wipe_parse_timestamp($raw['date_enroll'] ?? null),
        'udid'            => $raw['deviceudid']      ?? ($raw['udid'] ?? null),
    ];
}

function musky_wipe_build_state(
    string $serial,
    bool $noReassign,
    int $now,
    array $baseline = [],
    $invErrand = null
): array {
    return [
        'serial' => $serial,
        'mode'   => $noReassign ? 'no_reassign' : 'reassign',
        'created_at' => date('Y-m-d H:i:s', $now),
        'inv_lookup_all_id' => $invErrand,
        'baseline_preflight' => [
            'status' => $invErrand ? 'submitted' : 'skipped',
            'errand_completed_at' => null,
            'completed_at' => null,
            'refresh_checks' => 0,
        ],
        'baseline' => $baseline,
        'clear' => ['errand_id'=>null,'status'=>'not_started','completed_at'=>null],
        'limbo' => ['errand_id'=>null,'status'=>'not_started','completed_at'=>null],
        'clear_wait' => ['started_at'=>null,'seconds'=>null],
        'wipe' => ['errand_id'=>null,'status'=>'not_started','completed_at'=>null],
        'post_inv' => ['attempts'=>[],'good'=>false],
        'reassign' => ['wait_started_at'=>null,'errand_id'=>null,'status'=>'not_started'],
        'tags_restore' => ['errand_id'=>null,'status'=>'not_started'],
        'stage' => 'bootstrap',
        'failure_reason' => null,
    ];
}

function musky_wipe_lookup_device_row(string $serial): array {
    $devResp = nora_api_get('/devices', ['serial' => $serial]);
    if (!is_array($devResp) || empty($devResp['devices'][0]) || !is_array($devResp['devices'][0])) {
        return [];
    }

    return $devResp['devices'][0];
}

function musky_wipe_owner_override_for_serial(array $ownerOverrides, string $serial): array {
    $key = strtoupper(trim($serial));
    if ($key === '') {
        return [];
    }
    $override = $ownerOverrides[$key] ?? [];
    return is_array($override) ? $override : [];
}

function musky_wipe_normalize_serial_list(array $serials): array {
    $normalized = array_values(array_unique(array_filter(array_map(
        static fn($serial) => strtoupper(trim((string)$serial)),
        $serials
    ))));
    sort($normalized);
    return $normalized;
}

function musky_wipe_launch_context_allows_skip(array $launchContext, array $serials, int $now): bool {
    if (empty($launchContext['skip_initial_inv_lookup'])) {
        return false;
    }

    $createdAt = (int)($launchContext['created_at'] ?? 0);
    if ($createdAt <= 0 || ($now - $createdAt) > 1800) {
        return false;
    }

    $contextSerials = musky_wipe_normalize_serial_list((array)($launchContext['serials'] ?? []));
    $currentSerials = musky_wipe_normalize_serial_list($serials);

    return $contextSerials !== [] && $contextSerials === $currentSerials;
}

function musky_wipe_last_seen_is_recent($value, int $now, int $maxAge): bool {
    $ts = musky_wipe_parse_timestamp($value);
    if ($ts === null) {
        return false;
    }
    return ($now - $ts) <= $maxAge;
}

function musky_wipe_is_recoverable_bootstrap_failure(array $state): bool {
    if (($state['stage'] ?? '') !== 'failed') {
        return false;
    }

    if (!empty($state['clear']['errand_id']) || !empty($state['wipe']['errand_id']) || !empty($state['limbo']['errand_id'])) {
        return false;
    }

    $baseline = is_array($state['baseline'] ?? null) ? $state['baseline'] : [];
    $hasMissingCoreBaseline = trim((string)($baseline['last_seen'] ?? '')) === ''
        || trim((string)($baseline['udid'] ?? '')) === ''
        || trim((string)($baseline['asset_tag'] ?? '')) === '';

    if (!$hasMissingCoreBaseline) {
        return false;
    }

    $failure = strtolower(trim((string)($state['failure_reason'] ?? '')));
    $lookupError = trim((string)($state['bootstrap_lookup_error'] ?? ''));

    return $lookupError !== ''
        || str_contains($failure, 'last seen more than 8 hours ago')
        || str_contains($failure, 'nora')
        || str_contains($failure, 'lookup');
}

function musky_wipe_apply_owner_override(array &$baseline, array $override): bool {
    $overrideMosyleId = trim((string)($override['owner_mosyle_id'] ?? ''));
    if ($overrideMosyleId === '') {
        return false;
    }

    $forceReplace = !empty($override['force_replace']);
    $currentMosyleId = trim((string)($baseline['owner_mosyle_id'] ?? ''));
    if (!$forceReplace && $currentMosyleId !== '') {
        return false;
    }

    $changed = false;

    if ($currentMosyleId !== $overrideMosyleId) {
        $baseline['owner_mosyle_id'] = $overrideMosyleId;
        $changed = true;
    }

    $overrideEmail = trim((string)($override['owner_email'] ?? ''));
    $currentEmail = trim((string)($baseline['owner_email'] ?? ''));
    if ($overrideEmail !== '' && ($forceReplace || $currentEmail === '' || strcasecmp($currentEmail, $overrideEmail) !== 0)) {
        $baseline['owner_email'] = $overrideEmail;
        $changed = true;
    }

    $overrideName = trim((string)($override['owner_name'] ?? ''));
    $currentName = trim((string)($baseline['owner_name'] ?? ''));
    if ($overrideName !== '' && ($forceReplace || $currentName === '' || strcasecmp($currentName, $overrideName) !== 0)) {
        $baseline['owner_name'] = $overrideName;
        $changed = true;
    }

    $overrideSource = trim((string)($override['source'] ?? ''));
    $overrideMessage = trim((string)($override['message'] ?? ''));
    if (($baseline['owner_override_source'] ?? '') !== $overrideSource) {
        $baseline['owner_override_source'] = $overrideSource;
        $changed = true;
    }
    if (($baseline['owner_override_message'] ?? '') !== $overrideMessage) {
        $baseline['owner_override_message'] = $overrideMessage;
        $changed = true;
    }

    return $changed;
}

// ============================================================================
// MAIN WORKER
// ============================================================================

function musky_wipe_worker_run(
    string $serialParam,
    bool $noReassign,
    array $ownerOverrides = [],
    array $restartRequest = [],
    array $launchContext = []
): array
{
    global $MAX_LAST_SEEN_AGE, $POST_WIPE_WAIT, $REASSIGN_WAIT, $BASELINE_PREFLIGHT_SETTLE_WAIT;

    $submitterUser =
        $_SESSION['musky_user']['username']
        ?? $_SESSION['musky_user']['email']
        ?? 'Musky_Wipe_iPad.php';

    $serialParam = trim($serialParam);
    if ($serialParam === '') {
        return [
            'serials' => [],
            'perSerialStates' => [],
            'needsRefresh' => false,
            'submitterUser' => $submitterUser,
            'now' => time(),
            'noReassign' => $noReassign,
            'launchContextUsed' => false,
        ];
    }

    $serials = array_values(array_unique(array_filter(
        preg_split('/\s*,\s*/', $serialParam)
    )));
    $totalSerialCount = count($serials);

    $now = time();
    $needsRefresh = false;
    $launchContextUsed = false;

    $restartMode = '';
    $restartSerial = '';
    if ($totalSerialCount === 1 && !empty($restartRequest['serial'])) {
        $requestedSerial = strtoupper(trim((string)($restartRequest['serial'] ?? '')));
        $activeSerial = strtoupper(trim((string)($serials[0] ?? '')));
        if ($requestedSerial !== '' && $requestedSerial === $activeSerial) {
            $restartMode = !empty($restartRequest['force']) ? 'force' : 'retry';
            $restartSerial = $serials[0];
        }
    }

    if ($restartSerial !== '') {
        $previousState = load_state($restartSerial);
        $ownerOverride = musky_wipe_owner_override_for_serial($ownerOverrides, $restartSerial);
        $archivedPaths = musky_wipe_reset_runtime_files($restartSerial);

        if ($restartMode === 'force') {
            $baselineSeed = !empty($previousState['baseline']) && is_array($previousState['baseline'])
                ? musky_wipe_build_baseline($previousState['baseline'])
                : musky_wipe_build_baseline(musky_wipe_lookup_device_row($restartSerial));

            $forcedState = musky_wipe_build_state($restartSerial, $noReassign, $now, $baselineSeed);
            $forcedState['stage'] = 'wipe_pending';
            $forcedState['clear']['status'] = 'skipped_force_restart';
            $forcedState['limbo']['status'] = 'skipped_force_restart';
            $forcedState['force_restart'] = true;

            if (musky_wipe_apply_owner_override($forcedState['baseline'], $ownerOverride)) {
                $forcedState['baseline']['owner_override_applied_at'] = date('Y-m-d H:i:s', $now);
            }

            save_state($restartSerial, $forcedState);
            log_line(
                $restartSerial,
                sprintf(
                    "Manual FORCE restart requested. Archived prior state=%s log=%s",
                    $archivedPaths['state'] ?? 'none',
                    $archivedPaths['log'] ?? 'none'
                )
            );
            log_line(
                $restartSerial,
                "FORCE path: skipping resume/bootstrap and restarting at WIPEME."
            );
        } else {
            log_line(
                $restartSerial,
                sprintf(
                    "Manual retry requested. Archived prior state=%s log=%s",
                    $archivedPaths['state'] ?? 'none',
                    $archivedPaths['log'] ?? 'none'
                )
            );
        }
    }

    // ----------------------------------------
    // FIRST PASS — BASELINE SNAPSHOT
    // ----------------------------------------

    $anyStateExists = false;
    foreach ($serials as $s) {
        if (file_exists(state_file_for_serial($s))) {
            $anyStateExists = true;
            break;
        }
    }

    if (!$anyStateExists) {
        $skipInitialPreflight = musky_wipe_launch_context_allows_skip($launchContext, $serials, $now);
        $launchContextUsed = $skipInitialPreflight;
        $invErrand = null;
        $preflightLookupError = '';

        if (!$skipInitialPreflight) {
            $resp = nora_api_post_json('/errand/create', [
                'serial'    => 'NONE',
                'udid'      => 'SYSTEM_TASK',
                'submitter' => $submitterUser,
                'nora'      => 'TRUE',
                'priority'  => 5,
                'extra1'    => 'INV_LOOKUP',
                'extra2'    => implode(',', $serials),
            ]);

            $invErrand = $resp['errand_id'] ?? null;
            if (!$invErrand && !empty($resp['error'])) {
                $preflightLookupError = 'Preflight INV_LOOKUP errand could not be created: ' . trim((string)$resp['error']);
            }
        }

        $devResp = nora_api_get('/devices', ['serial' => implode(',', $serials)]);
        $devIndex = [];
        $deviceLookupError = '';
        if (is_array($devResp) && !empty($devResp['error'])) {
            $deviceLookupError = 'Nora /devices lookup failed: ' . trim((string)$devResp['error']);
        }

        if (!empty($devResp['devices'])) {
            foreach ($devResp['devices'] as $d) {
                if (!empty($d['serial_number'])) {
                    $devIndex[$d['serial_number']] = $d;
                }
            }
        }

        foreach ($serials as $ser) {
            $raw = $devIndex[$ser] ?? [];
            $ownerOverride = musky_wipe_owner_override_for_serial($ownerOverrides, $ser);

            $state = musky_wipe_build_state(
                $ser,
                $noReassign,
                $now,
                musky_wipe_build_baseline($raw),
                $invErrand
            );
            if ($skipInitialPreflight) {
                $state['baseline_preflight']['status'] = 'skipped_launch_context';
                $state['baseline_preflight']['errand_completed_at'] = date('Y-m-d H:i:s', $now);
                $state['baseline_preflight']['completed_at'] = date('Y-m-d H:i:s', $now);
                $state['baseline_preflight']['refresh_checks'] = 1;
                $state['launch_context'] = [
                    'source' => trim((string)($launchContext['source'] ?? 'burrows')),
                    'created_at' => date('Y-m-d H:i:s', (int)($launchContext['created_at'] ?? $now)),
                ];
                log_line(
                    $ser,
                    'Step1: Fresh launch context confirmed recent Resolve Devices. Skipping duplicate bootstrap INV_LOOKUP.'
                );
            }
            if (!$raw) {
                $messages = array_values(array_filter([$preflightLookupError, $deviceLookupError]));
                $state['bootstrap_lookup_error'] = $messages
                    ? implode(' ', $messages)
                    : 'Nora device lookup returned no record for this serial during bootstrap.';
            }

            if (musky_wipe_apply_owner_override($state['baseline'], $ownerOverride)) {
                log_line(
                    $ser,
                    'Step2: Owner override applied from Technology Marsh (' .
                    ($state['baseline']['owner_override_source'] ?? 'override') .
                    '): ' .
                    (($state['baseline']['owner_override_message'] ?? '') !== ''
                        ? $state['baseline']['owner_override_message']
                        : 'Using fallback owner for reassignment.')
                );
            }

            save_state($ser, $state);

            log_line(
                $ser,
                sprintf(
                    "Step2: Baseline snapshot: owner=%s mosyle_id=%s last_seen=%s date_enroll=%s udid=%s",
                    $state['baseline']['owner_name'] ?? 'NULL',
                    $state['baseline']['owner_mosyle_id'] ?? 'NULL',
                    musky_wipe_format_timestamp($state['baseline']['last_seen'] ?? null),
                    musky_wipe_format_timestamp($state['baseline']['date_enroll'] ?? null),
                    $state['baseline']['udid'] ?? 'NULL'
                )
            );
        }
    }
    // ----------------------------------------
    // SECOND PASS: per-serial state machine
    // ----------------------------------------

    $perSerialStates = [];

    foreach ($serials as $ser) {
        $state = load_state($ser);
        $ownerOverride = musky_wipe_owner_override_for_serial($ownerOverrides, $ser);
        if (empty($state)) {
            // This should not happen unless /tmp was wiped mid-run
            $state = musky_wipe_build_state(
                $ser,
                $noReassign,
                $now,
                musky_wipe_build_baseline()
            );
            $state['failure_reason'] = 'State missing, re-bootstrap';
            log_line($ser, "WARNING: State file missing; re-bootstrap will occur next refresh.");
        }

        if (musky_wipe_is_recoverable_bootstrap_failure($state)) {
            $refreshRow = musky_wipe_lookup_device_row($ser);
            if ($refreshRow) {
                $recoveredBaseline = musky_wipe_build_baseline($refreshRow);
                foreach ($recoveredBaseline as $key => $value) {
                    $state['baseline'][$key] = $value;
                }
                if (musky_wipe_apply_owner_override($state['baseline'], $ownerOverride)) {
                    log_line(
                        $ser,
                        'Bootstrap recovery re-applied owner override (' .
                        ($state['baseline']['owner_override_source'] ?? 'override') .
                        ').'
                    );
                }

                $state['stage'] = 'bootstrap';
                $state['failure_reason'] = null;
                $state['bootstrap_lookup_error'] = null;
                $state['baseline_preflight']['status'] = 'skipped';
                $state['baseline_preflight']['errand_completed_at'] = date('Y-m-d H:i:s', $now);
                $state['baseline_preflight']['completed_at'] = date('Y-m-d H:i:s', $now);
                $state['baseline_preflight']['refresh_checks'] = (int)($state['baseline_preflight']['refresh_checks'] ?? 0) + 1;
                $state['inv_lookup_all_id'] = null;

                log_line(
                    $ser,
                    sprintf(
                        "Bootstrap recovery refreshed baseline: asset_tag=%s last_seen=%s date_enroll=%s udid=%s",
                        $state['baseline']['asset_tag'] ?? 'NULL',
                        musky_wipe_format_timestamp($state['baseline']['last_seen'] ?? null),
                        musky_wipe_format_timestamp($state['baseline']['date_enroll'] ?? null),
                        $state['baseline']['udid'] ?? 'NULL'
                    )
                );
                save_state($ser, $state);
            }
        }

        if (musky_wipe_apply_owner_override($state['baseline'], $ownerOverride)) {
            log_line(
                $ser,
                'Owner override applied from Technology Marsh (' .
                ($state['baseline']['owner_override_source'] ?? 'override') .
                '): ' .
                (($state['baseline']['owner_override_message'] ?? '') !== ''
                    ? $state['baseline']['owner_override_message']
                    : 'Using fallback owner for reassignment.')
            );
            save_state($ser, $state);
        }

        if (empty($state['baseline']['asset_tag'] ?? null) && $ser !== '') {
            $assetResp = nora_api_get('/devices', ['serial' => $ser]);
            if (is_array($assetResp) && !empty($assetResp['devices'][0]['asset_tag'])) {
                $state['baseline']['asset_tag'] = $assetResp['devices'][0]['asset_tag'];
                save_state($ser, $state);
            }
        }

        $stage = $state['stage'] ?? 'bootstrap';

        if (!in_array($stage, ['complete','failed'], true)) {
            $needsRefresh = true;
        }

        // ------------------------------------------------------------
        // STAGE: bootstrap → CLEARCOMMANDSALL (last_seen validation)
        // ------------------------------------------------------------
        if ($stage === 'bootstrap') {
            $preflight = $state['baseline_preflight'] ?? [
                'status' => 'skipped',
                'errand_completed_at' => null,
                'completed_at' => null,
                'refresh_checks' => 0,
            ];
            $preflightStatus = trim((string)($preflight['status'] ?? ''));
            $preflightDone = trim((string)($preflight['completed_at'] ?? '')) !== '';
            $preflightErrandId = (int)($state['inv_lookup_all_id'] ?? 0);

            if ($preflightErrandId > 0 && !$preflightDone) {
                $preflightResp = nora_api_get("/errand/status/{$preflightErrandId}");
                $currentPreflightStatus = trim((string)($preflightResp['Status'] ?? ''));

                if ($currentPreflightStatus !== '' && $currentPreflightStatus !== $preflightStatus) {
                    $state['baseline_preflight']['status'] = $currentPreflightStatus;
                    log_line($ser, "Step3: Preflight INV_LOOKUP status={$currentPreflightStatus}.");
                }

                if ($currentPreflightStatus === 'Complete') {
                    if (trim((string)($state['baseline_preflight']['errand_completed_at'] ?? '')) === '') {
                        $state['baseline_preflight']['errand_completed_at'] = date('Y-m-d H:i:s', $now);
                    }
                    $state['baseline_preflight']['status'] = 'Complete';
                    $state['baseline_preflight']['refresh_checks'] = (int)($state['baseline_preflight']['refresh_checks'] ?? 0) + 1;

                    $refreshRow = musky_wipe_lookup_device_row($ser);
                    if ($refreshRow) {
                        $refreshedBaseline = musky_wipe_build_baseline($refreshRow);
                        foreach ($refreshedBaseline as $key => $value) {
                            $state['baseline'][$key] = $value;
                        }

                        if (musky_wipe_apply_owner_override($state['baseline'], $ownerOverride)) {
                            log_line(
                                $ser,
                                'Step3: Owner override re-applied after preflight refresh (' .
                                ($state['baseline']['owner_override_source'] ?? 'override') .
                                ').'
                            );
                        }

                        log_line(
                            $ser,
                            sprintf(
                                "Step3: Refreshed baseline after preflight: owner=%s mosyle_id=%s last_seen=%s date_enroll=%s udid=%s",
                                $state['baseline']['owner_name'] ?? 'NULL',
                                $state['baseline']['owner_mosyle_id'] ?? 'NULL',
                                musky_wipe_format_timestamp($state['baseline']['last_seen'] ?? null),
                                musky_wipe_format_timestamp($state['baseline']['date_enroll'] ?? null),
                                $state['baseline']['udid'] ?? 'NULL'
                            )
                        );
                    } else {
                        log_line($ser, "Step3: WARNING. Preflight INV_LOOKUP completed, but refreshed device data could not be read.");
                    }

                    $baselineLastSeenReady = musky_wipe_last_seen_is_recent(
                        $state['baseline']['last_seen'] ?? null,
                        $now,
                        $MAX_LAST_SEEN_AGE
                    );
                    $errandCompletedAtTs = musky_wipe_parse_timestamp($state['baseline_preflight']['errand_completed_at'] ?? null);
                    $settleElapsed = $errandCompletedAtTs !== null ? ($now - $errandCompletedAtTs) : 0;

                    if ($baselineLastSeenReady) {
                        $state['baseline_preflight']['completed_at'] = date('Y-m-d H:i:s', $now);
                        log_line($ser, "Step3: Preflight refresh confirmed a recent last_seen value. Proceeding to bootstrap gate.");
                        save_state($ser, $state);
                    } elseif ($settleElapsed < $BASELINE_PREFLIGHT_SETTLE_WAIT) {
                        log_line(
                            $ser,
                            "Step3: Preflight INV_LOOKUP is complete, but last_seen is still stale (" .
                            musky_wipe_format_timestamp($state['baseline']['last_seen'] ?? null) .
                            "). Waiting for Nora device data to settle before enforcing the 8-hour check."
                        );
                        save_state($ser, $state);
                        $perSerialStates[$ser] = $state;
                        continue;
                    } else {
                        $state['baseline_preflight']['completed_at'] = date('Y-m-d H:i:s', $now);
                        log_line(
                            $ser,
                            "Step3: Preflight settle window expired after {$settleElapsed}s. Proceeding with the latest known last_seen=" .
                            musky_wipe_format_timestamp($state['baseline']['last_seen'] ?? null) . '.'
                        );
                        save_state($ser, $state);
                    }
                } elseif (in_array($currentPreflightStatus, ['Failed','Cancelled','Rejected','Unknown'], true)) {
                    $state['baseline_preflight']['status'] = $currentPreflightStatus;
                    $state['baseline_preflight']['completed_at'] = date('Y-m-d H:i:s', $now);
                    log_line(
                        $ser,
                        "Step3: WARNING. Preflight INV_LOOKUP ended with status={$currentPreflightStatus}; proceeding with currently known baseline values."
                    );
                    save_state($ser, $state);
                } else {
                    save_state($ser, $state);
                    $perSerialStates[$ser] = $state;
                    continue;
                }
            }

            $bl = $state['baseline'];
            $bootstrapLookupError = trim((string)($state['bootstrap_lookup_error'] ?? ''));
            if ($bootstrapLookupError !== '' && empty($bl['last_seen'])) {
                $state['stage'] = 'failed';
                $state['failure_reason'] = $bootstrapLookupError;
                log_line($ser, "Step4: ABORT. {$bootstrapLookupError}");
                save_state($ser, $state);
                $perSerialStates[$ser] = $state;
                continue;
            }

            $lastSeenStr = $bl['last_seen'] ?? null;
            $tooOld = true;

            if ($lastSeenStr) {
                $lsTs = strtotime($lastSeenStr);
                if ($lsTs !== false && ($now - $lsTs) <= $MAX_LAST_SEEN_AGE) {
                    $tooOld = false;
                }
            }

            if ($tooOld) {
                $state['stage'] = 'failed';
                $state['failure_reason'] = 'Last seen more than 8 hours ago (or unknown).';
                log_line($ser, "Step4: ABORT. Device last_seen too old or missing.");
            } else {
                $payload = [
                    'serial'    => $ser,
                    'udid'      => $bl['udid'] ?? 'UNKNOWN',
                    'submitter' => $submitterUser,
                    'mosbasic'  => 'CLEARCOMMANDSALL',
                    'priority'  => 5,
                ];

                $resp = nora_api_post_json('/errand/create', $payload);
                $errId = $resp['errand_id'] ?? null;

                if ($errId) {
                    $state['clear']['errand_id'] = $errId;
                    $state['clear']['status']    = 'submitted';
                    $state['stage']              = 'clear_pending';
                    log_line($ser, "Step4: CLEARCOMMANDSALL submitted – ErrandID={$errId}");
                } else {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = 'Failed to create CLEARCOMMANDSALL errand.';
                    log_line($ser, "Step4: ERROR. Failed to submit CLEARCOMMANDSALL.");
                }
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: clear_pending
        // ------------------------------------------------------------
        else if ($stage === 'clear_pending') {
            $errId = $state['clear']['errand_id'];

            $resp = nora_api_get("/errand/status/{$errId}");
            if ($resp && !empty($resp['Status'])) {
                $status = $resp['Status'];
                $state['clear']['status'] = $status;

                if ($status === 'Complete') {
                    $state['clear']['completed_at'] = date('Y-m-d H:i:s', $now);

                    if ($state['mode'] === 'no_reassign') {
                        $payload = [
                            'serial'    => $ser,
                            'udid'      => $state['baseline']['udid'] ?? 'UNKNOWN',
                            'submitter' => $submitterUser,
                            'mosbasic'  => 'LIMBOME',
                            'priority'  => 5,
                        ];

                        $r = nora_api_post_json('/errand/create', $payload);
                        $state['limbo']['errand_id'] = $r['errand_id'] ?? null;
                        $state['limbo']['status']    = 'submitted';
                        $state['stage']              = 'limbo_pending';

                        log_line($ser, "Step5b: LIMBOME submitted.");
                    } else {
                        $state['clear_wait']['started_at'] = date('Y-m-d H:i:s', $now);
                        $state['clear_wait']['seconds']    = compute_clear_cooldown($totalSerialCount);
                        $state['stage'] = 'clear_wait';

                        log_line($ser, "Step5a: CLEAR cooldown started.");
                    }
                }
                else if (in_array($status, ['Failed','Cancelled','Rejected','Unknown'], true)) {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = "CLEARCOMMANDSALL failed ({$status}).";
                    log_line($ser, "Step4: CLEARCOMMANDSALL failed ({$status}).");
                }
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: clear_wait
        // ------------------------------------------------------------
        else if ($stage === 'clear_wait') {
            $started = strtotime($state['clear_wait']['started_at']);
            $wait    = $state['clear_wait']['seconds'];

            if (($now - $started) >= $wait) {
                $state['stage'] = 'wipe_pending';
                log_line($ser, "Step6: CLEAR cooldown complete.");
            }

            save_state($ser, $state);
        }
        // ------------------------------------------------------------
        // STAGE: limbo_pending (STORAGE-only)
        // ------------------------------------------------------------
        else if ($stage === 'limbo_pending') {
            $errId = $state['limbo']['errand_id'] ?? null;

            if (!$errId) {
                $state['stage'] = 'failed';
                $state['failure_reason'] = 'Missing LIMBOME errand ID.';
                log_line($ser, "Step5b: ERROR. No LIMBOME errand ID recorded.");
                save_state($ser, $state);
                $perSerialStates[$ser] = $state;
                continue;
            }

            $resp = nora_api_get("/errand/status/{$errId}");
            if ($resp && !empty($resp['Status'])) {
                $status = $resp['Status'];
                $state['limbo']['status'] = $status;

                if ($status === 'Complete') {
                    $state['limbo']['completed_at'] = date('Y-m-d H:i:s', $now);

                    $cooldown = compute_clear_cooldown($totalSerialCount);
                    $state['clear_wait']['started_at'] = date('Y-m-d H:i:s', $now);
                    $state['clear_wait']['seconds']    = $cooldown;
                    $state['stage']                    = 'clear_wait';

                    log_line($ser, "Step5b: LIMBOME completed. Starting {$cooldown}s cooldown before WIPEME.");
                } elseif (in_array($status, ['Failed','Cancelled','Rejected','Unknown'], true)) {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = "LIMBOME ended with status={$status}.";
                    log_line($ser, "Step5b: ERROR. LIMBOME ended with status={$status}.");
                } else {
                    log_line($ser, "Step5b: LIMBOME status={$status} (waiting).");
                }
            } else {
                log_line($ser, "Step5b: WARNING. Could not read LIMBOME status from API.");
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: wipe_pending (submit WIPEME then monitor)
        // ------------------------------------------------------------
        else if ($stage === 'wipe_pending') {
            $errId = $state['wipe']['errand_id'] ?? null;

            // First entry: submit WIPEME
            if (!$errId) {
                $bl = $state['baseline'];

                $payload = [
                    'serial'    => $ser,
                    'udid'      => $bl['udid'] ?? 'UNKNOWN',
                    'submitter' => $submitterUser,
                    'mosbasic'  => 'WIPEME',
                    'priority'  => 5,
                ];

                // Reassign mode: NOVPP flag
                if (($state['mode'] ?? '') === 'reassign') {
                    $payload['extra2'] = 'NOVPP';
                }

                $resp = nora_api_post_json('/errand/create', $payload);
                $errId = $resp['errand_id'] ?? null;

                if ($errId) {
                    $state['wipe']['errand_id'] = $errId;
                    $state['wipe']['status']    = 'submitted';
                    log_line($ser, "Step6: WIPEME submitted – ErrandID={$errId}.");
                } else {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = 'Failed to create WIPEME errand.';
                    log_line($ser, "Step6: ERROR. Failed to submit WIPEME.");
                    save_state($ser, $state);
                    $perSerialStates[$ser] = $state;
                    continue;
                }

                save_state($ser, $state);
                $perSerialStates[$ser] = $state;
                continue;
            }

            // Monitor WIPEME status
            $resp = nora_api_get("/errand/status/{$errId}");
            if ($resp && !empty($resp['Status'])) {
                $status = $resp['Status'];
                $state['wipe']['status'] = $status;

                if ($status === 'Complete') {
                    $state['wipe']['completed_at'] = date('Y-m-d H:i:s', $now);
                    $state['stage'] = 'wipe_wait';
                    log_line($ser, "Step6: WIPEME completed. Waiting {$POST_WIPE_WAIT}s before post-wipe INV_LOOKUP.");
                } elseif (in_array($status, ['Failed','Cancelled','Rejected','Unknown'], true)) {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = "WIPEME ended with status={$status}.";
                    log_line($ser, "Step6: ERROR. WIPEME ended with status={$status}.");
                } else {
                    log_line($ser, "Step6: WIPEME status={$status} (waiting).");
                }
            } else {
                log_line($ser, "Step6: WARNING. Could not read WIPEME status from API.");
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: wipe_wait (post-wipe cooldown → create INV_LOOKUP)
        // ------------------------------------------------------------
        else if ($stage === 'wipe_wait') {
            $completedAtStr = $state['wipe']['completed_at'] ?? null;
            $completedTs    = $completedAtStr ? strtotime($completedAtStr) : null;

            if (!$completedTs) {
                $completedTs = $now;
                $state['wipe']['completed_at'] = date('Y-m-d H:i:s', $completedTs);
            }

            $elapsed = $now - $completedTs;

            if ($elapsed >= $POST_WIPE_WAIT) {
                $payload = [
                    'serial'    => 'NONE',
                    'udid'      => 'SYSTEM_TASK',
                    'submitter' => $submitterUser,
                    'nora'      => 'TRUE',
                    'priority'  => 5,
                    'extra1'    => 'INV_LOOKUP',
                    'extra2'    => $ser,
                ];

                $resp = nora_api_post_json('/errand/create', $payload);
                $invId = $resp['errand_id'] ?? null;

                if ($invId) {
                    $state['post_inv']['attempts'][] = [
                        'priority'  => 5,
                        'errand_id' => $invId,
                        'status'    => 'submitted',
                    ];
                    $state['stage'] = 'post_inv';
                    log_line($ser, "Step8: Post-wipe INV_LOOKUP submitted (priority 5) – ErrandID={$invId}.");
                } else {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = 'Failed to create post-wipe INV_LOOKUP (priority 5).';
                    log_line($ser, "Step8: ERROR. Failed to submit post-wipe INV_LOOKUP (priority 5).");
                }
            } else {
                $remaining = $POST_WIPE_WAIT - $elapsed;
                log_line($ser, "Step7: Waiting {$remaining}s post-wipe cooldown before INV_LOOKUP.");
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: post_inv  (BDA-1)
        // REQUIRE: last_seen NEWER than baseline AND date_enroll NEWER than baseline
        // ALSO: log current/baseline values every check
        // ------------------------------------------------------------
        else if ($stage === 'post_inv') {
            $attempts = $state['post_inv']['attempts'] ?? [];

            if (empty($attempts)) {
                $state['stage'] = 'failed';
                $state['failure_reason'] = 'No post-wipe INV_LOOKUP attempts recorded.';
                log_line($ser, "BDA-1: ERROR. No INV_LOOKUP attempts present.");
                save_state($ser, $state);
                $perSerialStates[$ser] = $state;
                continue;
            }

            $currentAttemptIndex = count($attempts) - 1;
            $att   = $attempts[$currentAttemptIndex];
            $errId = $att['errand_id'];

            $resp = nora_api_get("/errand/status/{$errId}");
            if ($resp && !empty($resp['Status'])) {
                $status = $resp['Status'];
                $state['post_inv']['attempts'][$currentAttemptIndex]['status'] = $status;

                if ($status === 'Complete') {
                    // Query current device record
                    $devResp = nora_api_get('/devices', ['serial' => $ser]);

                    $lastSeenStr   = null;
                    $dateEnrollStr = null;
                    $latestUdid    = null;
                    $latestAssetTag = null;

                    if (is_array($devResp) && !empty($devResp['devices'])) {
                        $row = $devResp['devices'][0];
                        $lastSeenStr   = $row['last_seen']   ?? null;
                        $dateEnrollStr = $row['date_enroll'] ?? null;
                        $latestUdid    = $row['deviceudid']  ?? ($row['udid'] ?? null);
                        $latestAssetTag = $row['asset_tag']  ?? null;
                    }

                    $wipeCompletedStr = $state['wipe']['completed_at'] ?? null;
                    $baselineLastSeenStr = $state['baseline']['last_seen'] ?? null;
                    $baselineLastSeenTs = musky_wipe_parse_timestamp($baselineLastSeenStr);
                    $currentLastSeenTs = musky_wipe_parse_timestamp($lastSeenStr);

                    $baselineEnrollStr = $state['baseline']['date_enroll'] ?? null;
                    $baselineEnrollTs  = musky_wipe_parse_timestamp($baselineEnrollStr);
                    $currentEnrollTs   = musky_wipe_parse_timestamp($dateEnrollStr);

                    // ALWAYS log what we're comparing (your request)
                    log_line(
                        $ser,
                        sprintf(
                            "BDA-1: CHECK baseline_date_enroll=%s current_date_enroll=%s baseline_last_seen=%s current_last_seen=%s wipe_completed_at=%s",
                            musky_wipe_format_timestamp($baselineEnrollStr),
                            musky_wipe_format_timestamp($dateEnrollStr),
                            musky_wipe_format_timestamp($baselineLastSeenStr),
                            musky_wipe_format_timestamp($lastSeenStr),
                            musky_wipe_format_timestamp($wipeCompletedStr)
                        )
                    );

                    // Criteria:
                    // 1) last_seen moved forward past baseline
                    $lastSeenOk = false;
                    if ($baselineLastSeenTs && $currentLastSeenTs) {
                        $lastSeenOk = ($currentLastSeenTs > $baselineLastSeenTs);
                    } elseif (!$baselineLastSeenTs && $currentLastSeenTs) {
                        $lastSeenOk = true;
                    }

                    // 2) date_enroll advanced past baseline
                    $enrollOk = false;
                    if ($baselineEnrollTs && $currentEnrollTs) {
                        $enrollOk = ($currentEnrollTs > $baselineEnrollTs);
                    } elseif (!$baselineEnrollTs && $currentEnrollTs) {
                        // If baseline had no enroll date, any current enroll counts as advancement
                        $enrollOk = true;
                    }

                    if ($lastSeenOk && $enrollOk) {
                        $state['post_inv']['good'] = true;

                        log_line(
                            $ser,
                            "BDA-1: SUCCESS. last_seen and date_enroll both advanced; device re-enrolled confirmed."
                        );

                        // Update UDID in state to latest observed (helps ASSIGNME reliability)
                        if (!empty($latestUdid) && $latestUdid !== ($state['baseline']['udid'] ?? null)) {
                            log_line(
                                $ser,
                                "BDA-1: NOTE. UDID changed after wipe (baseline_udid=" .
                                ($state['baseline']['udid'] ?? 'NULL') . " new_udid={$latestUdid}). Updating state."
                            );
                            $state['baseline']['udid'] = $latestUdid;
                        }
                        if (!empty($latestAssetTag) && $latestAssetTag !== ($state['baseline']['asset_tag'] ?? null)) {
                            $state['baseline']['asset_tag'] = $latestAssetTag;
                        }

                        if (($state['mode'] ?? '') === 'reassign') {
                            $state['reassign']['wait_started_at'] = date('Y-m-d H:i:s', $now);
                            $state['stage'] = 'reassign_wait';
                            log_line($ser, "BDA-2: Starting 5-minute wait before ASSIGNME.");
                        } else {
                            $state['reassign']['status'] = 'skipped_storage_mode';
                            $state['stage'] = 'assign_pending';
                            log_line($ser, "BDA-2: STORAGE mode – skipping ASSIGNME; proceeding to tag handling (BDA-3).");
                        }
                    } else {
                        // Explicit logging of failure condition
                        if (!$lastSeenOk) {
                            log_line(
                                $ser,
                                "BDA-1: NOT READY. last_seen has not advanced past baseline_last_seen yet."
                            );
                        }
                        if (!$enrollOk) {
                            log_line(
                                $ser,
                                "BDA-1: NOT READY. date_enroll has NOT advanced past baseline yet."
                            );
                        }

                        // Escalate INV_LOOKUP priority 5 → 4 → 3 → 2
                        $currentPriority = (int)($att['priority'] ?? 5);
                        $nextPriority = null;
                        if ($currentPriority === 5) $nextPriority = 4;
                        if ($currentPriority === 4) $nextPriority = 3;

                        if ($nextPriority === null) {

                            log_line(
                                $ser,
                                "BDA-1: HAIL-MARY OVERRIDE. Inventory did not confirm re-enroll in time, but forcing continuation anyway."
                            );

                            // Mark as logically good even though inventory lagged
                            $state['post_inv']['good'] = true;
                            $state['post_inv']['forced'] = true;

                            if (($state['mode'] ?? '') === 'reassign') {
                                $state['reassign']['wait_started_at'] = date('Y-m-d H:i:s', $now);
                                $state['stage'] = 'reassign_wait';

                                log_line(
                                    $ser,
                                    "BDA-1: FORCED → proceeding to BDA-2 reassign_wait despite missing inventory confirmation."
                                );
                            } else {
                                // STORAGE MODE
                                $state['reassign']['status'] = 'skipped_storage_mode';
                                $state['stage'] = 'assign_pending';

                                log_line(
                                    $ser,
                                    "BDA-1: FORCED → STORAGE mode, skipping reassign and proceeding to tag handling."
                                );
                            }
                        } else {
                            $payload = [
                                'serial'    => 'NONE',
                                'udid'      => 'SYSTEM_TASK',
                                'submitter' => $submitterUser,
                                'nora'      => 'TRUE',
                                'priority'  => $nextPriority,
                                'extra1'    => 'INV_LOOKUP',
                                'extra2'    => $ser,
                            ];

                            $r2 = nora_api_post_json('/errand/create', $payload);
                            $errId2 = $r2['errand_id'] ?? null;

                            if ($errId2) {
                                $state['post_inv']['attempts'][] = [
                                    'priority'  => $nextPriority,
                                    'errand_id' => $errId2,
                                    'status'    => 'submitted',
                                ];
                                log_line($ser, "BDA-1: Retrying INV_LOOKUP at priority {$nextPriority} – ErrandID={$errId2}.");
                            } else {
                                $state['stage'] = 'failed';
                                $state['failure_reason'] = "Failed to create post-wipe INV_LOOKUP at priority {$nextPriority}.";
                                log_line($ser, "BDA-1: ERROR. Could not submit INV_LOOKUP at priority {$nextPriority}.");
                            }
                        }
                    }
                }
                else if (in_array($status, ['Failed','Cancelled','Rejected','Unknown'], true)) {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = "INV_LOOKUP ended with status={$status}.";
                    log_line($ser, "BDA-1: ERROR. INV_LOOKUP ended with status={$status}.");
                } else {
                    log_line($ser, "BDA-1: INV_LOOKUP status={$status} (waiting).");
                }
            } else {
                log_line($ser, "BDA-1: WARNING. Could not read INV_LOOKUP status for ErrandID={$errId}.");
            }

            save_state($ser, $state);
        }
        // ------------------------------------------------------------
        // STAGE: reassign_wait (BDA-2: per-serial wait before ASSIGNME)
        // ------------------------------------------------------------
        else if ($stage === 'reassign_wait') {
            $waitStr = $state['reassign']['wait_started_at'] ?? null;
            $waitTs  = $waitStr ? strtotime($waitStr) : null;

            if (!$waitTs) {
                $waitTs = $now;
                $state['reassign']['wait_started_at'] = date('Y-m-d H:i:s', $waitTs);
            }

            $elapsed = $now - $waitTs;

            if ($elapsed >= $REASSIGN_WAIT) {
                // ASSIGNME back to original owner (Mosyle user ID)
                $origMosyleId = $state['baseline']['owner_mosyle_id'] ?? null;

                if (!$origMosyleId) {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = 'Missing original owner Mosyle ID for ASSIGNME.';
                    log_line($ser, "BDA-2: ERROR. No baseline owner_mosyle_id available.");
                    save_state($ser, $state);
                    $perSerialStates[$ser] = $state;
                    continue;
                }

                $assignPayload = [
                    'id'            => $origMosyleId,
                    'serial_number' => $ser,
                ];

                $payload = [
                    'serial'    => $ser,
                    'udid'      => $state['baseline']['udid'] ?? 'UNKNOWN',
                    'submitter' => $submitterUser,
                    'mosbasic'  => 'ASSIGNME',
                    'priority'  => 5,
                    'extra1'    => json_encode($assignPayload, JSON_UNESCAPED_SLASHES),
                ];

                $resp = nora_api_post_json('/errand/create', $payload);
                $errId = $resp['errand_id'] ?? null;

                if ($errId) {
                    $state['reassign']['errand_id'] = $errId;
                    $state['reassign']['status']    = 'submitted';
                    $state['stage']                 = 'assign_pending';
                    log_line($ser, "BDA-2: ASSIGNME submitted back to {$origMosyleId} – ErrandID={$errId}.");
                } else {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = 'Failed to create ASSIGNME errand.';
                    log_line($ser, "BDA-2: ERROR. Failed to submit ASSIGNME.");
                }
            } else {
                $remaining = $REASSIGN_WAIT - $elapsed;
                log_line($ser, "BDA-2: Waiting {$remaining}s before ASSIGNME.");
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: assign_pending
        // ------------------------------------------------------------
        else if ($stage === 'assign_pending') {
            if ($state['mode'] === 'reassign' && !empty($state['reassign']['errand_id'])) {
                $errId = $state['reassign']['errand_id'];

                $resp = nora_api_get("/errand/status/{$errId}");
                if ($resp && !empty($resp['Status'])) {
                    $status = $resp['Status'];
                    $state['reassign']['status'] = $status;

                    if ($status === 'Complete') {
                        log_line($ser, "BDA-2: ASSIGNME completed successfully.");
                        $state['stage'] = 'tags_pending';
                    }
                    elseif (in_array($status, ['Failed','Cancelled','Rejected','Unknown'], true)) {
                        $state['stage'] = 'failed';
                        $state['failure_reason'] = "ASSIGNME ended with status={$status}.";
                        log_line($ser, "BDA-2: ERROR. ASSIGNME ended with status={$status}.");
                    } else {
                        log_line($ser, "BDA-2: ASSIGNME status={$status} (waiting).");
                    }
                } else {
                    log_line($ser, "BDA-2: WARNING. Could not read ASSIGNME status.");
                }

                save_state($ser, $state);
            }
            else {
                // STORAGE mode or skipped reassign
                $state['stage'] = 'tags_pending';
                save_state($ser, $state);
            }
        }

        // ------------------------------------------------------------
        // STAGE: tags_pending (BDA-3)
        // ------------------------------------------------------------
        else if ($stage === 'tags_pending') {
            $baselineTags = $state['baseline']['tags'] ?? null;

            if ($state['mode'] === 'no_reassign') {
                // STORAGE: clear tags
                $tagPayload = [
                    'serial' => $ser,
                    'tags'   => ' ',
                ];
            } else {
                // Reassign: restore baseline tags
                if (!$baselineTags || $baselineTags === '[]') {
                    log_line($ser, "BDA-3: No baseline tags to restore. Workflow complete.");
                    $state['stage'] = 'complete';
                    save_state($ser, $state);
                    $perSerialStates[$ser] = $state;
                    continue;
                }

                $tagPayload = [
                    'serial' => $ser,
                    'tags'   => $baselineTags,
                ];
            }

            $payload = [
                'serial'    => $ser,
                'udid'      => $state['baseline']['udid'] ?? 'UNKNOWN',
                'submitter' => $submitterUser,
                'mosbasic'  => 'SETMETAGS',
                'priority'  => 3,
                'extra1'    => json_encode($tagPayload, JSON_UNESCAPED_SLASHES),
            ];

            $resp = nora_api_post_json('/errand/create', $payload);
            $errId = $resp['errand_id'] ?? null;

            if ($errId) {
                $state['tags_restore']['errand_id'] = $errId;
                $state['tags_restore']['status']    = 'submitted';
                $state['stage']                     = 'tags_wait';
                log_line($ser, "BDA-3: SETMETAGS submitted – ErrandID={$errId}.");
            } else {
                $state['stage'] = 'failed';
                $state['failure_reason'] = 'Failed to submit SETMETAGS.';
                log_line($ser, "BDA-3: ERROR. Failed to submit SETMETAGS.");
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: tags_wait
        // ------------------------------------------------------------
        else if ($stage === 'tags_wait') {
            $errId = $state['tags_restore']['errand_id'] ?? null;

            if (!$errId) {
                $state['stage'] = 'failed';
                $state['failure_reason'] = 'Missing SETMETAGS errand ID.';
                log_line($ser, "BDA-3: ERROR. Missing SETMETAGS errand ID.");
                save_state($ser, $state);
                $perSerialStates[$ser] = $state;
                continue;
            }

            $resp = nora_api_get("/errand/status/{$errId}");
            if ($resp && !empty($resp['Status'])) {
                $status = $resp['Status'];
                $state['tags_restore']['status'] = $status;

                if ($status === 'Complete') {
                    log_line($ser, "BDA-3: SETMETAGS completed. Workflow COMPLETE.");
                    $state['stage'] = 'complete';
                }
                elseif (in_array($status, ['Failed','Cancelled','Rejected','Unknown'], true)) {
                    $state['stage'] = 'failed';
                    $state['failure_reason'] = "SETMETAGS ended with status={$status}.";
                    log_line($ser, "BDA-3: ERROR. SETMETAGS ended with status={$status}.");
                } else {
                    log_line($ser, "BDA-3: SETMETAGS status={$status} (waiting).");
                }
            }

            save_state($ser, $state);
        }

        // ------------------------------------------------------------
        // STAGE: complete / failed
        // ------------------------------------------------------------
        else if (in_array($stage, ['complete','failed'], true)) {
            // terminal states — nothing to advance
        }

        $perSerialStates[$ser] = $state;
    }

    return [
        'serials'         => $serials,
        'perSerialStates' => $perSerialStates,
        'needsRefresh'    => $needsRefresh,
        'submitterUser'   => $submitterUser,
        'now'             => $now,
        'noReassign'      => $noReassign,
    ];
}
// ============================================================================
// END OF FILE
// ============================================================================
?>
