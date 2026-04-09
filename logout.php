<?php
require_once __DIR__ . '/app/bootstrap/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

if (isLoggedIn()) {
    writeAuditLog(
        'logout',
        'auth',
        ($_SESSION['nama'] ?? 'User') . ' logout dari sistem.',
        [
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'metadata' => ['username' => $_SESSION['username'] ?? null, 'role' => $_SESSION['role'] ?? null],
        ]
    );
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    } else {
        setcookie(
            session_name(),
            '',
            time() - 42000,
            ($params['path'] ?? '/') . '; samesite=' . ($params['samesite'] ?? 'Lax'),
            $params['domain'] ?? '',
            !empty($params['secure']),
            !empty($params['httponly'])
        );
    }
}

session_destroy();

header('Location: ' . loginUrl());
exit;
