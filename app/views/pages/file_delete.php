<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/file_manager.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID file wajib diisi.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, transaksi_id, detail_transaksi_id, path_file, nama_asli, tipe_file, version_batch, version_no, is_current_version FROM file_transaksi WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'File tidak ditemukan.']);
    exit;
}

if (!canManageTransactionFiles((int) $file['transaksi_id'], (int) ($file['detail_transaksi_id'] ?? 0))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk menghapus file ini.']);
    exit;
}

$filePath = resolveManagedUploadPath((string) ($file['path_file'] ?? ''));

$conn->begin_transaction();

$stmt = $conn->prepare("UPDATE file_transaksi SET is_active = 0 WHERE id = ?");
if (!$stmt) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan penghapusan file.']);
    exit;
}

$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    $conn->commit();
} else {
    $conn->rollback();
}

if ($ok) {
    if (($file['tipe_file'] ?? '') === 'siap_cetak' && readyPrintVersioningReady()) {
        restoreCurrentReadyPrintVersionAfterDeletion(
            $conn,
            (int) $file['transaksi_id'],
            (int) ($file['detail_transaksi_id'] ?? 0)
        );
    }

    writeAuditLog(
        'file_delete',
        'file_transaksi',
        'File transaksi ' . ($file['nama_asli'] ?? ('#' . $id)) . ' dihapus.',
        [
            'entity_id' => (int) $file['id'],
            'metadata' => [
                'transaksi_id' => (int) $file['transaksi_id'],
                'detail_transaksi_id' => (int) ($file['detail_transaksi_id'] ?? 0),
                'nama_file' => $file['nama_asli'] ?? null,
                'tipe_file' => $file['tipe_file'] ?? null,
                'version_batch' => $file['version_batch'] ?? null,
                'version_no' => isset($file['version_no']) ? (int) $file['version_no'] : null,
                'is_current_version' => !empty($file['is_current_version']),
            ],
        ]
    );

    $message = 'File berhasil dihapus.';
    if ($filePath !== null && is_file($filePath) && !@unlink($filePath)) {
        $message = 'File dinonaktifkan, tetapi file fisik belum bisa dibersihkan dari server.';
    }

    echo json_encode(['success' => true, 'message' => $message]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus file dari database.']);
}
