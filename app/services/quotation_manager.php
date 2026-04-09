<?php

function quotationSupportReady(mysqli $conn): bool
{
    if (!schemaTableExists($conn, 'penawaran') || !schemaTableExists($conn, 'penawaran_item')) {
        return false;
    }

    $requiredHeaderColumns = [
        'no_penawaran',
        'tanggal',
        'berlaku_sampai',
        'status',
        'subtotal',
        'diskon',
        'pajak',
        'total',
        'converted_transaksi_id',
        'converted_by',
        'converted_at',
    ];
    foreach ($requiredHeaderColumns as $column) {
        if (!schemaColumnExists($conn, 'penawaran', $column)) {
            return false;
        }
    }

    $requiredItemColumns = [
        'produk_id',
        'nama_item',
        'kategori_tipe',
        'satuan',
        'qty',
        'lebar',
        'tinggi',
        'harga',
        'finishing_nama',
        'finishing_biaya',
        'subtotal',
        'catatan',
        'sort_order',
    ];
    foreach ($requiredItemColumns as $column) {
        if (!schemaColumnExists($conn, 'penawaran_item', $column)) {
            return false;
        }
    }

    return true;
}

function quotationEnsureSupportTables(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (quotationSupportReady($conn)) {
        return $ready = true;
    }

    if (!appSchemaAutoMigrateEnabled()) {
        return $ready = false;
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS penawaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            no_penawaran VARCHAR(50) NOT NULL UNIQUE,
            pelanggan_id INT NULL,
            user_id INT NULL,
            tanggal DATE NOT NULL,
            berlaku_sampai DATE NULL,
            status ENUM('draft','dikirim','disetujui','ditolak','dikonversi') NOT NULL DEFAULT 'draft',
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            diskon DECIMAL(15,2) NOT NULL DEFAULT 0,
            pajak DECIMAL(15,2) NOT NULL DEFAULT 0,
            total DECIMAL(15,2) NOT NULL DEFAULT 0,
            catatan TEXT NULL,
            converted_transaksi_id INT NULL,
            converted_by INT NULL,
            converted_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_penawaran_tanggal (tanggal),
            KEY idx_penawaran_status (status),
            KEY idx_penawaran_pelanggan (pelanggan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS penawaran_item (
            id INT AUTO_INCREMENT PRIMARY KEY,
            penawaran_id INT NOT NULL,
            produk_id INT NULL,
            nama_item VARCHAR(150) NOT NULL,
            kategori_tipe VARCHAR(20) NOT NULL DEFAULT 'lainnya',
            satuan VARCHAR(30) NULL,
            qty DECIMAL(10,4) NOT NULL DEFAULT 1,
            lebar DECIMAL(10,2) NOT NULL DEFAULT 0,
            tinggi DECIMAL(10,2) NOT NULL DEFAULT 0,
            harga DECIMAL(15,2) NOT NULL DEFAULT 0,
            finishing_nama VARCHAR(100) NULL,
            finishing_biaya DECIMAL(15,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            catatan TEXT NULL,
            sort_order INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_penawaran_item_header (penawaran_id),
            KEY idx_penawaran_item_produk (produk_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            return $ready = false;
        }
    }

    $headerColumnQueries = [];
    if (!schemaColumnExists($conn, 'penawaran', 'berlaku_sampai')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN berlaku_sampai DATE NULL AFTER tanggal";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'status')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN status ENUM('draft','dikirim','disetujui','ditolak','dikonversi') NOT NULL DEFAULT 'draft' AFTER berlaku_sampai";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'subtotal')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN subtotal DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER status";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'diskon')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN diskon DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER subtotal";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'pajak')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN pajak DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER diskon";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'total')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER pajak";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'catatan')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN catatan TEXT NULL AFTER total";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'converted_transaksi_id')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN converted_transaksi_id INT NULL AFTER catatan";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'converted_by')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN converted_by INT NULL AFTER converted_transaksi_id";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'converted_at')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN converted_at DATETIME NULL AFTER converted_by";
    }
    if (!schemaColumnExists($conn, 'penawaran', 'updated_at')) {
        $headerColumnQueries[] = "ALTER TABLE penawaran ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at";
    }

    foreach ($headerColumnQueries as $sql) {
        if (!$conn->query($sql)) {
            return $ready = false;
        }
    }

    $itemColumnQueries = [];
    if (!schemaColumnExists($conn, 'penawaran_item', 'produk_id')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN produk_id INT NULL AFTER penawaran_id";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'nama_item')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN nama_item VARCHAR(150) NOT NULL AFTER produk_id";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'kategori_tipe')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN kategori_tipe VARCHAR(20) NOT NULL DEFAULT 'lainnya' AFTER nama_item";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'satuan')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN satuan VARCHAR(30) NULL AFTER kategori_tipe";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'qty')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN qty DECIMAL(10,4) NOT NULL DEFAULT 1 AFTER satuan";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'lebar')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN lebar DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER qty";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'tinggi')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN tinggi DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER lebar";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'harga')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN harga DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER tinggi";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'finishing_nama')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN finishing_nama VARCHAR(100) NULL AFTER harga";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'finishing_biaya')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN finishing_biaya DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER finishing_nama";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'subtotal')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN subtotal DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER finishing_biaya";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'catatan')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN catatan TEXT NULL AFTER subtotal";
    }
    if (!schemaColumnExists($conn, 'penawaran_item', 'sort_order')) {
        $itemColumnQueries[] = "ALTER TABLE penawaran_item ADD COLUMN sort_order INT NOT NULL DEFAULT 1 AFTER catatan";
    }

    foreach ($itemColumnQueries as $sql) {
        if (!$conn->query($sql)) {
            return $ready = false;
        }
    }

    return $ready = quotationSupportReady($conn);
}

