<?php
// Shared device icon helpers for Musky.

function musky_device_icon_ipad_map(): array
{
    return [
        'ipad6,11' => 5, 'ipad6,12' => 5,
        'ipad7,5' => 6, 'ipad7,6' => 6,
        'ipad7,11' => 7, 'ipad7,12' => 7,
        'ipad11,6' => 8, 'ipad11,7' => 8,
        'ipad12,1' => 9, 'ipad12,2' => 9,
        'ipad13,18' => 10, 'ipad13,19' => 10,
        'ipad14,10' => 11, 'ipad14,11' => 11,
        'ipad15,7' => 11,
        'ipad16,5' => 12,
    ];
}

function musky_device_icon_macbook_air_ids(): array
{
    return [
        'mac14,2' => true,
        'mac16,12' => true,
        'macbookair10,1' => true,
    ];
}

function musky_device_icon_macbook_neo_ids(): array
{
    return [
        'mac17,5' => true,
    ];
}

function musky_device_icon_desktop_mac_ids(): array
{
    return [
        'imac13,3' => true,
        'imac14,3' => true,
        'imac18,2' => true,
        'imac21,1' => true,
        'imac21,2' => true,
        'mac15,4' => true,
        'mac16,2' => true,
        'mac16,3' => true,
    ];
}

function musky_device_icon_normalize_code(?string $value): string
{
    return strtolower(preg_replace('/\s+/', '', trim((string)$value)));
}

function musky_device_icon_search_text(?string $deviceModel, ?string $modelName, ?string $deviceType): string
{
    return strtolower(trim(implode(' ', array_filter([
        trim((string)$deviceModel),
        trim((string)$modelName),
        trim((string)$deviceType),
    ], static fn($value) => $value !== ''))));
}

function musky_device_icon_ordinal(int $number): string
{
    $abs = abs($number);
    $mod100 = $abs % 100;
    if ($mod100 >= 11 && $mod100 <= 13) {
        return $number . 'th';
    }

    return match ($abs % 10) {
        1 => $number . 'st',
        2 => $number . 'nd',
        3 => $number . 'rd',
        default => $number . 'th',
    };
}

function musky_device_icon_extract_ipad_generation(string $searchText): ?int
{
    if ($searchText === '') {
        return null;
    }

    if (preg_match('/ipad\s*\(?\s*([0-9]{1,2})(?:st|nd|rd|th)?/i', $searchText, $match)) {
        return (int)$match[1];
    }

    return null;
}

function musky_device_icon_join_path(string $iconBase, string $filename): string
{
    return rtrim($iconBase, '/') . '/' . $filename;
}

function musky_device_icon_laptop_label(string $searchText, string $deviceModel, string $modelName): string
{
    if (str_contains($searchText, 'macbook neo')) {
        return 'MacBook Neo';
    }
    if (str_contains($searchText, 'macbook air') || str_contains($searchText, 'macbookair')) {
        return 'MacBook Air';
    }
    if (str_contains($searchText, 'macbook pro') || str_contains($searchText, 'macbookpro')) {
        return 'MacBook Pro';
    }
    if (str_contains($searchText, 'macbook')) {
        return 'MacBook';
    }
    if (str_contains($searchText, 'laptop') || str_contains($searchText, 'notebook')) {
        return 'Laptop';
    }
    if ($modelName !== '') {
        return $modelName;
    }
    if ($deviceModel !== '') {
        return $deviceModel;
    }
    return 'Laptop';
}

function musky_device_icon_profile(
    ?string $deviceModel,
    ?string $modelName = null,
    ?string $deviceType = null,
    string $iconBase = '../icons'
): array {
    $deviceModel = trim((string)$deviceModel);
    $modelName = trim((string)$modelName);
    $deviceType = trim((string)$deviceType);
    $modelCode = musky_device_icon_normalize_code($deviceModel);
    $modelNameCode = musky_device_icon_normalize_code($modelName);
    $searchText = musky_device_icon_search_text($deviceModel, $modelName, $deviceType);
    $ipadMap = musky_device_icon_ipad_map();

    $generation = $ipadMap[$modelCode] ?? $ipadMap[$modelNameCode] ?? null;
    if ($generation === null) {
        $generation = musky_device_icon_extract_ipad_generation($searchText);
    }

    if ($generation !== null) {
        return [
            'icon' => musky_device_icon_join_path($iconBase, $generation <= 9 ? 'ipad_home.png' : 'ipad_flat.png'),
            'label' => musky_device_icon_ordinal($generation) . ' Gen',
            'kind' => 'ipad',
        ];
    }

    if (preg_match('/ipad1[0-9]/i', $searchText)) {
        return [
            'icon' => musky_device_icon_join_path($iconBase, 'ipad_flat.png'),
            'label' => '11th Gen',
            'kind' => 'ipad',
        ];
    }

    $neoIds = musky_device_icon_macbook_neo_ids();
    if (isset($neoIds[$modelCode]) || str_contains($searchText, 'macbook neo')) {
        return [
            'icon' => musky_device_icon_join_path($iconBase, 'MuskyMacBookNeo.png'),
            'label' => 'MacBook Neo',
            'kind' => 'macbook_neo',
        ];
    }

    $airIds = musky_device_icon_macbook_air_ids();
    if (isset($airIds[$modelCode]) || str_contains($searchText, 'macbook air') || str_contains($searchText, 'macbookair')) {
        return [
            'icon' => musky_device_icon_join_path($iconBase, 'MuskyMacBookAir.png'),
            'label' => 'MacBook Air',
            'kind' => 'macbook_air',
        ];
    }

    $desktopIds = musky_device_icon_desktop_mac_ids();
    $looksDesktop = isset($desktopIds[$modelCode])
        || str_contains($searchText, 'imac')
        || str_contains($searchText, 'mac mini')
        || str_contains($searchText, 'macmini')
        || str_contains($searchText, 'desktop');
    $looksAppleLaptop = !$looksDesktop && (
        str_contains($searchText, 'macbook')
        || str_contains($searchText, 'laptop')
        || str_contains($searchText, 'notebook')
    );

    if ($looksAppleLaptop) {
        return [
            'icon' => musky_device_icon_join_path($iconBase, 'FakeMac.png'),
            'label' => musky_device_icon_laptop_label($searchText, $deviceModel, $modelName),
            'kind' => 'laptop',
        ];
    }

    if ($deviceModel !== '' || $modelName !== '' || $deviceType !== '') {
        return [
            'icon' => musky_device_icon_join_path($iconBase, 'not_ipad.png'),
            'label' => $modelName !== '' ? $modelName : ($deviceModel !== '' ? $deviceModel : 'Unsupported'),
            'kind' => 'other',
        ];
    }

    return [
        'icon' => musky_device_icon_join_path($iconBase, 'default.png'),
        'label' => 'Unsupported',
        'kind' => 'unknown',
    ];
}
