<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/chat_support.php';
requireRole('superadmin', 'admin', 'service', 'kasir', 'user');
$pageTitle = 'Room Chat';

$myId   = $_SESSION['user_id'];
$myRole = $_SESSION['role'];
$chatRoomCols = schemaTableColumns($conn, 'chat_room');
$hasChatRoomTipe = in_array('tipe', $chatRoomCols, true);
$hasChatRoomDivisi = in_array('divisi', $chatRoomCols, true);
$hasChatRoomIsDefault = in_array('is_default', $chatRoomCols, true);
$extraCss = '<link rel="stylesheet" href="'.BASE_URL.'/public/css/chat.css">';
$pageState = [
    'chat' => [
        'myUserId' => (int) $myId,
        'endpoint' => pageUrl('chat.php'),
        'streamEndpoint' => pageUrl('chat.php?format=stream'),
        'refreshMs' => 2000,
        'summaryMs' => 5000,
        'attachmentMaxBytes' => (int) chatAttachmentMaxBytes(),
        'attachmentLabel' => chatAttachmentAcceptedLabel(),
    ],
];
$pageJs   = 'chat.js';

// Cek divisi user dari tabel karyawan
$myDivisi = null;
$stmtDiv = $conn->prepare("SELECT divisi FROM karyawan WHERE user_id = ? LIMIT 1");
$stmtDiv->bind_param('i', $myId);
$stmtDiv->execute();
$resDiv = $stmtDiv->get_result();
if ($resDiv) { $row = $resDiv->fetch_assoc(); $myDivisi = $row['divisi'] ?? null; }
$stmtDiv->close();

function canAccessChatRoom(array $room, string $myRole, ?string $myDivisi, bool $hasChatRoomDivisi): bool
{
    $roomType = $room['tipe'] ?? 'group';
    $roomDivisi = $room['divisi'] ?? null;

    if ($roomType === 'group' && $hasChatRoomDivisi && !empty($roomDivisi) && !in_array($myRole, ['superadmin', 'admin'], true)) {
        return $myDivisi === $roomDivisi;
    }

    return true;
}

function fetchGroupChatRooms(mysqli $conn, bool $hasChatRoomTipe, bool $hasChatRoomDivisi, bool $hasChatRoomIsDefault, string $myRole, ?string $myDivisi): array
{
    $rooms = [];
    if ($hasChatRoomTipe) {
        $orderBy = $hasChatRoomIsDefault ? "ORDER BY is_default DESC, id ASC" : "ORDER BY id ASC";
        $res = $conn->query("SELECT * FROM chat_room WHERE tipe='group' $orderBy");
    } else {
        $res = $conn->query("SELECT * FROM chat_room ORDER BY id ASC");
    }

    if (!$res) {
        return $rooms;
    }

    foreach ($res->fetch_all(MYSQLI_ASSOC) as $room) {
        if (!$hasChatRoomTipe && strpos((string) ($room['nama'] ?? ''), 'personal_') === 0) {
            continue;
        }
        if (!canAccessChatRoom($room, $myRole, $myDivisi, $hasChatRoomDivisi)) {
            continue;
        }
        $rooms[] = $room;
    }

    return $rooms;
}

