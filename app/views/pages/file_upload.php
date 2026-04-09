<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/file_manager.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak valid.']);
    exit;
}

if (isUploadRequestTooLarge()) {
    http_response_code(413);
    echo json_encode([
        'success' => false,
        'message' => buildUploadTooLargeMessage(),
        'errors' => [buildUploadTooLargeMessage()],
    ]);
    exit;
}

$transaksiId = (int) ($_POST['transaksi_id'] ?? 0);
$detailId = !empty($_POST['detail_transaksi_id']) ? (int) $_POST['detail_transaksi_id'] : null;
$tipeFile = trim((string) ($_POST['tipe_file'] ?? ''));

if ($transaksiId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID transaksi tidak valid.']);
    exit;
}

if ($tipeFile === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tipe file wajib dipilih.']);
    exit;
}

if (!canManageTransactionFiles($transaksiId, $detailId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengelola file transaksi ini.']);
    exit;
}

$fieldName = isset($_FILES['files']) ? 'files' : 'file';
$result = storeTransactionUploads(
    $conn,
    $transaksiId,
    $detailId,
    $tipeFile,
    (int) ($_SESSION['user_id'] ?? 0),
    $fieldName
);

$messages = [];
if (!empty($result['files'])) {
    $messages[] = count($result['files']) === 1
        ? 'File berhasil diunggah.'
        : count($result['files']) . ' file berhasil diunggah.';
    if ($tipeFile === 'siap_cetak' && !empty($result['files'][0]['version_no'])) {
        $messages[] = 'Versi siap cetak V' . (int) $result['files'][0]['version_no'] . ' berhasil dibuat.';
    }
}
if (!empty($result['errors'])) {
    $messages[] = implode(' ', $result['errors']);
}

if (!empty($result['files'])) {
    $resolvedDetailId = isset($result['files'][0]['detail_transaksi_id'])
        ? (int) $result['files'][0]['detail_transaksi_id']
        : $detailId;
    writeAuditLog(
        'file_upload',
        'file_transaksi',
        count($result['files']) . ' file transaksi berhasil diunggah.',
        [
            'entity_id' => $transaksiId,
            'metadata' => [
                'transaksi_id' => $transaksiId,
                'detail_transaksi_id' => $resolvedDetailId,
                'tipe_file' => $tipeFile,
                'jumlah_file' => count($result['files']),
                'nama_file' => array_map(static function (array $file): string {
                    return (string) ($file['nama_asli'] ?? '');
                }, $result['files']),
                'version_no' => $result['files'][0]['version_no'] ?? null,
            ],
        ]
    );

    if ($tipeFile === 'siap_cetak') {
        try {
            webPushSendReadyPrintUploadedPush(
                $transaksiId,
                $resolvedDetailId,
                (int) ($_SESSION['user_id'] ?? 0),
                [
                    'version_no' => (int) ($result['files'][0]['version_no'] ?? 0),
                    'uploader_name' => (string) ($_SESSION['nama'] ?? ''),
                ]
            );
        } catch (Throwable $exception) {
        }
    }
}

echo json_encode([
    'success' => $result['success'],
    'message' => trim(implode(' ', $messages)) ?: 'Proses upload selesai.',
    'files' => $result['files'],
    'errors' => $result['errors'],
]);
