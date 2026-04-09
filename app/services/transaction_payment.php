<?php

require_once __DIR__ . '/file_manager.php';

function transactionPaymentSupportReady(mysqli $conn): bool
{
    if (!schemaTableExists($conn, 'transaksi') || !schemaTableExists($conn, 'transaksi_pembayaran')) {
        return false;
    }

    foreach (['metode_bayar', 'dp_amount', 'sisa_bayar', 'tempo_tgl'] as $column) {
        if (!schemaColumnExists($conn, 'transaksi', $column)) {
            return false;
        }
    }

    return true;
}

function transactionPaymentEnsureSupportTables(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (transactionPaymentSupportReady($conn)) {
        return $ready = true;
    }

    if (!schemaTableExists($conn, 'transaksi')) {
        return $ready = false;
    }

    if (!appSchemaAutoMigrateEnabled()) {
        return $ready = false;
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS transaksi_pembayaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaksi_id INT NOT NULL,
            tanggal DATE NOT NULL,
            nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
            metode VARCHAR(50) NULL,
            referensi VARCHAR(120) NULL,
            catatan VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_transaksi_bayar_header (transaksi_id),
            KEY idx_transaksi_bayar_tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            return $ready = false;
        }
    }

    $columnQueries = [];
    if (!schemaColumnExists($conn, 'transaksi', 'metode_bayar')) {
        $columnQueries[] = "ALTER TABLE transaksi ADD COLUMN metode_bayar VARCHAR(50) NULL AFTER kembalian";
    }
    if (!schemaColumnExists($conn, 'transaksi', 'dp_amount')) {
        $columnQueries[] = "ALTER TABLE transaksi ADD COLUMN dp_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER metode_bayar";
    }
    if (!schemaColumnExists($conn, 'transaksi', 'sisa_bayar')) {
        $columnQueries[] = "ALTER TABLE transaksi ADD COLUMN sisa_bayar DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER dp_amount";
    }
    if (!schemaColumnExists($conn, 'transaksi', 'tempo_tgl')) {
        $columnQueries[] = "ALTER TABLE transaksi ADD COLUMN tempo_tgl DATE NULL AFTER sisa_bayar";
    }

    foreach ($columnQueries as $sql) {
        if (!$conn->query($sql)) {
            return $ready = false;
        }
    }

    return $ready = true;
}

function transactionPaymentNormalizeMethod(string $value): string
{
    $value = strtolower(trim($value));

    return in_array($value, ['cash', 'transfer', 'qris', 'giro', 'lainnya', 'downpayment'], true)
        ? $value
        : 'cash';
}

