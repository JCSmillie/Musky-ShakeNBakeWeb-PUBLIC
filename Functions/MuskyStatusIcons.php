<?php
declare(strict_types=1);

function musky_status_icon_map(string $iconBase = '../Imagery'): array
{
    $base = rtrim($iconBase, '/');
    return [
        'complete' => [
            'src' => $base . '/MuskyPawThumbsUp.png',
            'alt' => 'OK',
        ],
        'failed' => [
            'src' => $base . '/MuskyPawThumbsDown.png',
            'alt' => 'Failed',
        ],
        'running' => [
            'src' => $base . '/MuskyPawRollingFingers.gif',
            'alt' => 'Working',
        ],
    ];
}

function musky_status_icon_key(?string $status): string
{
    $normalized = strtolower(trim((string)$status));
    if (in_array($normalized, ['complete', 'completed', 'success', 'ok', 'ready', 'done'], true)) {
        return 'complete';
    }
    if (in_array($normalized, ['failed', 'blocked', 'skipped', 'rejected', 'cancelled', 'canceled', 'error'], true)) {
        return 'failed';
    }
    return 'running';
}

function musky_status_icon_profile(?string $status, string $iconBase = '../Imagery'): array
{
    $map = musky_status_icon_map($iconBase);
    $key = musky_status_icon_key($status);
    return ['key' => $key] + ($map[$key] ?? $map['running']);
}

function musky_status_icon_img_html(?string $status, string $iconBase = '../Imagery'): string
{
    $icon = musky_status_icon_profile($status, $iconBase);
    return sprintf(
        '<img src="%s" alt="%s">',
        htmlspecialchars((string)$icon['src'], ENT_QUOTES),
        htmlspecialchars((string)$icon['alt'], ENT_QUOTES)
    );
}

function musky_status_icon_emit_js(string $functionName = 'muskyStatusIcon', string $iconBase = '../Imagery'): void
{
    $mapJson = json_encode(
        musky_status_icon_map($iconBase),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $functionName = preg_replace('/[^A-Za-z0-9_]/', '', $functionName) ?: 'muskyStatusIcon';
    echo "function {$functionName}(status) {\n";
    echo "    const normalized = String(status || '').trim().toLowerCase();\n";
    echo "    const key = ['complete', 'completed', 'success', 'ok', 'ready', 'done'].includes(normalized)\n";
    echo "        ? 'complete'\n";
    echo "        : (['failed', 'blocked', 'skipped', 'rejected', 'cancelled', 'canceled', 'error'].includes(normalized)\n";
    echo "            ? 'failed'\n";
    echo "            : 'running');\n";
    echo "    const map = {$mapJson};\n";
    echo "    const icon = map[key] || map.running;\n";
    echo "    return `<img src=\"\${icon.src}\" alt=\"\${icon.alt}\">`;\n";
    echo "}\n";
}
