<?php

function materialInventoryNormalizeDepartment(string $value): string
{
    return strtolower(trim($value)) === 'apparel' ? 'apparel' : 'printing';
}

function materialInventoryDepartmentLabel(string $departemen): string
{
    return materialInventoryNormalizeDepartment($departemen) === 'apparel' ? 'Apparel' : 'Printing';
}

function materialInventoryFormatMoney(float $amount): string
{
    return 'Rp ' . number_format(max(0, $amount), 0, ',', '.');
}

function materialInventoryBuildPayableTarget(array $rows): array
{
    $departments = [];
    foreach ($rows as $row) {
        $departments[materialInventoryNormalizeDepartment((string) ($row['departemen'] ?? 'printing'))] = true;
    }

    $href = 'pembelian_bahan.php';
    $url = pageUrl('pembelian_bahan.php');
    if (count($departments) === 1) {
        $departemen = (string) array_key_first($departments);
        $href .= '?departemen=' . urlencode($departemen);
        $url .= '?departemen=' . rawurlencode($departemen);
    }

    return [
        'href' => $href,
        'url' => $url,
    ];
}

function materialInventoryBuildDepartmentBreakdown(array $rows): string
{
    $counts = [
        'printing' => 0,
        'apparel' => 0,
    ];

    foreach ($rows as $row) {
        $departemen = materialInventoryNormalizeDepartment((string) ($row['departemen'] ?? 'printing'));
        $counts[$departemen] = (int) ($counts[$departemen] ?? 0) + 1;
    }

    $parts = [];
    foreach ($counts as $departemen => $count) {
        if ($count > 0) {
            $parts[] = materialInventoryDepartmentLabel($departemen) . ' ' . $count;
        }
    }

    return empty($parts) ? 'Tanpa departemen aktif' : implode(', ', $parts);
}

function materialInventorySupportReady(mysqli $conn): bool
{
    $requiredTables = [
        'stok_bahan',
        'material_suppliers',
        'pembelian_bahan',
        'pembelian_bahan_item',
        'pembelian_bahan_pembayaran',
        'stok_bahan_mutasi',
        'pembelian_bahan_reminder_log',
    ];

    foreach ($requiredTables as $table) {
        if (!schemaTableExists($conn, $table)) {
            return false;
        }
    }

    foreach (['supplier_id', 'metode_pembayaran', 'jatuh_tempo', 'dibayar_total', 'sisa_tagihan', 'status_pembayaran'] as $column) {
        if (!schemaColumnExists($conn, 'pembelian_bahan', $column)) {
            return false;
        }
    }

    return true;
}

