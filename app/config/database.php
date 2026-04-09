<?php

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__, 2));
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/config/env.php';

loadProjectEnvFile(PROJECT_ROOT . DIRECTORY_SEPARATOR . '.env');

$dbHost = (string) envValueAllowEmpty('DB_HOST', 'localhost');
$dbPort = (int) envValueAllowEmpty('DB_PORT', 3306);
$dbUser = envValueAllowEmpty('DB_USER', null);
$dbPass = envValueAllowEmpty('DB_PASS', null);
$dbName = envValueAllowEmpty('DB_NAME', null);

$missingConfig = [];
if ($dbUser === null || trim((string) $dbUser) === '') {
    $missingConfig[] = 'DB_USER';
}
if ($dbPass === null) {
    $missingConfig[] = 'DB_PASS';
}
if ($dbName === null || trim((string) $dbName) === '') {
    $missingConfig[] = 'DB_NAME';
}

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', envBool('APP_DEBUG', false));
}

if (!empty($missingConfig)) {
    http_response_code(500);

    $message = APP_DEBUG
        ? 'Konfigurasi database belum lengkap. Variabel yang wajib diisi: ' . implode(', ', $missingConfig)
        : 'Konfigurasi database belum lengkap. Periksa file environment aplikasi.';

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    exit($message);
}

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_USER', (string) $dbUser);
define('DB_PASS', (string) $dbPass);
define('DB_NAME', (string) $dbName);

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) {
    $message = APP_DEBUG
        ? 'Koneksi database gagal: ' . $conn->connect_error
        : 'Koneksi database gagal. Periksa konfigurasi environment.';

    http_response_code(500);

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    exit($message);
}

$conn->set_charset('utf8mb4');
