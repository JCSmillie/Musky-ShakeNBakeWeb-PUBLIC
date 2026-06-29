<?php

function handleUserRoutes($route, $method)
{
    header('Content-Type: application/json');

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        return;
    }

    // Expected route formats:
    // user/{email}/devices
    // user/{numeric-or-configured-user-id}/devices
    $parts = explode('/', $route);

    if (count($parts) < 3 || $parts[2] !== 'devices') {
        http_response_code(404);
        echo json_encode(["error" => "Invalid user route"]);
        return;
    }

    $identifier = trim(urldecode($parts[1]));

    global $pdo;
    global $auth;
    global $deprecatedCall;

    try {

        /*
        --------------------------------------------------
        1️⃣ CHECK IF USER EXISTS
        --------------------------------------------------
        */
        $selectColumns = ['id', 'full_name', 'email', 'user_type', 'grade'];
        $lookupColumns = [];
        foreach (['mosyle_id', 'user_id', 'username', 'district_id', 'student_id', 'employee_id'] as $column) {
            if (function_exists('nora_api_column_exists') && nora_api_column_exists($pdo, 'owners', $column)) {
                $selectColumns[] = $column;
                $lookupColumns[] = $column;
            }
        }

        $where = [];
        $params = [];
        $lookupType = 'unknown';
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $where[] = 'LOWER(email) = LOWER(?)';
            $params[] = $identifier;
            $lookupType = 'email';
        } elseif (ctype_digit($identifier)) {
            $where[] = 'id = ?';
            $params[] = (int)$identifier;
            $lookupType = 'id';
        }

        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            foreach ($lookupColumns as $column) {
                $where[] = 'LOWER(CAST(' . $column . ' AS CHAR)) = LOWER(?)';
                $params[] = $identifier;
                $lookupType = $lookupType === 'unknown' ? 'configured_user_id' : $lookupType;
            }
        }

        if (!$where) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid user identifier"]);
            return;
        }

        $stmtUser = $pdo->prepare("
            SELECT " . implode(', ', $selectColumns) . "
            FROM owners
            WHERE " . implode(' OR ', $where) . "
            LIMIT 2
        ");
        $stmtUser->execute($params);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $resp = [
                "exists" => false,
                "identifier_type" => $lookupType,
                "NeedsIPad" => true,
                "active_count" => 0,
                "former_count" => 0,
                "active_devices" => [],
                "former_devices" => []
            ];
            if ($lookupType === 'email') {
                $resp["email"] = $identifier;
            }
            if (function_exists('log_api')) {
                log_api($pdo, $auth['client_id'] ?? null, '/user/devices', 200, ['identifier_type' => $lookupType], $resp, $deprecatedCall ?? false);
            }
            echo json_encode($resp, JSON_PRETTY_PRINT);
            return;
        }

        $secondUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($secondUser) {
            http_response_code(409);
            echo json_encode(["error" => "Ambiguous user identifier"]);
            return;
        }


        /*
        --------------------------------------------------
        2️⃣ ELIGIBLE (ACTIVE) DEVICES
        - Assigned to user
        - NO BROKEN / STOLEN / RETIRED-* tags
        --------------------------------------------------
        */
        $stmtActive = $pdo->prepare("
            SELECT
                d.id,
                d.serial_number,
                d.asset_tag,
                d.model,
                d.device_model,
                d.os_version,
                d.last_seen
            FROM devices d
            JOIN owners o ON d.owner_id = o.id
            LEFT JOIN device_tags t
                ON t.device_id = d.id
                AND (
                    t.tag = 'BROKEN'
                    OR t.tag = 'STOLEN'
                    OR t.tag LIKE 'RETIRED-%'
                )
            WHERE o.id = ?
            AND t.device_id IS NULL
            ORDER BY d.last_seen DESC
        ");

        $stmtActive->execute([(int)$user['id']]);
        $activeDevices = $stmtActive->fetchAll(PDO::FETCH_ASSOC);

        $activeSerials = array_column($activeDevices, 'serial_number');


        /*
        --------------------------------------------------
        3️⃣ FORMER DEVICES
        - From device_history
        - Exclude currently eligible serials
        - Return latest snapshot per serial
        --------------------------------------------------
        */
        if (!empty($activeSerials)) {
            $placeholders = implode(',', array_fill(0, count($activeSerials), '?'));

            $stmtFormer = $pdo->prepare("
                SELECT dh.*
                FROM device_history dh
                INNER JOIN (
                    SELECT serial_number, MAX(snapshot_time) AS snapshot_time
                    FROM device_history FORCE INDEX (idx_hist_owneremail_serial_snapshot)
                    WHERE owner_email = ?
                    GROUP BY serial_number
                ) latest
                  ON latest.serial_number = dh.serial_number
                 AND latest.snapshot_time = dh.snapshot_time
                WHERE dh.owner_email = ?
                  AND dh.serial_number NOT IN ($placeholders)
                ORDER BY dh.snapshot_time DESC
            ");

            $params = array_merge([$user['email'], $user['email']], $activeSerials);
            $stmtFormer->execute($params);

        } else {

            $stmtFormer = $pdo->prepare("
                SELECT dh.*
                FROM device_history dh
                INNER JOIN (
                    SELECT serial_number, MAX(snapshot_time) AS snapshot_time
                    FROM device_history FORCE INDEX (idx_hist_owneremail_serial_snapshot)
                    WHERE owner_email = ?
                    GROUP BY serial_number
                ) latest
                  ON latest.serial_number = dh.serial_number
                 AND latest.snapshot_time = dh.snapshot_time
                WHERE dh.owner_email = ?
                ORDER BY dh.snapshot_time DESC
            ");

            $stmtFormer->execute([$user['email'], $user['email']]);
        }

        $formerDevices = $stmtFormer->fetchAll(PDO::FETCH_ASSOC);


        /*
        --------------------------------------------------
        4️⃣ NeedsIPad LOGIC
        --------------------------------------------------
        */
        $NeedsIPad = (count($activeDevices) === 0);


        /*
        --------------------------------------------------
        5️⃣ FINAL RESPONSE
        --------------------------------------------------
        */
        $resp = [
            "exists" => true,
            "identifier_type" => $lookupType,
            "user" => $user,
            "NeedsIPad" => $NeedsIPad,
            "active_count" => count($activeDevices),
            "former_count" => count($formerDevices),
            "active_devices" => $activeDevices,
            "former_devices" => $formerDevices
        ];

        if (function_exists('log_api')) {
            log_api($pdo, $auth['client_id'] ?? null, '/user/devices', 200, ['identifier_type' => $lookupType], $resp, $deprecatedCall ?? false);
        }

        echo json_encode($resp, JSON_PRETTY_PRINT);


    } catch (Exception $e) {

        http_response_code(500);
        echo json_encode([
            "error" => "Server error"
        ]);
    }
}