function transactionPaymentHasProofUpload(string $fieldName = 'bukti_pembayaran'): bool
{
    $files = normalizeTransactionUploadFiles($fieldName);
    if (empty($files)) {
        return false;
    }

    foreach ($files as $file) {
        $name = trim((string) ($file['name'] ?? ''));
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($name !== '' || $errorCode !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    return false;
}

function transactionPaymentStoreProofUpload(
    mysqli $conn,
    int $transaksiId,
    int $userId,
    string $fieldName = 'bukti_pembayaran'
): array {
    $response = [
        'attempted' => false,
        'success' => false,
        'files' => [],
        'errors' => [],
        'message' => '',
    ];

    if ($transaksiId <= 0 || $userId <= 0 || !transactionPaymentHasProofUpload($fieldName)) {
        return $response;
    }

    $response['attempted'] = true;
    $result = storeTransactionUploads($conn, $transaksiId, null, 'bukti_transfer', $userId, $fieldName);
    $response['success'] = !empty($result['files']);
    $response['files'] = $result['files'] ?? [];
    $response['errors'] = $result['errors'] ?? [];

    if (!empty($response['files'])) {
        writeAuditLog(
            'payment_proof_upload',
            'file_transaksi',
            'Bukti pembayaran transaksi berhasil diunggah.',
            [
                'entity_id' => $transaksiId,
                'metadata' => [
                    'transaksi_id' => $transaksiId,
                    'tipe_file' => 'bukti_transfer',
                    'jumlah_file' => count($response['files']),
                    'nama_file' => array_map(static function (array $file): string {
                        return (string) ($file['nama_asli'] ?? '');
                    }, $response['files']),
                ],
            ]
        );
    }

    if (!empty($response['files']) && empty($response['errors'])) {
        $response['message'] = count($response['files']) === 1
            ? 'Bukti pembayaran berhasil diunggah.'
            : count($response['files']) . ' bukti pembayaran berhasil diunggah.';
    } elseif (!empty($response['errors'])) {
        $response['message'] = implode(' ', $response['errors']);
    }

    return $response;
}

function transactionPaymentResolvePaidTotal(array $trx): float
{
    $total = max(0, (float) ($trx['total'] ?? 0));
    $paid = max(0, (float) ($trx['bayar'] ?? 0));

    return round(min($paid, $total), 2);
}

function transactionPaymentResolveRemaining(array $trx): float
{
    $total = max(0, (float) ($trx['total'] ?? 0));
    $fallbackRemaining = max(0, $total - transactionPaymentResolvePaidTotal($trx));
    $remaining = array_key_exists('sisa_bayar', $trx)
        ? max((float) ($trx['sisa_bayar'] ?? 0), $fallbackRemaining)
        : $fallbackRemaining;

    return round(min($remaining, $total), 2);
}

function transactionPaymentCanBeSettled(array $trx): bool
{
    $status = strtolower(trim((string) ($trx['status'] ?? '')));

    return $status !== 'batal' && transactionPaymentResolveRemaining($trx) > 0.000001;
}

function transactionPaymentHasPaidAmount(array $trx): bool
{
    return transactionPaymentResolvePaidTotal($trx) > 0.000001;
}

function transactionPaymentCanBeVoided(array $trx): bool
{
    $status = strtolower(trim((string) ($trx['status'] ?? '')));

    return $status !== 'batal' && transactionPaymentHasPaidAmount($trx);
}

function transactionPaymentIsVoidDisplay(array $trx): bool
{
    $status = strtolower(trim((string) ($trx['status'] ?? '')));

    return $status === 'batal' && transactionPaymentHasPaidAmount($trx);
}

function transactionPaymentStatusBadgeClass(array $trx): string
{
    if (transactionPaymentIsVoidDisplay($trx)) {
        return 'danger';
    }

    return [
        'draft' => 'secondary',
        'selesai' => 'success',
        'pending' => 'warning',
        'dp' => 'warning',
        'tempo' => 'info',
        'batal' => 'danger',
    ][strtolower(trim((string) ($trx['status'] ?? '')))] ?? 'secondary';
}

function transactionPaymentStatusLabel(array $trx): string
{
    if (transactionPaymentIsVoidDisplay($trx)) {
        return 'VOID';
    }

    return [
        'draft' => 'Draft',
        'selesai' => 'Selesai',
        'pending' => 'Pending',
        'dp' => 'DP',
        'tempo' => 'Tempo',
        'batal' => 'Batal',
    ][strtolower(trim((string) ($trx['status'] ?? '')))] ?? ucfirst((string) ($trx['status'] ?? ''));
}

function transactionVoidRollbackStock(mysqli $conn, int $transaksiId): int
{
    if ($transaksiId <= 0 || !schemaTableExists($conn, 'detail_transaksi')) {
        return 0;
    }

    $detailCols = schemaTableColumns($conn, 'detail_transaksi');
    if (!in_array('produk_id', $detailCols, true) || !in_array('qty', $detailCols, true)) {
        return 0;
    }

    $fields = ['produk_id', 'qty'];
    if (in_array('satuan', $detailCols, true)) {
        $fields[] = 'satuan';
    }

    $result = $conn->query(
        "SELECT " . implode(',', $fields) . "
         FROM detail_transaksi
         WHERE transaksi_id = " . (int) $transaksiId
    );
    if (!$result) {
        return 0;
    }

    $restockedCount = 0;
    $stmtUpdate = $conn->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?");
    if (!$stmtUpdate) {
        return 0;
    }

    while ($row = $result->fetch_assoc()) {
        $produkId = (int) ($row['produk_id'] ?? 0);
        $qty = max(0, (float) ($row['qty'] ?? 0));
        $satuan = strtolower(trim((string) ($row['satuan'] ?? '')));

        if ($produkId <= 0 || $qty <= 0 || !in_array($satuan, ['pcs', 'lembar'], true)) {
            continue;
        }

        $stmtUpdate->bind_param('di', $qty, $produkId);
        if ($stmtUpdate->execute()) {
            $restockedCount++;
        }
    }

    $stmtUpdate->close();

    return $restockedCount;
}

function transactionCancelProductionJobs(mysqli $conn, int $transaksiId, string $reason = ''): int
{
    if ($transaksiId <= 0 || !schemaTableExists($conn, 'produksi')) {
        return 0;
    }

    $produksiCols = schemaTableColumns($conn, 'produksi');
    if (!in_array('transaksi_id', $produksiCols, true) || !in_array('status', $produksiCols, true)) {
        return 0;
    }

    $reason = trim($reason);
    if (in_array('keterangan', $produksiCols, true)) {
        $stmt = $conn->prepare(
            "UPDATE produksi
             SET status = 'batal',
                 keterangan = TRIM(CONCAT(COALESCE(keterangan, ''), CASE WHEN COALESCE(keterangan, '') = '' OR ? = '' THEN '' ELSE '\n' END, ?))
             WHERE transaksi_id = ? AND status <> 'batal'"
        );
    } else {
        $stmt = $conn->prepare("UPDATE produksi SET status = 'batal' WHERE transaksi_id = ? AND status <> 'batal'");
    }

    if (!$stmt) {
        return 0;
    }

    if (in_array('keterangan', $produksiCols, true)) {
        $stmt->bind_param('ssi', $reason, $reason, $transaksiId);
    } else {
        $stmt->bind_param('i', $transaksiId);
    }
    $stmt->execute();
    $affectedRows = max(0, (int) $stmt->affected_rows);
    $stmt->close();

    return $affectedRows;
}

function transactionVoidCancelProduction(mysqli $conn, int $transaksiId): int
{
    return transactionCancelProductionJobs(
        $conn,
        $transaksiId,
        '[VOID transaksi] Job dibatalkan dari menu transaksi.'
    );
}

function transactionPaymentCreateRecord(mysqli $conn, array $payload): int
{
    if (!transactionPaymentEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel pembayaran transaksi belum siap.');
    }

    $transaksiId = (int) ($payload['transaksi_id'] ?? 0);
    $tanggal = trim((string) ($payload['tanggal'] ?? date('Y-m-d')));
    $nominal = max(0, (float) ($payload['nominal'] ?? 0));
    $metode = transactionPaymentNormalizeMethod((string) ($payload['metode'] ?? 'cash'));
    $referensi = trim((string) ($payload['referensi'] ?? ''));
    $catatan = trim((string) ($payload['catatan'] ?? ''));
    $createdBy = (int) ($payload['created_by'] ?? 0);

    if ($transaksiId <= 0 || $nominal <= 0) {
        throw new RuntimeException('Transaksi dan nominal pembayaran wajib diisi.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    $stmt = $conn->prepare(
        "INSERT INTO transaksi_pembayaran (
            transaksi_id,
            tanggal,
            nominal,
            metode,
            referensi,
            catatan,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Pembayaran transaksi tidak dapat disimpan saat ini.');
    }

    $stmt->bind_param('isdsssi', $transaksiId, $tanggal, $nominal, $metode, $referensi, $catatan, $createdBy);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Pembayaran transaksi gagal disimpan.');
    }

    $paymentId = (int) $stmt->insert_id;
    $stmt->close();

    return $paymentId;
}

function transactionPaymentLoadByTransactionIds(mysqli $conn, array $transaksiIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $transaksiIds), static function (int $id): bool {
        return $id > 0;
    })));
    if (empty($ids) || !schemaTableExists($conn, 'transaksi_pembayaran')) {
        return [];
    }

    $grouped = [];
    $result = $conn->query(
        "SELECT tp.*, u.nama AS nama_pembuat
         FROM transaksi_pembayaran tp
         LEFT JOIN users u ON u.id = tp.created_by
         WHERE tp.transaksi_id IN (" . implode(',', $ids) . ")
         ORDER BY tp.tanggal ASC, tp.id ASC"
    );
    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $grouped[(int) ($row['transaksi_id'] ?? 0)][] = $row;
    }

    return $grouped;
}

