<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/file_manager.php';
requireLogin();

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$requestedFile = basename(trim((string) ($_GET['file'] ?? '')));

if ($requestedFile === '' || $requestedFile === '.' || $requestedFile === '..') {
    http_response_code(400);
    exit('File media tidak valid.');
}

$relativePath = '';

if ($type === 'karyawan') {
    $currentUserPhoto = basename(trim((string) ($_SESSION['foto'] ?? '')));
    $allowed = hasRole('superadmin', 'admin') || ($currentUserPhoto !== '' && $currentUserPhoto === $requestedFile);

    if (!$allowed) {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke foto karyawan ini.');
    }

    $relativePath = 'uploads/karyawan/' . $requestedFile;
} elseif ($type === 'absensi') {
    if (!hasRole('superadmin', 'admin')) {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke foto absensi ini.');
    }

    $relativePath = 'uploads/absensi/' . $requestedFile;
} else {
    http_response_code(400);
    exit('Tipe media tidak dikenal.');
}

$absolutePath = resolveManagedUploadPath($relativePath);
if ($absolutePath === null || !is_file($absolutePath)) {
    http_response_code(404);
    exit('File media tidak ditemukan.');
}

$mimeType = sanitizeDownloadMimeType(detectFileMimeType($absolutePath), $requestedFile);
if (strpos($mimeType, 'image/') !== 0) {
    $mimeType = 'application/octet-stream';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

clearstatcache(true, $absolutePath);

header('Content-Type: ' . $mimeType);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Disposition: inline; ' . buildDownloadFilenameHeader($requestedFile));
header('Content-Length: ' . filesize($absolutePath));

readfile($absolutePath);
exit;
