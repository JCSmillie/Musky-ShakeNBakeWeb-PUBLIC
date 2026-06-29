<?php
declare(strict_types=1);

require_once __DIR__ . '/Musky_API_Helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function musky_new_hardware_now(): string
{
    return date('Y-m-d H:i:s');
}

function musky_new_hardware_actor_email(): string
{
    return trim((string)(
        $_SESSION['musky_user']['email']
        ?? $_SESSION['username']
        ?? $_SERVER['REMOTE_USER']
        ?? ''
    ));
}

function musky_new_hardware_clean_text(string $value, int $maxLength = 255): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        return '';
    }

    if (strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

function musky_new_hardware_serial_token(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    return $value;
}

function musky_new_hardware_match_key(string $value): string
{
    $value = musky_new_hardware_serial_token($value);
    if ($value !== '' && strlen($value) > 6 && str_starts_with($value, 'S')) {
        $value = substr($value, 1);
    }
    return $value;
}

function musky_new_hardware_valid_serial_key(string $value): bool
{
    if ($value === '') {
        return false;
    }

    if (strlen($value) < 6 || strlen($value) > 32) {
        return false;
    }

    if (preg_match('/\A[A-Z0-9]+\z/', $value) !== 1) {
        return false;
    }

    return true;
}

function musky_new_hardware_clean_asset_tag(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9_-]/', '', $value) ?? '';
    return musky_new_hardware_clean_text($value, 128);
}

function musky_new_hardware_valid_asset_tag(string $value): bool
{
    return $value !== ''
        && strlen($value) <= 128
        && preg_match('/\A[A-Z0-9_-]+\z/', $value) === 1;
}

function musky_new_hardware_extract_serial_candidates(string $source): array
{
    $tokens = preg_split('/[\s,;|]+/', strtoupper($source)) ?: [];
    $items = [];
    $seen = [];
    $validCount = 0;
    $invalidCount = 0;

    foreach ($tokens as $token) {
        $raw = musky_new_hardware_clean_text($token, 128);
        if ($raw === '') {
            continue;
        }

        $matchKey = musky_new_hardware_match_key($raw);
        if (!musky_new_hardware_valid_serial_key($matchKey)) {
            $invalidCount++;
            continue;
        }

        $validCount++;
        if (isset($seen[$matchKey])) {
            continue;
        }

        $seen[$matchKey] = true;
        $items[] = [
            'raw' => $raw,
            'match_key' => $matchKey,
        ];
    }

    return [
        'items' => $items,
        'valid_count' => $validCount,
        'unique_count' => count($items),
        'duplicate_count' => max(0, $validCount - count($items)),
        'invalid_count' => $invalidCount,
    ];
}

function musky_new_hardware_strip_utf8_bom(string $value): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
}

function musky_new_hardware_csv_delimiter_for_file(string $filename, string $firstLine): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'tsv') {
        return "\t";
    }

    $tabCount = substr_count($firstLine, "\t");
    $commaCount = substr_count($firstLine, ',');
    return $tabCount > $commaCount ? "\t" : ',';
}

function musky_new_hardware_parse_serial_table_file(string $tmpPath, string $filename): array
{
    $handle = @fopen($tmpPath, 'rb');
    if (!$handle) {
        return [
            'ok' => false,
            'message' => 'The uploaded serial file could not be opened.',
        ];
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        return [
            'ok' => false,
            'message' => 'The uploaded serial file was empty.',
        ];
    }

    rewind($handle);
    $delimiter = musky_new_hardware_csv_delimiter_for_file($filename, (string)$firstLine);
    $header = fgetcsv($handle, 0, $delimiter);
    if (!is_array($header)) {
        fclose($handle);
        return [
            'ok' => false,
            'message' => 'The uploaded CSV file did not contain a readable header row.',
        ];
    }

    $normalizedHeader = array_map(static function ($value): string {
        $value = musky_new_hardware_strip_utf8_bom((string)$value);
        $value = strtoupper(trim($value));
        return preg_replace('/[^A-Z0-9_]/', '', $value) ?? '';
    }, $header);

    $serialIndex = null;
    foreach ($normalizedHeader as $index => $columnName) {
        if (in_array($columnName, ['SERIAL', 'SERIAL_NO'], true)) {
            $serialIndex = $index;
            break;
        }
    }

    if ($serialIndex === null) {
        fclose($handle);
        return [
            'ok' => false,
            'message' => 'CSV uploads need a SERIAL or SERIAL_NO column.',
        ];
    }

    $serials = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (!is_array($row)) {
            continue;
        }

        $value = musky_new_hardware_clean_text((string)($row[$serialIndex] ?? ''), 128);
        if ($value !== '') {
            $serials[] = $value;
        }
    }
    fclose($handle);

    if (!$serials) {
        return [
            'ok' => false,
            'message' => 'The CSV file did not contain any serial values in SERIAL or SERIAL_NO.',
        ];
    }

    return [
        'ok' => true,
        'serial_blob' => implode("\n", $serials),
        'row_count' => count($serials),
        'source_kind' => $delimiter === "\t" ? 'tsv' : 'csv',
    ];
}

function musky_new_hardware_parse_uploaded_serial_file(array $file): array
{
    $tmpPath = trim((string)($file['tmp_name'] ?? ''));
    $filename = musky_new_hardware_clean_text((string)($file['name'] ?? ''), 255);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return [
            'ok' => false,
            'message' => 'No uploaded serial file was available to read.',
        ];
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['csv', 'tsv'], true)) {
        return musky_new_hardware_parse_serial_table_file($tmpPath, $filename);
    }

    if (in_array($ext, ['txt', 'log', ''], true)) {
        $contents = @file_get_contents($tmpPath);
        if (!is_string($contents) || trim($contents) === '') {
            return [
                'ok' => false,
                'message' => 'The uploaded text file was empty.',
            ];
        }

        return [
            'ok' => true,
            'serial_blob' => musky_new_hardware_strip_utf8_bom($contents),
            'row_count' => substr_count(trim($contents), "\n") + 1,
            'source_kind' => 'text',
        ];
    }

    return [
        'ok' => false,
        'message' => 'Unsupported file type. Use TXT for one serial per line, or CSV with SERIAL or SERIAL_NO.',
    ];
}

