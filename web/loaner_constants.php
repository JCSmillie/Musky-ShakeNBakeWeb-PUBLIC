<?php
// =====================================================================
// loaner_constants.php
// ---------------------------------------------------------------------
// Global constants for the Loaner Device Management System
// =====================================================================

// Safely define CAMPUS_IPS if not already defined
if (!defined('CAMPUS_IPS')) {
    define('CAMPUS_IPS', [
        '65.254.21.222',
        '65.254.21.223',
        '192.168.1.1',
        '10.0.0.1'
    ]);
}

/**
 * LOANER_POOLS
 * ------------------------------------------------------------------
 * Defines the available Loaner device pools.
 * 
 * - Key = short code for internal use (example: 'CSE', 'RAM')
 * - Value = full label used for searches (example: 'CSE-Loaner')
 *
 * To ADD or CHANGE a pool:
 *  - Add a new 'KEY' => 'VALUE' pair inside LOANER_POOLS below.
 */
if (!defined('LOANER_POOLS')) {
    define('LOANER_POOLS', [
        'CSE' => 'CSE-Loaner',
        'EV' => 'EV-Loaner',
        'RAM' => 'RAM-Loaner',
        'UP' => 'UP-Loaner',
        'GMS' => 'GMS-Loaner',
        'DistrictIT' => 'GSD-Loaner'
    ]);
}
?>
