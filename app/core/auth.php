<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function loginUrl() {
    return pageUrl('login.php');
}

function redirectToLogin() {
    header('Location: ' . loginUrl());
    exit;
}

function hasRole(...$roles) {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}

function getMaintenanceConfig() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [
        'active' => false,
        'message' => 'Sistem sedang maintenance. Silakan coba beberapa saat lagi.',
    ];

    global $conn;
    if (!isset($conn) || !$conn) return $cache;

    if (!schemaTableExists($conn, 'setting')) return $cache;

    if (!schemaColumnExists($conn, 'setting', 'maintenance_mode')) return $cache;

    $fields = 'maintenance_mode';
    if (schemaColumnExists($conn, 'setting', 'maintenance_msg')) $fields .= ', maintenance_msg';

    $result = $conn->query("SELECT $fields FROM setting WHERE id = 1 LIMIT 1");
    if (!$result) return $cache;

    $row = $result->fetch_assoc();
    if (!$row) return $cache;

    $cache['active'] = !empty($row['maintenance_mode']);
    if (!empty($row['maintenance_msg'])) {
        $cache['message'] = $row['maintenance_msg'];
    }

    return $cache;
}

function isMaintenanceActive() {
    return getMaintenanceConfig()['active'];
}

function getMaintenanceMessage() {
    return getMaintenanceConfig()['message'];
}

function loginThrottleConfig(): array
{
    return [
        'window_seconds' => 15 * 60,
        'max_attempts' => 5,
        'lock_seconds' => 15 * 60,
    ];
}

function loginThrottleDirectory(): ?string
{
    static $directory = null;
    static $initialized = false;

    if ($initialized) {
        return $directory;
    }
    $initialized = true;

    if (!defined('PROJECT_ROOT')) {
        return null;
    }

    if (function_exists('appPrivateStoragePath')) {
        $directory = appPrivateStoragePath('login_attempts');
    } else {
        $directory = PROJECT_ROOT
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'login_attempts';
    }

    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return null;
    }

    return $directory;
}

function loginThrottleKey(string $username): string
{
    $normalizedUsername = strtolower(trim($username));
    $ipAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    return hash('sha256', $normalizedUsername . '|' . $ipAddress);
}

function loginThrottlePath(string $username): ?string
{
    $directory = loginThrottleDirectory();
    if ($directory === null || $username === '') {
        return null;
    }

    return $directory . DIRECTORY_SEPARATOR . loginThrottleKey($username) . '.json';
}

function loginThrottleDefaultState(): array
{
    return [
        'attempts' => 0,
        'last_attempt_at' => 0,
        'lock_until' => 0,
    ];
}

function loginThrottleReadState(string $username): array
{
    $path = loginThrottlePath($username);
    if ($path === null || !is_file($path)) {
        return loginThrottleDefaultState();
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return loginThrottleDefaultState();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return loginThrottleDefaultState();
    }

    return [
        'attempts' => max(0, (int) ($decoded['attempts'] ?? 0)),
        'last_attempt_at' => max(0, (int) ($decoded['last_attempt_at'] ?? 0)),
        'lock_until' => max(0, (int) ($decoded['lock_until'] ?? 0)),
    ];
}

function loginThrottleWriteState(string $username, array $state): void
{
    $path = loginThrottlePath($username);
    if ($path === null) {
        return;
    }

    $payload = json_encode([
        'attempts' => max(0, (int) ($state['attempts'] ?? 0)),
        'last_attempt_at' => max(0, (int) ($state['last_attempt_at'] ?? 0)),
        'lock_until' => max(0, (int) ($state['lock_until'] ?? 0)),
    ]);

    if (!is_string($payload)) {
        return;
    }

    @file_put_contents($path, $payload, LOCK_EX);
}

