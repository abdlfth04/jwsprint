<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/file_manager.php';
requireRole('superadmin');

const SQL_RESTORE_MAX_BYTES = 50 * 1024 * 1024;

$backupDownloadEnabled = function_exists('appBackupDownloadEnabled') ? appBackupDownloadEnabled() : true;
$backupRestoreEnabled = function_exists('appBackupRestoreEnabled') ? appBackupRestoreEnabled() : APP_DEBUG;

$allowedSqlMimeTypes = [
    'application/sql',
    'application/octet-stream',
    'text/plain',
    'text/x-sql',
];

function backupRestoreBlockedStatement(string $sql): ?string
{
    $patterns = [
        '/\b(?:CREATE|DROP|ALTER)\s+DATABASE\b/i' => 'statement level database',
        '/\bUSE\s+[`"\']?[a-z0-9_]+[`"\']?\b/i' => 'statement USE database',
        '/\b(?:CREATE|ALTER|DROP)\s+USER\b/i' => 'statement akun database',
        '/\b(?:GRANT|REVOKE)\b/i' => 'statement hak akses database',
        '/\bSET\s+(?:GLOBAL|PERSIST)\b/i' => 'statement konfigurasi global database',
    ];

    foreach ($patterns as $pattern => $label) {
        if (preg_match($pattern, $sql) === 1) {
            return $label;
        }
    }

    return null;
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

if ($action === 'download') {
    if ($requestMethod !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Download backup harus memakai request POST yang valid.');
    }

    if (!$backupDownloadEnabled) {
        http_response_code(403);
        exit('Fitur backup database via web dinonaktifkan di environment ini.');
    }

    $dbName = DB_NAME;
    $tables = $conn->query("SHOW TABLES")->fetch_all(MYSQLI_NUM);
    $filename = 'jws_backup_' . date('Ymd_His') . '.sql';

    writeAuditLog(
        'backup_download',
        'backup',
        'Backup database diunduh.',
        [
            'metadata' => [
                'database' => $dbName,
                'filename' => $filename,
            ],
        ]
    );

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    echo "-- JWS Database Backup\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Database: {$dbName}\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as [$tbl]) {
        $create = $conn->query("SHOW CREATE TABLE `$tbl`")->fetch_row()[1];
        echo "DROP TABLE IF EXISTS `$tbl`;\n{$create};\n\n";

        $rows = $conn->query("SELECT * FROM `$tbl`");
        if ($rows && $rows->num_rows > 0) {
            echo "INSERT INTO `$tbl` VALUES\n";
            $chunks = [];
            while ($row = $rows->fetch_row()) {
                $vals = array_map(static function($value) use ($conn) {
                    return $value === null ? 'NULL' : "'" . $conn->real_escape_string($value) . "'";
                }, $row);
                $chunks[] = '(' . implode(',', $vals) . ')';
            }
            echo implode(",\n", $chunks) . ";\n\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

if ($action === 'restore') {
    if ($requestMethod !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Restore backup harus memakai request POST yang valid.');
    }

    header('Content-Type: application/json');

    if (!$backupRestoreEnabled) {
        http_response_code(403);
        echo json_encode(['success' => false, 'msg' => 'Fitur restore database via web dinonaktifkan di environment production.']);
        exit;
    }

    if (isUploadRequestTooLarge()) {
        http_response_code(413);
        echo json_encode(['success' => false, 'msg' => buildUploadTooLargeMessage(SQL_RESTORE_MAX_BYTES)]);
        exit;
    }

    $confirmRestore = strtoupper(trim((string) ($_POST['confirm_restore'] ?? '')));
    if ($confirmRestore !== 'RESTORE') {
        echo json_encode(['success' => false, 'msg' => 'Ketik RESTORE untuk mengonfirmasi proses pemulihan database.']);
        exit;
    }

    if (!isset($_FILES['sqlfile'])) {
        echo json_encode(['success'=>false,'msg'=>'File tidak ditemukan']); exit;
    }

    $uploadError = (int) ($_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        echo json_encode([
            'success' => false,
            'msg' => uploadErrorMessage($uploadError, (string) ($_FILES['sqlfile']['name'] ?? 'File restore'), SQL_RESTORE_MAX_BYTES),
        ]);
        exit;
    }

    if (empty($_FILES['sqlfile']['tmp_name'])) {
        echo json_encode(['success'=>false,'msg'=>'File tidak ditemukan']); exit;
    }

    if (!is_uploaded_file($_FILES['sqlfile']['tmp_name'])) {
        echo json_encode(['success' => false, 'msg' => 'Sumber file restore tidak valid.']); exit;
    }

    $ext = strtolower(pathinfo($_FILES['sqlfile']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        echo json_encode(['success'=>false,'msg'=>'Hanya file .sql yang diizinkan']); exit;
    }

    $fileSize = (int) ($_FILES['sqlfile']['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > SQL_RESTORE_MAX_BYTES) {
        echo json_encode(['success' => false, 'msg' => 'Ukuran file restore harus antara 1 byte hingga 50MB.']); exit;
    }

    $mimeType = detectFileMimeType($_FILES['sqlfile']['tmp_name']);
    if (!in_array($mimeType, $allowedSqlMimeTypes, true)) {
        echo json_encode(['success' => false, 'msg' => 'Tipe file restore tidak dikenali sebagai SQL/plain text.']); exit;
    }

    $sql = file_get_contents($_FILES['sqlfile']['tmp_name']);
    if (!$sql) { echo json_encode(['success'=>false,'msg'=>'File kosong']); exit; }

    $blockedStatement = backupRestoreBlockedStatement($sql);
    if ($blockedStatement !== null) {
        writeAuditLog(
            'backup_restore_blocked',
            'backup',
            'Restore database diblokir karena file memuat statement terlarang.',
            [
                'metadata' => [
                    'filename' => (string) ($_FILES['sqlfile']['name'] ?? ''),
                    'size' => $fileSize,
                    'blocked_statement' => $blockedStatement,
                ],
            ]
        );
        echo json_encode(['success' => false, 'msg' => 'File restore memuat ' . $blockedStatement . ' yang tidak diizinkan pada modul ini.']);
        exit;
    }

    set_time_limit(300);
    $errors = [];

    if (!$conn->multi_query($sql)) {
        $errors[] = $conn->error;
    } else {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
            if ($conn->errno) {
                $errors[] = $conn->error;
            }
        } while ($conn->more_results() && $conn->next_result());
    }

    if (empty($errors)) {
        writeAuditLog(
            'backup_restore_success',
            'backup',
            'Restore database berhasil dijalankan.',
            [
                'metadata' => [
                    'filename' => (string) ($_FILES['sqlfile']['name'] ?? ''),
                    'size' => $fileSize,
                ],
            ]
        );
        echo json_encode(['success'=>true,'msg'=>'Restore berhasil!']);
    } else {
        writeAuditLog(
            'backup_restore_failed',
            'backup',
            'Restore database selesai dengan error.',
            [
                'metadata' => [
                    'filename' => (string) ($_FILES['sqlfile']['name'] ?? ''),
                    'size' => $fileSize,
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 3),
                ],
            ]
        );
        echo json_encode(['success'=>false,'msg'=>'Selesai dengan ' . count($errors) . ' error: ' . implode('; ', array_slice($errors,0,3))]);
    }
    exit;
}

http_response_code(400);
echo 'Invalid action';
