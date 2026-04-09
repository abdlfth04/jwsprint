<?php

require_once __DIR__ . '/chat_support.php';

function notificationTableExists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $cache[$table] = false;
    }

    return $cache[$table] = schemaTableExists($conn, $table);
}

function getCurrentEmployeeProfile(): ?array
{
    static $checked = false;
    static $profile = null;

    if ($checked) {
        return $profile;
    }
    $checked = true;

    if (!isLoggedIn() || !notificationTableExists('karyawan')) {
        return null;
    }

    global $conn;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM karyawan WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $profile;
}

function notificationReadStateDirectory(): string
{
    return appPrivateStoragePath('notifications' . DIRECTORY_SEPARATOR . 'read_state');
}

function notificationReadStatePath(int $userId): string
{
    return notificationReadStateDirectory() . DIRECTORY_SEPARATOR . 'user_' . max(1, $userId) . '.json';
}

function notificationEnsureReadStateDirectory(): bool
{
    $directory = notificationReadStateDirectory();
    if (is_dir($directory)) {
        return is_writable($directory);
    }

    if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }

    return is_writable($directory);
}

function notificationNormalizeReadStateMap(array $state): array
{
    $normalized = [];
    foreach ($state as $readKey => $row) {
        $readKey = trim((string) $readKey);
        if ($readKey === '') {
            continue;
        }

        if (!is_array($row)) {
            $row = ['hash' => (string) $row];
        }

        $normalized[$readKey] = [
            'hash' => trim((string) ($row['hash'] ?? '')),
            'read_at' => trim((string) ($row['read_at'] ?? '')),
        ];
    }

    ksort($normalized);

    return $normalized;
}

function notificationLoadReadState(int $userId, bool $refresh = false): array
{
    if (!isset($GLOBALS['__jws_notification_read_state_cache']) || !is_array($GLOBALS['__jws_notification_read_state_cache'])) {
        $GLOBALS['__jws_notification_read_state_cache'] = [];
    }

    $cache = &$GLOBALS['__jws_notification_read_state_cache'];

    if ($userId <= 0) {
        return [];
    }

    if (!$refresh && array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $path = notificationReadStatePath($userId);
    if (!is_file($path)) {
        return $cache[$userId] = [];
    }

    $contents = @file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return $cache[$userId] = [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return $cache[$userId] = [];
    }

    return $cache[$userId] = notificationNormalizeReadStateMap($decoded);
}

function notificationSaveReadState(int $userId, array $state): bool
{
    if (!isset($GLOBALS['__jws_notification_read_state_cache']) || !is_array($GLOBALS['__jws_notification_read_state_cache'])) {
        $GLOBALS['__jws_notification_read_state_cache'] = [];
    }

    $cache = &$GLOBALS['__jws_notification_read_state_cache'];

    if ($userId <= 0 || !notificationEnsureReadStateDirectory()) {
        return false;
    }

    $path = notificationReadStatePath($userId);
    $normalized = notificationNormalizeReadStateMap($state);
    $written = @file_put_contents(
        $path,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    if ($written === false) {
        return false;
    }

    $cache[$userId] = $normalized;

    return true;
}

function notificationBuildReadKey(array $item): string
{
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    $customKey = trim((string) ($meta['read_key'] ?? ''));
    if ($customKey !== '') {
        return substr(hash('sha256', $customKey), 0, 40);
    }

    $parts = array_values(array_filter([
        trim((string) ($item['kind'] ?? 'generic')),
        trim((string) ($meta['reminder_kind'] ?? '')),
        trim((string) ($meta['read_scope'] ?? '')),
        trim((string) ($item['href'] ?? '')),
        trim((string) ($item['icon'] ?? '')),
    ], static function (string $value): bool {
        return $value !== '';
    }));

    $base = empty($parts) ? 'notification|generic' : implode('|', $parts);

    return substr(hash('sha256', $base), 0, 40);
}

function notificationBuildReadHash(array $item): string
{
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    unset($meta['read_key'], $meta['read_hash']);

    $payload = [
        'kind' => trim((string) ($item['kind'] ?? 'generic')),
        'tone' => trim((string) ($item['tone'] ?? 'info')),
        'count' => (int) ($item['count'] ?? 0),
        'title' => trim((string) ($item['title'] ?? '')),
        'message' => trim((string) ($item['message'] ?? '')),
        'href' => trim((string) ($item['href'] ?? '')),
        'meta' => $meta,
    ];

    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function notificationFilterReadItemsForUser(array $items, int $userId): array
{
    if ($userId <= 0 || empty($items)) {
        return $items;
    }

    $readState = notificationLoadReadState($userId);
    if (empty($readState)) {
        return $items;
    }

    $filtered = [];
    foreach ($items as $item) {
        $readKey = trim((string) ($item['read_key'] ?? notificationBuildReadKey($item)));
        $readHash = trim((string) ($item['read_hash'] ?? notificationBuildReadHash($item)));
        $storedHash = trim((string) ($readState[$readKey]['hash'] ?? ''));

        if ($readKey !== '' && $storedHash !== '' && hash_equals($storedHash, $readHash)) {
            continue;
        }

        $filtered[] = $item;
    }

    return array_values($filtered);
}

function notificationMarkItemsAsRead(int $userId, array $items): int
{
    if ($userId <= 0 || empty($items)) {
        return 0;
    }

    $readState = notificationLoadReadState($userId, true);
    $updated = 0;

    foreach ($items as $item) {
        $readKey = trim((string) ($item['read_key'] ?? notificationBuildReadKey($item)));
        $readHash = trim((string) ($item['read_hash'] ?? notificationBuildReadHash($item)));
        if ($readKey === '' || $readHash === '') {
            continue;
        }

        $currentHash = trim((string) ($readState[$readKey]['hash'] ?? ''));
        if ($currentHash !== '' && hash_equals($currentHash, $readHash)) {
            continue;
        }

        $readState[$readKey] = [
            'hash' => $readHash,
            'read_at' => date(DATE_ATOM),
        ];
        $updated++;
    }

    if ($updated <= 0) {
        return 0;
    }

    if (!notificationSaveReadState($userId, $readState)) {
        return 0;
    }

    return $updated;
}

function notificationMarkItemsByReadKeys(int $userId, array $readKeys): int
{
    if ($userId <= 0) {
        return 0;
    }

    $normalizedKeys = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $readKeys))));

    if (empty($normalizedKeys)) {
        return 0;
    }

    $lookup = [];
    foreach (collectNotificationItems(true) as $item) {
        $lookup[(string) ($item['read_key'] ?? '')] = $item;
    }

    $itemsToMark = [];
    foreach ($normalizedKeys as $readKey) {
        if (isset($lookup[$readKey])) {
            $itemsToMark[] = $lookup[$readKey];
        }
    }

    return notificationMarkItemsAsRead($userId, $itemsToMark);
}

