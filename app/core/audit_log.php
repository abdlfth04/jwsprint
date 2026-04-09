<?php

function auditLogTableReady(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $ready = false;
    }

    $result = $conn->query("SHOW TABLES LIKE 'audit_log'");
    return $ready = (bool) ($result && $result->num_rows > 0);
}

function writeAuditLog(string $action, string $entityType, string $summary, array $options = []): bool
{
    $action = trim($action);
    $entityType = trim($entityType);
    $summary = trim($summary);

    if ($action === '' || $entityType === '' || $summary === '' || !auditLogTableReady()) {
        return false;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return false;
    }

    $userId = array_key_exists('user_id', $options) ? (int) $options['user_id'] : (int) ($_SESSION['user_id'] ?? 0);
    $entityId = array_key_exists('entity_id', $options) ? (int) $options['entity_id'] : 0;
    $metadata = $options['metadata'] ?? null;
    $metadataJson = null;
    if ($metadata !== null) {
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $metadataJson = $encoded;
        }
    }

    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, aksi, entitas, entitas_id, ringkasan, metadata, ip_address, user_agent) VALUES (NULLIF(?, 0), ?, ?, NULLIF(?, 0), ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'ississss',
        $userId,
        $action,
        $entityType,
        $entityId,
        $summary,
        $metadataJson,
        $ipAddress,
        $userAgent
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function fetchRecentAuditLogs(int $limit = 20, array $filters = []): array
{
    if (!auditLogTableReady()) {
        return [];
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return [];
    }

    $limit = max(1, min($limit, 200));
    $where = [];
    $params = [];
    $types = '';

    if (!empty($filters['aksi'])) {
        $where[] = 'a.aksi = ?';
        $params[] = (string) $filters['aksi'];
        $types .= 's';
    }

    if (!empty($filters['entitas'])) {
        $where[] = 'a.entitas = ?';
        $params[] = (string) $filters['entitas'];
        $types .= 's';
    }

    if (array_key_exists('user_id', $filters) && $filters['user_id'] !== null) {
        $where[] = 'a.user_id = ?';
        $params[] = (int) $filters['user_id'];
        $types .= 'i';
    }

    if (!empty($filters['date'])) {
        $where[] = 'DATE(a.created_at) = ?';
        $params[] = (string) $filters['date'];
        $types .= 's';
    }

    $sql = "SELECT a.*, u.nama AS user_name, u.username
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= " ORDER BY a.created_at DESC LIMIT $limit";

    if (!$params) {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function getAuditSummaryCounts(): array
{
    $summary = [
        'today' => 0,
        'login' => 0,
        'file' => 0,
        'produksi' => 0,
    ];

    if (!auditLogTableReady()) {
        return $summary;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $summary;
    }

    $result = $conn->query("SELECT
        SUM(DATE(created_at) = CURDATE()) AS today_total,
        SUM(aksi IN ('login_success', 'logout') AND DATE(created_at) = CURDATE()) AS login_total,
        SUM(entitas = 'file_transaksi' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS file_total,
        SUM(entitas = 'produksi' AND DATE(created_at) = CURDATE()) AS produksi_total
        FROM audit_log");

    if (!$result) {
        return $summary;
    }

    $row = $result->fetch_assoc();
    if (!$row) {
        return $summary;
    }

    $summary['today'] = (int) ($row['today_total'] ?? 0);
    $summary['login'] = (int) ($row['login_total'] ?? 0);
    $summary['file'] = (int) ($row['file_total'] ?? 0);
    $summary['produksi'] = (int) ($row['produksi_total'] ?? 0);

    return $summary;
}

function auditActionLabel(string $action): string
{
    $map = [
        'login_success' => 'Login berhasil',
        'login_failed' => 'Login gagal',
        'login_blocked' => 'Login ditolak',
        'logout' => 'Logout',
        'transaksi_batal' => 'Batalkan transaksi',
        'transaksi_void' => 'VOID transaksi',
        'produksi_create_manual' => 'Tambah JO/SPK manual',
        'produksi_update' => 'Update produksi',
        'produksi_delete' => 'Hapus produksi',
        'file_upload' => 'Upload file',
        'file_delete' => 'Hapus file',
        'siap_cetak_submit_review' => 'Ajukan review siap cetak',
        'siap_cetak_approved' => 'Approve siap cetak',
        'siap_cetak_revisi' => 'Minta revisi siap cetak',
        'siap_cetak_draft' => 'Kembalikan ke draft siap cetak',
    ];

    return $map[$action] ?? ucwords(str_replace('_', ' ', $action));
}

function auditEntityLabel(string $entity): string
{
    $map = [
        'auth' => 'Autentikasi',
        'transaksi' => 'Transaksi',
        'produksi' => 'Produksi',
        'file_transaksi' => 'File Transaksi',
        'setting' => 'Setting',
    ];

    return $map[$entity] ?? ucwords(str_replace('_', ' ', $entity));
}

function formatAuditRelativeTime(?string $timestamp): string
{
    if (!$timestamp) {
        return '-';
    }

    $time = strtotime($timestamp);
    if ($time === false) {
        return '-';
    }

    $diff = time() - $time;
    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }
    if ($diff < 604800) {
        return floor($diff / 86400) . ' hari lalu';
    }

    return date('d/m/Y H:i', $time);
}
