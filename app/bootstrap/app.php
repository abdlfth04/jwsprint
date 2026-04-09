<?php
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__, 2));
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/config/env.php';

loadProjectEnvFile(PROJECT_ROOT . DIRECTORY_SEPARATOR . '.env');

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', envBool('APP_DEBUG', false));
}

if (!function_exists('appPasswordMinLength')) {
    function appPasswordMinLength(): int
    {
        $value = (int) envValue('APP_PASSWORD_MIN_LENGTH', 8);

        return max(8, min($value, 128));
    }
}

if (!function_exists('appPrivateStorageRootPath')) {
    function appPrivateStorageRootPath(): string
    {
        $configuredPath = trim((string) envValueAllowEmpty('APP_PRIVATE_STORAGE_PATH', ''));
        if ($configuredPath !== '') {
            return rtrim($configuredPath, "\\/");
        }

        return dirname(PROJECT_ROOT) . DIRECTORY_SEPARATOR . '.jws_private';
    }
}

if (!function_exists('appPrivateStoragePath')) {
    function appPrivateStoragePath(string $path = ''): string
    {
        $root = appPrivateStorageRootPath();
        $relativePath = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if ($relativePath === '') {
            return $root;
        }

        return $root . DIRECTORY_SEPARATOR . $relativePath;
    }
}

if (!function_exists('appSchemaAutoMigrateEnabled')) {
    function appSchemaAutoMigrateEnabled(): bool
    {
        return envBool('APP_SCHEMA_AUTO_MIGRATE', APP_DEBUG);
    }
}

if (!function_exists('appBackupDownloadEnabled')) {
    function appBackupDownloadEnabled(): bool
    {
        return envBool('APP_ALLOW_BACKUP_DOWNLOAD', true);
    }
}

if (!function_exists('appBackupRestoreEnabled')) {
    function appBackupRestoreEnabled(): bool
    {
        return envBool('APP_ALLOW_BACKUP_RESTORE', APP_DEBUG);
    }
}

if (!function_exists('appSendHeaderIfMissing')) {
    function appSendHeaderIfMissing(string $name, string $value): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $normalizedName = strtolower($name) . ':';
        foreach (headers_list() as $existingHeader) {
            if (strpos(strtolower($existingHeader), $normalizedName) === 0) {
                return;
            }
        }

        header($name . ': ' . $value);
    }
}

if (!function_exists('appRuntimeLogPath')) {
    function appRuntimeLogPath(): string
    {
        return appPrivateStoragePath('logs' . DIRECTORY_SEPARATOR . 'php-runtime.log');
    }
}

if (!function_exists('appLogRuntimeIssue')) {
    function appLogRuntimeIssue(string $type, array $context = []): void
    {
        $logPath = appRuntimeLogPath();
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $payload = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'context' => $context,
        ];

        @file_put_contents(
            $logPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }
}

if (!function_exists('bootstrapRuntimeDiagnostics')) {
    function bootstrapRuntimeDiagnostics(): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $initialized = true;

        set_exception_handler(static function (Throwable $throwable): void {
            appLogRuntimeIssue('uncaught_exception', [
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ]);

            if (!headers_sent()) {
                http_response_code(500);
            }

            if (APP_DEBUG) {
                echo 'Unhandled exception: ' . $throwable->getMessage();
            }
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!$error) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
                return;
            }

            appLogRuntimeIssue('fatal_error', [
                'message' => (string) ($error['message'] ?? ''),
                'file' => (string) ($error['file'] ?? ''),
                'line' => (int) ($error['line'] ?? 0),
            ]);
        });
    }
}

bootstrapRuntimeDiagnostics();

if (!function_exists('isHttpsRequest')) {
    function isHttpsRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto === 'https') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
    }
}