function notificationMarkAllCurrentItemsAsRead(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    return notificationMarkItemsAsRead($userId, collectNotificationItems(true));
}

function notificationResetCollectedItemsCache(): void
{
    unset($GLOBALS['__jws_notification_items_cache']);
}

function buildNotificationItem(
    string $icon,
    string $tone,
    int $count,
    string $title,
    string $message,
    string $href,
    int $priority,
    string $kind = 'generic',
    array $meta = []
): array
{
    $normalizedHref = $href;
    if (
        $href !== ''
        && function_exists('pageUrl')
        && !preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $href)
    ) {
        $normalizedHref = pageUrl(ltrim($href, '/'));
    }

    $item = [
        'icon' => $icon,
        'tone' => $tone,
        'count' => $count,
        'title' => $title,
        'message' => $message,
        'href' => $normalizedHref,
        'priority' => $priority,
        'kind' => $kind,
        'meta' => $meta,
    ];

    $item['read_key'] = notificationBuildReadKey($item);
    $item['read_hash'] = notificationBuildReadHash($item);

    return $item;
}

function notificationCanAccessSupplierPayables(string $role): bool
{
    return in_array($role, ['superadmin', 'admin', 'service'], true);
}

function notificationTransactionCashierQueueCount(): int
{
    global $conn;
    if (!isset($conn) || !$conn || !notificationTableExists('transaksi')) {
        return 0;
    }

    if (function_exists('transactionWorkflowSupportReady')) {
        transactionWorkflowSupportReady($conn);
    }

    $sql = schemaColumnExists($conn, 'transaksi', 'workflow_step')
        ? "SELECT COUNT(*) AS total FROM transaksi WHERE workflow_step = 'cashier'"
        : "SELECT COUNT(*) AS total FROM transaksi WHERE status = 'pending'";

    $result = $conn->query($sql);
    return (int) (($result ? $result->fetch_assoc()['total'] : 0) ?? 0);
}