function quotationAllowedStatuses(): array
{
    return ['draft', 'dikirim', 'disetujui', 'ditolak', 'dikonversi'];
}

function quotationStatusLabel(string $status): string
{
    return [
        'draft' => 'Draft',
        'dikirim' => 'Dikirim',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak',
        'dikonversi' => 'Dikonversi',
    ][strtolower(trim($status))] ?? ucfirst($status);
}

function quotationStatusBadgeClass(string $status): string
{
    return [
        'draft' => 'badge-secondary',
        'dikirim' => 'badge-info',
        'disetujui' => 'badge-success',
        'ditolak' => 'badge-danger',
        'dikonversi' => 'badge-primary',
    ][strtolower(trim($status))] ?? 'badge-secondary';
}

function quotationCanBeModified(array $quote): bool
{
    return strtolower(trim((string) ($quote['status'] ?? 'draft'))) !== 'dikonversi';
}

function quotationCanBeConverted(array $quote): bool
{
    $status = strtolower(trim((string) ($quote['status'] ?? 'draft')));
    return !in_array($status, ['ditolak', 'dikonversi'], true);
}

function quotationGenerateNumber(mysqli $conn, ?string $date = null): string
{
    $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    $prefix = 'QT-' . date('Ymd', strtotime($date));

    if (!quotationEnsureSupportTables($conn)) {
        return $prefix . '-' . strtoupper(substr(uniqid(), -4));
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM penawaran WHERE tanggal = ?");
    if (!$stmt) {
        return $prefix . '-' . strtoupper(substr(uniqid(), -4));
    }

    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sequence = ((int) ($row['total'] ?? 0)) + 1;

    return $prefix . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}

function quotationNormalizeCategory(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['printing', 'apparel', 'lainnya'], true) ? $value : 'lainnya';
}

function quotationResolveProductId(mysqli $conn, int $productId): ?int
{
    if ($productId <= 0 || !schemaTableExists($conn, 'produk')) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id FROM produk WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row['id']) ? (int) $row['id'] : null;
}