function loginThrottleReset(string $username): void
{
    $path = loginThrottlePath($username);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

function loginThrottleStatus(string $username): array
{
    $config = loginThrottleConfig();
    $state = loginThrottleReadState($username);
    $now = time();
    $windowSeconds = max(60, (int) ($config['window_seconds'] ?? 900));

    if ($state['last_attempt_at'] > 0 && ($now - $state['last_attempt_at']) > $windowSeconds && $state['lock_until'] <= $now) {
        loginThrottleReset($username);
        $state = loginThrottleDefaultState();
    }

    $secondsRemaining = max(0, $state['lock_until'] - $now);

    return [
        'locked' => $secondsRemaining > 0,
        'seconds_remaining' => $secondsRemaining,
        'attempts' => (int) ($state['attempts'] ?? 0),
        'lock_until' => (int) ($state['lock_until'] ?? 0),
    ];
}

function loginThrottleRegisterFailure(string $username): array
{
    $config = loginThrottleConfig();
    $state = loginThrottleReadState($username);
    $now = time();
    $windowSeconds = max(60, (int) ($config['window_seconds'] ?? 900));
    $maxAttempts = max(3, (int) ($config['max_attempts'] ?? 5));
    $lockSeconds = max(60, (int) ($config['lock_seconds'] ?? 900));

    if ($state['last_attempt_at'] <= 0 || ($now - $state['last_attempt_at']) > $windowSeconds) {
        $state = loginThrottleDefaultState();
    }

    $state['attempts'] = max(0, (int) ($state['attempts'] ?? 0)) + 1;
    $state['last_attempt_at'] = $now;
    if ($state['attempts'] >= $maxAttempts) {
        $state['lock_until'] = $now + $lockSeconds;
    }

    loginThrottleWriteState($username, $state);

    return loginThrottleStatus($username);
}

function loginThrottleMessage(array $status): string
{
    $seconds = max(0, (int) ($status['seconds_remaining'] ?? 0));
    if ($seconds <= 0) {
        return 'Terlalu banyak percobaan login. Coba lagi beberapa saat lagi.';
    }

    $minutes = intdiv($seconds, 60);
    $remainingSeconds = $seconds % 60;
    $parts = [];
    if ($minutes > 0) {
        $parts[] = $minutes . ' menit';
    }
    if ($remainingSeconds > 0 || empty($parts)) {
        $parts[] = $remainingSeconds . ' detik';
    }

    return 'Terlalu banyak percobaan login. Coba lagi dalam ' . implode(' ', $parts) . '.';
}

function showMaintenancePage() {
    $message = htmlspecialchars(getMaintenanceMessage(), ENT_QUOTES, 'UTF-8');
    $baseCss = function_exists('assetUrl') ? assetUrl('css/base.css') : '/public/css/base.css';
    $componentCss = function_exists('assetUrl') ? assetUrl('css/components.css') : '/public/css/components.css';

    http_response_code(503);
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Maintenance</title>
    <link rel="stylesheet" href="'.$baseCss.'">
    <link rel="stylesheet" href="'.$componentCss.'">
    <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:Inter,sans-serif;padding:24px}
    .maintenance-box{max-width:480px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:32px;text-align:center;box-shadow:0 20px 45px rgba(15,23,42,.08)}
    .maintenance-badge{display:inline-flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:50%;background:#fef3c7;color:#b45309;font-size:32px;margin-bottom:18px}
    h1{margin:0 0 10px;font-size:1.6rem;color:#0f172a}
    p{margin:0;color:#475569;line-height:1.6}
    .maintenance-note{margin-top:18px;font-size:.9rem;color:#64748b}
    </style></head><body>
    <div class="maintenance-box">
        <div class="maintenance-badge">!</div>
        <h1>Sistem Sedang Maintenance</h1>
        <p>'.$message.'</p>
        <div class="maintenance-note">Silakan hubungi superadmin jika Anda memerlukan akses darurat.</div>
    </div>
    </body></html>';
    exit;
}

function enforceMaintenanceMode() {
    if (isMaintenanceActive() && !hasRole('superadmin')) {
        showMaintenancePage();
    }
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirectToLogin();
    }

    enforceMaintenanceMode();
}

function hasCustomPermission($halaman) {
    if (!isLoggedIn()) return false;

    static $cache = [];

    global $conn;
    if (!isset($conn) || !$conn) return false;

    $uid = intval($_SESSION['user_id']);
    $hal = basename($halaman);
    $cacheKey = $uid . '|' . $hal;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!schemaTableExists($conn, 'user_permissions')) {
        return $cache[$cacheKey] = false;
    }

    $stmt = $conn->prepare("SELECT id FROM user_permissions WHERE user_id = ? AND halaman = ? AND aktif = 1 AND (expired_at IS NULL OR expired_at > NOW()) LIMIT 1");
    if (!$stmt) {
        return $cache[$cacheKey] = false;
    }

    $stmt->bind_param('is', $uid, $hal);
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed = (bool) ($result && $result->num_rows > 0);
    $stmt->close();

    return $cache[$cacheKey] = $allowed;
}

function currentUserEmployeeId(): ?int
{
    static $resolved = false;
    static $employeeId = null;

    if ($resolved) {
        return $employeeId;
    }
    $resolved = true;

    if (!isLoggedIn()) {
        return null;
    }

    global $conn;
    if (!isset($conn) || !$conn || !schemaTableExists($conn, 'karyawan')) {
        return null;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id FROM karyawan WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $employeeId = !empty($row['id']) ? (int) $row['id'] : null;

    return $employeeId;
}

function canAccessTransactionDetail($transaksiId) {
    if (!isLoggedIn() || $transaksiId <= 0) return false;
    if (hasRole('superadmin', 'admin', 'service', 'kasir')) return true;
    return hasCustomPermission('transaksi.php');
}

function canAccessProductionRecord(int $produksiId): bool
{
    static $cache = [];

    if (!isLoggedIn() || $produksiId <= 0) {
        return false;
    }

    if (hasRole('superadmin', 'admin', 'service')) {
        return true;
    }

    if (hasCustomPermission('produksi.php')) {
        return true;
    }

    global $conn;
    if (!isset($conn) || !$conn || !schemaTableExists($conn, 'produksi')) {
        return false;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $employeeId = currentUserEmployeeId();
    $employeeBound = $employeeId !== null ? 1 : 0;
    $employeeIdValue = $employeeId ?? 0;
    $cacheKey = implode('|', [$userId, $employeeIdValue, $produksiId]);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $hasTodoTable = schemaTableExists($conn, 'todo_list_tahapan');
    $sql = $hasTodoTable
        ? "SELECT pr.id
           FROM produksi pr
           LEFT JOIN todo_list_tahapan tt ON tt.produksi_id = pr.id
           WHERE pr.id = ?
             AND (
                 pr.status IN ('antrian', 'proses')
                 OR
                 tt.user_id = ?
                 OR (? = 1 AND pr.karyawan_id = ?)
             )
           LIMIT 1"
        : "SELECT pr.id
           FROM produksi pr
           WHERE pr.id = ?
             AND (
                 pr.status IN ('antrian', 'proses')
                 OR (? = 1 AND pr.karyawan_id = ?)
             )
           LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($hasTodoTable) {
        $stmt->bind_param('iiii', $produksiId, $userId, $employeeBound, $employeeIdValue);
    } else {
        $stmt->bind_param('iii', $produksiId, $employeeBound, $employeeIdValue);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed = (bool) ($result && $result->num_rows > 0);
    $stmt->close();

    return $cache[$cacheKey] = $allowed;
}

function canCurrentUserAccessTransactionFileScope(int $transaksiId, ?int $detailTransaksiId = null): bool
{
    static $cache = [];

    if (!isLoggedIn() || $transaksiId <= 0) {
        return false;
    }

    global $conn;
    if (!isset($conn) || !$conn || !schemaTableExists($conn, 'produksi')) {
        return false;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $employeeId = currentUserEmployeeId();
    $employeeBound = $employeeId !== null ? 1 : 0;
    $employeeIdValue = $employeeId ?? 0;
    $detailValue = max(0, (int) ($detailTransaksiId ?? 0));
    $detailBound = $detailValue > 0 ? 1 : 0;
    $cacheKey = implode('|', [$userId, $employeeIdValue, $transaksiId, $detailValue]);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $hasTodoTable = schemaTableExists($conn, 'todo_list_tahapan');
    $sql = $hasTodoTable
        ? "SELECT pr.id
           FROM produksi pr
           LEFT JOIN todo_list_tahapan tt ON tt.produksi_id = pr.id
           WHERE pr.transaksi_id = ?
             AND (
                 ? = 0
                 OR pr.detail_transaksi_id = ?
                 OR pr.detail_transaksi_id IS NULL
                 OR pr.detail_transaksi_id = 0
             )
             AND (
                 pr.status IN ('antrian', 'proses')
                 OR
                 tt.user_id = ?
                 OR (? = 1 AND pr.karyawan_id = ?)
             )
           LIMIT 1"
        : "SELECT pr.id
           FROM produksi pr
           WHERE pr.transaksi_id = ?
             AND (
                 ? = 0
                 OR pr.detail_transaksi_id = ?
                 OR pr.detail_transaksi_id IS NULL
                 OR pr.detail_transaksi_id = 0
             )
             AND (
                 pr.status IN ('antrian', 'proses')
                 OR (? = 1 AND pr.karyawan_id = ?)
             )
           LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($hasTodoTable) {
        $stmt->bind_param('iiiiii', $transaksiId, $detailBound, $detailValue, $userId, $employeeBound, $employeeIdValue);
    } else {
        $stmt->bind_param('iiiii', $transaksiId, $detailBound, $detailValue, $employeeBound, $employeeIdValue);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed = (bool) ($result && $result->num_rows > 0);
    $stmt->close();

    return $cache[$cacheKey] = $allowed;
}

function canAccessFileDownload($transaksiId, ?int $detailTransaksiId = null) {
    if (!isLoggedIn() || $transaksiId <= 0) return false;
    if (hasRole('superadmin', 'admin', 'service', 'kasir')) return true;
    if (hasCustomPermission('transaksi.php') || hasCustomPermission('produksi.php') || hasCustomPermission('siap_cetak.php')) return true;
    if (!hasRole('user')) return false;

    return canCurrentUserAccessTransactionFileScope((int) $transaksiId, $detailTransaksiId);
}

function canManageTransactionFiles($transaksiId, ?int $detailTransaksiId = null) {
    if (!isLoggedIn() || $transaksiId <= 0) return false;
    if (hasRole('superadmin', 'admin', 'service')) return true;
    if (hasCustomPermission('transaksi.php') || hasCustomPermission('siap_cetak.php')) return true;
    if (!hasRole('user')) return false;

    return canCurrentUserAccessTransactionFileScope((int) $transaksiId, $detailTransaksiId);
}

function requireRole(...$roles) {
    requireLogin();
    if (!hasRole(...$roles)) {
        $currentPage = basename($_SERVER['PHP_SELF']);
        if (hasCustomPermission($currentPage)) return;

        http_response_code(403);
        $baseCss = function_exists('assetUrl') ? assetUrl('css/base.css') : '/public/css/base.css';
        $layoutCss = function_exists('assetUrl') ? assetUrl('css/layout.css') : '/public/css/layout.css';
        $componentCss = function_exists('assetUrl') ? assetUrl('css/components.css') : '/public/css/components.css';
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title>
        <link rel="stylesheet" href="'.$baseCss.'">
        <link rel="stylesheet" href="'.$layoutCss.'">
        <link rel="stylesheet" href="'.$componentCss.'">
        </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
        <div class="card" style="text-align:center;max-width:400px;padding:40px">
            <i class="fas fa-lock" style="font-size:3rem;color:var(--danger)"></i>
            <h2 style="margin:16px 0 8px">Akses Ditolak</h2>
            <p style="color:var(--text-muted)">Anda tidak memiliki izin untuk mengakses halaman ini.</p>
            <a href="javascript:history.back()" class="btn btn-primary" style="margin-top:16px">Kembali</a>
        </div></body></html>';
        exit;
    }
}

function currentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'nama' => $_SESSION['nama'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'foto' => $_SESSION['foto'] ?? null,
    ];
}