function notificationTransactionCashierQueueHref(): string
{
    global $conn;

    if (isset($conn) && $conn && schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
        return 'transaksi.php?workflow=cashier';
    }

    return 'transaksi.php?status=pending';
}

function notificationBuildSupplierPayableItems(string $role, int $userId): array
{
    if (!notificationCanAccessSupplierPayables($role) || $userId <= 0 || !function_exists('materialInventoryFetchPayableAlertSummary')) {
        return [];
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return [];
    }

    $dueSoonDays = 7;
    $summary = materialInventoryFetchPayableAlertSummary($conn, $dueSoonDays);
    materialInventoryTriggerAutomaticPayableReminders($conn, $userId, $role, $summary, $dueSoonDays);

    $items = [];

    if ((int) ($summary['overdue']['count'] ?? 0) > 0) {
        $rows = is_array($summary['overdue']['rows'] ?? null) ? $summary['overdue']['rows'] : [];
        $target = materialInventoryBuildPayableTarget($rows);
        $items[] = buildNotificationItem(
            'fa-calendar-xmark',
            'danger',
            (int) ($summary['overdue']['count'] ?? 0),
            (int) ($summary['overdue']['count'] ?? 0) . ' hutang supplier melewati jatuh tempo',
            materialInventoryBuildDepartmentBreakdown($rows) . '. Total sisa ' . materialInventoryFormatMoney((float) ($summary['overdue']['total'] ?? 0)) . '.',
            (string) ($target['href'] ?? 'pembelian_bahan.php'),
            11,
            'supplier_payable',
            [
                'reminder_kind' => 'overdue',
                'total_tagihan' => (float) ($summary['overdue']['total'] ?? 0),
            ]
        );
    }

    if ((int) ($summary['due_soon']['count'] ?? 0) > 0) {
        $rows = is_array($summary['due_soon']['rows'] ?? null) ? $summary['due_soon']['rows'] : [];
        $target = materialInventoryBuildPayableTarget($rows);
        $items[] = buildNotificationItem(
            'fa-hourglass-half',
            'warning',
            (int) ($summary['due_soon']['count'] ?? 0),
            (int) ($summary['due_soon']['count'] ?? 0) . ' hutang supplier jatuh tempo <= 7 hari',
            materialInventoryBuildDepartmentBreakdown($rows) . '. Total sisa ' . materialInventoryFormatMoney((float) ($summary['due_soon']['total'] ?? 0)) . '.',
            (string) ($target['href'] ?? 'pembelian_bahan.php'),
            17,
            'supplier_payable',
            [
                'reminder_kind' => 'due_soon',
                'total_tagihan' => (float) ($summary['due_soon']['total'] ?? 0),
            ]
        );
    }

    return $items;
}

function fetchAssignedStageStats(int $userId): array
{
    $summary = [
        'total' => 0,
        'overdue' => 0,
    ];

    if (
        $userId <= 0
        || !notificationTableExists('todo_list_tahapan')
        || !notificationTableExists('produksi')
    ) {
        return $summary;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $summary;
    }

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN p.deadline IS NOT NULL AND p.deadline < CURDATE() THEN 1 ELSE 0 END) AS overdue
         FROM todo_list_tahapan t
         LEFT JOIN produksi p ON p.id = t.produksi_id
         WHERE t.user_id = ?
           AND t.status = 'belum'
           AND (p.id IS NULL OR p.status IS NULL OR p.status NOT IN ('selesai', 'batal'))"
    );
    if (!$stmt) {
        return $summary;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $summary['total'] = (int) ($row['total'] ?? 0);
    $summary['overdue'] = (int) ($row['overdue'] ?? 0);

    return $summary;
}