function quotationNormalizeItems(mysqli $conn, array $payload): array
{
    $names = $payload['item_name'] ?? [];
    $count = is_array($names) ? count($names) : 0;
    $items = [];

    for ($index = 0; $index < $count; $index++) {
        $productId = (int) (($payload['product_id'][$index] ?? 0));
        $name = trim((string) ($payload['item_name'][$index] ?? ''));
        $category = quotationNormalizeCategory((string) ($payload['kategori_tipe'][$index] ?? 'lainnya'));
        $unit = trim((string) ($payload['satuan'][$index] ?? 'pcs'));
        $qty = max(0, (float) ($payload['qty'][$index] ?? 0));
        $width = max(0, (float) ($payload['lebar'][$index] ?? 0));
        $height = max(0, (float) ($payload['tinggi'][$index] ?? 0));
        $price = max(0, (float) ($payload['harga'][$index] ?? 0));
        $finishName = trim((string) ($payload['finishing_nama'][$index] ?? ''));
        $finishCost = max(0, (float) ($payload['finishing_biaya'][$index] ?? 0));
        $note = trim((string) ($payload['item_catatan'][$index] ?? ''));

        if ($name === '' && $qty <= 0 && $price <= 0 && $finishName === '' && $note === '') {
            continue;
        }

        if ($name === '') {
            throw new RuntimeException('Nama item pada penawaran wajib diisi.');
        }
        if ($qty <= 0) {
            throw new RuntimeException('Qty item penawaran harus lebih besar dari nol.');
        }

        $resolvedProductId = quotationResolveProductId($conn, $productId);
        $subtotal = round(($qty * $price) + $finishCost, 2);

        $items[] = [
            'produk_id' => $resolvedProductId,
            'nama_item' => $name,
            'kategori_tipe' => $category,
            'satuan' => $unit !== '' ? $unit : 'pcs',
            'qty' => $qty,
            'lebar' => $width,
            'tinggi' => $height,
            'harga' => $price,
            'finishing_nama' => $finishName,
            'finishing_biaya' => $finishCost,
            'subtotal' => $subtotal,
            'catatan' => $note,
            'sort_order' => count($items) + 1,
        ];
    }

    if (empty($items)) {
        throw new RuntimeException('Minimal satu item penawaran wajib diisi.');
    }

    return $items;
}

