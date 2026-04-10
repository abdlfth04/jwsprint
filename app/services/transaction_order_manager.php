<?php

function transactionOrderEnsureSupport(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (!schemaTableExists($conn, 'transaksi')) {
        return $ready = false;
    }

    if (!schemaColumnExists($conn, 'transaksi', 'catatan_invoice')) {
        if (!appSchemaAutoMigrateEnabled()) {
            return $ready = false;
        }

        $ok = $conn->query(
            "ALTER TABLE transaksi
             ADD COLUMN catatan_invoice VARCHAR(120) NULL AFTER catatan"
        );
        if (!$ok) {
            return $ready = false;
        }
    }

    return $ready = schemaColumnExists($conn, 'transaksi', 'catatan_invoice');
}

function transactionOrderHasInvoiceNoteColumn(mysqli $conn): bool
{
    transactionOrderEnsureSupport($conn);
    return schemaColumnExists($conn, 'transaksi', 'catatan_invoice');
}

function transactionOrderSanitizeInvoiceNote(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 120);
    }

    return substr($value, 0, 120);
}

function transactionOrderTransaksiColumns(mysqli $conn): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    return $cache = schemaTableColumns($conn, 'transaksi');
}

function transactionOrderDetailColumns(mysqli $conn): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    return $cache = schemaTableColumns($conn, 'detail_transaksi');
}

function transactionOrderProduksiColumns(mysqli $conn): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    return $cache = schemaTableColumns($conn, 'produksi');
}

function transactionOrderNormalizeCartItems(array $items): array
{
    if (empty($items)) {
        throw new RuntimeException('Keranjang kosong.');
    }

    $normalizedItems = [];
    $subtotal = 0.0;
    $requiresDesign = false;

    foreach ($items as $item) {
        $isCustom = !empty($item['is_custom']);
        $namaItem = trim((string) ($item['nama'] ?? ''));
        $qty = max(0, (float) ($item['qty'] ?? 0));
        $harga = max(0, (float) ($item['harga'] ?? 0));
        $subtotalItem = max(0, (float) ($item['subtotal'] ?? 0));

        if ($qty <= 0) {
            throw new RuntimeException('Qty produk tidak valid.');
        }
        if ($namaItem === '') {
            throw new RuntimeException('Nama item transaksi tidak boleh kosong.');
        }

        if ($isCustom) {
            if ($harga <= 0) {
                throw new RuntimeException('Harga produk custom harus lebih besar dari nol.');
            }
            $subtotalItem = round($harga * $qty, 2);
        } elseif ($harga <= 0) {
            throw new RuntimeException('Harga item transaksi tidak valid.');
        }

        $kategoriTipe = $isCustom
            ? 'lainnya'
            : strtolower(trim((string) ($item['kat_tipe'] ?? $item['kategori_tipe'] ?? 'lainnya')));
        $satuan = trim((string) ($item['satuan'] ?? 'pcs')) ?: 'pcs';

        $normalizedItems[] = [
            'is_custom' => $isCustom ? 1 : 0,
            'id' => $isCustom ? 0 : (int) ($item['id'] ?? 0),
            'nama' => $namaItem,
            'kat_tipe' => $kategoriTipe,
            'satuan' => $satuan,
            'qty' => $qty,
            'lebar' => (float) ($item['lebar'] ?? 0),
            'tinggi' => (float) ($item['tinggi'] ?? 0),
            'luas' => (float) ($item['luas'] ?? 0),
            'harga' => $harga,
            'finishing_id' => (int) ($item['finishing_id'] ?? 0),
            'finishing_nama' => trim((string) ($item['finishing_nama'] ?? '')),
            'finishing_biaya' => (float) ($item['finishing_biaya'] ?? 0),
            'bahan_id' => (int) ($item['bahan_id'] ?? 0),
            'bahan_nama' => trim((string) ($item['bahan_nama'] ?? '')),
            'size_detail' => trim((string) ($item['size_detail'] ?? '')),
            'subtotal' => $subtotalItem,
            'catatan' => trim((string) ($item['catatan'] ?? '')),
        ];

        $subtotal += $subtotalItem;
        if (in_array($kategoriTipe, ['printing', 'apparel'], true)) {
            $requiresDesign = true;
        }
    }

    return [
        'items' => $normalizedItems,
        'subtotal' => round($subtotal, 2),
        'requires_design' => $requiresDesign,
    ];
}