function fetchPersonalChatRooms(mysqli $conn, int $myId, bool $hasChatRoomTipe): array
{
    $rooms = [];
    $like1 = "personal_{$myId}_%";
    $like2 = "personal_%_{$myId}";
    $sql = $hasChatRoomTipe
        ? "SELECT cr.* FROM chat_room cr WHERE cr.tipe='personal' AND (cr.nama LIKE ? OR cr.nama LIKE ?) ORDER BY cr.id DESC"
        : "SELECT cr.* FROM chat_room cr WHERE cr.nama LIKE ? OR cr.nama LIKE ? ORDER BY cr.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $like1, $like2);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows === 0) {
        return $rooms;
    }

    $rawRooms = $res->fetch_all(MYSQLI_ASSOC);
    $otherUserIds = [];
    foreach ($rawRooms as $room) {
        $parts = explode('_', $room['nama']);
        if (count($parts) < 3) {
            continue;
        }
        $otherUserIds[] = ($parts[1] == $myId) ? (int) $parts[2] : (int) $parts[1];
    }

    $usersMap = [];
    if (!empty($otherUserIds)) {
        $idList = implode(',', array_map('intval', array_unique($otherUserIds)));
        $resUsers = $conn->query("SELECT id, nama FROM users WHERE id IN ($idList)");
        if ($resUsers) {
            foreach ($resUsers->fetch_all(MYSQLI_ASSOC) as $user) {
                $usersMap[(int) $user['id']] = $user['nama'];
            }
        }
    }

    foreach ($rawRooms as $room) {
        $parts = explode('_', $room['nama']);
        if (count($parts) < 3) {
            continue;
        }
        $otherId = ($parts[1] == $myId) ? (int) $parts[2] : (int) $parts[1];
        $room['display_name'] = $usersMap[$otherId] ?? ('User #' . $otherId);
        $room['other_id'] = $otherId;
        $rooms[] = $room;
    }

    return $rooms;
}

function fetchChatRoomSummaries(mysqli $conn, array $roomIds): array
{
    if (empty($roomIds)) {
        return [];
    }

    $idList = implode(',', array_map('intval', array_unique($roomIds)));
    $sql = "SELECT cp.room_id, cp.id AS last_id, cp.pesan AS last_message, cp.created_at, cp.user_id, u.nama AS sender_name
        FROM chat_pesan cp
        JOIN (
            SELECT room_id, MAX(id) AS max_id
            FROM chat_pesan
            WHERE room_id IN ($idList)
            GROUP BY room_id
        ) latest ON latest.max_id = cp.id
        JOIN users u ON u.id = cp.user_id";
    $res = $conn->query($sql);

    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function formatChatPreviewText(string $text, int $limit = 56): string
{
    return chatCollapsePreviewText($text, $limit);
}

function buildChatRoomSummaryView(?array $summary, int $myId, bool $isPersonal): array
{
    if (empty($summary)) {
        return ['Belum ada pesan', ''];
    }

    $senderId = (int) ($summary['user_id'] ?? 0);
    $senderName = trim((string) ($summary['sender_name'] ?? ''));
    $message = (string) ($summary['last_message'] ?? '');
    $previewBase = trim((string) ($summary['preview_text'] ?? ''));
    if ($previewBase === '') {
        $previewBase = chatPreviewTextFromStoredMessage($message, 120);
    }
    $prefix = (!$isPersonal && $senderId !== $myId && $senderName !== '') ? ($senderName . ': ') : '';
    $preview = formatChatPreviewText($prefix . $previewBase);

    $timeText = '';
    $createdAt = $summary['created_at'] ?? null;
    if (!empty($createdAt)) {
        $timeText = date('Y-m-d', strtotime($createdAt)) === date('Y-m-d')
            ? date('H:i', strtotime($createdAt))
            : date('d/m', strtotime($createdAt));
    }

    return [$preview, $timeText];
}

function fetchChatMessagesSince(mysqli $conn, int $roomId, int $since = 0): array
{
    $roomId = (int) $roomId;
    $since = (int) $since;

    if ($roomId <= 0) {
        return [];
    }

    if ($since > 0) {
        $stmtLoad = $conn->prepare("SELECT cp.id, cp.pesan, cp.created_at, u.nama, u.id AS user_id
            FROM chat_pesan cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.room_id = ? AND cp.id > ?
            ORDER BY cp.id ASC
            LIMIT 50");
        if (!$stmtLoad) {
            return [];
        }
        $stmtLoad->bind_param('ii', $roomId, $since);
    } else {
        $stmtLoad = $conn->prepare("SELECT * FROM (
            SELECT cp.id, cp.pesan, cp.created_at, u.nama, u.id AS user_id
            FROM chat_pesan cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.room_id = ?
            ORDER BY cp.id DESC
            LIMIT 50
        ) recent
        ORDER BY id ASC");
        if (!$stmtLoad) {
            return [];
        }
        $stmtLoad->bind_param('i', $roomId);
    }

    $stmtLoad->execute();
    $rows = $stmtLoad->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtLoad->close();

    return array_map('chatDecorateMessageRow', $rows);
}

function buildChatRoomSummariesWithUnread(mysqli $conn, int $userId, array $roomIds): array
{
    $roomIds = array_values(array_unique(array_map('intval', $roomIds)));
    if (empty($roomIds)) {
        return [];
    }

    $summaryRows = fetchChatRoomSummaries($conn, $roomIds);
    $unreadMap = notificationGetUnreadChatRoomMap($userId, $roomIds);

    foreach ($summaryRows as &$row) {
        $roomKey = (int) ($row['room_id'] ?? 0);
        $row['unread_count'] = (int) ($unreadMap[$roomKey]['unread_count'] ?? 0);
        $row['preview_text'] = chatPreviewTextFromStoredMessage((string) ($row['last_message'] ?? ''), 120);
    }
    unset($row);

    return $summaryRows;
}

if (($_GET['format'] ?? '') === 'stream') {
    $roomId = (int) ($_GET['room_id'] ?? 0);
    $lastSeenId = (int) ($_GET['last_id'] ?? 0);
    $accessibleRoomIds = notificationGetAccessibleChatRoomIds($myId, $myRole, $myDivisi);

    if ($roomId > 0 && !in_array($roomId, $accessibleRoomIds, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'msg' => 'Anda tidak memiliki akses ke room ini']);
        exit;
    }

    realtimeStreamPrepare();

    $startedAt = microtime(true);
    $maxDuration = 24;
    $iteration = 0;
    $summarySignature = '';

    while (!connection_aborted() && (microtime(true) - $startedAt) < $maxDuration) {
        $accessibleRoomIds = notificationGetAccessibleChatRoomIds($myId, $myRole, $myDivisi);
        $summaryRows = buildChatRoomSummariesWithUnread($conn, $myId, $accessibleRoomIds);
        $nextSummarySignature = realtimeStreamSignature($summaryRows);

        if ($iteration === 0 || $nextSummarySignature !== $summarySignature) {
            realtimeStreamSend('summaries', [
                'summaries' => $summaryRows,
                'generated_at' => date(DATE_ATOM),
            ]);
            $summarySignature = $nextSummarySignature;
        }

        if ($roomId > 0) {
            $messages = fetchChatMessagesSince($conn, $roomId, $lastSeenId);
            if (!empty($messages)) {
                foreach ($messages as $message) {
                    $lastSeenId = max($lastSeenId, (int) ($message['id'] ?? 0));
                }

                notificationMarkChatRoomAsRead($roomId, $myId);
                realtimeStreamSend('messages', [
                    'room_id' => $roomId,
                    'messages' => $messages,
                    'last_id' => $lastSeenId,
                    'generated_at' => date(DATE_ATOM),
                ]);
            }
        }

        realtimeStreamSend('ping', [
            'generated_at' => date(DATE_ATOM),
        ]);

        $iteration++;
        usleep(2000000);
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isUploadRequestTooLarge()) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'msg' => buildUploadTooLargeMessage(chatAttachmentMaxBytes()),
    ]);
    exit;
}