if (!function_exists('configureSessionSecurity')) {
    function appSessionLifetimeSeconds(): int
    {
        $days = (int) envValue('APP_SESSION_LIFETIME_DAYS', 0);
        if ($days <= 0) {
            return 0;
        }

        return max(0, min($days, 365)) * 86400;
    }

    function appSessionCookieSettings(): array
    {
        $sameSite = ucfirst(strtolower((string) envValue('APP_SESSION_SAMESITE', 'Lax')));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        $sessionSecure = envBool('APP_SESSION_SECURE', isHttpsRequest() || $sameSite === 'None');
        $sessionHttpOnly = envBool('APP_SESSION_HTTPONLY', true);
        $sessionPath = (string) envValue('APP_SESSION_PATH', '/');
        $sessionDomain = trim((string) envValue('APP_SESSION_DOMAIN', ''));

        return [
            'lifetime' => appSessionLifetimeSeconds(),
            'path' => $sessionPath,
            'domain' => $sessionDomain,
            'secure' => $sessionSecure,
            'httponly' => $sessionHttpOnly,
            'samesite' => $sameSite,
        ];
    }

    function configureSessionSecurity(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $settings = appSessionCookieSettings();

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_secure', !empty($settings['secure']) ? '1' : '0');
        ini_set('session.cookie_httponly', !empty($settings['httponly']) ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string) max(1440, (int) ($settings['lifetime'] ?? 0)));
        ini_set('session.cookie_lifetime', (string) max(0, (int) ($settings['lifetime'] ?? 0)));

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($settings);
            return;
        }

        session_set_cookie_params(
            (int) ($settings['lifetime'] ?? 0),
            (string) ($settings['path'] ?? '/') . '; samesite=' . (string) ($settings['samesite'] ?? 'Lax'),
            (string) ($settings['domain'] ?? ''),
            !empty($settings['secure']),
            !empty($settings['httponly'])
        );
    }
}

if (!function_exists('refreshSessionCookieLifetime')) {
    function refreshSessionCookieLifetime(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent() || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $settings = appSessionCookieSettings();
        $lifetime = (int) ($settings['lifetime'] ?? 0);
        if ($lifetime <= 0) {
            return;
        }

        $expiresAt = time() + $lifetime;

        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), session_id(), [
                'expires' => $expiresAt,
                'path' => (string) ($settings['path'] ?? '/'),
                'domain' => (string) ($settings['domain'] ?? ''),
                'secure' => !empty($settings['secure']),
                'httponly' => !empty($settings['httponly']),
                'samesite' => (string) ($settings['samesite'] ?? 'Lax'),
            ]);
            return;
        }

        setcookie(
            session_name(),
            session_id(),
            $expiresAt,
            (string) ($settings['path'] ?? '/') . '; samesite=' . (string) ($settings['samesite'] ?? 'Lax'),
            (string) ($settings['domain'] ?? ''),
            !empty($settings['secure']),
            !empty($settings['httponly'])
        );
    }
}

if (!function_exists('enforceHttpsRedirectIfNeeded')) {
    function enforceHttpsRedirectIfNeeded(): void
    {
        if (!envBool('APP_FORCE_HTTPS', !APP_DEBUG) || isHttpsRequest()) {
            return;
        }

        $rawHost = (string) envValue('APP_HTTPS_HOST', $_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', trim($rawHost));
        if ($host === '') {
            return;
        }

        $port = (int) envValue('APP_HTTPS_PORT', 443);
        $targetHost = $port > 0 && $port !== 443 ? $host . ':' . $port : $host;
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        header('Location: https://' . $targetHost . $requestUri, true, 302);
        exit;
    }
}

if (!function_exists('applyAppSecurityHeaders')) {
    function applyAppSecurityHeaders(): void
    {
        if (!envBool('APP_SECURITY_HEADERS_ENABLED', true)) {
            return;
        }

        appSendHeaderIfMissing('X-Content-Type-Options', 'nosniff');
        appSendHeaderIfMissing('X-Frame-Options', 'SAMEORIGIN');
        appSendHeaderIfMissing('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (isHttpsRequest() && envBool('APP_ENABLE_HSTS', !APP_DEBUG)) {
            $maxAge = max(300, (int) envValue('APP_HSTS_MAX_AGE', 15552000));
            $value = 'max-age=' . $maxAge;

            if (envBool('APP_HSTS_INCLUDE_SUBDOMAINS', false)) {
                $value .= '; includeSubDomains';
            }

            if (envBool('APP_HSTS_PRELOAD', false)) {
                $value .= '; preload';
            }

            appSendHeaderIfMissing('Strict-Transport-Security', $value);
        }
    }
}

if (!function_exists('applyDynamicResponseCacheHeaders')) {
    function applyDynamicResponseCacheHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $extension = strtolower(pathinfo($requestPath, PATHINFO_EXTENSION));

        // Dynamic app routes include the project root and PHP endpoints that can embed session-bound CSRF tokens.
        if ($requestPath !== '' && $extension !== '' && $extension !== 'php') {
            return;
        }

        appSendHeaderIfMissing('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        appSendHeaderIfMissing('Pragma', 'no-cache');
        appSendHeaderIfMissing('Expires', '0');
    }
}

configureSessionSecurity();
enforceHttpsRedirectIfNeeded();
applyAppSecurityHeaders();
applyDynamicResponseCacheHeaders();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

refreshSessionCookieLifetime();

if (!defined('BASE_URL')) {
    $projectRoot = str_replace('\\', '/', (string) realpath(PROJECT_ROOT));
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'])) : '';

    if ($projectRoot !== '' && $documentRoot !== '' && strpos($projectRoot, $documentRoot) === 0) {
        $relativePath = substr($projectRoot, strlen($documentRoot));
        define('BASE_URL', rtrim($relativePath, '/'));
    } else {
        define('BASE_URL', '');
    }
}

if (!function_exists('baseUrl')) {
    function baseUrl(string $path = ''): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $path = trim($path);

        if ($path === '') {
            return $base;
        }

        $normalizedPath = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if ($base === '' || $base === '/') {
            return $normalizedPath;
        }

        return rtrim($base, '/') . $normalizedPath;
    }
}