function transactionOrderResolveStatusFromTotals(
    string $currentStatus,
    string $currentMethod,
    float $paidTotal,
    float $remaining
): string {
    if ($remaining <= 0.000001) {
        return 'selesai';
    }

    $normalizedStatus = strtolower(trim($currentStatus));
    $normalizedMethod = strtolower(trim($currentMethod));

    if ($normalizedStatus === 'draft' && $paidTotal <= 0.000001) {
        return 'draft';
    }

    if ($normalizedStatus === 'tempo' || $normalizedMethod === 'tempo') {
        return 'tempo';
    }

    return $paidTotal > 0.000001 ? 'dp' : 'pending';
}

function transactionOrderItemRequiresProductionJob(array $item): bool
{
    $category = strtolower(trim((string) ($item['kat_tipe'] ?? $item['kategori_tipe'] ?? '')));

    return in_array($category, ['printing', 'apparel'], true);
}

function transactionOrderItemDocumentType(array $item): string
{
    $category = strtolower(trim((string) ($item['kat_tipe'] ?? $item['kategori_tipe'] ?? '')));

    return $category === 'apparel' ? 'SPK' : 'JO';
}

function transactionOrderCalculateItemSubtotal(array $item): float
{
    $qty = max(0, (float) ($item['qty'] ?? 0));
    $harga = max(0, (float) ($item['harga'] ?? 0));
    $finishingBiaya = max(0, (float) ($item['finishing_biaya'] ?? 0));
    $category = strtolower(trim((string) ($item['kat_tipe'] ?? $item['kategori_tipe'] ?? '')));

    if ($category === 'printing') {
        return round(($harga * $qty) + $finishingBiaya, 2);
    }

    if ($category === 'apparel') {
        return round(($harga + $finishingBiaya) * $qty, 2);
    }

    return round($harga * $qty, 2);
}

function transactionOrderFetchTransactionHeader(mysqli $conn, int $transaksiId, bool $forUpdate = false): array
{
    if ($transaksiId <= 0 || !schemaTableExists($conn, 'transaksi')) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM transaksi
         WHERE id = ?
         LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $trx = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $trx;
}

function transactionOrderFetchDetailRows(mysqli $conn, int $transaksiId, bool $forUpdate = false): array
{
    if ($transaksiId <= 0 || !schemaTableExists($conn, 'detail_transaksi')) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM detail_transaksi
         WHERE transaksi_id = ?
         ORDER BY id ASC" . ($forUpdate ? ' FOR UPDATE' : '')
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function transactionOrderCanAmendTransaction(array $trx): bool
{
    $status = strtolower(trim((string) ($trx['status'] ?? '')));
    if ($status === 'batal') {
        return false;
    }

    if (transactionPaymentHasPaidAmount($trx)) {
        return false;
    }

    return transactionPaymentResolveRemaining($trx) > 0.000001;
}

function transactionOrderAdjustStockForDetailChange(mysqli $conn, array $detail, float $newQty): void
{
    $produkId = (int) ($detail['produk_id'] ?? 0);
    $satuan = strtolower(trim((string) ($detail['satuan'] ?? '')));
    if ($produkId <= 0 || !in_array($satuan, ['pcs', 'lembar'], true)) {
        return;
    }

    $oldQty = max(0, (float) ($detail['qty'] ?? 0));
    $delta = round($newQty - $oldQty, 4);
    if (abs($delta) <= 0.000001) {
        return;
    }

    $stmt = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?");
    if (!$stmt) {
        throw new RuntimeException('Stok produk tidak dapat diperbarui saat ini.');
    }

    $stmt->bind_param('di', $delta, $produkId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Stok produk gagal diperbarui.');
    }
    $stmt->close();
}

function transactionOrderRefreshHeaderTotals(mysqli $conn, int $transaksiId): array
{
    $trx = transactionOrderFetchTransactionHeader($conn, $transaksiId, true);
    if (!$trx) {
        throw new RuntimeException('Transaksi tidak ditemukan.');
    }

    $items = transactionOrderFetchDetailRows($conn, $transaksiId);
    if (empty($items)) {
        throw new RuntimeException('Transaksi harus memiliki minimal satu item.');
    }

    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += max(0, (float) ($item['subtotal'] ?? 0));
    }

    $diskon = max(0, (float) ($trx['diskon'] ?? 0));
    $pajak = max(0, (float) ($trx['pajak'] ?? 0));
    $total = round($subtotal - $diskon + $pajak, 2);
    if ($total <= 0.000001) {
        throw new RuntimeException('Total transaksi harus lebih besar dari nol setelah item diperbarui.');
    }

    $recordedPaid = max(0, (float) ($trx['bayar'] ?? 0));
    $paidTotal = round(min($recordedPaid, $total), 2);
    $remaining = round(max(0, $total - $paidTotal), 2);
    $status = transactionOrderResolveStatusFromTotals(
        (string) ($trx['status'] ?? ''),
        (string) ($trx['metode_bayar'] ?? ''),
        $paidTotal,
        $remaining
    );
    $kembalian = $remaining > 0.000001 ? 0 : round(max(0, $recordedPaid - $total), 2);
    $workflowStep = $status === 'draft'
        ? 'draft'
        : ($remaining > 0.000001 ? 'cashier' : 'production');

    $fields = [
        'total = ?',
        'bayar = ?',
        'kembalian = ?',
        'status = ?',
    ];
    $values = [$total, $paidTotal, $kembalian, $status];
    $types = 'ddds';

    if (schemaColumnExists($conn, 'transaksi', 'dp_amount')) {
        $fields[] = 'dp_amount = ?';
        $values[] = $remaining > 0.000001 ? min($paidTotal, $total) : 0;
        $types .= 'd';
    }
    if (schemaColumnExists($conn, 'transaksi', 'sisa_bayar')) {
        $fields[] = 'sisa_bayar = ?';
        $values[] = $remaining;
        $types .= 'd';
    }
    if (schemaColumnExists($conn, 'transaksi', 'tempo_tgl') && $status !== 'tempo') {
        $fields[] = 'tempo_tgl = NULL';
    }
    if (schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
        $fields[] = 'workflow_step = ?';
        $values[] = $workflowStep;
        $types .= 's';
    }

    $values[] = $transaksiId;
    $types .= 'i';

    $stmt = $conn->prepare(
        "UPDATE transaksi
         SET " . implode(', ', $fields) . "
         WHERE id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Header transaksi tidak dapat diperbarui.');
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Header transaksi gagal diperbarui.');
    }
    $stmt->close();

    return transactionOrderFetchTransactionHeader($conn, $transaksiId);
}

