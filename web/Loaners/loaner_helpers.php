<?php
// =====================================================================
// loaner_helpers.php
// ---------------------------------------------------------------------
// Provides helper functions for the Loaner Device Manager project.
// =====================================================================

// Load global constants (one directory up)
require_once __DIR__ . '/../loaner_constants.php';

/**
 * Decode the Last Check-In timestamp into minutes ago or hours ago.
 */
function decodeLastCheckIn($timestamp) {
    if (!is_numeric($timestamp) || $timestamp <= 0) {
        return 'Bad Data';
    }
    $diffSeconds = time() - (int)$timestamp;
    return ($diffSeconds < 3600) ? round($diffSeconds / 60) . ' min ago' : round($diffSeconds / 3600) . ' hrs ago';
}

/**
 * Decode iOS update information.
 */
function decodeIosUpdate($field) {
    return (trim($field) === '') 
        ? '<span class="device-update-good">Up To Date</span>' 
        : '<span class="device-update-warning">Needs Update (' . htmlspecialchars($field) . ')</span>';
}

/**
 * Decode IP to determine if the device is On Campus or Off Campus.
 */
function decodeIpCampusStatus($ip) {
    $trimmedIp = trim($ip);
    $isCampus = in_array($trimmedIp, CAMPUS_IPS);
    $status = $isCampus ? 'On Campus' : 'Off Campus';
    return [$status, $trimmedIp];
}

/**
 * Decode Device User Status by comparing Mosyle and IIQ user fields and considering ticket.
 */
function decodeDeviceUserStatus($mosyleUser, $iiqUser, $ticketNumber) {
    $mosyleUser = trim($mosyleUser);
    $iiqUser = trim($iiqUser);
    $ticketNumber = trim($ticketNumber);

    if ($mosyleUser === '' && $iiqUser === 'IIQNOTASSIGN' && $ticketNumber === 'NoInfo') {
        return ['Ready to Loan', 'device-user-ready'];
    } elseif ($mosyleUser === $iiqUser && $mosyleUser !== '' && $ticketNumber !== 'NoInfo') {
        return [$mosyleUser, 'device-user-good'];
    } elseif ($mosyleUser === $iiqUser && $mosyleUser !== '' && $ticketNumber === 'NoInfo') {
        return [$mosyleUser, 'device-user-warning'];
    } elseif ($mosyleUser !== $iiqUser && $mosyleUser !== '' && $iiqUser !== '' && $ticketNumber !== 'NoInfo') {
        return [$mosyleUser . ' / ' . $iiqUser, 'device-user-bad'];
    } elseif ($mosyleUser !== $iiqUser && $mosyleUser !== '' && $iiqUser !== '' && $ticketNumber === 'NoInfo') {
        return [$mosyleUser . ' / ' . $iiqUser, 'device-user-bad'];
    } elseif ($mosyleUser === '' && $iiqUser === '' && $ticketNumber !== 'NoInfo') {
        return ['Unassigned', 'device-user-unassigned'];
    } elseif ($mosyleUser === '' && $iiqUser === '' && $ticketNumber === 'NoInfo') {
        return ['Unassigned', 'device-user-unassigned'];
    } else {
        return ['Bad Data', 'device-user-error'];
    }
}
?>