if (!function_exists('pageUrl')) {
    function pageUrl(string $page = ''): string
    {
        return baseUrl('pages/' . ltrim($page, '/'));
    }
}

if (!function_exists('assetUrl')) {
    function assetUrl(string $asset = ''): string
    {
        static $cache = [];

        $cacheKey = (string) $asset;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $relativeAsset = 'public/' . ltrim($asset, '/');
        $url = baseUrl($relativeAsset);
        $absolutePath = PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeAsset);

        if ($asset !== '' && is_file($absolutePath)) {
            $url .= '?v=' . filemtime($absolutePath);
        }

        return $cache[$cacheKey] = $url;
    }
}

if (!function_exists('brandMarkUrl')) {
    function brandMarkUrl(): string
    {
        return assetUrl('img/pwa-icon.svg');
    }
}

if (!function_exists('companyLogoUrl')) {
    function companyLogoUrl(): string
    {
        return assetUrl('img/logo.png');
    }
}

if (!function_exists('pwaIconUrl')) {
    function pwaIconUrl(): string
    {
        return assetUrl('img/pwa-icon-192.png');
    }
}

if (!function_exists('pwaTouchIconUrl')) {
    function pwaTouchIconUrl(): string
    {
        return assetUrl('img/pwa-icon-512.png');
    }
}

if (!function_exists('uploadUrl')) {
    function uploadUrl(string $path = ''): string
    {
        return baseUrl('uploads/' . ltrim($path, '/'));
    }
}

if (!function_exists('appMediaUrl')) {
    function appMediaUrl(string $type, string $filename = ''): string
    {
        $cleanType = strtolower(trim($type));
        $cleanFilename = basename(trim($filename));

        if ($cleanType === '' || $cleanFilename === '' || $cleanFilename === '.' || $cleanFilename === '..') {
            return '';
        }

        return pageUrl('media.php?type=' . rawurlencode($cleanType) . '&file=' . rawurlencode($cleanFilename));
    }
}

if (!function_exists('employeePhotoUrl')) {
    function employeePhotoUrl(string $filename = ''): string
    {
        return appMediaUrl('karyawan', $filename);
    }
}

if (!function_exists('attendancePhotoUrl')) {
    function attendancePhotoUrl(string $filename = ''): string
    {
        return appMediaUrl('absensi', $filename);
    }
}

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/core/schema.php';
require_once APP_ROOT . '/services/customer_support.php';
require_once APP_ROOT . '/core/csrf.php';
require_once APP_ROOT . '/core/auth.php';
require_once APP_ROOT . '/core/audit_log.php';
require_once APP_ROOT . '/services/transaction_file_scope.php';
require_once APP_ROOT . '/services/ready_print_manager.php';
require_once APP_ROOT . '/services/transaction_payment.php';
require_once APP_ROOT . '/services/transaction_order_manager.php';
require_once APP_ROOT . '/services/transaction_workflow.php';
require_once APP_ROOT . '/services/payroll_schedule.php';
require_once APP_ROOT . '/services/notifications.php';
require_once APP_ROOT . '/services/web_push.php';
require_once APP_ROOT . '/services/realtime_stream.php';
require_once APP_ROOT . '/services/material_inventory.php';

customerSupportReady($conn);

enforceCsrfProtectionForStateChangingRequests();
