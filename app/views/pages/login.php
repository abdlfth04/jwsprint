<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

if (isLoggedIn()) {
    header('Location: ' . pageUrl('dashboard.php'));
    exit;
}

$maintenanceActive = isMaintenanceActive();
$maintenanceMessage = getMaintenanceMessage();
$companyLogoUrl = companyLogoUrl();
$error = '';

if (($_GET['security'] ?? '') === 'expired') {
    $error = 'Sesi keamanan login sudah kedaluwarsa. Halaman sudah dimuat ulang, silakan coba login kembali.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif (($throttle = loginThrottleStatus($username))['locked']) {
        writeAuditLog(
            'login_blocked',
            'auth',
            'Login diblokir sementara karena terlalu banyak percobaan gagal.',
            [
                'metadata' => [
                    'username' => $username,
                    'reason' => 'too_many_attempts',
                    'seconds_remaining' => (int) ($throttle['seconds_remaining'] ?? 0),
                ],
            ]
        );
        $error = loginThrottleMessage($throttle);
    } else {
        $stmt = $conn->prepare("SELECT id, nama, username, password, role, foto, status FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'aktif') {
                writeAuditLog(
                    'login_blocked',
                    'auth',
                    'Login ditolak karena akun tidak aktif.',
                    [
                        'user_id' => (int) $user['id'],
                        'metadata' => ['username' => $username, 'reason' => 'status_nonaktif'],
                    ]
                );
                $error = 'Akun Anda tidak aktif. Silakan hubungi administrator.';
            } elseif ($maintenanceActive && $user['role'] !== 'superadmin') {
                writeAuditLog(
                    'login_blocked',
                    'auth',
                    'Login ditolak karena maintenance mode aktif.',
                    [
                        'user_id' => (int) $user['id'],
                        'metadata' => ['username' => $username, 'reason' => 'maintenance_mode'],
                    ]
                );
                $error = $maintenanceMessage;
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama'] = $user['nama'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['foto'] = $user['foto'];

                writeAuditLog(
                    'login_success',
                    'auth',
                    $user['nama'] . ' berhasil login.',
                    [
                        'user_id' => (int) $user['id'],
                        'metadata' => ['username' => $user['username'], 'role' => $user['role']],
                    ]
                );

                loginThrottleReset($username);
                header('Location: ' . pageUrl('dashboard.php'));
                exit;
            }
        } else {
            $throttle = loginThrottleRegisterFailure($username);
            writeAuditLog(
                'login_failed',
                'auth',
                'Percobaan login gagal.',
                [
                    'user_id' => isset($user['id']) ? (int) $user['id'] : 0,
                    'metadata' => [
                        'username' => $username,
                        'attempts' => (int) ($throttle['attempts'] ?? 0),
                        'locked' => !empty($throttle['locked']),
                    ],
                ]
            );
            $error = !empty($throttle['locked'])
                ? loginThrottleMessage($throttle)
                : 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - JWS</title>
    <meta name="theme-color" content="#0f766e">
    <?= csrfMetaTag() ?>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?= baseUrl('manifest.webmanifest') ?>?v=20260403-pwa-logo">
    <link rel="icon" href="<?= pwaIconUrl() ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?= pwaTouchIconUrl() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="<?= assetUrl('css/base.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/components.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/login.css') ?>?v=20260403-simple">
    <script>
    window.APP_CSRF_TOKEN = <?= json_encode(csrfToken(), JSON_UNESCAPED_SLASHES) ?>;
    window.WEB_PUSH_PUBLIC_KEY = <?= json_encode((string) (webPushGetVapidConfig()['public_key'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    window.WEB_PUSH_CONFIGURED = <?= json_encode(!empty(webPushGetVapidConfig()['configured'])) ?>;
    </script>
</head>
<body class="auth-page">
    <div class="auth-shell">
        <section class="auth-card">
            <div class="auth-brand">
                <img src="<?= $companyLogoUrl ?>" alt="Logo perusahaan JWS Printing & Apparel" class="auth-logo" width="220" height="100">
                <h1 class="auth-title">JWS Printing & Apparel</h1>
                <p class="auth-subtitle">Integrated Management System</p>
            </div>

            <?php if ($maintenanceActive): ?>
                <div class="alert alert-warning">
                    <div>Sistem sedang maintenance. Hanya superadmin yang dapat login sementara waktu.</div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <?= csrfInput() ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan username" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password" required>
                </div>

                <button type="submit" class="btn btn-primary btn-lg auth-submit">Login</button>
            </form>
        </section>
    </div>

    <script>window.APP_BASE_URL = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= assetUrl('js/main.js') ?>"></script>
</body>
</html>