function quotationLoadHeader(mysqli $conn, int $quotationId, bool $forUpdate = false): ?array
{
    if ($quotationId <= 0 || !quotationEnsureSupportTables($conn)) {
        return null;
    }

    $sql = "SELECT q.*, p.nama AS nama_pelanggan, u.nama AS nama_pembuat, t.no_transaksi AS no_transaksi_konversi
        FROM penawaran q
        LEFT JOIN pelanggan p ON p.id = q.pelanggan_id
        LEFT JOIN users u ON u.id = q.user_id
        LEFT JOIN transaksi t ON t.id = q.converted_transaksi_id
        WHERE q.id = ?
        LIMIT 1";
    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $quotationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function quotationLoadItems(mysqli $conn, int $quotationId): array
{
    if ($quotationId <= 0 || !quotationEnsureSupportTables($conn)) {
        return [];
    }

    $stmt = $conn->prepare("SELECT * FROM penawaran_item WHERE penawaran_id = ? ORDER BY sort_order ASC, id ASC");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $quotationId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function quotationSave(mysqli $conn, array $payload): array
{
    if (!quotationEnsureSupportTables($conn)) {
        throw new RuntimeException('Fitur penawaran belum siap di database.');
    }

    $quotationId = (int) ($payload['id'] ?? 0);
    $customerId = (int) ($payload['pelanggan_id'] ?? 0);
    $customerId = $customerId > 0 ? $customerId : null;
    $userId = (int) ($payload['user_id'] ?? 0);
    $date = trim((string) ($payload['tanggal'] ?? date('Y-m-d')));
    $validUntil = trim((string) ($payload['berlaku_sampai'] ?? date('Y-m-d', strtotime('+7 days'))));
    $discount = max(0, (float) ($payload['diskon'] ?? 0));
    $tax = max(0, (float) ($payload['pajak'] ?? 0));
    $note = trim((string) ($payload['catatan'] ?? ''));
    $items = quotationNormalizeItems($conn, $payload);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
        $validUntil = date('Y-m-d', strtotime($date . ' +7 days'));
    }

    $subtotal = round(array_sum(array_map(static function (array $item): float {
        return (float) ($item['subtotal'] ?? 0);
    }, $items)), 2);
    $total = round(max(0, $subtotal - $discount + $tax), 2);
    if ($total <= 0) {
        throw new RuntimeException('Total penawaran harus lebih besar dari nol.');
    }

    $isUpdate = false;
    $quoteNumber = '';
    if ($quotationId > 0) {
        $existing = quotationLoadHeader($conn, $quotationId, true);
        if (!$existing) {
            throw new RuntimeException('Penawaran tidak ditemukan.');
        }
        if (!quotationCanBeModified($existing)) {
            throw new RuntimeException('Penawaran yang sudah dikonversi tidak dapat diubah.');
        }

        $customerIdValue = $customerId ?? 0;
        $stmt = $conn->prepare(
            "UPDATE penawaran
             SET pelanggan_id = NULLIF(?, 0), tanggal = ?, berlaku_sampai = ?, subtotal = ?, diskon = ?, pajak = ?, total = ?, catatan = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Penawaran tidak dapat diperbarui saat ini.');
        }

        $stmt->bind_param('issddddsi', $customerIdValue, $date, $validUntil, $subtotal, $discount, $tax, $total, $note, $quotationId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Penawaran gagal diperbarui.');
        }
        $stmt->close();

        $stmtDelete = $conn->prepare("DELETE FROM penawaran_item WHERE penawaran_id = ?");
        if (!$stmtDelete) {
            throw new RuntimeException('Item penawaran tidak dapat dibersihkan.');
        }
        $stmtDelete->bind_param('i', $quotationId);
        $stmtDelete->execute();
        $stmtDelete->close();

        $quoteNumber = (string) ($existing['no_penawaran'] ?? '');
        $isUpdate = true;
    } else {
        $quoteNumber = quotationGenerateNumber($conn, $date);
        $customerIdValue = $customerId ?? 0;
        $stmt = $conn->prepare(
            "INSERT INTO penawaran (
                no_penawaran, pelanggan_id, user_id, tanggal, berlaku_sampai, status, subtotal, diskon, pajak, total, catatan
            ) VALUES (?, NULLIF(?, 0), ?, ?, ?, 'draft', ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Penawaran tidak dapat dibuat saat ini.');
        }

        $stmt->bind_param('siissdddds', $quoteNumber, $customerIdValue, $userId, $date, $validUntil, $subtotal, $discount, $tax, $total, $note);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Penawaran gagal disimpan.');
        }
        $quotationId = (int) $conn->insert_id;
        $stmt->close();
    }

    $stmtItem = $conn->prepare(
        "INSERT INTO penawaran_item (
            penawaran_id, produk_id, nama_item, kategori_tipe, satuan, qty, lebar, tinggi, harga, finishing_nama, finishing_biaya, subtotal, catatan, sort_order
        ) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmtItem) {
        throw new RuntimeException('Item penawaran tidak dapat disimpan saat ini.');
    }

    foreach ($items as $item) {
        $productId = (int) ($item['produk_id'] ?? 0);
        $stmtItem->bind_param(
            'iisssddddsddsi',
            $quotationId,
            $productId,
            $item['nama_item'],
            $item['kategori_tipe'],
            $item['satuan'],
            $item['qty'],
            $item['lebar'],
            $item['tinggi'],
            $item['harga'],
            $item['finishing_nama'],
            $item['finishing_biaya'],
            $item['subtotal'],
            $item['catatan'],
            $item['sort_order']
        );
        if (!$stmtItem->execute()) {
            $stmtItem->close();
            throw new RuntimeException('Salah satu item penawaran gagal disimpan.');
        }
    }
    $stmtItem->close();

    return [
        'id' => $quotationId,
        'no_penawaran' => $quoteNumber,
        'subtotal' => $subtotal,
        'total' => $total,
        'item_count' => count($items),
        'is_update' => $isUpdate,
    ];
}