function transactionOrderUpdateDraftDetailItem(mysqli $conn, int $transaksiId, int $detailId, array $payload): array
{
    if ($transaksiId <= 0 || $detailId <= 0 || !schemaTableExists($conn, 'detail_transaksi')) {
        throw new RuntimeException('Item transaksi tidak valid.');
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM detail_transaksi
         WHERE transaksi_id = ? AND id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Item transaksi tidak dapat dibaca.');
    }

    $stmt->bind_param('ii', $transaksiId, $detailId);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$detail) {
        throw new RuntimeException('Item transaksi tidak ditemukan.');
    }

    $kategori = strtolower(trim((string) ($detail['kategori_tipe'] ?? '')));
    $satuan = trim((string) ($detail['satuan'] ?? 'pcs')) ?: 'pcs';
    $namaProduk = trim((string) ($payload['nama_produk'] ?? $detail['nama_produk'] ?? ''));
    $harga = max(0, (float) ($payload['harga'] ?? $detail['harga'] ?? 0));
    $sizeDetail = trim((string) ($payload['size_detail'] ?? $detail['size_detail'] ?? ''));
    $catatan = trim((string) ($payload['catatan'] ?? $detail['catatan'] ?? ''));
    $lebar = (float) ($detail['lebar'] ?? 0);
    $tinggi = (float) ($detail['tinggi'] ?? 0);
    $luas = (float) ($detail['luas'] ?? 0);
    $qty = max(0, (float) ($detail['qty'] ?? 0));

    if ($namaProduk === '') {
        throw new RuntimeException('Nama item transaksi wajib diisi.');
    }
    if ($harga <= 0) {
        throw new RuntimeException('Harga item harus lebih besar dari nol.');
    }

    if ($kategori === 'printing' && strtolower($satuan) === 'm2') {
        $lebar = max(0, (float) ($payload['lebar'] ?? $detail['lebar'] ?? 0));
        $tinggi = max(0, (float) ($payload['tinggi'] ?? $detail['tinggi'] ?? 0));
        $luas = round($lebar * $tinggi, 4);
        $qty = $luas;

        if ($lebar <= 0 || $tinggi <= 0 || $qty <= 0) {
            throw new RuntimeException('Lebar dan tinggi item printing harus lebih besar dari nol.');
        }
    } else {
        $qty = max(0, (float) ($payload['qty'] ?? $detail['qty'] ?? 0));
        if ($qty <= 0) {
            throw new RuntimeException('Qty item harus lebih besar dari nol.');
        }
    }

    $updatedDetail = $detail;
    $updatedDetail['kategori_tipe'] = $kategori;
    $updatedDetail['satuan'] = $satuan;
    $updatedDetail['nama_produk'] = $namaProduk;
    $updatedDetail['harga'] = $harga;
    $updatedDetail['qty'] = $qty;
    $updatedDetail['lebar'] = $lebar;
    $updatedDetail['tinggi'] = $tinggi;
    $updatedDetail['luas'] = $luas;
    $updatedDetail['size_detail'] = $sizeDetail;
    $updatedDetail['catatan'] = $catatan;

    // Printing qty-fix baru menyimpan finishing sebagai total order.
    // Saat qty draft diubah, biaya finishing perlu diskalakan ulang agar subtotal tetap konsisten.
    if (
        $kategori === 'printing'
        && strtolower($satuan) !== 'm2'
        && max(0, (int) ($detail['finishing_id'] ?? 0)) === 0
        && max(0, (float) ($detail['finishing_biaya'] ?? 0)) > 0
    ) {
        $qtyLama = max(0, (float) ($detail['qty'] ?? 0));
        if ($qtyLama > 0) {
            $finishingPerUnit = max(0, (float) $detail['finishing_biaya']) / $qtyLama;
            $updatedDetail['finishing_biaya'] = round($finishingPerUnit * $qty, 2);
        }
    }

    $updatedDetail['subtotal'] = transactionOrderCalculateItemSubtotal($updatedDetail);

    transactionOrderAdjustStockForDetailChange($conn, $detail, (float) $updatedDetail['qty']);

    $detailCols = transactionOrderDetailColumns($conn);
    $fields = ['nama_produk = ?', 'qty = ?', 'harga = ?', 'subtotal = ?'];
    $values = [$namaProduk, (float) $updatedDetail['qty'], $harga, (float) $updatedDetail['subtotal']];
    $types = 'sddd';

    if (in_array('lebar', $detailCols, true)) {
        $fields[] = 'lebar = ?';
        $values[] = (float) $updatedDetail['lebar'];
        $types .= 'd';
    }
    if (in_array('tinggi', $detailCols, true)) {
        $fields[] = 'tinggi = ?';
        $values[] = (float) $updatedDetail['tinggi'];
        $types .= 'd';
    }
    if (in_array('luas', $detailCols, true)) {
        $fields[] = 'luas = ?';
        $values[] = (float) $updatedDetail['luas'];
        $types .= 'd';
    }
    if (in_array('size_detail', $detailCols, true)) {
        $fields[] = 'size_detail = ?';
        $values[] = $sizeDetail;
        $types .= 's';
    }
    if (in_array('finishing_biaya', $detailCols, true)) {
        $fields[] = 'finishing_biaya = ?';
        $values[] = (float) ($updatedDetail['finishing_biaya'] ?? 0);
        $types .= 'd';
    }
    if (in_array('catatan', $detailCols, true)) {
        $fields[] = 'catatan = ?';
        $values[] = $catatan;
        $types .= 's';
    }

    $values[] = $detailId;
    $values[] = $transaksiId;
    $types .= 'ii';

    $stmtUpdate = $conn->prepare(
        "UPDATE detail_transaksi
         SET " . implode(', ', $fields) . "
         WHERE id = ? AND transaksi_id = ?"
    );
    if (!$stmtUpdate) {
        throw new RuntimeException('Item transaksi tidak dapat diperbarui.');
    }

    $stmtUpdate->bind_param($types, ...$values);
    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        throw new RuntimeException('Item transaksi gagal diperbarui.');
    }
    $stmtUpdate->close();

    return [
        'detail_id' => $detailId,
        'nama_produk' => $namaProduk,
        'qty' => (float) $updatedDetail['qty'],
        'subtotal' => (float) $updatedDetail['subtotal'],
    ];
}