function musky_new_hardware_device_normalized_serial_sql(string $column = 'serial_number'): string
{
    $clean = "REPLACE(REPLACE(UPPER(TRIM({$column})), '-', ''), ' ', '')";
    return "
        CASE
            WHEN {$column} IS NULL THEN ''
            WHEN LEFT({$clean}, 1) = 'S' AND CHAR_LENGTH({$clean}) > 6
                THEN SUBSTRING({$clean}, 2)
            ELSE {$clean}
        END
    ";
}

function musky_new_hardware_existing_device_map(PDO $pdo, array $matchKeys): array
{
    $keys = [];
    foreach ($matchKeys as $key) {
        $normalized = musky_new_hardware_match_key((string)$key);
        if (!musky_new_hardware_valid_serial_key($normalized)) {
            continue;
        }
        $keys[$normalized] = true;
    }

    $keys = array_keys($keys);
    if (!$keys) {
        return [];
    }

    $map = [];
    $expr = musky_new_hardware_device_normalized_serial_sql('serial_number');

    foreach (array_chunk($keys, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "
            SELECT id, serial_number, {$expr} AS match_key
              FROM devices
             WHERE {$expr} IN ({$placeholders})
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($chunk);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $matchKey = musky_new_hardware_match_key((string)($row['match_key'] ?? $row['serial_number'] ?? ''));
            if ($matchKey === '') {
                continue;
            }

            $map[$matchKey] = [
                'id' => (int)($row['id'] ?? 0),
                'serial_number' => (string)($row['serial_number'] ?? ''),
            ];
        }
    }

    return $map;
}