function quotationUpdateStatus(mysqli $conn, int $quotationId, string $status): array
{
    if (!quotationEnsureSupportTables($conn)) {
        throw new RuntimeException('Fitur penawaran belum siap di database.');
    }

    $status = strtolower(trim($status));
    if (!in_array($status, ['draft', 'dikirim', 'disetujui', 'ditolak'], true)) {
        throw new RuntimeException('Status penawaran tidak valid.');
    }

    $quote = quotationLoadHeader($conn, $quotationId, true);
    if (!$quote) {
        throw new RuntimeException('Penawaran tidak ditemukan.');
    }
    if (!quotationCanBeModified($quote)) {
        throw new RuntimeException('Penawaran yang sudah dikonversi tidak dapat diubah statusnya.');
    }

    $stmt = $conn->prepare("UPDATE penawaran SET status = ? WHERE id = ?");
    if (!$stmt) {
        throw new RuntimeException('Status penawaran tidak dapat diperbarui saat ini.');
    }

    $stmt->bind_param('si', $status, $quotationId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Status penawaran gagal diperbarui.');
    }
    $stmt->close();

    $quote['status'] = $status;

    return $quote;
}

function quotationDelete(mysqli $conn, int $quotationId): array
{
    if (!quotationEnsureSupportTables($conn)) {
        throw new RuntimeException('Fitur penawaran belum siap di database.');
    }

    $quote = quotationLoadHeader($conn, $quotationId, true);
    if (!$quote) {
        throw new RuntimeException('Penawaran tidak ditemukan.');
    }
    if (!quotationCanBeModified($quote)) {
        throw new RuntimeException('Penawaran yang sudah dikonversi tidak dapat dihapus.');
    }

    $stmtItem = $conn->prepare("DELETE FROM penawaran_item WHERE penawaran_id = ?");
    if (!$stmtItem) {
        throw new RuntimeException('Item penawaran tidak dapat dihapus.');
    }
    $stmtItem->bind_param('i', $quotationId);
    $stmtItem->execute();
    $stmtItem->close();

    $stmt = $conn->prepare("DELETE FROM penawaran WHERE id = ?");
    if (!$stmt) {
        throw new RuntimeException('Penawaran tidak dapat dihapus saat ini.');
    }

    $stmt->bind_param('i', $quotationId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Penawaran gagal dihapus.');
    }
    $stmt->close();

    return $quote;
}

function quotationInsertProductionJob(mysqli $conn, int $transaksiId, int $detailId, array $item, int $userId): void
{
    if (!schemaTableExists($conn, 'produksi')) {
        return;
    }

    $category = quotationNormalizeCategory((string) ($item['kategori_tipe'] ?? 'lainnya'));
    if (!in_array($category, ['printing', 'apparel'], true)) {
        return;
    }

    $produksiCols = schemaTableColumns($conn, 'produksi');
    $hasNoDok = in_array('no_dokumen', $produksiCols, true);
    $hasTipeDok = in_array('tipe_dokumen', $produksiCols, true);
    $hasTransaksiId = in_array('transaksi_id', $produksiCols, true);
    $hasDetailId = in_array('detail_transaksi_id', $produksiCols, true);
    if (!$hasNoDok || !$hasTipeDok) {
        return;
    }

    $documentType = $category === 'apparel' ? 'SPK' : 'JO';
    $number = $documentType . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    $name = (string) ($item['nama_item'] ?? 'Pekerjaan');
    $qty = (float) ($item['qty'] ?? 0);
    $unit = (string) ($item['satuan'] ?? 'pcs');
    $width = (float) ($item['lebar'] ?? 0);
    $height = (float) ($item['tinggi'] ?? 0);
    $finishName = trim((string) ($item['finishing_nama'] ?? ''));
    $jobName = $name;
    if ($width > 0 && $height > 0) {
        $jobName .= " ({$width}x{$height})";
    } else {
        $jobName .= " (qty: {$qty} {$unit})";
    }
    if ($finishName !== '') {
        $jobName .= ' + ' . $finishName;
    }

    $fields = ['no_dokumen', 'tipe_dokumen', 'nama_pekerjaan', 'tanggal', 'status', 'user_id'];
    $types = 'sssssi';
    $values = [$number, $documentType, $jobName, date('Y-m-d'), 'antrian', $userId];

    if ($hasTransaksiId) {
        $fields[] = 'transaksi_id';
        $values[] = $transaksiId;
        $types .= 'i';
    }
    if ($hasDetailId) {
        $fields[] = 'detail_transaksi_id';
        $values[] = $detailId;
        $types .= 'i';
    }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $stmt = $conn->prepare("INSERT INTO produksi (" . implode(',', $fields) . ") VALUES ({$placeholders})");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $stmt->close();
        return;
    }

    $produksiId = (int) $conn->insert_id;
    $stmt->close();

    if (!schemaTableExists($conn, 'todo_list_tahapan')) {
        return;
    }

    $steps = $category === 'apparel'
        ? ['Setting/Design', 'Jahit', 'Finishing & QC']
        : ['Setting File', 'Cetak', 'Finishing & QC'];

    $stmtTodo = $conn->prepare(
        "INSERT INTO todo_list_tahapan (produksi_id, nama_tahapan, urutan, status) VALUES (?, ?, ?, 'belum')"
    );
    if (!$stmtTodo) {
        return;
    }

    foreach ($steps as $index => $stepName) {
        $order = $index + 1;
        $stmtTodo->bind_param('isi', $produksiId, $stepName, $order);
        $stmtTodo->execute();
    }
    $stmtTodo->close();
}

