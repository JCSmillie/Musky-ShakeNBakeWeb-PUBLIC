<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtimeDir = '/var/lib/musky';
$muskyConfigPath = $root . '/musky_config.json';
$noraConfigPath = $root . '/nora_config.json';
$mosyleCredsPath = $runtimeDir . '/.MosyleAPI';
$serviceTokenPath = $runtimeDir . '/nora_api_service.token';

function env_string(string $key, string $default = ''): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if (!is_string($value) || $value === '') {
        return $default;
    }

    return in_array(strtoupper(trim($value)), ['1', 'TRUE', 'YES', 'ON', 'ENABLED'], true);
}

function env_csv(string $key, array $default = []): array
{
    $value = getenv($key);
    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    $items = array_map(static fn(string $part): string => trim($part), explode(',', $value));
    $items = array_values(array_filter($items, static fn(string $part): bool => $part !== ''));
    return array_values(array_unique($items));
}

function write_json_file(string $path, array $payload): void
{
    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );
}

if (!is_dir($runtimeDir)) {
    mkdir($runtimeDir, 0775, true);
}

$serviceToken = trim(env_string('MUSKY_NORA_API_SERVICE_TOKEN', ''));
if ($serviceToken === '') {
    if (is_readable($serviceTokenPath)) {
        $serviceToken = trim((string)file_get_contents($serviceTokenPath));
    } else {
        $serviceToken = bin2hex(random_bytes(24));
    }
}
file_put_contents($serviceTokenPath, $serviceToken . PHP_EOL);
@chown($serviceTokenPath, 'www-data');
@chgrp($serviceTokenPath, 'www-data');
@chmod($serviceTokenPath, 0640);

$mosyleKey = env_string('MUSKY_MOSYLE_API_KEY', '');
$mosyleUser = env_string('MUSKY_MOSYLE_API_USERNAME', '');
$mosylePass = env_string('MUSKY_MOSYLE_API_PASSWORD', '');
if ($mosyleKey !== '' || $mosyleUser !== '' || $mosylePass !== '') {
    $mosyleContents = 'MOSYLE_API_key="' . addcslashes($mosyleKey, "\\\"") . '"' . PHP_EOL
        . 'MOSYLE_API_Username="' . addcslashes($mosyleUser, "\\\"") . '"' . PHP_EOL
        . 'MOSYLE_API_Password="' . addcslashes($mosylePass, "\\\"") . '"' . PHP_EOL;
    file_put_contents($mosyleCredsPath, $mosyleContents);
    @chown($mosyleCredsPath, 'www-data');
    @chgrp($mosyleCredsPath, 'www-data');
    @chmod($mosyleCredsPath, 0640);
}