function materialInventoryEnsureSupportTables(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (materialInventorySupportReady($conn)) {
        return $ready = true;
    }

    if (!schemaTableExists($conn, 'stok_bahan')) {
        return $ready = false;
    }

    if (!appSchemaAutoMigrateEnabled()) {
        return $ready = false;
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS material_suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(190) NOT NULL,
            departemen ENUM('printing','apparel') NOT NULL,
            telepon VARCHAR(40) NULL,
            email VARCHAR(120) NULL,
            alamat TEXT NULL,
            catatan TEXT NULL,
            status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_material_supplier_departemen (departemen),
            KEY idx_material_supplier_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS pembelian_bahan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            no_pembelian VARCHAR(60) NOT NULL,
            tanggal DATE NOT NULL,
            departemen ENUM('printing','apparel') NOT NULL,
            supplier_nama VARCHAR(190) NULL,
            referensi_nota VARCHAR(120) NULL,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            ongkir DECIMAL(15,2) NOT NULL DEFAULT 0,
            diskon DECIMAL(15,2) NOT NULL DEFAULT 0,
            grand_total DECIMAL(15,2) NOT NULL DEFAULT 0,
            catatan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pembelian_no (no_pembelian),
            KEY idx_pembelian_tanggal (tanggal),
            KEY idx_pembelian_departemen (departemen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS pembelian_bahan_item (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembelian_id INT NOT NULL,
            stok_bahan_id INT NOT NULL,
            departemen ENUM('printing','apparel') NOT NULL,
            nama_bahan VARCHAR(190) NOT NULL,
            satuan VARCHAR(50) NULL,
            qty DECIMAL(15,3) NOT NULL DEFAULT 0,
            harga_beli DECIMAL(15,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pembelian_item_header (pembelian_id),
            KEY idx_pembelian_item_bahan (stok_bahan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS pembelian_bahan_pembayaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembelian_id INT NOT NULL,
            tanggal DATE NOT NULL,
            nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
            metode VARCHAR(50) NULL,
            referensi VARCHAR(120) NULL,
            catatan VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pembelian_bayar_header (pembelian_id),
            KEY idx_pembelian_bayar_tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS stok_bahan_mutasi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stok_bahan_id INT NOT NULL,
            departemen ENUM('printing','apparel') NOT NULL,
            tipe VARCHAR(50) NOT NULL,
            arah ENUM('masuk','keluar') NOT NULL,
            nama_bahan VARCHAR(190) NOT NULL,
            qty DECIMAL(15,3) NOT NULL DEFAULT 0,
            stok_sebelum DECIMAL(15,3) NOT NULL DEFAULT 0,
            stok_sesudah DECIMAL(15,3) NOT NULL DEFAULT 0,
            harga_satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_nilai DECIMAL(15,2) NOT NULL DEFAULT 0,
            referensi_tipe VARCHAR(50) NULL,
            referensi_id INT NULL,
            keterangan VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mutasi_bahan (stok_bahan_id),
            KEY idx_mutasi_departemen (departemen),
            KEY idx_mutasi_tanggal (created_at),
            KEY idx_mutasi_referensi (referensi_tipe, referensi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS pembelian_bahan_reminder_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembelian_id INT NOT NULL,
            user_id INT NOT NULL,
            departemen ENUM('printing','apparel') NOT NULL,
            reminder_kind ENUM('overdue','due_soon') NOT NULL,
            reminder_date DATE NOT NULL,
            jatuh_tempo DATE NULL,
            push_subscriptions INT NOT NULL DEFAULT 0,
            push_sent INT NOT NULL DEFAULT 0,
            push_failed INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pembelian_reminder (pembelian_id, user_id, reminder_kind, reminder_date),
            KEY idx_pembelian_reminder_user (user_id, reminder_date),
            KEY idx_pembelian_reminder_kind (reminder_kind, reminder_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            return $ready = false;
        }
    }

    $columnQueries = [];
    if (!schemaColumnExists($conn, 'pembelian_bahan', 'supplier_id')) {
        $columnQueries[] = "ALTER TABLE pembelian_bahan ADD COLUMN supplier_id INT NULL AFTER departemen";
    }
    if (!schemaColumnExists($conn, 'pembelian_bahan', 'metode_pembayaran')) {
        $columnQueries[] = "ALTER TABLE pembelian_bahan ADD COLUMN metode_pembayaran ENUM('tunai','tempo') NOT NULL DEFAULT 'tunai' AFTER supplier_nama";
    }
    if (!schemaColumnExists($conn, 'pembelian_bahan', 'jatuh_tempo')) {
        $columnQueries[] = "ALTER TABLE pembelian_bahan ADD COLUMN jatuh_tempo DATE NULL AFTER metode_pembayaran";
    }
    if (!schemaColumnExists($conn, 'pembelian_bahan', 'dibayar_total')) {
        $columnQueries[] = "ALTER TABLE pembelian_bahan ADD COLUMN dibayar_total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER grand_total";
    }
    if (!schemaColumnExists($conn, 'pembelian_bahan', 'sisa_tagihan')) {
        $columnQueries[] = "ALTER TABLE pembelian_bahan ADD COLUMN sisa_tagihan DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER dibayar_total";
    }
    if (!schemaColumnExists($conn, 'pembelian_bahan', 'status_pembayaran')) {
        $columnQueries[] = "ALTER TABLE pembelian_bahan ADD COLUMN status_pembayaran ENUM('lunas','parsial','belum_lunas') NOT NULL DEFAULT 'lunas' AFTER sisa_tagihan";
    }

    foreach ($columnQueries as $sql) {
        if (!$conn->query($sql)) {
            return $ready = false;
        }
    }

    return $ready = true;
}

function materialInventoryLoadCatalog(mysqli $conn): array
{
    $catalog = [
        'printing' => [],
        'apparel' => [],
    ];

    if (!schemaTableExists($conn, 'stok_bahan')) {
        return $catalog;
    }

    $result = $conn->query(
        "SELECT id, kode, nama, kategori, satuan, stok, stok_minimum, harga_beli, keterangan
         FROM stok_bahan
         WHERE kategori IN ('printing','apparel')
         ORDER BY kategori, nama"
    );

    if (!$result) {
        return $catalog;
    }

    while ($row = $result->fetch_assoc()) {
        $dept = materialInventoryNormalizeDepartment((string) ($row['kategori'] ?? 'printing'));
        $catalog[$dept][] = [
            'id' => (int) ($row['id'] ?? 0),
            'kode' => trim((string) ($row['kode'] ?? '')),
            'nama' => trim((string) ($row['nama'] ?? '')),
            'kategori' => $dept,
            'satuan' => trim((string) ($row['satuan'] ?? '')),
            'stok' => (float) ($row['stok'] ?? 0),
            'stok_minimum' => (float) ($row['stok_minimum'] ?? 0),
            'harga_beli' => (float) ($row['harga_beli'] ?? 0),
            'keterangan' => trim((string) ($row['keterangan'] ?? '')),
        ];
    }

    return $catalog;
}

function materialInventoryLoadCatalogById(mysqli $conn): array
{
    $byId = [];
    foreach (materialInventoryLoadCatalog($conn) as $rows) {
        foreach ($rows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }
    }

    return $byId;
}

function materialInventoryLoadSuppliers(mysqli $conn, ?string $departemen = null, bool $activeOnly = false): array
{
    if (!materialInventorySupportReady($conn) || !schemaTableExists($conn, 'material_suppliers')) {
        return [];
    }

    $params = [];
    $types = '';
    $where = [];

    if ($departemen !== null) {
        $where[] = 'departemen = ?';
        $params[] = materialInventoryNormalizeDepartment($departemen);
        $types .= 's';
    }
    if ($activeOnly) {
        $where[] = "status = 'aktif'";
    }

    $sql = "SELECT * FROM material_suppliers";
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY status DESC, nama ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function materialInventoryFetchPayableAlertSummary(mysqli $conn, int $dueSoonDays = 7): array
{
    $dueSoonDays = max(1, min(30, $dueSoonDays));
    $summary = [
        'due_soon_days' => $dueSoonDays,
        'active' => [
            'count' => 0,
            'total' => 0.0,
        ],
        'overdue' => [
            'count' => 0,
            'total' => 0.0,
            'rows' => [],
        ],
        'due_soon' => [
            'count' => 0,
            'total' => 0.0,
            'rows' => [],
        ],
        'departments' => [
            'printing' => [
                'active_count' => 0,
                'active_total' => 0.0,
                'overdue_count' => 0,
                'overdue_total' => 0.0,
                'due_soon_count' => 0,
                'due_soon_total' => 0.0,
            ],
            'apparel' => [
                'active_count' => 0,
                'active_total' => 0.0,
                'overdue_count' => 0,
                'overdue_total' => 0.0,
                'due_soon_count' => 0,
                'due_soon_total' => 0.0,
            ],
        ],
    ];

    if (!materialInventorySupportReady($conn) || !schemaTableExists($conn, 'pembelian_bahan')) {
        return $summary;
    }

    $result = $conn->query(
        "SELECT
            pb.id,
            pb.no_pembelian,
            pb.tanggal,
            pb.departemen,
            pb.supplier_nama,
            pb.jatuh_tempo,
            pb.grand_total,
            pb.dibayar_total,
            pb.sisa_tagihan,
            pb.status_pembayaran,
            pb.metode_pembayaran,
            ms.nama AS nama_supplier_master,
            DATEDIFF(pb.jatuh_tempo, CURDATE()) AS sisa_hari
         FROM pembelian_bahan pb
         LEFT JOIN material_suppliers ms ON ms.id = pb.supplier_id
         WHERE pb.status_pembayaran <> 'lunas'
           AND pb.sisa_tagihan > 0
         ORDER BY
            CASE WHEN pb.jatuh_tempo IS NULL THEN 2 WHEN pb.jatuh_tempo < CURDATE() THEN 0 ELSE 1 END,
            pb.jatuh_tempo ASC,
            pb.tanggal ASC,
            pb.id ASC"
    );
    if (!$result) {
        return $summary;
    }

    while ($row = $result->fetch_assoc()) {
        $departemen = materialInventoryNormalizeDepartment((string) ($row['departemen'] ?? 'printing'));
        $remaining = max(0, (float) ($row['sisa_tagihan'] ?? 0));
        $supplierDisplay = trim((string) (($row['nama_supplier_master'] ?? '') !== '' ? $row['nama_supplier_master'] : ($row['supplier_nama'] ?? '')));
        if ($supplierDisplay === '') {
            $supplierDisplay = 'Supplier tanpa nama';
        }

        $cleanRow = [
            'id' => (int) ($row['id'] ?? 0),
            'no_pembelian' => trim((string) ($row['no_pembelian'] ?? '')),
            'tanggal' => trim((string) ($row['tanggal'] ?? '')),
            'departemen' => $departemen,
            'departemen_label' => materialInventoryDepartmentLabel($departemen),
            'supplier_display' => $supplierDisplay,
            'jatuh_tempo' => trim((string) ($row['jatuh_tempo'] ?? '')),
            'grand_total' => (float) ($row['grand_total'] ?? 0),
            'dibayar_total' => (float) ($row['dibayar_total'] ?? 0),
            'sisa_tagihan' => $remaining,
            'status_pembayaran' => trim((string) ($row['status_pembayaran'] ?? 'belum_lunas')),
            'metode_pembayaran' => trim((string) ($row['metode_pembayaran'] ?? 'tempo')),
            'sisa_hari' => (($row['jatuh_tempo'] ?? null) !== null && (string) ($row['jatuh_tempo'] ?? '') !== '')
                ? (int) ($row['sisa_hari'] ?? 0)
                : null,
        ];

        $summary['active']['count']++;
        $summary['active']['total'] += $remaining;
        $summary['departments'][$departemen]['active_count']++;
        $summary['departments'][$departemen]['active_total'] += $remaining;

        if ($cleanRow['jatuh_tempo'] === '' || $cleanRow['sisa_hari'] === null) {
            continue;
        }

        if ((int) $cleanRow['sisa_hari'] < 0) {
            $summary['overdue']['count']++;
            $summary['overdue']['total'] += $remaining;
            $summary['overdue']['rows'][] = $cleanRow;
            $summary['departments'][$departemen]['overdue_count']++;
            $summary['departments'][$departemen]['overdue_total'] += $remaining;
            continue;
        }

        if ((int) $cleanRow['sisa_hari'] <= $dueSoonDays) {
            $summary['due_soon']['count']++;
            $summary['due_soon']['total'] += $remaining;
            $summary['due_soon']['rows'][] = $cleanRow;
            $summary['departments'][$departemen]['due_soon_count']++;
            $summary['departments'][$departemen]['due_soon_total'] += $remaining;
        }
    }

    foreach (['active', 'overdue', 'due_soon'] as $bucket) {
        $summary[$bucket]['total'] = round((float) ($summary[$bucket]['total'] ?? 0), 2);
    }
    foreach (['printing', 'apparel'] as $departemen) {
        foreach (['active_total', 'overdue_total', 'due_soon_total'] as $field) {
            $summary['departments'][$departemen][$field] = round((float) ($summary['departments'][$departemen][$field] ?? 0), 2);
        }
    }

    return $summary;
}

function materialInventoryLoadReminderLoggedPurchaseIds(mysqli $conn, int $userId, string $kind, array $purchaseIds, string $reminderDate): array
{
    if (
        $userId <= 0
        || empty($purchaseIds)
        || !materialInventorySupportReady($conn)
        || !schemaTableExists($conn, 'pembelian_bahan_reminder_log')
    ) {
        return [];
    }

    $kind = $kind === 'overdue' ? 'overdue' : 'due_soon';
    $ids = implode(',', array_values(array_unique(array_map('intval', $purchaseIds))));
    if ($ids === '') {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT pembelian_id
         FROM pembelian_bahan_reminder_log
         WHERE user_id = ?
           AND reminder_kind = ?
           AND reminder_date = ?
           AND pembelian_id IN ({$ids})"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iss', $userId, $kind, $reminderDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $logged = [];
    foreach ($rows as $row) {
        $logged[(int) ($row['pembelian_id'] ?? 0)] = true;
    }

    return $logged;
}

function materialInventoryFilterPendingReminderRows(mysqli $conn, int $userId, string $kind, array $rows, ?string $reminderDate = null): array
{
    if ($userId <= 0 || empty($rows)) {
        return [];
    }

    $reminderDate = $reminderDate ?: date('Y-m-d');
    $purchaseIds = array_values(array_filter(array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $rows)));
    $loggedMap = materialInventoryLoadReminderLoggedPurchaseIds($conn, $userId, $kind, $purchaseIds, $reminderDate);

    return array_values(array_filter($rows, static function (array $row) use ($loggedMap): bool {
        return !isset($loggedMap[(int) ($row['id'] ?? 0)]);
    }));
}

function materialInventoryBuildPayableReminderPushMessage(string $kind, array $rows, int $dueSoonDays = 7): array
{
    $kind = $kind === 'overdue' ? 'overdue' : 'due_soon';
    $count = count($rows);
    $total = array_reduce($rows, static function (float $carry, array $row): float {
        return $carry + (float) ($row['sisa_tagihan'] ?? 0);
    }, 0.0);
    $target = materialInventoryBuildPayableTarget($rows);
    $departmentSummary = materialInventoryBuildDepartmentBreakdown($rows);
    $firstSupplier = trim((string) ($rows[0]['supplier_display'] ?? ''));

    if ($kind === 'overdue') {
        $title = $count === 1 ? '1 hutang supplier melewati tempo' : $count . ' hutang supplier melewati tempo';
        $body = $departmentSummary . '. Total sisa ' . materialInventoryFormatMoney($total) . '.';
        if ($firstSupplier !== '') {
            $body .= ' Prioritas: ' . $firstSupplier . '.';
        }

        return [
            'title' => $title,
            'body' => $body,
            'url' => (string) ($target['url'] ?? pageUrl('pembelian_bahan.php')),
            'kind' => 'supplier_payable',
            'tag' => 'jws-supplier-payable-overdue-' . date('Ymd'),
            'ttl' => 600,
            'urgency' => 'high',
            'forceDisplay' => true,
            'data' => [
                'url' => (string) ($target['url'] ?? pageUrl('pembelian_bahan.php')),
                'kind' => 'supplier_payable',
                'reminder_kind' => 'overdue',
            ],
        ];
    }

    $title = $count === 1
        ? '1 hutang supplier jatuh tempo dekat'
        : $count . ' hutang supplier jatuh tempo <= ' . $dueSoonDays . ' hari';
    $body = $departmentSummary . '. Total sisa ' . materialInventoryFormatMoney($total) . '.';
    if ($firstSupplier !== '') {
        $body .= ' Cek supplier: ' . $firstSupplier . '.';
    }

    return [
        'title' => $title,
        'body' => $body,
        'url' => (string) ($target['url'] ?? pageUrl('pembelian_bahan.php')),
        'kind' => 'supplier_payable',
        'tag' => 'jws-supplier-payable-due-soon-' . date('Ymd'),
        'ttl' => 1800,
        'urgency' => 'normal',
        'forceDisplay' => true,
        'data' => [
            'url' => (string) ($target['url'] ?? pageUrl('pembelian_bahan.php')),
            'kind' => 'supplier_payable',
            'reminder_kind' => 'due_soon',
        ],
    ];
}

function materialInventoryStoreReminderLog(mysqli $conn, int $userId, string $kind, array $rows, array $dispatchResult): void
{
    if (
        $userId <= 0
        || empty($rows)
        || !materialInventorySupportReady($conn)
        || !schemaTableExists($conn, 'pembelian_bahan_reminder_log')
    ) {
        return;
    }

    $kind = $kind === 'overdue' ? 'overdue' : 'due_soon';
    $reminderDate = date('Y-m-d');
    $pushSubscriptions = max(0, (int) ($dispatchResult['subscriptions'] ?? 0));
    $pushSent = max(0, (int) ($dispatchResult['sent'] ?? 0));
    $pushFailed = max(0, (int) ($dispatchResult['failed'] ?? 0));

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO pembelian_bahan_reminder_log (
            pembelian_id,
            user_id,
            departemen,
            reminder_kind,
            reminder_date,
            jatuh_tempo,
            push_subscriptions,
            push_sent,
            push_failed
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return;
    }

    foreach ($rows as $row) {
        $purchaseId = (int) ($row['id'] ?? 0);
        if ($purchaseId <= 0) {
            continue;
        }

        $departemen = materialInventoryNormalizeDepartment((string) ($row['departemen'] ?? 'printing'));
        $jatuhTempo = trim((string) ($row['jatuh_tempo'] ?? ''));
        $jatuhTempoValue = $jatuhTempo !== '' ? $jatuhTempo : null;
        $stmt->bind_param(
            'iissssiii',
            $purchaseId,
            $userId,
            $departemen,
            $kind,
            $reminderDate,
            $jatuhTempoValue,
            $pushSubscriptions,
            $pushSent,
            $pushFailed
        );
        $stmt->execute();
    }

    $stmt->close();
}

function materialInventoryTriggerAutomaticPayableReminders(mysqli $conn, int $userId, string $role, array $summary, int $dueSoonDays = 7): void
{
    if (
        $userId <= 0
        || !in_array($role, ['superadmin', 'admin', 'service'], true)
        || !function_exists('webPushSendToUserIds')
    ) {
        return;
    }

    foreach (['overdue', 'due_soon'] as $kind) {
        $rows = is_array($summary[$kind]['rows'] ?? null) ? $summary[$kind]['rows'] : [];
        $pendingRows = materialInventoryFilterPendingReminderRows($conn, $userId, $kind, $rows);
        if (empty($pendingRows)) {
            continue;
        }

        $dispatchResult = webPushSendToUserIds([$userId], materialInventoryBuildPayableReminderPushMessage($kind, $pendingRows, $dueSoonDays));
        $subscriptions = (int) ($dispatchResult['subscriptions'] ?? 0);
        $sent = (int) ($dispatchResult['sent'] ?? 0);
        $failed = (int) ($dispatchResult['failed'] ?? 0);
        if ($subscriptions > 0 && ($sent > 0 || $failed > 0)) {
            materialInventoryStoreReminderLog($conn, $userId, $kind, $pendingRows, $dispatchResult);
        }
    }
}

function materialInventoryUpsertSupplier(mysqli $conn, array $payload): int
{
    if (!materialInventoryEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel supplier bahan belum siap.');
    }

    $id = (int) ($payload['id'] ?? 0);
    $nama = trim((string) ($payload['nama'] ?? ''));
    $departemen = materialInventoryNormalizeDepartment((string) ($payload['departemen'] ?? 'printing'));
    $telepon = trim((string) ($payload['telepon'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $alamat = trim((string) ($payload['alamat'] ?? ''));
    $catatan = trim((string) ($payload['catatan'] ?? ''));
    $status = trim((string) ($payload['status'] ?? 'aktif')) === 'nonaktif' ? 'nonaktif' : 'aktif';
    $createdBy = (int) ($payload['created_by'] ?? 0);

    if ($nama === '') {
        throw new RuntimeException('Nama supplier wajib diisi.');
    }

    if ($id > 0) {
        $stmt = $conn->prepare(
            "UPDATE material_suppliers
             SET nama = ?, departemen = ?, telepon = ?, email = ?, alamat = ?, catatan = ?, status = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Supplier tidak dapat diperbarui saat ini.');
        }

        $stmt->bind_param('sssssssi', $nama, $departemen, $telepon, $email, $alamat, $catatan, $status, $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Supplier gagal diperbarui.');
        }
        $stmt->close();

        return $id;
    }

    $stmt = $conn->prepare(
        "INSERT INTO material_suppliers (nama, departemen, telepon, email, alamat, catatan, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Supplier tidak dapat ditambahkan saat ini.');
    }

    $stmt->bind_param('sssssssi', $nama, $departemen, $telepon, $email, $alamat, $catatan, $status, $createdBy);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Supplier gagal ditambahkan.');
    }

    $newId = (int) $stmt->insert_id;
    $stmt->close();

    return $newId;
}

function materialInventoryGeneratePurchaseNumber(string $tanggal): string
{
    $stamp = strtotime($tanggal !== '' ? $tanggal : 'now');
    if ($stamp === false) {
        $stamp = time();
    }

    return 'PB-' . date('Ymd-His', $stamp) . '-' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

function materialInventorySanitizePurchaseItems(mysqli $conn, $rawItems, string $departemen): array
{
    $decoded = is_string($rawItems) ? json_decode($rawItems, true) : $rawItems;
    if (!is_array($decoded)) {
        return [];
    }

    $catalogById = materialInventoryLoadCatalogById($conn);
    $cleanItems = [];

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $stokBahanId = (int) ($item['stok_bahan_id'] ?? 0);
        $catalogItem = $catalogById[$stokBahanId] ?? null;
        if (!$catalogItem) {
            continue;
        }
        if (materialInventoryNormalizeDepartment((string) ($catalogItem['kategori'] ?? 'printing')) !== $departemen) {
            continue;
        }

        $qty = max(0, (float) ($item['qty'] ?? 0));
        $hargaBeli = max(0, (float) ($item['harga_beli'] ?? 0));
        if ($qty <= 0) {
            continue;
        }

        $cleanItems[] = [
            'stok_bahan_id' => $stokBahanId,
            'departemen' => $departemen,
            'nama_bahan' => (string) ($catalogItem['nama'] ?? ''),
            'satuan' => (string) ($catalogItem['satuan'] ?? ''),
            'qty' => round($qty, 3),
            'harga_beli' => round($hargaBeli, 2),
            'subtotal' => round($qty * $hargaBeli, 2),
        ];
    }

    return $cleanItems;
}

function materialInventoryBuildUsageSummary(array $rows): array
{
    $summary = [];
    foreach ($rows as $row) {
        $stokBahanId = (int) ($row['stok_bahan_id'] ?? 0);
        $qty = (float) ($row['qty'] ?? 0);
        if ($stokBahanId <= 0 || $qty <= 0) {
            continue;
        }

        if (!isset($summary[$stokBahanId])) {
            $summary[$stokBahanId] = [
                'stok_bahan_id' => $stokBahanId,
                'qty' => 0,
                'unit_cost' => (float) ($row['unit_cost'] ?? 0),
            ];
        }

        $summary[$stokBahanId]['qty'] += $qty;
        if ((float) ($row['unit_cost'] ?? 0) > 0) {
            $summary[$stokBahanId]['unit_cost'] = (float) $row['unit_cost'];
        }
    }

    return $summary;
}

function materialInventoryRecordMutation(mysqli $conn, array $payload): array
{
    if (!materialInventoryEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel inventori bahan belum siap.');
    }

    $stokBahanId = (int) ($payload['stok_bahan_id'] ?? 0);
    $deltaQty = (float) ($payload['delta_qty'] ?? 0);
    if ($stokBahanId <= 0 || abs($deltaQty) <= 0.000001) {
        return ['changed' => false];
    }

    $stmtMaterial = $conn->prepare(
        "SELECT id, kode, nama, kategori, satuan, stok, harga_beli
         FROM stok_bahan
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmtMaterial) {
        throw new RuntimeException('Data bahan tidak dapat dikunci untuk mutasi.');
    }

    $stmtMaterial->bind_param('i', $stokBahanId);
    $stmtMaterial->execute();
    $material = $stmtMaterial->get_result()->fetch_assoc();
    $stmtMaterial->close();

    if (!$material) {
        throw new RuntimeException('Bahan baku tidak ditemukan.');
    }

    $departemen = materialInventoryNormalizeDepartment((string) ($payload['departemen'] ?? ($material['kategori'] ?? 'printing')));
    $materialDept = materialInventoryNormalizeDepartment((string) ($material['kategori'] ?? 'printing'));
    if ($departemen !== $materialDept) {
        throw new RuntimeException('Bahan baku tidak berada di departemen yang sama.');
    }

    $stokSebelum = (float) ($material['stok'] ?? 0);
    $stokSesudah = round($stokSebelum + $deltaQty, 3);
    if ($stokSesudah < -0.000001) {
        throw new RuntimeException('Stok bahan tidak mencukupi untuk mutasi keluar.');
    }
    if ($stokSesudah < 0) {
        $stokSesudah = 0;
    }

    $hargaSatuan = max(0, (float) ($payload['harga_satuan'] ?? ($material['harga_beli'] ?? 0)));
    $updateHargaBeli = !empty($payload['update_harga_beli']) && $hargaSatuan > 0;
    $stmtUpdate = $updateHargaBeli
        ? $conn->prepare("UPDATE stok_bahan SET stok = ?, harga_beli = ? WHERE id = ?")
        : $conn->prepare("UPDATE stok_bahan SET stok = ? WHERE id = ?");
    if (!$stmtUpdate) {
        throw new RuntimeException('Stok bahan tidak dapat diperbarui.');
    }

    if ($updateHargaBeli) {
        $stmtUpdate->bind_param('ddi', $stokSesudah, $hargaSatuan, $stokBahanId);
    } else {
        $stmtUpdate->bind_param('di', $stokSesudah, $stokBahanId);
    }

    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        throw new RuntimeException('Perubahan stok bahan gagal disimpan.');
    }
    $stmtUpdate->close();

    $arah = $deltaQty >= 0 ? 'masuk' : 'keluar';
    $qtyAbs = round(abs($deltaQty), 3);
    $totalNilai = round($qtyAbs * $hargaSatuan, 2);
    $tipe = trim((string) ($payload['tipe'] ?? 'penyesuaian'));
    $referensiTipe = trim((string) ($payload['referensi_tipe'] ?? ''));
    $referensiId = isset($payload['referensi_id']) ? (int) $payload['referensi_id'] : null;
    $keterangan = trim((string) ($payload['keterangan'] ?? ''));
    $createdBy = isset($payload['created_by']) ? (int) $payload['created_by'] : null;

    $stmtInsert = $conn->prepare(
        "INSERT INTO stok_bahan_mutasi (
            stok_bahan_id,
            departemen,
            tipe,
            arah,
            nama_bahan,
            qty,
            stok_sebelum,
            stok_sesudah,
            harga_satuan,
            total_nilai,
            referensi_tipe,
            referensi_id,
            keterangan,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmtInsert) {
        throw new RuntimeException('Riwayat mutasi bahan gagal disiapkan.');
    }

    $namaBahan = (string) ($material['nama'] ?? '');
    $referensiIdValue = $referensiId ?: null;
    $stmtInsert->bind_param(
        'issssdddddsisi',
        $stokBahanId,
        $departemen,
        $tipe,
        $arah,
        $namaBahan,
        $qtyAbs,
        $stokSebelum,
        $stokSesudah,
        $hargaSatuan,
        $totalNilai,
        $referensiTipe,
        $referensiIdValue,
        $keterangan,
        $createdBy
    );

    if (!$stmtInsert->execute()) {
        $stmtInsert->close();
        throw new RuntimeException('Riwayat mutasi bahan gagal disimpan.');
    }
    $mutationId = (int) $stmtInsert->insert_id;
    $stmtInsert->close();

    return [
        'changed' => true,
        'mutation_id' => $mutationId,
        'stok_sebelum' => $stokSebelum,
        'stok_sesudah' => $stokSesudah,
        'arah' => $arah,
    ];
}

function materialInventoryRefreshPurchasePaymentSummary(mysqli $conn, int $purchaseId): array
{
    if ($purchaseId <= 0) {
        throw new RuntimeException('Pembelian bahan tidak valid.');
    }

    $stmtHeader = $conn->prepare(
        "SELECT id, grand_total, metode_pembayaran, jatuh_tempo
         FROM pembelian_bahan
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmtHeader) {
        throw new RuntimeException('Data pembelian tidak dapat dibaca.');
    }

    $stmtHeader->bind_param('i', $purchaseId);
    $stmtHeader->execute();
    $header = $stmtHeader->get_result()->fetch_assoc();
    $stmtHeader->close();

    if (!$header) {
        throw new RuntimeException('Pembelian bahan tidak ditemukan.');
    }

    $stmtPaid = $conn->prepare(
        "SELECT COALESCE(SUM(nominal), 0) AS total_bayar
         FROM pembelian_bahan_pembayaran
         WHERE pembelian_id = ?"
    );
    if (!$stmtPaid) {
        throw new RuntimeException('Ringkasan pembayaran tidak dapat dibaca.');
    }

    $stmtPaid->bind_param('i', $purchaseId);
    $stmtPaid->execute();
    $paymentRow = $stmtPaid->get_result()->fetch_assoc();
    $stmtPaid->close();

    $grandTotal = max(0, (float) ($header['grand_total'] ?? 0));
    $paidTotal = max(0, (float) ($paymentRow['total_bayar'] ?? 0));
    if ($paidTotal > $grandTotal) {
        $paidTotal = $grandTotal;
    }

    $remaining = max(0, round($grandTotal - $paidTotal, 2));
    $metode = trim((string) ($header['metode_pembayaran'] ?? 'tunai'));
    $status = 'belum_lunas';
    if ($remaining <= 0.000001) {
        $status = 'lunas';
        $remaining = 0;
    } elseif ($paidTotal > 0) {
        $status = 'parsial';
    } elseif ($metode === 'tunai') {
        $status = 'belum_lunas';
    }

    $stmtUpdate = $conn->prepare(
        "UPDATE pembelian_bahan
         SET dibayar_total = ?, sisa_tagihan = ?, status_pembayaran = ?
         WHERE id = ?"
    );
    if (!$stmtUpdate) {
        throw new RuntimeException('Ringkasan hutang pembelian tidak dapat diperbarui.');
    }

    $stmtUpdate->bind_param('ddsi', $paidTotal, $remaining, $status, $purchaseId);
    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        throw new RuntimeException('Ringkasan hutang pembelian gagal diperbarui.');
    }
    $stmtUpdate->close();

    return [
        'dibayar_total' => $paidTotal,
        'sisa_tagihan' => $remaining,
        'status_pembayaran' => $status,
    ];
}

function materialInventoryRegisterPurchasePayment(mysqli $conn, array $payload): int
{
    if (!materialInventoryEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel pembayaran pembelian belum siap.');
    }

    $purchaseId = (int) ($payload['pembelian_id'] ?? 0);
    $tanggal = trim((string) ($payload['tanggal'] ?? date('Y-m-d')));
    $nominal = max(0, (float) ($payload['nominal'] ?? 0));
    $metode = trim((string) ($payload['metode'] ?? 'transfer'));
    $referensi = trim((string) ($payload['referensi'] ?? ''));
    $catatan = trim((string) ($payload['catatan'] ?? ''));
    $createdBy = (int) ($payload['created_by'] ?? 0);

    if ($purchaseId <= 0 || $nominal <= 0) {
        throw new RuntimeException('Pembelian dan nominal pembayaran wajib diisi.');
    }

    $stmtHeader = $conn->prepare(
        "SELECT grand_total, dibayar_total, sisa_tagihan
         FROM pembelian_bahan
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmtHeader) {
        throw new RuntimeException('Data hutang pembelian tidak dapat dibaca.');
    }
    $stmtHeader->bind_param('i', $purchaseId);
    $stmtHeader->execute();
    $header = $stmtHeader->get_result()->fetch_assoc();
    $stmtHeader->close();

    if (!$header) {
        throw new RuntimeException('Pembelian bahan tidak ditemukan.');
    }

    $remaining = max(0, (float) ($header['sisa_tagihan'] ?? ((float) ($header['grand_total'] ?? 0) - (float) ($header['dibayar_total'] ?? 0))));
    if ($nominal - $remaining > 0.000001) {
        throw new RuntimeException('Nominal pembayaran melebihi sisa tagihan supplier.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO pembelian_bahan_pembayaran (
            pembelian_id,
            tanggal,
            nominal,
            metode,
            referensi,
            catatan,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Pembayaran supplier tidak dapat disimpan saat ini.');
    }

    $stmt->bind_param('isdsssi', $purchaseId, $tanggal, $nominal, $metode, $referensi, $catatan, $createdBy);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Pembayaran supplier gagal disimpan.');
    }

    $paymentId = (int) $stmt->insert_id;
    $stmt->close();
    materialInventoryRefreshPurchasePaymentSummary($conn, $purchaseId);

    return $paymentId;
}

function materialInventoryCreatePurchase(mysqli $conn, array $header, array $items): int
{
    if (!materialInventoryEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel inventori bahan belum siap.');
    }
    if (empty($items)) {
        throw new RuntimeException('Item pembelian bahan belum diisi.');
    }

    $tanggal = trim((string) ($header['tanggal'] ?? date('Y-m-d')));
    if ($tanggal === '') {
        $tanggal = date('Y-m-d');
    }
    $departemen = materialInventoryNormalizeDepartment((string) ($header['departemen'] ?? 'printing'));
    $supplierId = (int) ($header['supplier_id'] ?? 0);
    $supplierNama = trim((string) ($header['supplier_nama'] ?? ''));
    $metodePembayaran = trim((string) ($header['metode_pembayaran'] ?? 'tunai')) === 'tempo' ? 'tempo' : 'tunai';
    $jatuhTempo = trim((string) ($header['jatuh_tempo'] ?? ''));
    $referensiNota = trim((string) ($header['referensi_nota'] ?? ''));
    $ongkir = max(0, (float) ($header['ongkir'] ?? 0));
    $diskon = max(0, (float) ($header['diskon'] ?? 0));
    $initialPayment = max(0, (float) ($header['initial_payment'] ?? 0));
    $catatan = trim((string) ($header['catatan'] ?? ''));
    $createdBy = (int) ($header['created_by'] ?? 0);
    $subtotal = array_reduce($items, static function (float $carry, array $item): float {
        return $carry + (float) ($item['subtotal'] ?? 0);
    }, 0.0);
    $grandTotal = max(0, $subtotal + $ongkir - $diskon);
    if ($metodePembayaran === 'tunai') {
        $initialPayment = $grandTotal;
        $jatuhTempo = '';
    } else {
        if ($jatuhTempo === '') {
            throw new RuntimeException('Jatuh tempo wajib diisi untuk pembelian tempo.');
        }
        if ($initialPayment > $grandTotal) {
            $initialPayment = $grandTotal;
        }
    }
    $noPembelian = materialInventoryGeneratePurchaseNumber($tanggal);

    if ($supplierId > 0) {
        $stmtSupplier = $conn->prepare(
            "SELECT id, nama, departemen, status
             FROM material_suppliers
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmtSupplier) {
            throw new RuntimeException('Data supplier tidak dapat dibaca.');
        }

        $stmtSupplier->bind_param('i', $supplierId);
        $stmtSupplier->execute();
        $supplier = $stmtSupplier->get_result()->fetch_assoc();
        $stmtSupplier->close();

        if (!$supplier) {
            throw new RuntimeException('Supplier bahan tidak ditemukan.');
        }
        if (($supplier['status'] ?? 'aktif') !== 'aktif') {
            throw new RuntimeException('Supplier bahan sedang nonaktif.');
        }
        if (materialInventoryNormalizeDepartment((string) ($supplier['departemen'] ?? 'printing')) !== $departemen) {
            throw new RuntimeException('Supplier bahan tidak berada pada departemen yang sama.');
        }
        $supplierNama = trim((string) ($supplier['nama'] ?? $supplierNama));
    }
    if ($metodePembayaran === 'tempo' && $supplierNama === '') {
        throw new RuntimeException('Supplier wajib dipilih atau diisi untuk pembelian tempo.');
    }
    $jatuhTempoValue = $jatuhTempo !== '' ? $jatuhTempo : null;

    $stmtHeader = $conn->prepare(
        "INSERT INTO pembelian_bahan (
            no_pembelian,
            tanggal,
            departemen,
            supplier_id,
            supplier_nama,
            metode_pembayaran,
            jatuh_tempo,
            referensi_nota,
            subtotal,
            ongkir,
            diskon,
            grand_total,
            dibayar_total,
            sisa_tagihan,
            status_pembayaran,
            catatan,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmtHeader) {
        throw new RuntimeException('Header pembelian bahan tidak dapat disimpan.');
    }

    $stmtItem = $conn->prepare(
        "INSERT INTO pembelian_bahan_item (
            pembelian_id,
            stok_bahan_id,
            departemen,
            nama_bahan,
            satuan,
            qty,
            harga_beli,
            subtotal
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmtItem) {
        $stmtHeader->close();
        throw new RuntimeException('Item pembelian bahan tidak dapat disiapkan.');
    }

    $conn->begin_transaction();
    try {
        $initialPaid = 0.0;
        $initialRemaining = $grandTotal;
        $initialStatus = $grandTotal <= 0.000001 ? 'lunas' : 'belum_lunas';
        $stmtHeader->bind_param(
            'sssissssddddddssi',
            $noPembelian,
            $tanggal,
            $departemen,
            $supplierId,
            $supplierNama,
            $metodePembayaran,
            $jatuhTempoValue,
            $referensiNota,
            $subtotal,
            $ongkir,
            $diskon,
            $grandTotal,
            $initialPaid,
            $initialRemaining,
            $initialStatus,
            $catatan,
            $createdBy
        );
        if (!$stmtHeader->execute()) {
            throw new RuntimeException('Header pembelian bahan gagal disimpan.');
        }

        $purchaseId = (int) $stmtHeader->insert_id;

        foreach ($items as $item) {
            $stokBahanId = (int) ($item['stok_bahan_id'] ?? 0);
            $itemDept = materialInventoryNormalizeDepartment((string) ($item['departemen'] ?? $departemen));
            $namaBahan = (string) ($item['nama_bahan'] ?? '');
            $satuan = (string) ($item['satuan'] ?? '');
            $qty = (float) ($item['qty'] ?? 0);
            $hargaBeli = (float) ($item['harga_beli'] ?? 0);
            $subtotalItem = (float) ($item['subtotal'] ?? 0);

            $stmtItem->bind_param(
                'iisssddd',
                $purchaseId,
                $stokBahanId,
                $itemDept,
                $namaBahan,
                $satuan,
                $qty,
                $hargaBeli,
                $subtotalItem
            );
            if (!$stmtItem->execute()) {
                throw new RuntimeException('Item pembelian bahan gagal disimpan.');
            }

            materialInventoryRecordMutation($conn, [
                'stok_bahan_id' => $stokBahanId,
                'departemen' => $departemen,
                'delta_qty' => $qty,
                'harga_satuan' => $hargaBeli,
                'update_harga_beli' => true,
                'tipe' => 'pembelian',
                'referensi_tipe' => 'pembelian_bahan',
                'referensi_id' => $purchaseId,
                'keterangan' => trim('Pembelian ' . $noPembelian . ($supplierNama !== '' ? ' - ' . $supplierNama : '')),
                'created_by' => $createdBy,
            ]);
        }

        if ($initialPayment > 0) {
            materialInventoryRegisterPurchasePayment($conn, [
                'pembelian_id' => $purchaseId,
                'tanggal' => $tanggal,
                'nominal' => $initialPayment,
                'metode' => $metodePembayaran === 'tunai' ? 'tunai' : 'dp',
                'referensi' => $referensiNota,
                'catatan' => $metodePembayaran === 'tunai' ? 'Pelunasan langsung saat pembelian.' : 'Pembayaran awal pembelian tempo.',
                'created_by' => $createdBy,
            ]);
        } else {
            materialInventoryRefreshPurchasePaymentSummary($conn, $purchaseId);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $stmtHeader->close();
        $stmtItem->close();
        throw $e;
    }

    $stmtHeader->close();
    $stmtItem->close();

    return $purchaseId;
}

function materialInventoryApplyAdjustment(mysqli $conn, array $payload): void
{
    if (!materialInventoryEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel inventori bahan belum siap.');
    }

    $stokBahanId = (int) ($payload['stok_bahan_id'] ?? 0);
    $departemen = materialInventoryNormalizeDepartment((string) ($payload['departemen'] ?? 'printing'));
    $arah = strtolower(trim((string) ($payload['arah'] ?? 'masuk')));
    $qty = max(0, (float) ($payload['qty'] ?? 0));
    $hargaSatuan = max(0, (float) ($payload['harga_satuan'] ?? 0));
    $keterangan = trim((string) ($payload['keterangan'] ?? ''));
    $createdBy = (int) ($payload['created_by'] ?? 0);

    if ($stokBahanId <= 0 || $qty <= 0) {
        throw new RuntimeException('Bahan baku dan qty penyesuaian wajib diisi.');
    }

    $deltaQty = $arah === 'keluar' ? -$qty : $qty;

    $conn->begin_transaction();
    try {
        materialInventoryRecordMutation($conn, [
            'stok_bahan_id' => $stokBahanId,
            'departemen' => $departemen,
            'delta_qty' => $deltaQty,
            'harga_satuan' => $hargaSatuan,
            'update_harga_beli' => $deltaQty > 0 && $hargaSatuan > 0,
            'tipe' => 'penyesuaian',
            'referensi_tipe' => 'penyesuaian_manual',
            'referensi_id' => null,
            'keterangan' => $keterangan !== '' ? $keterangan : 'Penyesuaian stok manual',
            'created_by' => $createdBy,
        ]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function materialInventorySyncJobUsageStock(mysqli $conn, int $detailId, string $departemen, array $beforeRows, array $afterRows, int $userId): void
{
    if (!materialInventoryEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel inventori bahan belum siap.');
    }

    $beforeSummary = materialInventoryBuildUsageSummary($beforeRows);
    $afterSummary = materialInventoryBuildUsageSummary($afterRows);
    $materialIds = array_values(array_unique(array_merge(array_keys($beforeSummary), array_keys($afterSummary))));
    if (empty($materialIds)) {
        return;
    }

    foreach ($materialIds as $materialId) {
        $beforeQty = (float) ($beforeSummary[$materialId]['qty'] ?? 0);
        $afterQty = (float) ($afterSummary[$materialId]['qty'] ?? 0);
        $deltaUsage = round($afterQty - $beforeQty, 3);
        if (abs($deltaUsage) <= 0.000001) {
            continue;
        }

        $unitCost = (float) ($afterSummary[$materialId]['unit_cost'] ?? $beforeSummary[$materialId]['unit_cost'] ?? 0);
        materialInventoryRecordMutation($conn, [
            'stok_bahan_id' => (int) $materialId,
            'departemen' => $departemen,
            'delta_qty' => -$deltaUsage,
            'harga_satuan' => $unitCost,
            'update_harga_beli' => false,
            'tipe' => 'pemakaian_job',
            'referensi_tipe' => 'detail_transaksi',
            'referensi_id' => $detailId,
            'keterangan' => $deltaUsage > 0
                ? 'Pemakaian bahan untuk job #' . $detailId
                : 'Revisi pemakaian bahan untuk job #' . $detailId,
            'created_by' => $userId,
        ]);
    }
}