function musky_new_hardware_existing_unit_map(PDO $pdo, array $matchKeys): array
{
    musky_new_hardware_ensure_schema($pdo);

    $keys = [];
    foreach ($matchKeys as $key) {
        $normalized = musky_new_hardware_match_key((string)$key);
        if (!musky_new_hardware_valid_serial_key($normalized)) {
            continue;
        }
        $keys[$normalized] = true;
    }

    $keys = array_keys($keys);
    if (!$keys) {
        return [];
    }

    $map = [];
    foreach (array_chunk($keys, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "
            SELECT id, batch_id, serial_import_raw, serial_match_key, status
              FROM musky_new_hardware_units
             WHERE serial_match_key IN ({$placeholders})
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($chunk);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $matchKey = musky_new_hardware_match_key((string)($row['serial_match_key'] ?? ''));
            if ($matchKey === '') {
                continue;
            }

            $map[$matchKey] = [
                'id' => (int)($row['id'] ?? 0),
                'batch_id' => (int)($row['batch_id'] ?? 0),
                'serial_import_raw' => (string)($row['serial_import_raw'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
            ];
        }
    }

    return $map;
}

function musky_new_hardware_serial_exists_in_devices(PDO $pdo, string $matchKey): ?array
{
    $map = musky_new_hardware_existing_device_map($pdo, [$matchKey]);
    return $map[$matchKey] ?? null;
}

function musky_new_hardware_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_new_hardware_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_label VARCHAR(128) NOT NULL,
            source_filename VARCHAR(255) DEFAULT NULL,
            source_note TEXT DEFAULT NULL,
            uploaded_by VARCHAR(255) NOT NULL,
            raw_count INT NOT NULL DEFAULT 0,
            accepted_count INT NOT NULL DEFAULT 0,
            duplicate_count INT NOT NULL DEFAULT 0,
            status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mnh_batches_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_new_hardware_units (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_id BIGINT UNSIGNED NOT NULL,
            serial_import_raw VARCHAR(128) NOT NULL,
            serial_match_key VARCHAR(64) NOT NULL,
            status ENUM('AVAILABLE','IN_PROGRESS','COMPLETED') NOT NULL DEFAULT 'AVAILABLE',
            claimed_by VARCHAR(255) DEFAULT NULL,
            claim_session_id VARCHAR(128) DEFAULT NULL,
            claim_started_at DATETIME DEFAULT NULL,
            lookup_serial_raw VARCHAR(128) DEFAULT NULL,
            lookup_serial_key VARCHAR(64) DEFAULT NULL,
            asset_tag VARCHAR(128) DEFAULT NULL,
            assignment_input VARCHAR(255) DEFAULT NULL,
            owner_id BIGINT DEFAULT NULL,
            owner_email VARCHAR(255) DEFAULT NULL,
            owner_name VARCHAR(255) DEFAULT NULL,
            owner_user_type VARCHAR(64) DEFAULT NULL,
            owner_grade VARCHAR(32) DEFAULT NULL,
            owner_district_id VARCHAR(64) DEFAULT NULL,
            owner_status VARCHAR(32) DEFAULT NULL,
            owner_last_seen DATETIME DEFAULT NULL,
            usercheck_errand_id INT DEFAULT NULL,
            iiqsync_errand_id INT DEFAULT NULL,
            completed_by VARCHAR(255) DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            last_note TEXT DEFAULT NULL,
            extra_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mnh_units_match (serial_match_key),
            KEY idx_mnh_units_batch (batch_id),
            KEY idx_mnh_units_status (status),
            KEY idx_mnh_units_claimed (claimed_by),
            KEY idx_mnh_units_completed (completed_at),
            CONSTRAINT fk_mnh_units_batch
                FOREIGN KEY (batch_id) REFERENCES musky_new_hardware_batches (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS musky_new_hardware_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            unit_id BIGINT UNSIGNED DEFAULT NULL,
            batch_id BIGINT UNSIGNED DEFAULT NULL,
            actor_email VARCHAR(255) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_note TEXT DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mnh_events_unit (unit_id),
            KEY idx_mnh_events_batch (batch_id),
            KEY idx_mnh_events_created (created_at),
            CONSTRAINT fk_mnh_events_unit
                FOREIGN KEY (unit_id) REFERENCES musky_new_hardware_units (id)
                ON DELETE SET NULL,
            CONSTRAINT fk_mnh_events_batch
                FOREIGN KEY (batch_id) REFERENCES musky_new_hardware_batches (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ready = true;
}

function musky_new_hardware_log_event(
    PDO $pdo,
    ?int $unitId,
    ?int $batchId,
    string $actorEmail,
    string $eventType,
    string $eventNote = '',
    array $payload = []
): void {
    musky_new_hardware_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO musky_new_hardware_events (
            unit_id,
            batch_id,
            actor_email,
            event_type,
            event_note,
            payload_json,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $payloadJson = $payload
        ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;

    $stmt->execute([
        $unitId,
        $batchId,
        musky_new_hardware_clean_text($actorEmail, 255),
        musky_new_hardware_clean_text($eventType, 64),
        $eventNote !== '' ? $eventNote : null,
        $payloadJson,
        musky_new_hardware_now(),
    ]);
}

function musky_new_hardware_create_batch(
    PDO $pdo,
    string $batchLabel,
    string $serialSource,
    string $sourceFilename,
    string $sourceNote,
    string $actorEmail
): array {
    musky_new_hardware_ensure_schema($pdo);

    $parsed = musky_new_hardware_extract_serial_candidates($serialSource);
    $items = $parsed['items'];
    if (!$items) {
        return [
            'ok' => false,
            'message' => 'No usable serial numbers were found in the upload.',
        ];
    }

    $batchLabel = musky_new_hardware_clean_text($batchLabel, 128);
    if ($batchLabel === '') {
        $batchLabel = 'New Hardware Batch ' . date('Y-m-d H:i');
    }

    $sourceFilename = musky_new_hardware_clean_text($sourceFilename, 255);
    $sourceNote = musky_new_hardware_clean_text($sourceNote, 1000);
    $actorEmail = musky_new_hardware_clean_text($actorEmail, 255);
    $fileDuplicateCount = (int)($parsed['duplicate_count'] ?? 0);
    $invalidCount = (int)($parsed['invalid_count'] ?? 0);
    $existingDeviceMap = musky_new_hardware_existing_device_map(
        $pdo,
        array_column($items, 'match_key')
    );
    $existingUnitMap = musky_new_hardware_existing_unit_map(
        $pdo,
        array_column($items, 'match_key')
    );

    $pdo->beginTransaction();

    try {
        $insertBatch = $pdo->prepare("
            INSERT INTO musky_new_hardware_batches (
                batch_label,
                source_filename,
                source_note,
                uploaded_by,
                raw_count,
                accepted_count,
                duplicate_count,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)
        ");
        $now = musky_new_hardware_now();
        $insertBatch->execute([
            $batchLabel,
            $sourceFilename !== '' ? $sourceFilename : null,
            $sourceNote !== '' ? $sourceNote : null,
            $actorEmail !== '' ? $actorEmail : 'unknown',
            $parsed['valid_count'],
            $now,
            $now,
        ]);

        $batchId = (int)$pdo->lastInsertId();
        $insertUnit = $pdo->prepare("
            INSERT IGNORE INTO musky_new_hardware_units (
                batch_id,
                serial_import_raw,
                serial_match_key,
                status,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, 'AVAILABLE', ?, ?)
        ");

        $inserted = 0;
        $existingDeviceCount = 0;
        $alreadyQueuedCount = 0;
        foreach ($items as $item) {
            if (isset($existingDeviceMap[$item['match_key']])) {
                $existingDeviceCount++;
            }

            if (isset($existingUnitMap[$item['match_key']])) {
                $alreadyQueuedCount++;
                continue;
            }

            $insertUnit->execute([
                $batchId,
                $item['raw'],
                $item['match_key'],
                $now,
                $now,
            ]);
            if ($insertUnit->rowCount() > 0) {
                $inserted++;
            } else {
                $alreadyQueuedCount++;
            }
        }

        $updateBatch = $pdo->prepare("
            UPDATE musky_new_hardware_batches
               SET accepted_count = ?,
                   duplicate_count = ?,
                   updated_at = ?
             WHERE id = ?
        ");
        $updateBatch->execute([$inserted, $fileDuplicateCount, $now, $batchId]);

        musky_new_hardware_log_event(
            $pdo,
            null,
            $batchId,
            $actorEmail !== '' ? $actorEmail : 'unknown',
            'BATCH_UPLOAD',
            "Uploaded {$inserted} new hardware serial(s).",
            [
                'raw_count' => $parsed['valid_count'],
                'unique_count' => $parsed['unique_count'],
                'accepted_count' => $inserted,
                'duplicate_count' => $fileDuplicateCount,
                'already_queued_count' => $alreadyQueuedCount,
                'invalid_count' => $invalidCount,
                'existing_device_count' => $existingDeviceCount,
                'source_filename' => $sourceFilename,
            ]
        );

        $pdo->commit();

        return [
            'ok' => true,
            'batch_id' => $batchId,
            'accepted_count' => $inserted,
            'duplicate_count' => $fileDuplicateCount,
            'already_queued_count' => $alreadyQueuedCount,
            'invalid_count' => $invalidCount,
            'existing_device_count' => $existingDeviceCount,
            'message' => "Imported {$inserted} serial(s)."
                . ($fileDuplicateCount > 0 ? " {$fileDuplicateCount} duplicate(s) were repeated inside the upload." : '')
                . ($alreadyQueuedCount > 0 ? " {$alreadyQueuedCount} serial(s) were already in the New Hardware queue." : '')
                . ($invalidCount > 0 ? " {$invalidCount} serial(s) were skipped because they did not match the allowed serial format." : '')
                . ($existingDeviceCount > 0 ? " {$existingDeviceCount} already existed in Nora devices." : ''),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[NewHardware] batch import failed: ' . $e->getMessage());
        return [
            'ok' => false,
            'message' => 'The upload could not be saved right now.',
        ];
    }
}

function musky_new_hardware_fetch_batches(PDO $pdo, int $limit = 12): array
{
    musky_new_hardware_ensure_schema($pdo);
    $limit = max(1, min(50, $limit));

    $stmt = $pdo->prepare("
        SELECT id, batch_label, source_filename, source_note, uploaded_by,
               raw_count, accepted_count, duplicate_count, status,
               created_at, updated_at
          FROM musky_new_hardware_batches
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function musky_new_hardware_fetch_counts(PDO $pdo): array
{
    musky_new_hardware_ensure_schema($pdo);

    $rows = $pdo->query("
        SELECT serial_match_key, status
          FROM musky_new_hardware_units
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $existingMap = musky_new_hardware_existing_device_map(
        $pdo,
        array_column($rows, 'serial_match_key')
    );

    $counts = [
        'total' => 0,
        'available' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'already_in_nora' => 0,
    ];

    foreach ($rows as $row) {
        $counts['total']++;
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        $key = musky_new_hardware_match_key((string)($row['serial_match_key'] ?? ''));
        $exists = $key !== '' && isset($existingMap[$key]);

        if ($status === 'COMPLETED') {
            $counts['completed']++;
            continue;
        }

        if ($exists) {
            $counts['already_in_nora']++;
            continue;
        }

        if ($status === 'IN_PROGRESS') {
            $counts['in_progress']++;
        } else {
            $counts['available']++;
        }
    }

    return [
        'total' => (int)$counts['total'],
        'available' => (int)$counts['available'],
        'in_progress' => (int)$counts['in_progress'],
        'completed' => (int)$counts['completed'],
        'already_in_nora' => (int)$counts['already_in_nora'],
    ];
}

function musky_new_hardware_fetch_units(PDO $pdo, string $status = 'ALL', string $query = '', int $limit = 300): array
{
    musky_new_hardware_ensure_schema($pdo);

    $status = strtoupper(trim($status));
    $query = musky_new_hardware_clean_text($query, 128);
    $limit = max(10, min(500, $limit));

    $where = [];
    $params = [];

    if (in_array($status, ['AVAILABLE', 'IN_PROGRESS', 'COMPLETED'], true)) {
        $where[] = 'u.status = ?';
        $params[] = $status;
    }

    if ($query !== '') {
        $needle = '%' . $query . '%';
        $where[] = '(
            u.serial_match_key LIKE ?
            OR COALESCE(u.asset_tag, \'\') LIKE ?
            OR COALESCE(u.claimed_by, \'\') LIKE ?
            OR COALESCE(u.completed_by, \'\') LIKE ?
            OR COALESCE(u.owner_email, \'\') LIKE ?
            OR COALESCE(u.owner_name, \'\') LIKE ?
            OR COALESCE(u.assignment_input, \'\') LIKE ?
            OR COALESCE(b.batch_label, \'\') LIKE ?
        )';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $needle;
        }
    }

    $sql = "
        SELECT
            u.id,
            u.batch_id,
            b.batch_label,
            u.serial_import_raw,
            u.serial_match_key,
            u.status,
            u.claimed_by,
            u.claim_session_id,
            u.claim_started_at,
            u.lookup_serial_raw,
            u.asset_tag,
            u.assignment_input,
            u.owner_id,
            u.owner_email,
            u.owner_name,
            u.owner_user_type,
            u.owner_grade,
            u.owner_district_id,
            u.owner_status,
            u.usercheck_errand_id,
            u.iiqsync_errand_id,
            u.completed_by,
            u.completed_at,
            u.last_note,
            u.created_at,
            u.updated_at
        FROM musky_new_hardware_units u
        INNER JOIN musky_new_hardware_batches b
                ON b.id = u.batch_id
    ";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= "
        ORDER BY
            FIELD(u.status, 'IN_PROGRESS', 'AVAILABLE', 'COMPLETED'),
            COALESCE(u.claim_started_at, u.completed_at, u.created_at) DESC,
            u.id DESC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return musky_new_hardware_decorate_units($pdo, $rows, $status);
}

function musky_new_hardware_decorate_units(PDO $pdo, array $rows, string $statusFilter = 'ALL'): array
{
    if (!$rows) {
        return [];
    }

    $existingMap = musky_new_hardware_existing_device_map(
        $pdo,
        array_column($rows, 'serial_match_key')
    );

    $statusFilter = strtoupper(trim($statusFilter));
    $decorated = [];

    foreach ($rows as $row) {
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        $key = musky_new_hardware_match_key((string)($row['serial_match_key'] ?? ''));
        $existing = $key !== '' ? ($existingMap[$key] ?? null) : null;
        $alreadyInNora = $existing !== null && $status !== 'COMPLETED';

        $row['device_exists_in_nora'] = $existing ? 1 : 0;
        $row['device_exists_serial'] = $existing['serial_number'] ?? null;
        $row['device_exists_device_id'] = $existing['id'] ?? null;
        $row['actionable'] = $alreadyInNora ? 0 : 1;
        $row['workflow_state'] = $alreadyInNora ? 'ALREADY_IN_NORA' : $status;
        $row['workflow_note'] = $alreadyInNora
            ? 'Already exists in Nora devices. No new-hardware intake is needed.'
            : ($row['last_note'] ?? '');

        if ($statusFilter === 'ALREADY_IN_NORA' && !$alreadyInNora) {
            continue;
        }

        if (in_array($statusFilter, ['AVAILABLE', 'IN_PROGRESS'], true) && $alreadyInNora) {
            continue;
        }

        $decorated[] = $row;
    }

    return $decorated;
}

function musky_new_hardware_fetch_unit_by_id(PDO $pdo, int $unitId, bool $forUpdate = false): ?array
{
    musky_new_hardware_ensure_schema($pdo);

    $sql = "
        SELECT u.*, b.batch_label
          FROM musky_new_hardware_units u
          INNER JOIN musky_new_hardware_batches b
                  ON b.id = u.batch_id
         WHERE u.id = ?
         LIMIT 1
    ";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function musky_new_hardware_fetch_active_claim_for_actor(PDO $pdo, string $actorEmail, string $sessionId = ''): ?array
{
    musky_new_hardware_ensure_schema($pdo);
    $actorEmail = musky_new_hardware_clean_text($actorEmail, 255);
    $sessionId = musky_new_hardware_clean_text($sessionId, 128);
    if ($actorEmail === '') {
        return null;
    }

    $deviceExpr = musky_new_hardware_device_normalized_serial_sql('d.serial_number');

    $stmt = $pdo->prepare("
        SELECT u.*, b.batch_label
          FROM musky_new_hardware_units u
          INNER JOIN musky_new_hardware_batches b
                  ON b.id = u.batch_id
         WHERE u.status = 'IN_PROGRESS'
           AND u.claimed_by = ?
           AND NOT EXISTS (
                SELECT 1
                  FROM devices d
                 WHERE {$deviceExpr} = u.serial_match_key
                 LIMIT 1
           )
         ORDER BY
             CASE WHEN u.claim_session_id = ? THEN 0 ELSE 1 END,
             COALESCE(u.claim_started_at, u.updated_at, u.created_at) DESC,
             u.id DESC
         LIMIT 1
    ");
    $stmt->execute([$actorEmail, $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function musky_new_hardware_begin_claim(PDO $pdo, string $scanRaw, string $actorEmail, string $sessionId): array
{
    musky_new_hardware_ensure_schema($pdo);

    $scanRaw = musky_new_hardware_clean_text($scanRaw, 128);
    $matchKey = musky_new_hardware_match_key($scanRaw);
    $actorEmail = musky_new_hardware_clean_text($actorEmail, 255);
    $sessionId = musky_new_hardware_clean_text($sessionId, 128);

    if (!musky_new_hardware_valid_serial_key($matchKey)) {
        return ['ok' => false, 'message' => 'That serial scan did not look usable.'];
    }

    $existing = musky_new_hardware_fetch_active_claim_for_actor($pdo, $actorEmail, $sessionId);
    if ($existing && (int)$existing['id'] !== 0 && $existing['serial_match_key'] !== $matchKey) {
        return [
            'ok' => false,
            'message' => 'Finish or release your current machine before scanning a new one.',
            'unit' => $existing,
        ];
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT u.*, b.batch_label
              FROM musky_new_hardware_units u
              INNER JOIN musky_new_hardware_batches b
                      ON b.id = u.batch_id
             WHERE u.serial_match_key = ?
             LIMIT 1
             FOR UPDATE
        ");
        $stmt->execute([$matchKey]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unit)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That serial is not in the new hardware list.'];
        }

        $existingDevice = musky_new_hardware_serial_exists_in_devices($pdo, $matchKey);
        if ($existingDevice) {
            $note = 'Skipped because this serial already exists in Nora devices.';
            $updateExisting = $pdo->prepare("
                UPDATE musky_new_hardware_units
                   SET last_note = ?,
                       updated_at = ?
                 WHERE id = ?
            ");
            $updateExisting->execute([
                $note,
                musky_new_hardware_now(),
                (int)$unit['id'],
            ]);

            $pdo->commit();
            return [
                'ok' => false,
                'message' => 'That serial already exists in Nora devices, so it cannot be processed through New Hardware Intake.',
            ];
        }

        $status = strtoupper(trim((string)($unit['status'] ?? '')));
        $claimedBy = trim((string)($unit['claimed_by'] ?? ''));

        if ($status === 'COMPLETED') {
            $pdo->rollBack();
            return [
                'ok' => false,
                'message' => 'That machine has already been completed.',
                'unit' => $unit,
            ];
        }

        if ($status === 'IN_PROGRESS' && $claimedBy !== '' && strcasecmp($claimedBy, $actorEmail) !== 0) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'message' => "That machine is already being worked by {$claimedBy}.",
                'unit' => $unit,
            ];
        }

        $now = musky_new_hardware_now();
        $update = $pdo->prepare("
            UPDATE musky_new_hardware_units
               SET status = 'IN_PROGRESS',
                   claimed_by = ?,
                   claim_session_id = ?,
                   claim_started_at = COALESCE(claim_started_at, ?),
                   lookup_serial_raw = ?,
                   lookup_serial_key = ?,
                   last_note = ?,
                   updated_at = ?
             WHERE id = ?
        ");
        $update->execute([
            $actorEmail,
            $sessionId,
            $now,
            $scanRaw,
            $matchKey,
            'Serial matched. Waiting for asset tag and owner confirmation.',
            $now,
            (int)$unit['id'],
        ]);

        musky_new_hardware_log_event(
            $pdo,
            (int)$unit['id'],
            (int)$unit['batch_id'],
            $actorEmail,
            'CLAIM_STARTED',
            'Matched scanned serial against the new hardware pool.',
            [
                'scan_raw' => $scanRaw,
                'scan_match_key' => $matchKey,
            ]
        );

        $fresh = musky_new_hardware_fetch_unit_by_id($pdo, (int)$unit['id'], false);
        $pdo->commit();

        return [
            'ok' => true,
            'message' => 'Serial matched. Scan the asset tag and assignment next.',
            'unit' => $fresh ?: $unit,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[NewHardware] begin claim failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The claim could not be started right now.'];
    }
}

function musky_new_hardware_release_claim(PDO $pdo, int $unitId, string $actorEmail, bool $forceAdmin = false): array
{
    musky_new_hardware_ensure_schema($pdo);
    $actorEmail = musky_new_hardware_clean_text($actorEmail, 255);

    $pdo->beginTransaction();

    try {
        $unit = musky_new_hardware_fetch_unit_by_id($pdo, $unitId, true);
        if (!$unit) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That hardware row no longer exists.'];
        }

        if (strtoupper(trim((string)($unit['status'] ?? ''))) === 'COMPLETED') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Completed rows cannot be released back to available from this screen.'];
        }

        $claimedBy = trim((string)($unit['claimed_by'] ?? ''));
        if (!$forceAdmin && $claimedBy !== '' && strcasecmp($claimedBy, $actorEmail) !== 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Only the current claimer can release this machine.'];
        }

        $now = musky_new_hardware_now();
        $update = $pdo->prepare("
            UPDATE musky_new_hardware_units
               SET status = 'AVAILABLE',
                   claimed_by = NULL,
                   claim_session_id = NULL,
                   claim_started_at = NULL,
                   lookup_serial_raw = NULL,
                   lookup_serial_key = NULL,
                   asset_tag = NULL,
                   assignment_input = NULL,
                   owner_id = NULL,
                   owner_email = NULL,
                   owner_name = NULL,
                   owner_user_type = NULL,
                   owner_grade = NULL,
                   owner_district_id = NULL,
                   owner_status = NULL,
                   owner_last_seen = NULL,
                   usercheck_errand_id = NULL,
                   iiqsync_errand_id = NULL,
                   last_note = ?,
                   extra_json = NULL,
                   updated_at = ?
             WHERE id = ?
        ");
        $update->execute([
            $forceAdmin
                ? 'Admin released this machine back to available.'
                : 'Claim released back to available.',
            $now,
            $unitId,
        ]);

        musky_new_hardware_log_event(
            $pdo,
            $unitId,
            (int)$unit['batch_id'],
            $actorEmail !== '' ? $actorEmail : 'unknown',
            $forceAdmin ? 'ADMIN_RELEASE' : 'CLAIM_RELEASED',
            $forceAdmin ? 'Admin released an in-progress claim.' : 'Operator released an in-progress claim.',
            [
                'claimed_by' => $claimedBy,
                'serial_match_key' => $unit['serial_match_key'] ?? '',
            ]
        );

        $pdo->commit();
        return ['ok' => true, 'message' => 'The machine was returned to the available pool.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[NewHardware] release claim failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The claim could not be released right now.'];
    }
}

function musky_new_hardware_wait_for_errand(PDO $pdo, int $errandId, int $timeoutSeconds = 20, int $intervalMs = 500): array
{
    $deadline = microtime(true) + max(3, $timeoutSeconds);
    $stmt = $pdo->prepare("
        SELECT ErrandID, Status, ExtraDataField04, ExtraDataField06, CompleteDateTime
          FROM nora_errands
         WHERE ErrandID = ?
         LIMIT 1
    ");

    $lastRow = null;
    do {
        $stmt->execute([$errandId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $lastRow = $row;
            $status = strtoupper(trim((string)($row['Status'] ?? '')));
            if (in_array($status, ['COMPLETE', 'FAILED', 'REJECTED', 'CANCELLED'], true)) {
                return [
                    'ok' => $status === 'COMPLETE',
                    'status' => $status,
                    'row' => $row,
                    'message' => trim((string)($row['ExtraDataField04'] ?? '')),
                ];
            }
        }

        usleep(max(100, $intervalMs) * 1000);
    } while (microtime(true) < $deadline);

    return [
        'ok' => false,
        'status' => strtoupper(trim((string)($lastRow['Status'] ?? 'TIMEOUT'))),
        'row' => $lastRow,
        'message' => trim((string)($lastRow['ExtraDataField04'] ?? 'User check is still running.')),
    ];
}

function musky_new_hardware_submit_usercheck(PDO $pdo, string $assignmentInput, string $submitterEmail): array
{
    $payload = [
        'serial' => 'N/A',
        'udid' => 'SYSTEM_TASK',
        'submitter' => $submitterEmail !== '' ? $submitterEmail : 'NEW_HARDWARE',
        'nora' => 'TRUE',
        'priority' => 5,
        'extra1' => 'USERCHECK',
        'extra2' => $assignmentInput,
    ];

    $response = musky_nora_api_post_json('/errand/create', $payload, 20);
    $errandId = musky_nora_extract_errand_id($response);
    if (!$errandId) {
        $meta = musky_nora_last_response_meta();
        $httpCode = (int)($meta['http_code'] ?? 0);
        return [
            'ok' => false,
            'message' => $httpCode > 0
                ? "USERCHECK could not be queued (HTTP {$httpCode})."
                : 'USERCHECK could not be queued.',
            'response' => $response,
            'meta' => $meta,
        ];
    }

    $wait = musky_new_hardware_wait_for_errand($pdo, $errandId, 24, 500);
    $wait['errand_id'] = $errandId;
    if (!$wait['ok'] && trim((string)$wait['message']) === '') {
        $wait['message'] = 'USERCHECK did not complete successfully.';
    }
    return $wait;
}

function musky_new_hardware_find_owner_row(PDO $pdo, string $assignmentInput): ?array
{
    $assignmentInput = strtolower(trim($assignmentInput));
    if ($assignmentInput === '') {
        return null;
    }

    $username = strpos($assignmentInput, '@') !== false
        ? strtok($assignmentInput, '@')
        : $assignmentInput;

    $stmt = $pdo->prepare("
        SELECT
            id,
            mosyle_id,
            email,
            full_name,
            user_type,
            grade,
            district_id,
            last_seen,
            status,
            created_at,
            updated_at
        FROM owners
        WHERE LOWER(email) = :email_exact
           OR LOWER(SUBSTRING_INDEX(COALESCE(email, ''), '@', 1)) = :username
        ORDER BY
            CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END,
            CASE WHEN LOWER(email) = :email_exact THEN 0 ELSE 1 END,
            COALESCE(updated_at, created_at, '1970-01-01 00:00:00') DESC,
            id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':email_exact' => $assignmentInput,
        ':username' => $username,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function musky_new_hardware_find_owner_with_retry(PDO $pdo, string $assignmentInput, int $attempts = 5, int $delayMs = 350): ?array
{
    $attempts = max(1, $attempts);
    for ($i = 0; $i < $attempts; $i++) {
        $owner = musky_new_hardware_find_owner_row($pdo, $assignmentInput);
        if ($owner) {
            return $owner;
        }
        if ($i < $attempts - 1) {
            usleep(max(100, $delayMs) * 1000);
        }
    }
    return null;
}

function musky_new_hardware_maybe_run_iiqsync(PDO $pdo, array $owner, string $submitterEmail): array
{
    $ownerEmail = musky_new_hardware_clean_text((string)($owner['email'] ?? ''), 255);
    $districtId = musky_new_hardware_clean_text((string)($owner['district_id'] ?? ''), 64);
    if ($ownerEmail === '' || $districtId !== '') {
        return ['ok' => true, 'errand_id' => null, 'owner' => $owner];
    }

    $payload = [
        'serial' => 'N/A',
        'udid' => 'SYSTEM_TASK',
        'submitter' => $submitterEmail !== '' ? $submitterEmail : 'NEW_HARDWARE',
        'nora' => 'TRUE',
        'priority' => 5,
        'extra1' => 'IIQUSERSYNC',
        'extra2' => $ownerEmail,
    ];

    $response = musky_nora_api_post_json('/errand/create', $payload, 20);
    $errandId = musky_nora_extract_errand_id($response);
    if (!$errandId) {
        return [
            'ok' => false,
            'errand_id' => null,
            'owner' => $owner,
            'message' => 'IIQ user sync could not be queued.',
        ];
    }

    $wait = musky_new_hardware_wait_for_errand($pdo, $errandId, 12, 500);
    $refreshed = musky_new_hardware_find_owner_with_retry($pdo, $ownerEmail, 4, 300) ?: $owner;

    return [
        'ok' => $wait['ok'],
        'errand_id' => $errandId,
        'owner' => $refreshed,
        'message' => trim((string)($wait['message'] ?? '')),
    ];
}

function musky_new_hardware_prepare_confirmation(
    PDO $pdo,
    array $unit,
    string $assetTag,
    string $assignmentInput,
    string $actorEmail
): array {
    musky_new_hardware_ensure_schema($pdo);

    $assetTag = musky_new_hardware_clean_asset_tag($assetTag);
    $assignmentInput = musky_new_hardware_clean_text($assignmentInput, 255);
    $actorEmail = musky_new_hardware_clean_text($actorEmail, 255);
    $unitId = (int)($unit['id'] ?? 0);

    if ($unitId <= 0) {
        return ['ok' => false, 'message' => 'The selected machine is no longer available.'];
    }

    if (!musky_new_hardware_valid_asset_tag($assetTag)) {
        return ['ok' => false, 'message' => 'Scan a usable asset tag before continuing.'];
    }

    if ($assignmentInput === '') {
        return ['ok' => false, 'message' => 'Scan or type the assignment before continuing.'];
    }

    $status = strtoupper(trim((string)($unit['status'] ?? '')));
    if ($status !== 'IN_PROGRESS') {
        return ['ok' => false, 'message' => 'This machine is no longer in an editable in-progress state.'];
    }

    $claimedBy = trim((string)($unit['claimed_by'] ?? ''));
    if ($claimedBy !== '' && strcasecmp($claimedBy, $actorEmail) !== 0) {
        return ['ok' => false, 'message' => 'This machine is currently assigned to another operator.'];
    }

    $updatePreview = $pdo->prepare("
        UPDATE musky_new_hardware_units
           SET asset_tag = ?,
               assignment_input = ?,
               last_note = ?,
               updated_at = ?
         WHERE id = ?
    ");
    $updatePreview->execute([
        $assetTag,
        $assignmentInput,
        'Running Nora user check for the proposed owner.',
        musky_new_hardware_now(),
        $unitId,
    ]);

    $usercheck = musky_new_hardware_submit_usercheck($pdo, $assignmentInput, $actorEmail);
    if (!$usercheck['ok']) {
        $failureNote = $usercheck['message'] !== ''
            ? $usercheck['message']
            : 'USERCHECK reported that this assignment is not active.';
        $failureUpdate = $pdo->prepare("
            UPDATE musky_new_hardware_units
               SET usercheck_errand_id = ?,
                   last_note = ?,
                   updated_at = ?
             WHERE id = ?
        ");
        $failureUpdate->execute([
            $usercheck['errand_id'] ?? null,
            $failureNote,
            musky_new_hardware_now(),
            $unitId,
        ]);

        return [
            'ok' => false,
            'message' => $failureNote,
            'usercheck_errand_id' => $usercheck['errand_id'] ?? null,
            'usercheck' => $usercheck,
        ];
    }

    $owner = musky_new_hardware_find_owner_with_retry($pdo, $assignmentInput, 6, 350);
    if (!$owner) {
        $ownerMissingNote = 'USERCHECK passed, but Musky could not find the owner row to show confirmation details.';
        $ownerMissingUpdate = $pdo->prepare("
            UPDATE musky_new_hardware_units
               SET usercheck_errand_id = ?,
                   last_note = ?,
                   updated_at = ?
             WHERE id = ?
        ");
        $ownerMissingUpdate->execute([
            $usercheck['errand_id'] ?? null,
            $ownerMissingNote,
            musky_new_hardware_now(),
            $unitId,
        ]);

        return [
            'ok' => false,
            'message' => $ownerMissingNote,
            'usercheck_errand_id' => $usercheck['errand_id'] ?? null,
        ];
    }

    $iiqsync = musky_new_hardware_maybe_run_iiqsync($pdo, $owner, $actorEmail);
    $owner = is_array($iiqsync['owner'] ?? null) ? $iiqsync['owner'] : $owner;

    $successUpdate = $pdo->prepare("
        UPDATE musky_new_hardware_units
           SET usercheck_errand_id = ?,
               iiqsync_errand_id = ?,
               owner_id = ?,
               owner_email = ?,
               owner_name = ?,
               owner_user_type = ?,
               owner_grade = ?,
               owner_district_id = ?,
               owner_status = ?,
               owner_last_seen = ?,
               last_note = ?,
               updated_at = ?
         WHERE id = ?
    ");
    $successUpdate->execute([
        $usercheck['errand_id'] ?? null,
        $iiqsync['errand_id'] ?? null,
        !empty($owner['id']) ? (int)$owner['id'] : null,
        trim((string)($owner['email'] ?? '')) ?: null,
        trim((string)($owner['full_name'] ?? '')) ?: null,
        trim((string)($owner['user_type'] ?? '')) ?: null,
        trim((string)($owner['grade'] ?? '')) ?: null,
        trim((string)($owner['district_id'] ?? '')) ?: null,
        trim((string)($owner['status'] ?? '')) ?: null,
        trim((string)($owner['last_seen'] ?? '')) ?: null,
        'Owner check passed. Waiting for final confirmation.',
        musky_new_hardware_now(),
        $unitId,
    ]);

    return [
        'ok' => true,
        'message' => 'Owner check passed. Review the details below and confirm when ready.',
        'asset_tag' => $assetTag,
        'assignment_input' => $assignmentInput,
        'owner' => $owner,
        'usercheck_errand_id' => $usercheck['errand_id'] ?? null,
        'iiqsync_errand_id' => $iiqsync['errand_id'] ?? null,
        'iiqsync_message' => (string)($iiqsync['message'] ?? ''),
        'checked_at' => date('c'),
    ];
}

function musky_new_hardware_commit_confirmation(
    PDO $pdo,
    int $unitId,
    array $preview,
    string $actorEmail,
    string $sessionId
): array {
    musky_new_hardware_ensure_schema($pdo);

    $actorEmail = musky_new_hardware_clean_text($actorEmail, 255);
    $sessionId = musky_new_hardware_clean_text($sessionId, 128);
    $assetTag = musky_new_hardware_clean_asset_tag((string)($preview['asset_tag'] ?? ''));
    $assignmentInput = musky_new_hardware_clean_text((string)($preview['assignment_input'] ?? ''), 255);
    $owner = is_array($preview['owner'] ?? null) ? $preview['owner'] : [];

    if (!musky_new_hardware_valid_asset_tag($assetTag) || $assignmentInput === '' || !$owner) {
        return ['ok' => false, 'message' => 'The confirmation details expired or were incomplete.'];
    }

    $pdo->beginTransaction();

    try {
        $unit = musky_new_hardware_fetch_unit_by_id($pdo, $unitId, true);
        if (!$unit) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That hardware row no longer exists.'];
        }

        $status = strtoupper(trim((string)($unit['status'] ?? '')));
        if ($status === 'COMPLETED') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That machine has already been completed.'];
        }

        $claimedBy = trim((string)($unit['claimed_by'] ?? ''));
        if ($claimedBy !== '' && strcasecmp($claimedBy, $actorEmail) !== 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That machine is currently assigned to another operator.'];
        }

        $now = musky_new_hardware_now();
        $ownerId = (int)($owner['id'] ?? 0);
        $ownerEmail = musky_new_hardware_clean_text((string)($owner['email'] ?? ''), 255);
        $ownerName = musky_new_hardware_clean_text((string)($owner['full_name'] ?? ''), 255);
        $ownerType = musky_new_hardware_clean_text((string)($owner['user_type'] ?? ''), 64);
        $ownerGrade = musky_new_hardware_clean_text((string)($owner['grade'] ?? ''), 32);
        $ownerDistrict = musky_new_hardware_clean_text((string)($owner['district_id'] ?? ''), 64);
        $ownerStatus = musky_new_hardware_clean_text((string)($owner['status'] ?? ''), 32);
        $ownerLastSeen = trim((string)($owner['last_seen'] ?? '')) ?: null;

        $update = $pdo->prepare("
            UPDATE musky_new_hardware_units
               SET status = 'COMPLETED',
                   claimed_by = ?,
                   claim_session_id = ?,
                   asset_tag = ?,
                   assignment_input = ?,
                   owner_id = ?,
                   owner_email = ?,
                   owner_name = ?,
                   owner_user_type = ?,
                   owner_grade = ?,
                   owner_district_id = ?,
                   owner_status = ?,
                   owner_last_seen = ?,
                   usercheck_errand_id = ?,
                   iiqsync_errand_id = ?,
                   completed_by = ?,
                   completed_at = ?,
                   last_note = ?,
                   extra_json = ?,
                   updated_at = ?
             WHERE id = ?
        ");
        $update->execute([
            $actorEmail,
            $sessionId,
            $assetTag,
            $assignmentInput,
            $ownerId > 0 ? $ownerId : null,
            $ownerEmail !== '' ? $ownerEmail : null,
            $ownerName !== '' ? $ownerName : null,
            $ownerType !== '' ? $ownerType : null,
            $ownerGrade !== '' ? $ownerGrade : null,
            $ownerDistrict !== '' ? $ownerDistrict : null,
            $ownerStatus !== '' ? $ownerStatus : null,
            $ownerLastSeen,
            isset($preview['usercheck_errand_id']) ? (int)$preview['usercheck_errand_id'] : null,
            isset($preview['iiqsync_errand_id']) ? (int)$preview['iiqsync_errand_id'] : null,
            $actorEmail,
            $now,
            'Completed and waiting for the next Nora/Mosyle sync to populate the live device row.',
            json_encode([
                'checked_at' => $preview['checked_at'] ?? null,
                'iiqsync_message' => $preview['iiqsync_message'] ?? '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $now,
            $unitId,
        ]);

        musky_new_hardware_log_event(
            $pdo,
            $unitId,
            (int)$unit['batch_id'],
            $actorEmail !== '' ? $actorEmail : 'unknown',
            'CLAIM_COMPLETED',
            'Completed new hardware intake.',
            [
                'asset_tag' => $assetTag,
                'assignment_input' => $assignmentInput,
                'owner_email' => $ownerEmail,
                'usercheck_errand_id' => $preview['usercheck_errand_id'] ?? null,
                'iiqsync_errand_id' => $preview['iiqsync_errand_id'] ?? null,
            ]
        );

        $fresh = musky_new_hardware_fetch_unit_by_id($pdo, $unitId, false);
        $pdo->commit();

        return [
            'ok' => true,
            'message' => 'Machine completed. It will wait for the next Nora/Mosyle sync for the live device row.',
            'unit' => $fresh ?: $unit,
            'owner' => $owner,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[NewHardware] commit confirmation failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The machine could not be finalized right now.'];
    }
}