function transactionOrderDeleteDraftDetailItem(mysqli $conn, int $transaksiId, int $detailId): array
{
    if ($transaksiId <= 0 || $detailId <= 0 || !schemaTableExists($conn, 'detail_transaksi')) {
        throw new RuntimeException('Item transaksi tidak valid.');
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM detail_transaksi
         WHERE transaksi_id = ? AND id = ?
         LIMIT 1
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Item transaksi tidak dapat dibaca.');
    }

    $stmt->bind_param('ii', $transaksiId, $detailId);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$detail) {
        throw new RuntimeException('Item transaksi tidak ditemukan.');
    }

    $stmtCount = $conn->prepare("SELECT COUNT(*) AS total_item FROM detail_transaksi WHERE transaksi_id = ?");
    if (!$stmtCount) {
        throw new RuntimeException('Jumlah item transaksi tidak dapat diverifikasi.');
    }

    $stmtCount->bind_param('i', $transaksiId);
    $stmtCount->execute();
    $countRow = $stmtCount->get_result()->fetch_assoc() ?: ['total_item' => 0];
    $stmtCount->close();

    if ((int) ($countRow['total_item'] ?? 0) <= 1) {
        throw new RuntimeException('Item terakhir tidak dapat dihapus. Batalkan transaksi jika invoice ini tidak dipakai.');
    }

    if (schemaTableExists($conn, 'file_transaksi') && schemaColumnExists($conn, 'file_transaksi', 'detail_transaksi_id')) {
        $activeCondition = schemaColumnExists($conn, 'file_transaksi', 'is_active') ? ' AND is_active = 1' : '';
        $stmtFiles = $conn->prepare(
            "SELECT COUNT(*) AS total_file
             FROM file_transaksi
             WHERE detail_transaksi_id = ?" . $activeCondition
        );
        if ($stmtFiles) {
            $stmtFiles->bind_param('i', $detailId);
            $stmtFiles->execute();
            $fileRow = $stmtFiles->get_result()->fetch_assoc() ?: ['total_file' => 0];
            $stmtFiles->close();

            if ((int) ($fileRow['total_file'] ?? 0) > 0) {
                throw new RuntimeException('Item ini masih memiliki lampiran file. Pindahkan atau hapus lampirannya terlebih dahulu.');
            }
        }
    }

    transactionOrderAdjustStockForDetailChange($conn, $detail, 0.0);

    $stmtDelete = $conn->prepare("DELETE FROM detail_transaksi WHERE id = ? AND transaksi_id = ?");
    if (!$stmtDelete) {
        throw new RuntimeException('Item transaksi tidak dapat dihapus.');
    }

    $stmtDelete->bind_param('ii', $detailId, $transaksiId);
    if (!$stmtDelete->execute()) {
        $stmtDelete->close();
        throw new RuntimeException('Item transaksi gagal dihapus.');
    }
    $stmtDelete->close();

    return [
        'detail_id' => $detailId,
        'nama_produk' => (string) ($detail['nama_produk'] ?? ''),
    ];
}

