<?php
error_reporting(error_reporting() & ~E_DEPRECATED);

require_once __DIR__ . '/MuskyConfig.php';

if (!defined('MUSKY_BOOTSTRAP_LOADED')) {
    define('MUSKY_BOOTSTRAP_LOADED', true);

    $timezone = musky_root_config_string('app.timezone', 'America/New_York');
    if ($timezone !== '') {
        date_default_timezone_set($timezone);
    }

    $functionsDir = musky_root_path() . '/Functions';

    $GLOBALS['MOSBASIC_PATH'] = musky_root_config_string('paths.mosbasic_binary', '/Users/Shared/MOSBasic/mosbasic');
    $GLOBALS['LOCATION_SCRIPT_PATH'] = musky_root_config_string('paths.location_script_dir', $functionsDir);
    $GLOBALS['CAMPUS_IPS'] = musky_root_config_array('network.campus_ips', []);
    $GLOBALS['PROBLEM_REPORT_EMAIL'] = musky_root_config_string('email.problem_report_to', '');
    $GLOBALS['EMAIL_SENDER_ADDRESS'] = musky_root_config_string('email.sender_address', 'noreply@example.invalid');
    $GLOBALS['LOG_PATH'] = musky_root_config_string('paths.log_dir', '/tmp');
    $GLOBALS['SESSION_TIMEOUT'] = musky_root_config_int('session.timeout_seconds', 1800);
    $GLOBALS['SQLITE_PATH'] = musky_root_config_string('session.sqlite_path', '');

    if (!defined('ENABLE_DEVICE_MANAGER_MODULES')) {
        define('ENABLE_DEVICE_MANAGER_MODULES', musky_root_config_bool('modules.device_manager_enabled', true));
    }

    if (!defined('ENABLE_LOANER_MODULES')) {
        define('ENABLE_LOANER_MODULES', musky_root_config_bool('modules.loaner_enabled', false));
    }
}