function transactionPaymentBuildDisplayHistory(array $trx, array $paymentRows): array
{
    $rows = [];
    $recordedTotal = 0.0;

    foreach ($paymentRows as $payment) {
        $payment['is_initial'] = 0;
        $rows[] = $payment;
        $recordedTotal += max(0, (float) ($payment['nominal'] ?? 0));
    }

    $paidTotal = transactionPaymentResolvePaidTotal($trx);
    $initialAmount = round(max(0, $paidTotal - $recordedTotal), 2);
    if ($initialAmount > 0.000001) {
        $total = max(0, (float) ($trx['total'] ?? 0));
        $createdAt = (string) ($trx['created_at'] ?? date('Y-m-d H:i:s'));
        $rows[] = [
            'id' => 0,
            'transaksi_id' => (int) ($trx['id'] ?? 0),
            'tanggal' => date('Y-m-d', strtotime($createdAt)),
            'nominal' => $initialAmount,
            'metode' => (string) ($trx['metode_bayar'] ?? 'cash'),
            'referensi' => '',
            'catatan' => $paidTotal < $total ? 'Pembayaran awal / DP' : 'Pembayaran awal',
            'created_by' => (int) ($trx['user_id'] ?? 0),
            'nama_pembuat' => (string) ($trx['nama_kasir'] ?? '-'),
            'created_at' => $createdAt,
            'is_initial' => 1,
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        $leftAt = strtotime((string) ($left['created_at'] ?? ($left['tanggal'] ?? 'now')));
        $rightAt = strtotime((string) ($right['created_at'] ?? ($right['tanggal'] ?? 'now')));
        if ($leftAt === $rightAt) {
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        }

        return $leftAt <=> $rightAt;
    });

    return $rows;
}