function transactionOrderSyncProductionJobsByPaymentState(mysqli $conn, int $transaksiId, int $userId = 0): array
{
    $trx = transactionOrderFetchTransactionHeader($conn, $transaksiId);
    if (!$trx || !schemaTableExists($conn, 'produksi')) {
        return ['created_jobs' => 0, 'cancelled_jobs' => 0];
    }

    $remaining = transactionPaymentResolveRemaining($trx);
    $status = strtolower(trim((string) ($trx['status'] ?? '')));
    if ($status === 'batal' || $remaining > 0.000001) {
        return [
            'created_jobs' => 0,
            'cancelled_jobs' => transactionCancelProductionJobs(
                $conn,
                $transaksiId,
                '[Workflow invoice] JO/SPK ditahan sampai invoice dilunasi.'
            ),
        ];
    }

    $details = transactionOrderFetchDetailRows($conn, $transaksiId);
    if (empty($details)) {
        return ['created_jobs' => 0, 'cancelled_jobs' => 0];
    }

    $hasStatusColumn = schemaColumnExists($conn, 'produksi', 'status');
    $activeJobsByKey = [];
    $stmtJobs = $conn->prepare(
        "SELECT id, detail_transaksi_id, tipe_dokumen" . ($hasStatusColumn ? ', status' : '') . "
         FROM produksi
         WHERE transaksi_id = ?
         ORDER BY id DESC"
    );
    if ($stmtJobs) {
        $stmtJobs->bind_param('i', $transaksiId);
        $stmtJobs->execute();
        $jobRows = $stmtJobs->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtJobs->close();

        foreach ($jobRows as $jobRow) {
            if ($hasStatusColumn && strtolower(trim((string) ($jobRow['status'] ?? ''))) === 'batal') {
                continue;
            }

            $detailKey = (int) ($jobRow['detail_transaksi_id'] ?? 0);
            $tipeDokumen = strtoupper(trim((string) ($jobRow['tipe_dokumen'] ?? '')));
            if ($detailKey <= 0 || $tipeDokumen === '') {
                continue;
            }

            $activeJobsByKey[$detailKey . '|' . $tipeDokumen] = (int) ($jobRow['id'] ?? 0);
        }
    }

    $resolvedUserId = $userId > 0
        ? $userId
        : (int) ($trx['user_id'] ?? ($_SESSION['user_id'] ?? 0));
    $createdJobs = 0;
    foreach ($details as $detail) {
        if (!transactionOrderItemRequiresProductionJob($detail)) {
            continue;
        }

        $detailId = (int) ($detail['id'] ?? 0);
        if ($detailId <= 0) {
            continue;
        }

        $documentType = transactionOrderItemDocumentType($detail);
        $jobKey = $detailId . '|' . $documentType;
        if (isset($activeJobsByKey[$jobKey])) {
            continue;
        }

        transactionOrderCreateProductionJob($conn, $transaksiId, $detailId, [
            'kat_tipe' => (string) ($detail['kategori_tipe'] ?? ''),
            'satuan' => (string) ($detail['satuan'] ?? ''),
            'qty' => (float) ($detail['qty'] ?? 0),
            'lebar' => (float) ($detail['lebar'] ?? 0),
            'tinggi' => (float) ($detail['tinggi'] ?? 0),
            'luas' => (float) ($detail['luas'] ?? 0),
            'finishing_nama' => (string) ($detail['finishing_nama'] ?? ''),
            'nama' => (string) ($detail['nama_produk'] ?? ''),
        ], $resolvedUserId);
        $createdJobs++;
    }

    return [
        'created_jobs' => $createdJobs,
        'cancelled_jobs' => 0,
    ];
}