// ?????? AJAX ??????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Kirim pesan
    if ($_POST['action'] === 'kirim') {
        $roomId = intval($_POST['room_id'] ?? 0);
        $pesan  = trim((string) ($_POST['pesan'] ?? ''));

        // Validasi akses room - Gunakan prepared statement
        $stmtRoom = $conn->prepare("SELECT * FROM chat_room WHERE id = ?");
        $stmtRoom->bind_param('i', $roomId);
        $stmtRoom->execute();
        $room = $stmtRoom->get_result()->fetch_assoc();
        $stmtRoom->close();
        if (!$room) { echo json_encode(['success'=>false,'msg'=>'Room tidak ditemukan']); exit; }
        if (!canAccessChatRoom($room, $myRole, $myDivisi, $hasChatRoomDivisi)) {
            echo json_encode(['success'=>false,'msg'=>'Anda tidak memiliki akses ke room ini']); exit;
        }

        $attachmentMeta = null;
        if (!empty($_FILES['attachment']) && is_array($_FILES['attachment'])) {
            $attachmentUpload = chatStoreUploadedAttachment($_FILES['attachment'], $roomId);
            if (empty($attachmentUpload['success'])) {
                echo json_encode([
                    'success' => false,
                    'msg' => (string) ($attachmentUpload['message'] ?? 'Lampiran chat gagal diproses.'),
                ]);
                exit;
            }

            $attachmentMeta = $attachmentUpload['attachment'] ?? null;
        }

        if ($pesan === '' && $attachmentMeta === null) {
            echo json_encode(['success' => false, 'msg' => 'Tulis pesan atau pilih lampiran terlebih dahulu.']);
            exit;
        }

        $storedMessage = chatBuildStoredMessagePayload($pesan, $attachmentMeta);

        $cleanupAttachment = static function (?array $attachment): void {
            if (!is_array($attachment) || empty($attachment['path'])) {
                return;
            }

            $absolutePath = chatResolveAttachmentAbsolutePath((string) $attachment['path']);
            if ($absolutePath !== null && is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        };

        $stmt = $conn->prepare("INSERT INTO chat_pesan (room_id,user_id,pesan) VALUES (?,?,?)");
        if (!$stmt) {
            $cleanupAttachment($attachmentMeta);
            echo json_encode(['success' => false, 'msg' => 'Pesan chat belum bisa disimpan.']);
            exit;
        }

        $stmt->bind_param('iis', $roomId, $myId, $storedMessage);
        if (!$stmt->execute()) {
            $stmt->close();
            $cleanupAttachment($attachmentMeta);
            echo json_encode(['success' => false, 'msg' => 'Pesan chat gagal disimpan.']);
            exit;
        }
        $stmt->close();
        $messageId = (int) $conn->insert_id;
        $stmtMsg = $conn->prepare("SELECT cp.id, cp.room_id, cp.pesan, cp.created_at, u.nama, u.id AS user_id
            FROM chat_pesan cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.id = ?
            LIMIT 1");
        $stmtMsg->bind_param('i', $messageId);
        $stmtMsg->execute();
        $message = $stmtMsg->get_result()->fetch_assoc();
        $stmtMsg->close();

        try {
            webPushSendChatMessagePush($room, (array) $message, $myId);
        } catch (Throwable $exception) {
        }

        echo json_encode(['success'=>true, 'message' => chatDecorateMessageRow((array) $message)]);
        exit;
    }

    // Load pesan
    if ($_POST['action'] === 'load') {
        $roomId = intval($_POST['room_id'] ?? 0);
        $since  = intval($_POST['since'] ?? 0);

        $stmtRoom = $conn->prepare("SELECT * FROM chat_room WHERE id = ?");
        $stmtRoom->bind_param('i', $roomId);
        $stmtRoom->execute();
        $room = $stmtRoom->get_result()->fetch_assoc();
        $stmtRoom->close();
        if (!$room || !canAccessChatRoom($room, $myRole, $myDivisi, $hasChatRoomDivisi)) {
            echo json_encode([]);
            exit;
        }

        $rows = fetchChatMessagesSince($conn, $roomId, $since);
        notificationMarkChatRoomAsRead($roomId, $myId);
        echo json_encode($rows);
        exit;
    }

    if ($_POST['action'] === 'ringkasan') {
        $roomIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['room_ids'] ?? [])))));
        if (empty($roomIds)) {
            echo json_encode([]);
            exit;
        }

        $idList = implode(',', $roomIds);
        $allowedIds = [];
        $resRooms = $conn->query("SELECT * FROM chat_room WHERE id IN ($idList)");
        if ($resRooms) {
            foreach ($resRooms->fetch_all(MYSQLI_ASSOC) as $room) {
                if (canAccessChatRoom($room, $myRole, $myDivisi, $hasChatRoomDivisi)) {
                    $allowedIds[] = (int) $room['id'];
                }
            }
        }

        echo json_encode(buildChatRoomSummariesWithUnread($conn, $myId, $allowedIds));
        exit;
    }

    // Buat room custom (admin/superadmin)
    if ($_POST['action'] === 'buat_room' && hasRole('superadmin','admin')) {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        if ($nama === '') {
            echo json_encode(['success' => false, 'msg' => 'Nama room wajib diisi']);
            exit;
        }

        $sql = $hasChatRoomTipe
            ? "INSERT INTO chat_room (nama,tipe,created_by) VALUES (?,'group',?)"
            : "INSERT INTO chat_room (nama,created_by) VALUES (?,?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $nama, $myId);
        $stmt->execute();
        echo json_encode(['success'=>true,'id'=>$conn->insert_id,'nama'=>$nama,'tipe'=>'group']);
        exit;
    }

    // Buka/buat chat personal
    if ($_POST['action'] === 'personal') {
        $targetId = intval($_POST['target_id'] ?? 0);
        if ($targetId === $myId) { echo json_encode(['success'=>false,'msg'=>'Tidak bisa chat dengan diri sendiri']); exit; }
        if ($targetId <= 0) { echo json_encode(['success'=>false,'msg'=>'User tujuan tidak valid']); exit; }

        $stmtTarget = $conn->prepare("SELECT id, nama FROM users WHERE id = ? LIMIT 1");
        if (!$stmtTarget) {
            echo json_encode(['success'=>false,'msg'=>'User tujuan tidak dapat diverifikasi']);
            exit;
        }
        $stmtTarget->bind_param('i', $targetId);
        $stmtTarget->execute();
        $targetUser = $stmtTarget->get_result()->fetch_assoc();
        $stmtTarget->close();
        if (!$targetUser) {
            echo json_encode(['success'=>false,'msg'=>'User tujuan tidak ditemukan']);
            exit;
        }

        // Cek apakah sudah ada room personal antara dua user ini
        $ids = [$myId, $targetId];
        sort($ids);
        $roomNama = 'personal_' . $ids[0] . '_' . $ids[1];
        
        // Gunakan prepared statement
        $sqlExist = $hasChatRoomTipe
            ? "SELECT id, nama FROM chat_room WHERE nama = ? AND tipe='personal' LIMIT 1"
            : "SELECT id, nama FROM chat_room WHERE nama = ? LIMIT 1";
        $stmtExist = $conn->prepare($sqlExist);
        $stmtExist->bind_param('s', $roomNama);
        $stmtExist->execute();
        $existing = $stmtExist->get_result()->fetch_assoc();
        $stmtExist->close();

        if ($existing) {
            echo json_encode(['success'=>true,'room_id'=>$existing['id'],'is_new'=>false]);
        } else {
            $sqlInsert = $hasChatRoomTipe
                ? "INSERT INTO chat_room (nama,tipe,created_by) VALUES (?,'personal',?)"
                : "INSERT INTO chat_room (nama,created_by) VALUES (?,?)";
            $stmt = $conn->prepare($sqlInsert);
            $stmt->bind_param('si', $roomNama, $myId);
            $stmt->execute();
            $newId = $conn->insert_id;
            echo json_encode(['success'=>true,'room_id'=>$newId,'is_new'=>true]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'msg'=>'Aksi tidak dikenal']);
    exit;
}