function transactionPaymentRegisterSettlement(mysqli $conn, array $payload): array
{
    if (!transactionPaymentEnsureSupportTables($conn)) {
        throw new RuntimeException('Tabel pembayaran transaksi belum siap.');
    }

    $transaksiId = (int) ($payload['transaksi_id'] ?? 0);
    $nominal = max(0, (float) ($payload['nominal'] ?? 0));
    $metode = transactionPaymentNormalizeMethod((string) ($payload['metode'] ?? 'cash'));
    $tanggal = trim((string) ($payload['tanggal'] ?? date('Y-m-d')));
    $referensi = trim((string) ($payload['referensi'] ?? ''));
    $catatan = trim((string) ($payload['catatan'] ?? ''));
    $createdBy = (int) ($payload['created_by'] ?? 0);

    if ($transaksiId <= 0 || $nominal <= 0) {
        throw new RuntimeException('Transaksi dan nominal pembayaran wajib diisi.');
    }

    $stmt = $conn->prepare(
        "SELECT id, no_transaksi, total, bayar, sisa_bayar, status, metode_bayar
         FROM transaksi
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Data transaksi tidak dapat dibaca.');
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $trx = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$trx) {
        throw new RuntimeException('Transaksi tidak ditemukan.');
    }

    if (!transactionPaymentCanBeSettled($trx)) {
        throw new RuntimeException('Transaksi ini tidak memiliki sisa pembayaran.');
    }

    $remaining = transactionPaymentResolveRemaining($trx);
    if ($nominal - $remaining > 0.000001) {
        throw new RuntimeException('Nominal pembayaran melebihi sisa tagihan transaksi.');
    }

    $paymentId = transactionPaymentCreateRecord($conn, [
        'transaksi_id' => $transaksiId,
        'tanggal' => $tanggal,
        'nominal' => $nominal,
        'metode' => $metode,
        'referensi' => $referensi,
        'catatan' => $catatan,
        'created_by' => $createdBy,
    ]);

    $total = max(0, (float) ($trx['total'] ?? 0));
    $currentPaid = transactionPaymentResolvePaidTotal($trx);
    $newPaid = round(min($total, $currentPaid + $nominal), 2);
    $newRemaining = round(max(0, $remaining - $nominal), 2);
    $currentStatus = strtolower(trim((string) ($trx['status'] ?? '')));
    $newStatus = $newRemaining <= 0.000001
        ? 'selesai'
        : ($currentStatus === 'tempo' ? 'tempo' : ($currentStatus === 'pending' ? 'pending' : 'dp'));

    $stmtUpdate = $conn->prepare(
        "UPDATE transaksi
         SET bayar = ?, sisa_bayar = ?, status = ?
         WHERE id = ?"
    );
    if (!$stmtUpdate) {
        throw new RuntimeException('Ringkasan pembayaran transaksi tidak dapat diperbarui.');
    }

    $stmtUpdate->bind_param('ddsi', $newPaid, $newRemaining, $newStatus, $transaksiId);
    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        throw new RuntimeException('Ringkasan pembayaran transaksi gagal diperbarui.');
    }
    $stmtUpdate->close();

    return [
        'payment_id' => $paymentId,
        'transaksi_id' => $transaksiId,
        'no_transaksi' => (string) ($trx['no_transaksi'] ?? ''),
        'bayar' => $newPaid,
        'sisa_bayar' => $newRemaining,
        'status' => $newStatus,
    ];
}