function transactionOrderCreateProductionJob(
    mysqli $conn,
    int $transaksiId,
    int $detailId,
    array $item,
    int $userId
): void {
    $produksiCols = transactionOrderProduksiColumns($conn);
    $hasNoDok = in_array('no_dokumen', $produksiCols, true);
    $hasTipeDok = in_array('tipe_dokumen', $produksiCols, true);
    $hasTrxId = in_array('transaksi_id', $produksiCols, true);
    $hasDetId = in_array('detail_transaksi_id', $produksiCols, true);

    if (!$hasNoDok || !$hasTipeDok) {
        return;
    }

    $kategori = (string) ($item['kat_tipe'] ?? 'lainnya');
    if (!in_array($kategori, ['printing', 'apparel'], true)) {
        return;
    }

    $qty = (float) ($item['qty'] ?? 0);
    $lebar = (float) ($item['lebar'] ?? 0);
    $tinggi = (float) ($item['tinggi'] ?? 0);
    $luas = (float) ($item['luas'] ?? 0);
    $satuan = (string) ($item['satuan'] ?? 'pcs');
    $finishingNama = trim((string) ($item['finishing_nama'] ?? ''));
    $namaPekerjaan = trim((string) ($item['nama'] ?? 'Item Transaksi'));

    if ($luas > 0 && $lebar > 0 && $tinggi > 0) {
        $namaPekerjaan .= ' (' . rtrim(rtrim(number_format($lebar, 2, '.', ''), '0'), '.')
            . 'x' . rtrim(rtrim(number_format($tinggi, 2, '.', ''), '0'), '.')
            . ' m2)';
    } else {
        $namaPekerjaan .= ' (qty: ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')
            . ' ' . $satuan . ')';
    }
    if ($finishingNama !== '') {
        $namaPekerjaan .= ' + ' . $finishingNama;
    }

    if ($kategori === 'apparel') {
        $tahapanList = ['Setting/Design', 'Jahit', 'Finishing & QC'];
        $tipeDokumen = 'SPK';
    } else {
        $tahapanList = ['Setting File', 'Cetak', 'Finishing & QC'];
        $tipeDokumen = 'JO';
    }

    $noDokumen = $tipeDokumen . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    $tanggal = date('Y-m-d');

    $fields = ['no_dokumen', 'tipe_dokumen', 'nama_pekerjaan', 'tanggal', 'status', 'user_id'];
    $types = 'sssssi';
    $values = [$noDokumen, $tipeDokumen, $namaPekerjaan, $tanggal, 'antrian', $userId];

    if ($hasTrxId) {
        $fields[] = 'transaksi_id';
        $types .= 'i';
        $values[] = $transaksiId;
    }
    if ($hasDetId) {
        $fields[] = 'detail_transaksi_id';
        $types .= 'i';
        $values[] = $detailId;
    }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $stmt = $conn->prepare(
        "INSERT INTO produksi (" . implode(',', $fields) . ") VALUES ({$placeholders})"
    );
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

    if ($produksiId <= 0 || !schemaTableExists($conn, 'todo_list_tahapan')) {
        return;
    }

    $stmtTahapan = $conn->prepare(
        "INSERT INTO todo_list_tahapan (produksi_id, nama_tahapan, urutan, status)
         VALUES (?, ?, ?, 'belum')"
    );
    if (!$stmtTahapan) {
        return;
    }

    foreach ($tahapanList as $index => $namaTahapan) {
        $urutan = $index + 1;
        $stmtTahapan->bind_param('isi', $produksiId, $namaTahapan, $urutan);
        $stmtTahapan->execute();
    }

    $stmtTahapan->close();
}