$groupRooms = fetchGroupChatRooms($conn, $hasChatRoomTipe, $hasChatRoomDivisi, $hasChatRoomIsDefault, $myRole, $myDivisi);
$personalRooms = fetchPersonalChatRooms($conn, $myId, $hasChatRoomTipe);
$initialSummaries = [];
$allChatRoomIds = array_merge(array_column($groupRooms, 'id'), array_column($personalRooms, 'id'));
$summaryRows = fetchChatRoomSummaries($conn, $allChatRoomIds);
foreach ($summaryRows as $summary) {
    $initialSummaries[(int) $summary['room_id']] = $summary;
}
$initialUnreadMap = notificationGetUnreadChatRoomMap($myId, $allChatRoomIds);

// Daftar semua user untuk chat personal
$stmtAllUsers = $conn->prepare("SELECT u.id, u.nama, u.role FROM users u WHERE u.id != ? AND u.status='aktif' ORDER BY u.nama");
$stmtAllUsers->bind_param('i', $myId);
$stmtAllUsers->execute();
$allUsers = $stmtAllUsers->get_result()->fetch_all(MYSQLI_ASSOC);

require_once dirname(__DIR__) . '/layouts/header.php';
?>

<div class="page-stack chat-page">
    <div class="chat-layout" id="chatLayout">
        <aside class="chat-rooms">
            <div class="chat-sidebar-head">
                <div>
                    <div class="chat-sidebar-title">Chat</div>
                    <div class="chat-sidebar-copy">Koordinasi tim lebih cepat dengan filter room, urutan percakapan terbaru, dan draft otomatis.</div>
                </div>
                <div class="chat-sidebar-actions">
                    <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary btn-sm" title="Kembali ke Dashboard"><i class="fas fa-home"></i></a>
                    <?php if (hasRole('superadmin','admin')): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('modalRoom')" title="Buat Room Baru">
                            <i class="fas fa-plus"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-search-stack">
                <input type="text" id="chatRoomSearch" class="form-control" placeholder="Cari percakapan..." oninput="filterChatLists()">
                <input type="text" id="chatContactSearch" class="form-control" placeholder="Cari kontak..." oninput="filterChatLists()">
            </div>

            <div class="chat-filter-bar" id="chatFilterBar">
                <button type="button" class="chat-filter-chip active" data-chat-action="set-room-filter" data-chat-filter="all">
                    Semua
                    <span class="chat-filter-count" id="chatFilterCountAll">0</span>
                </button>
                <button type="button" class="chat-filter-chip" data-chat-action="set-room-filter" data-chat-filter="unread">
                    Belum dibaca
                    <span class="chat-filter-count" id="chatFilterCountUnread">0</span>
                </button>
                <button type="button" class="chat-filter-chip" data-chat-action="set-room-filter" data-chat-filter="group">
                    Group
                    <span class="chat-filter-count" id="chatFilterCountGroup">0</span>
                </button>
                <button type="button" class="chat-filter-chip" data-chat-action="set-room-filter" data-chat-filter="personal">
                    Personal
                    <span class="chat-filter-count" id="chatFilterCountPersonal">0</span>
                </button>
            </div>

            <div class="chat-sidebar-summary" id="chatSidebarSummary">
                <span class="chat-summary-pill"><i class="fas fa-inbox"></i> <span id="chatSummaryRooms">0 room aktif</span></span>
                <span class="chat-summary-pill"><i class="fas fa-envelope-open-text"></i> <span id="chatSummaryUnread">0 belum dibaca</span></span>
            </div>

            <div class="chat-sidebar-scroll">
                <section class="chat-room-section" data-room-section="group">
                    <div class="chat-section-label"><i class="fas fa-users"></i> Group</div>
                    <div class="chat-room-list" id="groupRoomList">
                        <?php foreach ($groupRooms as $r):
                            $icon = 'fa-users';
                            $color = 'var(--primary)';
                            $summary = $initialSummaries[(int) $r['id']] ?? null;
                            $unreadCount = (int) ($initialUnreadMap[(int) $r['id']]['unread_count'] ?? 0);
                            [$preview, $timeText] = buildChatRoomSummaryView($summary, $myId, false);
                            $lastTimestamp = !empty($summary['created_at']) ? (string) strtotime((string) $summary['created_at']) : '0';
                            if (isset($r['divisi'])) {
                                if ($r['divisi'] === 'printing') { $icon='fa-print'; $color='var(--primary)'; }
                                elseif ($r['divisi'] === 'apparel') { $icon='fa-shirt'; $color='#0f766e'; }
                            }
                        ?>
                            <button
                                type="button"
                                class="chat-room-item"
                                id="room-<?= (int) $r['id'] ?>"
                                data-chat-action="open-room"
                                data-room-id="<?= (int) $r['id'] ?>"
                                data-room-name="<?= htmlspecialchars($r['nama']) ?>"
                                data-room-type="group"
                                data-search="<?= htmlspecialchars(strtolower($r['nama'])) ?>"
                                data-default="<?= !empty($r['is_default']) ? '1' : '0' ?>"
                                data-last-ts="<?= htmlspecialchars($lastTimestamp) ?>"
                                data-unread-count="<?= (int) $unreadCount ?>">
                                <span class="chat-room-icon" style="color:<?= htmlspecialchars($color) ?>">
                                    <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                                </span>
                                <span class="chat-room-copy">
                                    <span class="chat-room-name">
                                        <?= htmlspecialchars($r['nama']) ?>
                                        <?php if (!empty($r['is_default'])): ?>
                                            <span class="room-badge">default</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="chat-room-preview" id="room-preview-<?= (int) $r['id'] ?>"><?= htmlspecialchars($preview) ?></span>
                                </span>
                                <span class="chat-room-meta">
                                    <span class="chat-room-time" id="room-time-<?= (int) $r['id'] ?>"><?= htmlspecialchars($timeText) ?></span>
                                    <span class="chat-room-unread" id="room-unread-<?= (int) $r['id'] ?>" <?= $unreadCount > 0 ? '' : 'hidden' ?>><?= $unreadCount > 9 ? '9+' : (int) $unreadCount ?></span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="chat-inline-note" id="groupRoomEmptyNote" hidden>Tidak ada room group yang cocok dengan filter saat ini.</div>
                </section>

                <section class="chat-room-section" data-room-section="personal">
                    <div class="chat-section-label"><i class="fas fa-user"></i> Personal</div>
                    <div class="chat-room-list" id="personalRoomList">
                        <?php foreach ($personalRooms as $r):
                            $summary = $initialSummaries[(int) $r['id']] ?? null;
                            $unreadCount = (int) ($initialUnreadMap[(int) $r['id']]['unread_count'] ?? 0);
                            [$preview, $timeText] = buildChatRoomSummaryView($summary, $myId, true);
                            $lastTimestamp = !empty($summary['created_at']) ? (string) strtotime((string) $summary['created_at']) : '0';
                        ?>
                            <button
                                type="button"
                                class="chat-room-item"
                                id="room-<?= (int) $r['id'] ?>"
                                data-chat-action="open-room"
                                data-room-id="<?= (int) $r['id'] ?>"
                                data-room-name="<?= htmlspecialchars($r['display_name']) ?>"
                                data-room-type="personal"
                                data-search="<?= htmlspecialchars(strtolower($r['display_name'])) ?>"
                                data-last-ts="<?= htmlspecialchars($lastTimestamp) ?>"
                                data-unread-count="<?= (int) $unreadCount ?>">
                                <span class="chat-room-avatar"><?= strtoupper(substr($r['display_name'], 0, 1)) ?></span>
                                <span class="chat-room-copy">
                                    <span class="chat-room-name"><?= htmlspecialchars($r['display_name']) ?></span>
                                    <span class="chat-room-preview" id="room-preview-<?= (int) $r['id'] ?>"><?= htmlspecialchars($preview) ?></span>
                                </span>
                                <span class="chat-room-meta">
                                    <span class="chat-room-time" id="room-time-<?= (int) $r['id'] ?>"><?= htmlspecialchars($timeText) ?></span>
                                    <span class="chat-room-unread" id="room-unread-<?= (int) $r['id'] ?>" <?= $unreadCount > 0 ? '' : 'hidden' ?>><?= $unreadCount > 9 ? '9+' : (int) $unreadCount ?></span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="chat-inline-note" id="personalRoomEmptyNote" <?= empty($personalRooms) ? '' : 'hidden' ?>>Belum ada chat personal. Pilih kontak untuk mulai chat.</div>
                </section>

                <section class="chat-room-section">
                    <div class="chat-section-label"><i class="fas fa-address-book"></i> Kontak</div>
                    <div class="chat-contact-list" id="chatContactList">
                        <?php foreach ($allUsers as $u): ?>
                            <button
                                type="button"
                                class="user-list-item"
                                data-chat-action="open-personal"
                                data-target-id="<?= (int) $u['id'] ?>"
                                data-target-name="<?= htmlspecialchars($u['nama']) ?>"
                                data-search="<?= htmlspecialchars(strtolower($u['nama'] . ' ' . $u['role'])) ?>"
                                >
                                <span class="user-avatar-sm"><?= strtoupper(substr($u['nama'], 0, 1)) ?></span>
                                <span class="chat-contact-copy">
                                    <span class="chat-contact-name"><?= htmlspecialchars($u['nama']) ?></span>
                                    <span class="chat-contact-role"><?= strtoupper(htmlspecialchars($u['role'])) ?></span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </aside>

        <section class="chat-main">
            <div class="chat-main-header">
                <button type="button" class="chat-back-btn" data-chat-action="show-sidebar" aria-label="Kembali ke daftar room">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="chat-main-heading">
                    <div class="chat-main-title-row">
                        <i class="fas fa-comments" id="chatRoomTitleIcon"></i>
                        <span id="chatRoomTitleText">Pilih room atau kontak untuk mulai chat</span>
                    </div>
                    <div class="chat-main-subtitle" id="chatRoomTitleMeta">Pilih room untuk mulai chat</div>
                </div>
                <div class="chat-main-status">
                    <span class="chat-live-pill" id="chatRealtimeState">Idle</span>
                    <span class="chat-sync-text" id="chatSyncText">Belum sinkron</span>
                </div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <div class="chat-empty-state" id="chatEmptyState">
                    <i class="fas fa-comments"></i>
                    <div>Pilih room atau kontak untuk mulai chat.</div>
                </div>
            </div>

            <div class="chat-input-area" id="chatInputArea" hidden>
                <div class="chat-composer-meta">
                    <span id="chatDraftStatus">Draft tersimpan otomatis per room.</span>
                    <span id="chatQuickReplyHint">Lampiran <?= htmlspecialchars(chatAttachmentAcceptedLabel()) ?> hingga <?= htmlspecialchars(formatUploadByteSize(chatAttachmentMaxBytes())) ?> siap dipakai.</span>
                </div>
                <div class="chat-attachment-strip" id="chatAttachmentStrip" hidden>
                    <div class="chat-attachment-selected" id="chatAttachmentSelected">
                        <span class="chat-attachment-selected-icon"><i class="fas fa-paperclip" id="chatAttachmentIcon"></i></span>
                        <span class="chat-attachment-selected-copy">
                            <strong id="chatAttachmentName">Belum ada file</strong>
                            <span id="chatAttachmentMeta">Pilih lampiran chat</span>
                        </span>
                    </div>
                    <button type="button" class="chat-attachment-clear" data-chat-action="clear-attachment" aria-label="Hapus lampiran">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
                <div class="chat-quick-replies" id="chatQuickReplies">
                    <button type="button" class="chat-quick-reply" data-chat-action="quick-reply" data-chat-quick-reply="Siap, saya proses sekarang.">Siap proses</button>
                    <button type="button" class="chat-quick-reply" data-chat-action="quick-reply" data-chat-quick-reply="Tolong cek update terakhir ya.">Tolong cek</button>
                    <button type="button" class="chat-quick-reply" data-chat-action="quick-reply" data-chat-quick-reply="Saya OTW, nanti saya kabari lagi.">Saya OTW</button>
                    <button type="button" class="chat-quick-reply" data-chat-action="quick-reply" data-chat-quick-reply="Sudah selesai, silakan dicek.">Sudah selesai</button>
                </div>
                <div class="chat-composer-row">
                    <input type="file" id="chatAttachmentInput" class="chat-attachment-input" accept="<?= htmlspecialchars(chatAttachmentAcceptAttribute(), ENT_QUOTES) ?>">
                    <button type="button" class="btn btn-secondary chat-attach-btn" data-chat-action="open-attachment-picker" aria-label="Lampirkan file">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <textarea
                        id="chatInput"
                        class="form-control"
                        rows="1"
                        placeholder="Tulis pesan, lalu tekan Enter atau kirim lampiran"
                        oninput="autoGrowChatInput(this); syncChatComposerState()"
                        onkeydown="handleChatInputKeydown(event)"></textarea>
                    <button type="button" class="btn btn-primary" id="chatSendButton" onclick="kirimPesan()" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Modal Buat Room -->
<div class="modal-overlay" id="modalRoom">
    <div class="modal-box">
        <div class="modal-header"><h5>Buat Room Baru</h5><button class="modal-close" onclick="closeModal('modalRoom')">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Nama Room</label>
                <input type="text" id="namaRoom" class="form-control" placeholder="Nama room...">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalRoom')">Batal</button>
            <button class="btn btn-primary" onclick="buatRoom()">Buat</button>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