function quotationConvertToTransaction(mysqli $conn, int $quotationId, int $userId): array
{
    if (!quotationEnsureSupportTables($conn)) {
        throw new RuntimeException('Fitur penawaran belum siap di database.');
    }
    if (!transactionPaymentEnsureSupportTables($conn)) {
        throw new RuntimeException('Fitur transaksi belum siap di database.');
    }

    $quote = quotationLoadHeader($conn, $quotationId, true);
    if (!$quote) {
        throw new RuntimeException('Penawaran tidak ditemukan.');
    }
    if (!quotationCanBeConverted($quote)) {
        throw new RuntimeException('Penawaran ini tidak dapat dikonversi menjadi transaksi.');
    }

    $items = quotationLoadItems($conn, $quotationId);
    if (empty($items)) {
        throw new RuntimeException('Penawaran tidak memiliki item untuk dikonversi.');
    }

    $transactionNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    $customerId = !empty($quote['pelanggan_id']) ? (int) $quote['pelanggan_id'] : null;
    $total = max(0, (float) ($quote['total'] ?? 0));
    $discount = max(0, (float) ($quote['diskon'] ?? 0));
    $tax = max(0, (float) ($quote['pajak'] ?? 0));
    $noteParts = ['Konversi dari penawaran ' . (string) ($quote['no_penawaran'] ?? '') . '.'];
    if (!empty($quote['catatan'])) {
        $noteParts[] = trim((string) $quote['catatan']);
    }
    $transactionNote = trim(implode("\n", $noteParts));

    $trxCols = schemaTableColumns($conn, 'transaksi');
    $hasTax = in_array('pajak', $trxCols, true);
    $hasMethod = in_array('metode_bayar', $trxCols, true);
    $hasDp = in_array('dp_amount', $trxCols, true);
    $hasRemaining = in_array('sisa_bayar', $trxCols, true);
    $hasTempo = in_array('tempo_tgl', $trxCols, true);
    $hasTaxPercent = in_array('pajak_persen', $trxCols, true);
    $hasWorkflow = in_array('workflow_step', $trxCols, true);

    $fields = ['no_transaksi', 'pelanggan_id', 'user_id', 'total', 'diskon', 'bayar', 'kembalian', 'status', 'catatan'];
    $values = [$transactionNumber, $customerId, $userId, $total, $discount, 0.0, 0.0, 'pending', $transactionNote];
    $types = 'siiddddss';
    $placeholders = ['?', 'NULLIF(?, 0)', '?', '?', '?', '?', '?', '?', '?'];

    if ($hasTax) {
        $fields[] = 'pajak';
        $values[] = $tax;
        $types .= 'd';
        $placeholders[] = '?';
    }
    if ($hasTaxPercent) {
        $fields[] = 'pajak_persen';
        $values[] = 0.0;
        $types .= 'd';
        $placeholders[] = '?';
    }
    if ($hasMethod) {
        $fields[] = 'metode_bayar';
        $values[] = 'cash';
        $types .= 's';
        $placeholders[] = '?';
    }
    if ($hasDp) {
        $fields[] = 'dp_amount';
        $values[] = 0.0;
        $types .= 'd';
        $placeholders[] = '?';
    }
    if ($hasRemaining) {
        $fields[] = 'sisa_bayar';
        $values[] = $total;
        $types .= 'd';
        $placeholders[] = '?';
    }
    if ($hasTempo) {
        $fields[] = 'tempo_tgl';
        $values[] = null;
        $types .= 's';
        $placeholders[] = '?';
    }
    if ($hasWorkflow) {
        $fields[] = 'workflow_step';
        $values[] = 'cashier';
        $types .= 's';
        $placeholders[] = '?';
    }

    $values[1] = $customerId ?? 0;
    $placeholder = implode(',', $placeholders);
    $stmt = $conn->prepare("INSERT INTO transaksi (" . implode(',', $fields) . ") VALUES ({$placeholder})");
    if (!$stmt) {
        throw new RuntimeException('Transaksi hasil konversi tidak dapat dibuat.');
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Transaksi hasil konversi gagal disimpan.');
    }

    $transaksiId = (int) $conn->insert_id;
    $stmt->close();

    $detailCols = schemaTableColumns($conn, 'detail_transaksi');
    foreach ($items as $item) {
        $productId = quotationResolveProductId($conn, (int) ($item['produk_id'] ?? 0));
        $name = (string) ($item['nama_item'] ?? '');
        $category = quotationNormalizeCategory((string) ($item['kategori_tipe'] ?? 'lainnya'));
        $unit = (string) ($item['satuan'] ?? 'pcs');
        $qty = max(0, (float) ($item['qty'] ?? 0));
        $width = max(0, (float) ($item['lebar'] ?? 0));
        $height = max(0, (float) ($item['tinggi'] ?? 0));
        $area = $width > 0 && $height > 0 ? round($width * $height, 4) : 0.0;
        $price = max(0, (float) ($item['harga'] ?? 0));
        $finishName = trim((string) ($item['finishing_nama'] ?? ''));
        $finishCost = max(0, (float) ($item['finishing_biaya'] ?? 0));
        $subtotal = max(0, (float) ($item['subtotal'] ?? 0));
        $note = trim((string) ($item['catatan'] ?? ''));

        $detailFields = ['transaksi_id', 'produk_id', 'nama_produk', 'qty', 'harga', 'subtotal'];
        $detailValues = [$transaksiId, $productId, $name, $qty, $price, $subtotal];
        $detailTypes = 'iisddd';
        $detailPlaceholders = ['?', 'NULLIF(?, 0)', '?', '?', '?', '?'];

        if (in_array('kategori_tipe', $detailCols, true)) {
            $detailFields[] = 'kategori_tipe';
            $detailValues[] = $category;
            $detailTypes .= 's';
            $detailPlaceholders[] = '?';
        }
        if (in_array('satuan', $detailCols, true)) {
            $detailFields[] = 'satuan';
            $detailValues[] = $unit;
            $detailTypes .= 's';
            $detailPlaceholders[] = '?';
        }
        if (in_array('lebar', $detailCols, true)) {
            $detailFields[] = 'lebar';
            $detailValues[] = $width;
            $detailTypes .= 'd';
            $detailPlaceholders[] = '?';
        }
        if (in_array('tinggi', $detailCols, true)) {
            $detailFields[] = 'tinggi';
            $detailValues[] = $height;
            $detailTypes .= 'd';
            $detailPlaceholders[] = '?';
        }
        if (in_array('luas', $detailCols, true)) {
            $detailFields[] = 'luas';
            $detailValues[] = $area;
            $detailTypes .= 'd';
            $detailPlaceholders[] = '?';
        }
        if (in_array('finishing_id', $detailCols, true)) {
            $detailFields[] = 'finishing_id';
            $detailValues[] = 0;
            $detailTypes .= 'i';
            $detailPlaceholders[] = 'NULLIF(?, 0)';
        }
        if (in_array('finishing_nama', $detailCols, true)) {
            $detailFields[] = 'finishing_nama';
            $detailValues[] = $finishName;
            $detailTypes .= 's';
            $detailPlaceholders[] = '?';
        }
        if (in_array('finishing_biaya', $detailCols, true)) {
            $detailFields[] = 'finishing_biaya';
            $detailValues[] = $finishCost;
            $detailTypes .= 'd';
            $detailPlaceholders[] = '?';
        }
        if (in_array('bahan_id', $detailCols, true)) {
            $detailFields[] = 'bahan_id';
            $detailValues[] = 0;
            $detailTypes .= 'i';
            $detailPlaceholders[] = 'NULLIF(?, 0)';
        }
        if (in_array('bahan_nama', $detailCols, true)) {
            $detailFields[] = 'bahan_nama';
            $detailValues[] = '';
            $detailTypes .= 's';
            $detailPlaceholders[] = '?';
        }
        if (in_array('size_detail', $detailCols, true)) {
            $detailFields[] = 'size_detail';
            $detailValues[] = '';
            $detailTypes .= 's';
            $detailPlaceholders[] = '?';
        }
        if (in_array('catatan', $detailCols, true)) {
            $detailFields[] = 'catatan';
            $detailValues[] = $note;
            $detailTypes .= 's';
            $detailPlaceholders[] = '?';
        }

        $detailValues[1] = $productId ?? 0;
        $detailPlaceholder = implode(',', $detailPlaceholders);
        $stmtDetail = $conn->prepare("INSERT INTO detail_transaksi (" . implode(',', $detailFields) . ") VALUES ({$detailPlaceholder})");
        if (!$stmtDetail) {
            throw new RuntimeException('Item transaksi hasil konversi tidak dapat disimpan.');
        }

        $stmtDetail->bind_param($detailTypes, ...$detailValues);
        if (!$stmtDetail->execute()) {
            $stmtDetail->close();
            throw new RuntimeException('Salah satu item transaksi hasil konversi gagal disimpan.');
        }

        $detailId = (int) $conn->insert_id;
        $stmtDetail->close();

        if ($productId !== null && in_array(strtolower($unit), ['pcs', 'lembar'], true)) {
            $stmtStock = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?");
            if ($stmtStock) {
                $stmtStock->bind_param('di', $qty, $productId);
                $stmtStock->execute();
                $stmtStock->close();
            }
        }

        quotationInsertProductionJob($conn, $transaksiId, $detailId, $item, $userId);
    }

    $stmtUpdateQuote = $conn->prepare(
        "UPDATE penawaran
         SET status = 'dikonversi', converted_transaksi_id = ?, converted_by = ?, converted_at = NOW()
         WHERE id = ?"
    );
    if (!$stmtUpdateQuote) {
        throw new RuntimeException('Status penawaran tidak dapat diperbarui setelah konversi.');
    }

    $stmtUpdateQuote->bind_param('iii', $transaksiId, $userId, $quotationId);
    if (!$stmtUpdateQuote->execute()) {
        $stmtUpdateQuote->close();
        throw new RuntimeException('Penawaran gagal ditandai sebagai sudah dikonversi.');
    }
    $stmtUpdateQuote->close();

    return [
        'quotation_id' => $quotationId,
        'no_penawaran' => (string) ($quote['no_penawaran'] ?? ''),
        'transaksi_id' => $transaksiId,
        'no_transaksi' => $transactionNumber,
        'total' => $total,
        'item_count' => count($items),
    ];
}