function transactionOrderInsertItems(mysqli $conn, int $transaksiId, array $items, int $userId, bool $createProductionJobs = true): array
{
    if (!schemaTableExists($conn, 'detail_transaksi')) {
        throw new RuntimeException('Tabel detail transaksi belum tersedia.');
    }

    $detailCols = transactionOrderDetailColumns($conn);
    $detailIds = [];
    $requiresDesign = false;

    foreach ($items as $item) {
        $isCustom = !empty($item['is_custom']);
        $produkId = $isCustom ? 0 : (int) ($item['id'] ?? 0);
        $kategori = (string) ($item['kat_tipe'] ?? 'lainnya');
        $satuan = (string) ($item['satuan'] ?? 'pcs');
        $qty = (float) ($item['qty'] ?? 0);
        $harga = (float) ($item['harga'] ?? 0);
        $subtotal = (float) ($item['subtotal'] ?? 0);
        $finishingId = (int) ($item['finishing_id'] ?? 0);
        $bahanId = (int) ($item['bahan_id'] ?? 0);

        $fields = ['transaksi_id', 'produk_id', 'nama_produk', 'qty', 'harga', 'subtotal'];
        $values = [$transaksiId, $produkId, (string) ($item['nama'] ?? ''), $qty, $harga, $subtotal];
        $types = 'iisddd';
        $placeholders = ['?', 'NULLIF(?, 0)', '?', '?', '?', '?'];

        if (in_array('kategori_tipe', $detailCols, true)) {
            $fields[] = 'kategori_tipe';
            $values[] = $kategori;
            $types .= 's';
            $placeholders[] = '?';
        }
        if (in_array('satuan', $detailCols, true)) {
            $fields[] = 'satuan';
            $values[] = $satuan;
            $types .= 's';
            $placeholders[] = '?';
        }
        if (in_array('lebar', $detailCols, true)) {
            $fields[] = 'lebar';
            $values[] = (float) ($item['lebar'] ?? 0);
            $types .= 'd';
            $placeholders[] = '?';
        }
        if (in_array('tinggi', $detailCols, true)) {
            $fields[] = 'tinggi';
            $values[] = (float) ($item['tinggi'] ?? 0);
            $types .= 'd';
            $placeholders[] = '?';
        }
        if (in_array('luas', $detailCols, true)) {
            $fields[] = 'luas';
            $values[] = (float) ($item['luas'] ?? 0);
            $types .= 'd';
            $placeholders[] = '?';
        }
        if (in_array('finishing_id', $detailCols, true)) {
            $fields[] = 'finishing_id';
            $values[] = $finishingId;
            $types .= 'i';
            $placeholders[] = 'NULLIF(?, 0)';
        }
        if (in_array('finishing_nama', $detailCols, true)) {
            $fields[] = 'finishing_nama';
            $values[] = (string) ($item['finishing_nama'] ?? '');
            $types .= 's';
            $placeholders[] = '?';
        }
        if (in_array('finishing_biaya', $detailCols, true)) {
            $fields[] = 'finishing_biaya';
            $values[] = (float) ($item['finishing_biaya'] ?? 0);
            $types .= 'd';
            $placeholders[] = '?';
        }
        if (in_array('bahan_id', $detailCols, true)) {
            $fields[] = 'bahan_id';
            $values[] = $bahanId;
            $types .= 'i';
            $placeholders[] = 'NULLIF(?, 0)';
        }
        if (in_array('bahan_nama', $detailCols, true)) {
            $fields[] = 'bahan_nama';
            $values[] = (string) ($item['bahan_nama'] ?? '');
            $types .= 's';
            $placeholders[] = '?';
        }
        if (in_array('size_detail', $detailCols, true)) {
            $fields[] = 'size_detail';
            $values[] = (string) ($item['size_detail'] ?? '');
            $types .= 's';
            $placeholders[] = '?';
        }
        if (in_array('catatan', $detailCols, true)) {
            $fields[] = 'catatan';
            $values[] = (string) ($item['catatan'] ?? '');
            $types .= 's';
            $placeholders[] = '?';
        }

        $placeholderSql = implode(',', $placeholders);
        $stmt = $conn->prepare(
            "INSERT INTO detail_transaksi (" . implode(',', $fields) . ") VALUES ({$placeholderSql})"
        );
        if (!$stmt) {
            throw new RuntimeException('Detail transaksi tidak dapat disimpan.');
        }

        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Gagal menyimpan item transaksi.');
        }

        $detailId = (int) $conn->insert_id;
        $detailIds[] = $detailId;
        $stmt->close();

        if ($produkId > 0 && in_array(strtolower($satuan), ['pcs', 'lembar'], true)) {
            $stmtStock = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?");
            if (!$stmtStock) {
                throw new RuntimeException('Stok produk tidak dapat diperbarui saat ini.');
            }

            $stmtStock->bind_param('di', $qty, $produkId);
            if (!$stmtStock->execute()) {
                $stmtStock->close();
                throw new RuntimeException('Stok produk gagal diperbarui.');
            }
            $stmtStock->close();
        }

        if (in_array($kategori, ['printing', 'apparel'], true)) {
            $requiresDesign = true;
            if ($createProductionJobs) {
                transactionOrderCreateProductionJob($conn, $transaksiId, $detailId, $item, $userId);
            }
        }
    }

    return [
        'detail_ids' => $detailIds,
        'requires_design' => $requiresDesign,
    ];
}