function transactionVoidSale(mysqli $conn, array $payload): array
{
    $transaksiId = (int) ($payload['transaksi_id'] ?? 0);

    if ($transaksiId <= 0) {
        throw new RuntimeException('Transaksi tidak valid untuk proses VOID.');
    }

    $stmt = $conn->prepare(
        "SELECT t.id, t.no_transaksi, t.total, t.bayar, t.sisa_bayar, t.status, t.metode_bayar, t.catatan
         FROM transaksi t
         WHERE t.id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Data transaksi tidak dapat dibaca.');
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $trx = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$trx) {
        throw new RuntimeException('Transaksi tidak ditemukan.');
    }

    if (!transactionPaymentCanBeVoided($trx)) {
        throw new RuntimeException('VOID hanya dapat dilakukan untuk transaksi yang sudah memiliki pembayaran.');
    }

    $restockedItems = transactionVoidRollbackStock($conn, $transaksiId);
    $cancelledJobs = transactionVoidCancelProduction($conn, $transaksiId);

    $voidNote = '[VOID ' . date('d/m/Y H:i') . '] Transaksi dibatalkan dari menu transaksi.';
    $existingNote = trim((string) ($trx['catatan'] ?? ''));
    $mergedNote = $existingNote === '' ? $voidNote : ($existingNote . "\n" . $voidNote);

    $stmtUpdate = $conn->prepare(
        "UPDATE transaksi
         SET status = 'batal', sisa_bayar = 0, tempo_tgl = NULL, catatan = ?
         WHERE id = ?"
    );
    if (!$stmtUpdate) {
        throw new RuntimeException('Status VOID tidak dapat disimpan saat ini.');
    }

    $stmtUpdate->bind_param('si', $mergedNote, $transaksiId);
    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        throw new RuntimeException('Transaksi gagal di-VOID.');
    }
    $stmtUpdate->close();

    return [
        'transaksi_id' => $transaksiId,
        'no_transaksi' => (string) ($trx['no_transaksi'] ?? ''),
        'restocked_items' => $restockedItems,
        'cancelled_jobs' => $cancelledJobs,
        'bayar' => transactionPaymentResolvePaidTotal($trx),
        'status' => 'batal',
    ];
}
