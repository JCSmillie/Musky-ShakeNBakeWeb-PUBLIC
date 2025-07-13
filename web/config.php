<?php
// config.php
// Main configuration file for Smillieland-based MUSKY tools

// =============================================
// Paths (custom binaries, tools, or scripts)
// =============================================
$MOSBASIC_PATH = '/backup/MOSBasic/mosbasic';
$LOCATION_SCRIPT_PATH = '/AddonStorage/webcontent/MuskyFunctions/';

// =============================================
// IP Addresses Considered "On Campus"
// Devices reporting any of these IPs will be flagged
// as being inside trusted district infrastructure
// =============================================
$CAMPUS_IPS = [
    '65.254.21.222', // Gateway Core WiFi
    '10.1.12.200',   // Example: New Campus Building IP
    '10.1.12.201',   // Admin Center WAN IP
    // Add more IPs as needed
];

// =============================================
// Email Settings for auto-notifications
// =============================================
$PROBLEM_REPORT_EMAIL = 'jsmillie@gatewayk12.org';
$EMAIL_SENDER_ADDRESS = 'noreply@gatewayk12.org';

// =============================================
// MUSKY Device Manager Settings
// =============================================

// Path where Device Manager logs will be saved
// This path must exist and be writable by the web server
$LOG_PATH = '/usr/local/mosbasicsupport/MUSKY/';

// =============================================
// (Add future shared config here as needed)
// =============================================


// =============================================
// 3rd Party Module Settings
// =============================================
// Enable/Disable 3rd Party Modules
if (!defined('ENABLE_DEVICE_MANAGER_MODULES')) {
    define('ENABLE_DEVICE_MANAGER_MODULES', true);
}
if (!defined('ENABLE_LOANER_MODULES')) {
    define('ENABLE_LOANER_MODULES', false);
}



// =============================================
// Slack Settings
// =============================================

// Enable or disable Slack notifications for problem reports
$ENABLE_SLACK = true; // <-- Set to true to enable

// Slack Webhook URL (only used if $ENABLE_SLACK is true)
$SLACK_WEBHOOK_URL = 'https://hooks.slack.com/services/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';
// =============================================
// Two-Factor Authentication Support
// =============================================
// These settings enable the PHP-based LDAP+TOTP 2FA portal
// If 2FA is enabled, users must log in via the specified portal before accessing MUSKY tools
// If false, legacy Apache or .htaccess authentication may be used instead
// =============================================
$ENABLE_2FA = true;

// Path to the Smillieland 2FA portal config file.
// Used by check_access.php to enforce login and access control.
$TWO_FA_CONFIG_PATH = '/AddonStorage/webcontent/htdocs/secure/2fa-portal/config.php';

// Base URL to the 2FA portal. Can be local (e.g. /2fa-portal/) or remote (https://some.other.server/portal/)
$TWO_FA_PORTAL_URL = 'https://donatello.gatewayk12.org/secure/2fa-portal/';

// Session timeout in seconds (used by auth_check.php / check_access.php)
$SESSION_TIMEOUT = 1800;

// CHECK ACCESS LOGS
$SESSION_LOG_PATH = '/usr/local/mosbasicsupport/Smillieland-2fa/session_log.txt';