function transactionOrderFetchRecentInvoices(mysqli $conn, int $limit = 80): array
{
    if (!schemaTableExists($conn, 'transaksi')) {
        return [];
    }

    $limit = max(1, min($limit, 200));
    $trxCols = transactionOrderTransaksiColumns($conn);
    $hasCustomerJoin = schemaTableExists($conn, 'pelanggan') && in_array('pelanggan_id', $trxCols, true);
    $hasCreatedAt = in_array('created_at', $trxCols, true);
    $hasInvoiceNote = in_array('catatan_invoice', $trxCols, true);
    $hasWorkflow = in_array('workflow_step', $trxCols, true);
    $hasRemaining = in_array('sisa_bayar', $trxCols, true);
    $hasMethod = in_array('metode_bayar', $trxCols, true);

    $fields = [
        't.id',
        't.no_transaksi',
        't.total',
        't.bayar',
        't.status',
        $hasCustomerJoin ? 't.pelanggan_id' : 'NULL AS pelanggan_id',
        $hasRemaining ? 't.sisa_bayar' : '0 AS sisa_bayar',
        $hasWorkflow ? 't.workflow_step' : "'production' AS workflow_step",
        $hasInvoiceNote ? 't.catatan_invoice' : "'' AS catatan_invoice",
        $hasCreatedAt ? 't.created_at' : 'NULL AS created_at',
        $hasMethod ? 't.metode_bayar' : "'' AS metode_bayar",
        $hasCustomerJoin ? 'p.nama AS nama_pelanggan' : "NULL AS nama_pelanggan",
    ];

    $query = $conn->query(
        "SELECT " . implode(', ', $fields) . "
         FROM transaksi t" . ($hasCustomerJoin ? ' LEFT JOIN pelanggan p ON t.pelanggan_id = p.id' : '') . "
         WHERE t.status <> 'batal'
         ORDER BY " . ($hasCreatedAt ? 't.created_at DESC' : 't.id DESC') . "
         LIMIT {$limit}"
    );
    if (!$query) {
        return [];
    }

    $rows = $query->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $index => $row) {
        $paidTotal = transactionPaymentResolvePaidTotal($row);
        $remaining = transactionPaymentResolveRemaining($row);
        $workflowStep = transactionWorkflowResolveStep($row);
        $rows[$index]['bayar'] = $paidTotal;
        $rows[$index]['remaining_amount'] = $remaining;
        $rows[$index]['workflow_step_value'] = $workflowStep;
        $rows[$index]['workflow_step_label'] = transactionWorkflowLabel($workflowStep);
        $rows[$index]['status_label'] = transactionPaymentStatusLabel($row);
        $rows[$index]['search_text'] = trim(implode(' ', array_filter([
            (string) ($row['no_transaksi'] ?? ''),
            (string) ($row['nama_pelanggan'] ?? 'Umum'),
            (string) ($row['status'] ?? ''),
            (string) ($row['workflow_step'] ?? ''),
            (string) ($row['catatan_invoice'] ?? ''),
        ])));
    }

    return $rows;
}