$muskyConfig = [
    'app' => [
        'timezone' => env_string('TZ', 'America/New_York'),
    ],
    'session' => [
        'timeout_seconds' => (int)env_string('MUSKY_SESSION_TIMEOUT_SECONDS', '1800'),
        'sqlite_path' => '',
    ],
    'modules' => [
        'device_manager_enabled' => env_bool('MUSKY_DEVICE_MANAGER_ENABLED', true),
        'loaner_enabled' => env_bool('MUSKY_LOANER_ENABLED', false),
    ],
    'network' => [
        'campus_ips' => env_csv('MUSKY_CAMPUS_IPS', []),
    ],
    'identity' => [
        'allowed_email_domains' => env_csv('MUSKY_ALLOWED_EMAIL_DOMAINS', ['example.org', 'example.net']),
        'staff_domains' => env_csv('MUSKY_STAFF_DOMAINS', ['example.org']),
        'student_domains' => env_csv('MUSKY_STUDENT_DOMAINS', ['example.net']),
        'hdk_domains' => env_csv('MUSKY_HDK_DOMAINS', ['example.net']),
        'restricted_individual_domains' => env_csv('MUSKY_RESTRICTED_INDIVIDUAL_DOMAINS', ['example.net']),
        'teacher_email_domain' => env_string('MUSKY_TEACHER_EMAIL_DOMAIN', 'example.org'),
        'username_lookup_domains' => env_csv('MUSKY_USERNAME_LOOKUP_DOMAINS', ['example.net', 'example.org']),
    ],
    'email' => [
        'problem_report_to' => env_string('MUSKY_PROBLEM_REPORT_TO', 'helpdesk@example.org'),
        'sender_address' => env_string('MUSKY_EMAIL_SENDER_ADDRESS', 'noreply@example.org'),
    ],
    'paths' => [
        'mosbasic_binary' => '/usr/local/bin/mosbasic',
        'log_dir' => '/opt/musky/logs',
        'nora_template_dir' => $runtimeDir . '/Templates',
        'mosbasic_drop_dir' => $runtimeDir . '/MOSBasic-Drop',
    ],
    'nora_api' => [
        'base_url' => env_string('MUSKY_NORA_API_BASE_URL', 'http://127.0.0.1/api/NoraAPI.php?path='),
    ],
    'mosyle' => [
        'api_base_url' => env_string('MUSKY_MOSYLE_API_BASE_URL', 'https://managerapi.mosyle.com/v2'),
        'credentials_file' => $mosyleCredsPath,
    ],
    'debug' => [
        'nora_api_helper_log' => '/opt/musky/logs/nora_api_musky_helper.log',
        'loaner_invlookup_log' => '/opt/musky/logs/nora_api_invlookup.log',
        'loaner_invlookup_status_log' => '/opt/musky/logs/nora_api_invlookup_status.log',
    ],
    'dev' => [
        'host_suffix' => '.local.host',
        'default_test_user_email' => 'dev@example.org',
        'test_sqlite_path' => '/tmp/musky_users.sqlite',
    ],
    'google_sso' => [
        'client_id' => env_string('MUSKY_GOOGLE_CLIENT_ID', ''),
        'client_secret' => env_string('MUSKY_GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => env_string('MUSKY_GOOGLE_REDIRECT_URI', 'http://localhost:8088/SSO/Google/callback.php'),
        'service_keyfile' => env_string('MUSKY_GOOGLE_SERVICE_KEYFILE', $runtimeDir . '/GoogleCreds/musky-service.json'),
        'impersonate_admin' => env_string('MUSKY_GOOGLE_IMPERSONATE_ADMIN', 'admin@example.org'),
        'allowed_domains' => env_csv('MUSKY_GOOGLE_ALLOWED_DOMAINS', ['example.org']),
        'enable_debug_logs' => env_bool('MUSKY_GOOGLE_ENABLE_DEBUG_LOGS', false),
        'debug_log_dir' => env_string('MUSKY_GOOGLE_DEBUG_LOG_DIR', $runtimeDir . '/GoogleAuths'),
        'disable_temp_debug_files' => env_bool('MUSKY_GOOGLE_DISABLE_TEMP_DEBUG_FILES', true),
    ],
];

$noraConfig = [
    'host' => env_string('MUSKY_DB_HOST', 'host.docker.internal'),
    'port' => env_string('MUSKY_DB_PORT', '7306'),
    'user' => env_string('MUSKY_DB_USER', 'nora'),
    'pass' => env_string('MUSKY_DB_PASS', 'nora_password'),
    'name' => env_string('MUSKY_DB_NAME', 'nora'),
    'noraAPI' => [
        'auth' => [
            'trusted_internal_ips' => ['127.0.0.1', '::1'],
            'trusted_internal_cidrs' => [],
            'allow_db_fallback_auth' => false,
        ],
        'limits' => [
            'devices_max_serials' => 50,
            'errand_create' => [
                'trusted_internal_window_seconds' => 60,
                'trusted_internal_max_per_window' => 0,
                'external_window_seconds' => 60,
                'external_max_per_window' => 10,
            ],
        ],
        'service_client' => [
            'client_name' => 'Musky Internal Service',
            'token_file' => $serviceTokenPath,
            'required_permissions' => [
                'create_errands',
                'query_status',
                'verbose_status',
                'device_lookup',
                'user_device_lookup',
            ],
        ],
    ],
    '_musky' => [
        'musky_config_path' => $muskyConfigPath,
    ],
];

write_json_file($muskyConfigPath, $muskyConfig);
write_json_file($noraConfigPath, $noraConfig);

foreach ([$muskyConfigPath, $noraConfigPath] as $configPath) {
    @chown($configPath, 'www-data');
    @chgrp($configPath, 'www-data');
    @chmod($configPath, 0640);
}
