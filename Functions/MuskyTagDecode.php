<?php
// ============================================================================
// MuskyTagDecode.php
// ----------------------------------------------------------------------------
// Shared helpers for DeviceManager tag decoding backed by nora_config_store.
// Group: TagDecode
// Set:   DEFAULT
// ============================================================================

if (!function_exists('musky_tag_decode_group')) {
    function musky_tag_decode_group(): string
    {
        return 'TagDecode';
    }
}

if (!function_exists('musky_tag_decode_set')) {
    function musky_tag_decode_set(): string
    {
        return 'DEFAULT';
    }
}

if (!function_exists('musky_tag_decode_defaults')) {
    function musky_tag_decode_defaults(): array
    {
        return [
            'ADE' => 'ASM Good!',
            'BROKEN' => 'Device is in inventory as Broken, undeployed.',
            'ChildrensInstitute' => 'Student Enrolled at Childrens Institute',
            'ChromeUser' => 'Chrome allowed on this iPad',
            'CSE-Loaner' => 'CSE Loaner Pool Device',
            'CYBER' => 'Student Enrolled in Gator Cyber',
            'DaNewz' => 'Ele Student News Team',
            'DePaul' => 'Student Enrolled at DePaul',
            'Ele_Allow_Camera' => 'K-4 Camera Always On',
            'EV-Loaner' => 'EV Loaner Pool Device',
            'GMS-Loaner' => 'GMS Loaner Pool Device',
            'GSDIT-Loaner' => 'GSD IT Loaner Pool Device',
            'HDK' => 'Help Desk Student, Respect.',
            'HomeSchool' => 'Student Home Schooled',
            'InStorage' => 'IIQ says undeployed',
            'NoYouTube4U' => 'YouTube Block on Device',
            'Out2AGi' => '@AGI for Repair',
            'PACE' => 'Student Enrolled at PACE',
            'RAM-Loaner' => 'Ramsey Ele Loaner Pool Device',
            'RETIRED-2025' => 'RETIRED 2025',
            'RETIRED-2026' => 'RETIRED 2026',
            'STOLEN' => 'STOLEN DEVICE',
            'Staff' => 'iPad-STAFF ASSIGNED',
            'Student' => 'iPad-STUDENT ASSIGNED',
            'Sunrise' => 'Student Enrolled at Sunrise',
            'Teacher' => 'iPad-TEACHER ASSIGNED',
            'UP-Loaner' => 'UP Loaner Pool Device',
        ];
    }
}

if (!function_exists('musky_tag_decode_table_exists')) {
    function musky_tag_decode_table_exists(?PDO $pdo): bool
    {
        if (!$pdo instanceof PDO) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT 1
                  FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
            ");
            $stmt->execute(['nora_config_store']);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('musky_tag_decode_db_map')) {
    function musky_tag_decode_db_map(?PDO $pdo = null): array
    {
        $pdo = $pdo instanceof PDO ? $pdo : (function_exists('musky_config_pdo') ? musky_config_pdo() : null);
        if (!$pdo instanceof PDO || !musky_tag_decode_table_exists($pdo)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT ConfigKey, ConfigValue
                  FROM nora_config_store
                 WHERE UPPER(ConfigGroup) = UPPER(?)
                   AND UPPER(ConfigSet) = UPPER(?)
                   AND IsActive = 1
                 ORDER BY ConfigKey
            ");
            $stmt->execute([musky_tag_decode_group(), musky_tag_decode_set()]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $map = [];
            foreach ($rows as $row) {
                $key = trim((string)($row['ConfigKey'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $map[$key] = trim((string)($row['ConfigValue'] ?? ''));
            }
            return $map;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('musky_tag_decode_translations')) {
    function musky_tag_decode_translations(?PDO $pdo = null): array
    {
        $defaults = musky_tag_decode_defaults();
        $dbMap = musky_tag_decode_db_map($pdo);
        if (!$dbMap) {
            return $defaults;
        }

        foreach ($dbMap as $key => $value) {
            $defaults[$key] = $value;
        }
        return $defaults;
    }
}

if (!function_exists('musky_tag_decode_seed_defaults')) {
    function musky_tag_decode_seed_defaults(PDO $pdo, string $actor = 'system'): array
    {
        $result = [
            'inserted' => 0,
            'updated' => 0,
            'errors' => [],
            'total_defaults' => 0,
        ];

        if (!musky_tag_decode_table_exists($pdo)) {
            $result['errors'][] = 'nora_config_store table is missing.';
            return $result;
        }

        $defaults = musky_tag_decode_defaults();
        $result['total_defaults'] = count($defaults);

        $sql = "
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
        ";

        $stmt = $pdo->prepare($sql);
        $descPrefix = 'TagDecode map for tag ';

        foreach ($defaults as $tag => $translation) {
            $tag = trim((string)$tag);
            if ($tag === '') {
                continue;
            }

            try {
                $stmt->execute([
                    musky_tag_decode_group(),
                    $tag,
                    musky_tag_decode_set(),
                    (string)$translation,
                    $descPrefix . $tag,
                    $actor,
                    $actor,
                ]);

                $affected = (int)$stmt->rowCount();
                if ($affected === 1) {
                    $result['inserted']++;
                } else {
                    $result['updated']++;
                }
            } catch (Throwable $e) {
                $result['errors'][] = $tag . ': ' . $e->getMessage();
            }
        }

        return $result;
    }
}