function fetchAssignedStageRows(int $userId, int $limit = 5): array
{
    if (
        $userId <= 0
        || !notificationTableExists('todo_list_tahapan')
        || !notificationTableExists('produksi')
    ) {
        return [];
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return [];
    }

    $limit = max(1, min($limit, 20));
    $sql = "SELECT
            t.id,
            t.produksi_id,
            t.nama_tahapan,
            t.urutan,
            t.status,
            p.no_dokumen,
            p.tipe_dokumen,
            p.nama_pekerjaan,
            p.deadline,
            p.status AS produksi_status,
            trx.no_transaksi
        FROM todo_list_tahapan t
        LEFT JOIN produksi p ON p.id = t.produksi_id
        LEFT JOIN transaksi trx ON trx.id = p.transaksi_id
        WHERE t.user_id = ?
          AND t.status = 'belum'
          AND (p.id IS NULL OR p.status IS NULL OR p.status NOT IN ('selesai', 'batal'))
        ORDER BY
            CASE WHEN p.deadline IS NOT NULL AND p.deadline < CURDATE() THEN 0 ELSE 1 END,
            CASE WHEN p.deadline IS NULL THEN 1 ELSE 0 END,
            p.deadline ASC,
            p.created_at DESC,
            t.urutan ASC,
            t.id ASC
        LIMIT {$limit}";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function notificationGetChatRoomSchemaInfo(): array
{
    static $schema = null;
    if (is_array($schema)) {
        return $schema;
    }

    $schema = [
        'available' => false,
        'has_tipe' => false,
        'has_divisi' => false,
        'has_is_default' => false,
    ];

    if (!notificationTableExists('chat_room') || !notificationTableExists('chat_pesan')) {
        return $schema;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $schema;
    }

    $columns = schemaTableColumns($conn, 'chat_room');

    $schema['available'] = true;
    $schema['has_tipe'] = in_array('tipe', $columns, true);
    $schema['has_divisi'] = in_array('divisi', $columns, true);
    $schema['has_is_default'] = in_array('is_default', $columns, true);

    return $schema;
}

function notificationChatCanAccessRoom(array $room, string $role, ?string $divisi, bool $hasChatRoomDivisi): bool
{
    $roomType = (string) ($room['tipe'] ?? 'group');
    $roomDivisi = $room['divisi'] ?? null;

    if (
        $roomType === 'group'
        && $hasChatRoomDivisi
        && !empty($roomDivisi)
        && !in_array($role, ['superadmin', 'admin'], true)
    ) {
        return $divisi === $roomDivisi;
    }

    return true;
}

function notificationEnsureChatReadTrackingTable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    global $conn;
    if (!isset($conn) || !$conn || !notificationTableExists('chat_room') || !notificationTableExists('chat_pesan')) {
        return $ready = false;
    }

    if (notificationTableExists('chat_read_status')) {
        return $ready = true;
    }

    if (!appSchemaAutoMigrateEnabled()) {
        return $ready = false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS chat_read_status (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT NOT NULL,
        user_id INT NOT NULL,
        last_read_id INT NOT NULL DEFAULT 0,
        last_read_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_chat_read_room_user (room_id, user_id),
        KEY idx_chat_read_user (user_id),
        KEY idx_chat_read_room (room_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $created = $conn->query($sql);
    if (!$created) {
        return $ready = false;
    }

    return $ready = true;
}

function notificationGetAccessibleChatRoomIds(?int $userId = null, ?string $role = null, ?string $divisi = null): array
{
    $schema = notificationGetChatRoomSchemaInfo();
    if (empty($schema['available'])) {
        return [];
    }

    $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    $role = $role ?? (string) ($_SESSION['role'] ?? '');
    if ($userId <= 0) {
        return [];
    }

    if ($divisi === null) {
        $employee = getCurrentEmployeeProfile();
        $divisi = $employee['divisi'] ?? null;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return [];
    }

    $roomIds = [];

    if ($schema['has_tipe']) {
        $resGroups = $conn->query("SELECT * FROM chat_room WHERE tipe='group' ORDER BY id ASC");
    } else {
        $resGroups = $conn->query("SELECT * FROM chat_room ORDER BY id ASC");
    }

    if ($resGroups) {
        foreach ($resGroups->fetch_all(MYSQLI_ASSOC) as $room) {
            if (!$schema['has_tipe'] && strpos((string) ($room['nama'] ?? ''), 'personal_') === 0) {
                continue;
            }
            if (!notificationChatCanAccessRoom($room, $role, $divisi, (bool) $schema['has_divisi'])) {
                continue;
            }
            $roomIds[] = (int) ($room['id'] ?? 0);
        }
    }

    $like1 = "personal_{$userId}_%";
    $like2 = "personal_%_{$userId}";
    $sqlPersonal = $schema['has_tipe']
        ? "SELECT id FROM chat_room WHERE tipe='personal' AND (nama LIKE ? OR nama LIKE ?)"
        : "SELECT id FROM chat_room WHERE nama LIKE ? OR nama LIKE ?";
    $stmt = $conn->prepare($sqlPersonal);
    if ($stmt) {
        $stmt->bind_param('ss', $like1, $like2);
        $stmt->execute();
        $resPersonal = $stmt->get_result();
        if ($resPersonal) {
            foreach ($resPersonal->fetch_all(MYSQLI_ASSOC) as $room) {
                $roomIds[] = (int) ($room['id'] ?? 0);
            }
        }
        $stmt->close();
    }

    $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds))));
    sort($roomIds);

    return $roomIds;
}

