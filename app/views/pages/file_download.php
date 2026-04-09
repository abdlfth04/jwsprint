<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/file_manager.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID file tidak valid.');
}

$stmt = $conn->prepare("SELECT * FROM file_transaksi WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    exit('File tidak ditemukan atau telah dihapus.');
}

$file = $result->fetch_assoc();
$stmt->close();

if (!canAccessFileDownload((int) $file['transaksi_id'], (int) ($file['detail_transaksi_id'] ?? 0))) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke file ini.');
}

$filepath = resolveManagedUploadPath((string) ($file['path_file'] ?? ''));
if ($filepath === null || !file_exists($filepath) || !is_file($filepath)) {
    http_response_code(404);
    exit('File fisik tidak ditemukan di server.');
}

$detectedMimeType = sanitizeDownloadMimeType(detectFileMimeType($filepath), (string) ($file['nama_asli'] ?? ''));
$disposition = (isset($_GET['inline']) && canDisplayTransactionFileInline($detectedMimeType)) ? 'inline' : 'attachment';
$filenameHeader = buildDownloadFilenameHeader((string) ($file['nama_asli'] ?? 'download'));

while (ob_get_level() > 0) {
    ob_end_clean();
}

clearstatcache(true, $filepath);

header('Content-Type: ' . $detectedMimeType);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Disposition: ' . $disposition . '; ' . $filenameHeader);
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($filepath));

readfile($filepath);
exit;
