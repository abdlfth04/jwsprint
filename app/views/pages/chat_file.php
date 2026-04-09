<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/chat_support.php';
requireLogin();

$messageId = (int) ($_GET['id'] ?? 0);
if ($messageId <= 0) {
    http_response_code(400);
    exit('ID lampiran chat tidak valid.');
}

$stmt = $conn->prepare("SELECT id, room_id, pesan FROM chat_pesan WHERE id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    exit('Lampiran chat tidak dapat dibuka.');
}

$stmt->bind_param('i', $messageId);
$stmt->execute();
$message = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$message) {
    http_response_code(404);
    exit('Pesan chat tidak ditemukan.');
}

$employee = getCurrentEmployeeProfile();
$accessibleRoomIds = notificationGetAccessibleChatRoomIds(
    (int) ($_SESSION['user_id'] ?? 0),
    (string) ($_SESSION['role'] ?? ''),
    $employee['divisi'] ?? null
);

if (!in_array((int) ($message['room_id'] ?? 0), $accessibleRoomIds, true)) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke lampiran chat ini.');
}

$payload = chatParseStoredMessagePayload((string) ($message['pesan'] ?? ''));
$attachment = is_array($payload['attachment'] ?? null) ? $payload['attachment'] : null;
if ($attachment === null) {
    http_response_code(404);
    exit('Lampiran chat tidak ditemukan.');
}

$absolutePath = chatResolveAttachmentAbsolutePath((string) ($attachment['path'] ?? ''));
if ($absolutePath === null || !is_file($absolutePath)) {
    http_response_code(404);
    exit('File lampiran chat tidak ditemukan di server.');
}

$detectedMimeType = sanitizeDownloadMimeType(
    (string) ($attachment['mime'] ?? detectFileMimeType($absolutePath)),
    (string) ($attachment['name'] ?? 'download')
);
$disposition = (isset($_GET['inline']) && canDisplayTransactionFileInline($detectedMimeType)) ? 'inline' : 'attachment';
$filenameHeader = buildDownloadFilenameHeader((string) ($attachment['name'] ?? 'download'));

while (ob_get_level() > 0) {
    ob_end_clean();
}

clearstatcache(true, $absolutePath);

header('Content-Type: ' . $detectedMimeType);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Disposition: ' . $disposition . '; ' . $filenameHeader);
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($absolutePath));

readfile($absolutePath);
exit;