function notificationGetUnreadChatRoomMap(int $userId, array $roomIds): array
{
    if ($userId <= 0 || empty($roomIds) || !notificationEnsureChatReadTrackingTable()) {
        return [];
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return [];
    }

    $idList = implode(',', array_map('intval', array_values(array_unique($roomIds))));
    if ($idList === '') {
        return [];
    }

    $sql = "SELECT unread.room_id, unread.unread_count, unread.latest_unread_id, cp.pesan AS latest_message,
                cp.created_at, cp.user_id, u.nama AS sender_name
        FROM (
            SELECT cp.room_id, COUNT(*) AS unread_count, MAX(cp.id) AS latest_unread_id
            FROM chat_pesan cp
            LEFT JOIN chat_read_status crs
                ON crs.room_id = cp.room_id AND crs.user_id = ?
            WHERE cp.room_id IN ($idList)
              AND cp.user_id <> ?
              AND cp.id > COALESCE(crs.last_read_id, 0)
            GROUP BY cp.room_id
        ) unread
        JOIN chat_pesan cp ON cp.id = unread.latest_unread_id
        JOIN users u ON u.id = cp.user_id";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $map[(int) ($row['room_id'] ?? 0)] = [
            'room_id' => (int) ($row['room_id'] ?? 0),
            'unread_count' => (int) ($row['unread_count'] ?? 0),
            'latest_unread_id' => (int) ($row['latest_unread_id'] ?? 0),
            'latest_message' => (string) ($row['latest_message'] ?? ''),
            'created_at' => $row['created_at'] ?? null,
            'user_id' => (int) ($row['user_id'] ?? 0),
            'sender_name' => (string) ($row['sender_name'] ?? ''),
        ];
    }

    return $map;
}

function notificationMarkChatRoomAsRead(int $roomId, int $userId): bool
{
    if ($roomId <= 0 || $userId <= 0 || !notificationEnsureChatReadTrackingTable()) {
        return false;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return false;
    }

    $stmtMax = $conn->prepare("SELECT MAX(id) AS last_id FROM chat_pesan WHERE room_id = ?");
    if (!$stmtMax) {
        return false;
    }

    $stmtMax->bind_param('i', $roomId);
    $stmtMax->execute();
    $lastId = (int) (($stmtMax->get_result()->fetch_assoc()['last_id'] ?? 0));
    $stmtMax->close();

    $stmt = $conn->prepare(
        "INSERT INTO chat_read_status (room_id, user_id, last_read_id, last_read_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
            last_read_at = NOW()"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('iii', $roomId, $userId, $lastId);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function notificationGetUnreadChatSummaryForCurrentUser(): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = (string) ($_SESSION['role'] ?? '');
    if ($userId <= 0 || $role === '') {
        return [
            'room_count' => 0,
            'message_count' => 0,
            'latest' => null,
        ];
    }

    $employee = getCurrentEmployeeProfile();
    $divisi = $employee['divisi'] ?? null;
    $roomIds = notificationGetAccessibleChatRoomIds($userId, $role, $divisi);
    $map = notificationGetUnreadChatRoomMap($userId, $roomIds);

    $roomCount = count($map);
    $messageCount = 0;
    $latest = null;
    foreach ($map as $row) {
        $messageCount += (int) ($row['unread_count'] ?? 0);
        if ($latest === null || (int) ($row['latest_unread_id'] ?? 0) > (int) ($latest['latest_unread_id'] ?? 0)) {
            $latest = $row;
        }
    }

    return [
        'room_count' => $roomCount,
        'message_count' => $messageCount,
        'latest' => $latest,
    ];
}

function collectNotificationItems(bool $forceRefresh = false): array
{
    if ($forceRefresh) {
        notificationResetCollectedItemsCache();
    }

    if (isset($GLOBALS['__jws_notification_items_cache']) && is_array($GLOBALS['__jws_notification_items_cache'])) {
        return $GLOBALS['__jws_notification_items_cache'];
    }

    $role = $_SESSION['role'] ?? '';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $employee = getCurrentEmployeeProfile();
    $employeeId = (int) ($employee['id'] ?? 0);

    global $conn;
    if (!isset($conn) || !$conn) {
        return $GLOBALS['__jws_notification_items_cache'] = [];
    }

    $items = [];
    $items = array_merge($items, notificationBuildSupplierPayableItems($role, $userId));

    if (in_array($role, ['superadmin', 'admin', 'kasir'], true) && notificationTableExists('transaksi')) {
        $total = notificationTransactionCashierQueueCount();
        if ($total > 0) {
            $items[] = buildNotificationItem(
                'fa-cash-register',
                'warning',
                $total,
                $total . ' order menunggu kasir',
                $role === 'kasir'
                    ? 'Selesaikan pembayaran order yang masih menunggu pelunasan agar antrean checkout tetap lancar.'
                    : 'Ada order yang sudah siap ditangani kasir dan perlu tindak lanjut pembayaran.',
                notificationTransactionCashierQueueHref(),
                20,
                'cashier_queue'
            );
        }
    }

    if (in_array($role, ['superadmin', 'admin', 'service'], true) && notificationTableExists('produksi')) {
        $result = $conn->query("SELECT COUNT(*) AS total FROM produksi WHERE deadline IS NOT NULL AND deadline < CURDATE() AND status NOT IN ('selesai', 'batal')");
        $overdue = (int) (($result ? $result->fetch_assoc()['total'] : 0) ?? 0);
        if ($overdue > 0) {
            $items[] = buildNotificationItem(
                'fa-triangle-exclamation',
                'danger',
                $overdue,
                $overdue . ' job produksi melewati deadline',
                'Ada JO/SPK aktif yang sudah lewat target penyelesaian.',
                'produksi.php?progress=belum',
                10
            );
        }

        $result = $conn->query("SELECT COUNT(*) AS total FROM produksi WHERE status IN ('antrian', 'proses') AND karyawan_id IS NULL");
        $unassigned = (int) (($result ? $result->fetch_assoc()['total'] : 0) ?? 0);
        if ($unassigned > 0) {
            $items[] = buildNotificationItem(
                'fa-user-plus',
                'info',
                $unassigned,
                $unassigned . ' job belum punya PIC',
                'Beberapa pekerjaan produksi belum ditugaskan ke karyawan.',
                'produksi.php?progress=belum',
                25
            );
        }
    }

    $chatUnread = notificationGetUnreadChatSummaryForCurrentUser();
    if (($chatUnread['room_count'] ?? 0) > 0) {
        $roomCount = (int) ($chatUnread['room_count'] ?? 0);
        $messageCount = (int) ($chatUnread['message_count'] ?? 0);
        $latest = $chatUnread['latest'] ?? null;
        $latestSender = trim((string) ($latest['sender_name'] ?? ''));
        $previewText = chatPreviewTextFromStoredMessage((string) ($latest['latest_message'] ?? ''), 52);

        $message = $messageCount > 1
            ? $messageCount . ' pesan baru di ' . $roomCount . ' room chat.'
            : 'Ada pesan baru di room chat Anda.';

        if ($latestSender !== '') {
            $message .= ' Terakhir dari ' . $latestSender . ($previewText !== '' ? ': ' . $previewText : '.');
        }

        $items[] = buildNotificationItem(
            'fa-comments',
            'info',
            $roomCount,
            $roomCount === 1 ? '1 room chat belum dibaca' : $roomCount . ' room chat belum dibaca',
            $message,
            'chat.php',
            12,
            'chat',
            [
                'chat_room_count' => $roomCount,
                'chat_message_count' => $messageCount,
                'latest_unread_id' => (int) ($latest['latest_unread_id'] ?? 0),
                'latest_sender' => $latestSender,
                'latest_message' => $previewText,
            ]
        );
    }

    if (in_array($role, ['service', 'kasir', 'user'], true) && $userId > 0) {
        $assignedStageStats = fetchAssignedStageStats($userId);
        if (($assignedStageStats['total'] ?? 0) > 0) {
            $overdueAssigned = (int) ($assignedStageStats['overdue'] ?? 0);
            $items[] = buildNotificationItem(
                'fa-list-check',
                $overdueAssigned > 0 ? 'danger' : 'info',
                (int) $assignedStageStats['total'],
                (int) $assignedStageStats['total'] . ' tahapan menunggu Anda',
                $overdueAssigned > 0
                    ? $overdueAssigned . ' tahapan sudah melewati deadline. Buka dashboard untuk menyelesaikannya.'
                    : 'Ada tahapan kerja yang sudah didelegasikan ke Anda dan menunggu update progres.',
                'dashboard.php#delegasi-saya',
                $overdueAssigned > 0 ? 9 : 14
            );
        }
    }

    if ($role === 'user' && $employeeId > 0 && notificationTableExists('produksi')) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM produksi WHERE karyawan_id = ? AND status IN ('antrian', 'proses')");
        if ($stmt) {
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $activeJobs = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
            $stmt->close();
            if ($activeJobs > 0) {
                $items[] = buildNotificationItem(
                    'fa-industry',
                    'info',
                    $activeJobs,
                    $activeJobs . ' job aktif ditugaskan ke Anda',
                    'Buka daftar produksi untuk melihat prioritas kerja pribadi.',
                    'produksi.php',
                    15
                );
            }
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM produksi WHERE karyawan_id = ? AND deadline IS NOT NULL AND deadline < CURDATE() AND status NOT IN ('selesai', 'batal')");
        if ($stmt) {
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $overdueMine = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
            $stmt->close();
            if ($overdueMine > 0) {
                $items[] = buildNotificationItem(
                    'fa-bolt',
                    'danger',
                    $overdueMine,
                    $overdueMine . ' job Anda melewati deadline',
                    'Ada pekerjaan pribadi yang perlu diprioritaskan hari ini.',
                    'produksi.php?progress=belum',
                    10
                );
            }
        }
    }

    if (in_array($role, ['superadmin', 'admin', 'service', 'user'], true) && notificationTableExists('file_transaksi')) {
        $result = $conn->query("SELECT COUNT(*) AS total FROM file_transaksi WHERE tipe_file = 'siap_cetak' AND is_active = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)");
        $readyPrint = (int) (($result ? $result->fetch_assoc()['total'] : 0) ?? 0);
        if ($readyPrint > 0) {
            $items[] = buildNotificationItem(
                'fa-print',
                'info',
                $readyPrint,
                $readyPrint . ' file siap cetak baru',
                'Ada file final baru yang perlu dicek sebelum masuk proses cetak.',
                'siap_cetak.php',
                30
            );
        }
    }

    if (in_array($role, ['superadmin', 'admin'], true) && notificationTableExists('produk')) {
        $result = $conn->query("SELECT COUNT(*) AS total FROM produk WHERE stok <= 5");
        $lowStock = (int) (($result ? $result->fetch_assoc()['total'] : 0) ?? 0);
        if ($lowStock > 0) {
            $items[] = buildNotificationItem(
                'fa-boxes-stacked',
                'warning',
                $lowStock,
                $lowStock . ' produk masuk ambang stok minimum',
                'Perlu restock atau evaluasi stok agar operasional tidak tertahan.',
                'produk.php',
                35
            );
        }
    }

    usort($items, static function (array $left, array $right): int {
        if (($left['priority'] ?? 0) === ($right['priority'] ?? 0)) {
            return ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
        }
        return ($left['priority'] ?? 0) <=> ($right['priority'] ?? 0);
    });

    $items = notificationFilterReadItemsForUser($items, $userId);

    return $GLOBALS['__jws_notification_items_cache'] = $items;
}

function getNotificationItems(int $limit = 8): array
{
    return array_slice(collectNotificationItems(), 0, max(1, $limit));
}

function getNotificationCount(): int
{
    $total = 0;
    foreach (collectNotificationItems() as $item) {
        $total += (int) ($item['count'] ?? 0);
    }
    return $total;
}

function getNotificationToneBadge(string $tone): string
{
    $map = [
        'danger' => 'badge-danger',
        'warning' => 'badge-warning',
        'info' => 'badge-info',
        'success' => 'badge-success',
    ];

    return $map[$tone] ?? 'badge-secondary';
}
