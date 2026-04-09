<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();
transactionOrderEnsureSupport($conn);

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<p class="text-center text-muted">ID transaksi tidak valid.</p>';
    exit;
}

if (!canAccessTransactionDetail($id)) {
    http_response_code(403);
    echo '<p class="text-center text-muted">Anda tidak memiliki akses ke detail transaksi ini.</p>';
    exit;
}

$stmt = $conn->prepare("SELECT t.*, p.nama AS nama_pelanggan, u.nama AS nama_kasir
    FROM transaksi t
    LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.id = ?
    LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$trx = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$trx) {
    echo '<p class="text-center text-muted">Data tidak ditemukan.</p>';
    exit;
}

$stmtItems = $conn->prepare("SELECT * FROM detail_transaksi WHERE transaksi_id = ? ORDER BY id ASC");
$stmtItems->bind_param('i', $id);
$stmtItems->execute();
$items = $stmtItems->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtItems->close();

$tableExists = schemaTableExists($conn, 'file_transaksi');

$files = [];
if ($tableExists) {
    $stmtFiles = $conn->prepare("SELECT f.*, u.nama AS nama_uploader, dt.nama_produk AS nama_produk_detail
        FROM file_transaksi f
        LEFT JOIN users u ON f.uploaded_by = u.id
        LEFT JOIN detail_transaksi dt ON dt.id = f.detail_transaksi_id
        WHERE f.transaksi_id = ? AND f.is_active = 1 AND f.tipe_file = 'bukti_transfer'
        ORDER BY f.created_at DESC");
    $stmtFiles->bind_param('i', $id);
    $stmtFiles->execute();
    $files = $stmtFiles->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtFiles->close();
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    return number_format($bytes / 1024, 2) . ' KB';
}

function formatUploadItemLabel(array $item): string {
    $parts = [trim((string) ($item['nama_produk'] ?? 'Item #' . ($item['id'] ?? 0)))];
    if (!empty($item['qty'])) {
        $parts[] = 'Qty ' . rtrim(rtrim(number_format((float) $item['qty'], 2, '.', ''), '0'), '.');
    }
    if (!empty($item['kategori_tipe'])) {
        $parts[] = strtoupper((string) $item['kategori_tipe']);
    }

    return implode(' - ', $parts);
}

function getCandidateItemsForFileType(string $tipeFile, array $allItems, array $printingItems, array $apparelItems): array
{
    if ($tipeFile === 'bukti_transfer') {
        return [];
    }

    if (in_array($tipeFile, ['cetak', 'siap_cetak'], true)) {
        return $printingItems;
    }

    if (in_array($tipeFile, ['mockup', 'list_nama'], true)) {
        return $apparelItems;
    }

    return $allItems;
}

$printingItems = array_values(array_filter($items, static function (array $item): bool {
    return ($item['kategori_tipe'] ?? '') === 'printing';
}));
$apparelItems = array_values(array_filter($items, static function (array $item): bool {
    return ($item['kategori_tipe'] ?? '') === 'apparel';
}));
$remainingAmount = transactionPaymentResolveRemaining($trx);
$paidTotal = transactionPaymentResolvePaidTotal($trx);
$paymentRows = transactionPaymentLoadByTransactionIds($conn, [$id]);
$paymentHistory = transactionPaymentBuildDisplayHistory($trx, $paymentRows[$id] ?? []);
$paymentSupportReady = transactionPaymentSupportReady($conn);
$workflowStep = transactionWorkflowResolveStep($trx);
$canPrintInvoice = transactionWorkflowIsProductionOpen($trx);
$canCollectPayment = $paymentSupportReady
    && hasRole('superadmin', 'admin', 'kasir')
    && transactionWorkflowCanCollectPayment($trx);
$canVoid = hasRole('superadmin', 'admin') && transactionPaymentCanBeVoided($trx);
$canCancel = !$canVoid && !in_array((string) ($trx['status'] ?? ''), ['selesai', 'batal'], true);
$canEditDraft = hasRole('superadmin', 'admin', 'service', 'kasir')
    && transactionOrderCanAmendTransaction($trx);
$canAppendInvoice = $canEditDraft;
?>
<style>
.transaction-detail-sheet {
    display: grid;
    gap: 12px;
}

.transaction-detail-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.transaction-detail-summary-table td {
    padding: 6px 0;
    vertical-align: top;
}

.transaction-detail-draft-actions,
.transaction-detail-note-form {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.transaction-detail-note-form .form-control {
    flex: 1 1 260px;
}

@media (max-width: 768px) {
    .transaction-detail-sheet {
        gap: 10px;
    }

    .transaction-detail-sheet .transaction-detail-actions {
        gap: 6px;
        margin-bottom: 0;
    }

    .transaction-detail-sheet .transaction-detail-actions .btn,
    .transaction-detail-sheet .transaction-detail-actions form .btn {
        min-height: 38px;
        padding: 8px 10px;
        font-size: .74rem;
        line-height: 1.35;
    }

    .transaction-detail-table-wrap table {
        min-width: 620px;
    }
}

@media (max-width: 520px) {
    .transaction-detail-sheet {
        gap: 8px;
    }

    .transaction-detail-sheet .transaction-detail-actions {
        display: flex;
        flex-wrap: nowrap;
        gap: 8px;
        overflow-x: auto;
        padding-bottom: 2px;
        margin-bottom: 0;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }

    .transaction-detail-sheet .transaction-detail-actions::-webkit-scrollbar {
        display: none;
    }

    .transaction-detail-sheet .transaction-detail-actions > *,
    .transaction-detail-sheet .transaction-detail-actions form {
        flex: 0 0 auto;
        width: auto;
        min-width: 0;
        margin: 0;
    }

    .transaction-detail-sheet .transaction-detail-actions form {
        display: flex;
    }

    .transaction-detail-draft-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .transaction-detail-draft-actions > * {
        width: 100%;
        min-width: 0;
        margin: 0;
    }

    .transaction-detail-note-form {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .transaction-detail-note-form > * {
        min-width: 0;
        margin: 0;
    }

    .transaction-detail-sheet .transaction-detail-actions .btn,
    .transaction-detail-sheet .transaction-detail-actions form .btn,
    .transaction-detail-draft-actions .btn,
    .transaction-detail-note-form .btn {
        min-height: 34px;
        padding: 7px 10px;
        border-radius: 12px;
        justify-content: center;
        font-size: .72rem;
        line-height: 1.25;
        text-align: center;
    }

    .transaction-detail-sheet .transaction-detail-actions .btn,
    .transaction-detail-sheet .transaction-detail-actions form .btn {
        width: auto;
        white-space: nowrap;
    }

    .transaction-detail-draft-actions .btn {
        width: 100%;
        white-space: normal;
    }

    .transaction-detail-note-form .form-control {
        flex: 1 1 100%;
        width: 100%;
    }

    .transaction-detail-note,
    .transaction-detail-edit-note,
    .transaction-detail-invoice-note,
    .transaction-detail-upload-panel {
        padding: 10px !important;
        border-radius: 10px !important;
        font-size: .78rem !important;
    }

    .transaction-detail-sheet h6 {
        margin-bottom: 8px !important;
        font-size: .86rem;
    }

    .transaction-detail-sheet hr {
        margin: 4px 0;
    }

    .transaction-detail-summary-table,
    .transaction-detail-summary-table tbody {
        display: grid;
        gap: 8px;
        width: 100% !important;
    }

    .transaction-detail-summary-table tr {
        display: grid;
        grid-template-columns: minmax(92px, .82fr) minmax(0, 1fr);
        gap: 10px;
        align-items: start;
        margin: 0;
        padding: 9px 10px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface-2);
    }

    .transaction-detail-summary-table td {
        display: block;
        width: auto !important;
        padding: 0;
        overflow-wrap: anywhere;
    }

    .transaction-detail-summary-table td:first-child {
        margin: 0;
        color: var(--text-muted) !important;
    }
}

@media (max-width: 390px) {
    .transaction-detail-draft-actions,
    .transaction-detail-note-form {
        grid-template-columns: 1fr;
    }

    .transaction-detail-draft-actions,
    .transaction-detail-note-form {
        display: grid;
    }

    .transaction-detail-sheet .transaction-detail-actions .btn,
    .transaction-detail-sheet .transaction-detail-actions form .btn {
        white-space: normal;
    }

    .transaction-detail-summary-table tr {
        grid-template-columns: 1fr;
        gap: 6px;
    }
}
</style>
<div data-detail-title="Detail <?= htmlspecialchars((string) ($trx['no_transaksi'] ?? ('#' . $id)), ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="transaction-detail-sheet">
<div class="transaction-detail-actions">
    <?php if ($canPrintInvoice): ?>
        <a href="<?= pageUrl('invoice_cetak.php?id=' . (int) $id) ?>" target="_blank" class="btn btn-secondary btn-sm">
            <i class="fas fa-print"></i> Cetak Invoice
        </a>
    <?php endif; ?>

    <?php if ($canAppendInvoice): ?>
        <a href="<?= pageUrl('pos.php?append_to=' . (int) $id) ?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-layer-group"></i> Amend / Tambah Produk
        </a>
    <?php endif; ?>

    <?php if ($canCollectPayment): ?>
        <button
            type="button"
            class="btn btn-primary btn-sm"
            data-id="<?= (int) $id ?>"
            data-no="<?= htmlspecialchars((string) ($trx['no_transaksi'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            data-pelanggan="<?= htmlspecialchars((string) ($trx['nama_pelanggan'] ?? 'Umum'), ENT_QUOTES, 'UTF-8') ?>"
            data-total="<?= htmlspecialchars((string) ((float) ($trx['total'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
            data-paid="<?= htmlspecialchars((string) $paidTotal, ENT_QUOTES, 'UTF-8') ?>"
            data-remaining="<?= htmlspecialchars((string) $remainingAmount, ENT_QUOTES, 'UTF-8') ?>"
            data-status="<?= htmlspecialchars((string) ($trx['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            onclick="openPaymentModalFromDetailButton(this)"
        >
            <i class="fas fa-money-check-dollar"></i> Proses Pembayaran
        </button>
    <?php endif; ?>

    <?php if ($canVoid): ?>
        <form method="POST" action="<?= htmlspecialchars(pageUrl('transaksi.php')) ?>" onsubmit="confirmDelete(this, 'Yakin ingin VOID transaksi ini? Stok item akan dikembalikan dan job produksi terkait akan dibatalkan.');return false;">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="void">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-danger btn-sm">
                <i class="fas fa-rotate-left"></i> VOID
            </button>
        </form>
    <?php elseif ($canCancel): ?>
        <form method="POST" action="<?= htmlspecialchars(pageUrl('transaksi.php')) ?>" onsubmit="confirmDelete(this);return false;">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="batal">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-danger btn-sm">
                <i class="fas fa-ban"></i> Batalkan
            </button>
        </form>
    <?php endif; ?>
</div>
<div class="transaction-detail-note">
    Popup ini sekarang jadi pusat aksi transaksi: cek draft invoice, amend item, proses pembayaran, dan simpan bukti bayar. File referensi produksi dipusatkan di menu Siap Cetak.
</div>
<table class="transaction-detail-summary-table" style="width:100%;margin-bottom:12px;font-size:.9rem">
    <tr><td style="width:40%;color:var(--text-muted)">No. Transaksi</td><td><strong><?= htmlspecialchars($trx['no_transaksi']) ?></strong></td></tr>
    <tr><td style="color:var(--text-muted)">Pelanggan</td><td><?= htmlspecialchars($trx['nama_pelanggan'] ?? 'Umum') ?></td></tr>
    <tr><td style="color:var(--text-muted)">Kasir</td><td><?= htmlspecialchars($trx['nama_kasir'] ?? '-') ?></td></tr>
    <tr><td style="color:var(--text-muted)">Tanggal</td><td><?= date('d M Y H:i', strtotime($trx['created_at'])) ?></td></tr>
    <tr><td style="color:var(--text-muted)">Status</td><td><?= htmlspecialchars(transactionPaymentStatusLabel($trx)) ?></td></tr>
    <tr><td style="color:var(--text-muted)">Tahap Workflow</td><td><?= htmlspecialchars(transactionWorkflowLabel($workflowStep)) ?></td></tr>
    <tr><td style="color:var(--text-muted)">Catatan Invoice</td><td><?= htmlspecialchars((string) ($trx['catatan_invoice'] ?? '-')) ?: '-' ?></td></tr>
    <tr><td style="color:var(--text-muted)">Metode Awal</td><td><?= htmlspecialchars(($trx['metode_bayar'] ?? '') !== '' ? strtoupper((string) $trx['metode_bayar']) : '-') ?></td></tr>
    <?php if ((float) ($trx['dp_amount'] ?? 0) > 0): ?>
        <tr><td style="color:var(--text-muted)">DP Awal</td><td>Rp <?= number_format((float) ($trx['dp_amount'] ?? 0), 0, ',', '.') ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($trx['tempo_tgl'])): ?>
        <tr><td style="color:var(--text-muted)">Jatuh Tempo</td><td><?= date('d M Y', strtotime((string) $trx['tempo_tgl'])) ?></td></tr>
    <?php endif; ?>
</table>
<hr>
<?php if ($canEditDraft): ?>
<div class="transaction-detail-edit-note" style="margin-bottom:12px;padding:12px;border:1px solid var(--border);border-radius:12px;background:rgba(15,23,42,.04);font-size:.82rem;color:var(--text-muted);line-height:1.6">
    Draft ini belum menerima pembayaran, jadi item masih bisa diubah dari popup ini. Gunakan tombol amend untuk menambah produk baru dari menu POS, lalu lanjutkan ke pelunasan jika sudah final.
</div>
<?php endif; ?>
<div class="table-responsive transaction-detail-table-wrap">
<table class="transaction-detail-items-table" style="width:100%;font-size:.9rem">
    <thead><tr style="background:var(--bg)"><th style="padding:8px">Produk</th><th style="padding:8px;text-align:right">Qty</th><th style="padding:8px;text-align:right">Harga</th><th style="padding:8px;text-align:right">Subtotal</th><?php if ($canEditDraft): ?><th style="padding:8px;text-align:right">Aksi Draft</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
    <?php
    $itemQty = (float) ($item['qty'] ?? 0);
    $itemHarga = (float) ($item['harga'] ?? 0);
    $itemSubtotal = (float) ($item['subtotal'] ?? 0);
    $itemCategory = strtolower(trim((string) ($item['kategori_tipe'] ?? '')));
    $itemSatuan = strtolower(trim((string) ($item['satuan'] ?? 'pcs')));
    $isM2Printing = $itemCategory === 'printing' && $itemSatuan === 'm2';
    $itemMeta = array_filter([
        !empty($item['bahan_nama']) ? 'Bahan: ' . (string) $item['bahan_nama'] : '',
        !empty($item['finishing_nama']) ? 'Finishing: ' . (string) $item['finishing_nama'] : '',
        !empty($item['size_detail']) ? 'Detail: ' . (string) $item['size_detail'] : '',
        !empty($item['catatan']) ? 'Catatan: ' . (string) $item['catatan'] : '',
    ]);
    ?>
    <tr>
        <td style="padding:8px">
            <div style="font-weight:700;color:#111827"><?= htmlspecialchars((string) ($item['nama_produk'] ?? '-')) ?></div>
            <?php if (!empty($itemMeta)): ?>
                <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px;line-height:1.5"><?= htmlspecialchars(implode(' | ', $itemMeta)) ?></div>
            <?php endif; ?>
        </td>
        <td style="padding:8px;text-align:right"><?= rtrim(rtrim(number_format($itemQty, 2, '.', ''), '0'), '.') ?></td>
        <td style="padding:8px;text-align:right">Rp <?= number_format($itemHarga, 0, ',', '.') ?></td>
        <td style="padding:8px;text-align:right">Rp <?= number_format($itemSubtotal, 0, ',', '.') ?></td>
        <?php if ($canEditDraft): ?>
            <td style="padding:8px;text-align:right"><span class="badge badge-secondary">Editable</span></td>
        <?php endif; ?>
    </tr>
    <?php if ($canEditDraft): ?>
        <tr>
            <td colspan="<?= $canEditDraft ? 5 : 4 ?>" style="padding:0 8px 12px">
                <details style="border:1px solid var(--border);border-radius:12px;background:#fff">
                    <summary style="cursor:pointer;list-style:none;padding:10px 12px;font-weight:700;color:#111827">
                        Ubah item draft ini
                    </summary>
                    <div style="padding:12px;border-top:1px solid var(--border);background:var(--bg)">
                        <form method="POST" action="<?= htmlspecialchars(pageUrl('transaksi.php')) ?>">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="update_draft_item">
                            <input type="hidden" name="transaksi_id" value="<?= (int) $id ?>">
                            <input type="hidden" name="detail_id" value="<?= (int) ($item['id'] ?? 0) ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Nama Item</label>
                                    <input type="text" name="nama_produk" class="form-control" value="<?= htmlspecialchars((string) ($item['nama_produk'] ?? '')) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Harga</label>
                                    <input type="number" name="harga" class="form-control" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $itemHarga) ?>" required>
                                </div>
                            </div>
                            <?php if ($isM2Printing): ?>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Lebar (m)</label>
                                        <input type="number" name="lebar" class="form-control" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ((float) ($item['lebar'] ?? 0))) ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Tinggi (m)</label>
                                        <input type="number" name="tinggi" class="form-control" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ((float) ($item['tinggi'] ?? 0))) ?>" required>
                                    </div>
                                </div>
                                <div style="margin:-6px 0 12px;color:var(--text-muted);font-size:.78rem">Qty untuk item printing `m2` dihitung otomatis dari lebar x tinggi.</div>
                            <?php else: ?>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Qty</label>
                                        <input type="number" name="qty" class="form-control" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $itemQty) ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Satuan</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars((string) ($item['satuan'] ?? 'pcs')) ?>" readonly style="background:var(--bg)">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Detail Ukuran / Size</label>
                                    <input type="text" name="size_detail" class="form-control" value="<?= htmlspecialchars((string) ($item['size_detail'] ?? '')) ?>" placeholder="Contoh: S:4, M:6 / 2x1 m / detail ukuran lain">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Catatan Item</label>
                                    <input type="text" name="catatan_item" class="form-control" value="<?= htmlspecialchars((string) ($item['catatan'] ?? '')) ?>" placeholder="Catatan produksi / revisi item">
                                </div>
                            </div>
                            <div class="transaction-detail-draft-actions" style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-save"></i> Simpan Perubahan
                                </button>
                        </form>
                                <form method="POST" action="<?= htmlspecialchars(pageUrl('transaksi.php')) ?>" onsubmit="confirmDelete(this, 'Yakin ingin menghapus item ini dari draft invoice?');return false;" style="margin:0">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="delete_draft_item">
                                    <input type="hidden" name="transaksi_id" value="<?= (int) $id ?>">
                                    <input type="hidden" name="detail_id" value="<?= (int) ($item['id'] ?? 0) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash"></i> Hapus Item
                                    </button>
                                </form>
                            </div>
                    </div>
                </details>
            </td>
        </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><td colspan="<?= $canEditDraft ? 4 : 3 ?>" style="padding:8px;text-align:right;color:var(--text-muted)">Diskon</td><td style="padding:8px;text-align:right">Rp <?= number_format($trx['diskon'], 0, ',', '.') ?></td></tr>
        <tr><td colspan="<?= $canEditDraft ? 4 : 3 ?>" style="padding:8px;text-align:right;font-weight:700">TOTAL</td><td style="padding:8px;text-align:right;font-weight:700;color:var(--primary)">Rp <?= number_format($trx['total'], 0, ',', '.') ?></td></tr>
        <tr><td colspan="<?= $canEditDraft ? 4 : 3 ?>" style="padding:8px;text-align:right;color:var(--text-muted)">Bayar</td><td style="padding:8px;text-align:right">Rp <?= number_format($paidTotal, 0, ',', '.') ?></td></tr>
        <?php if ($remainingAmount > 0): ?>
            <tr><td colspan="<?= $canEditDraft ? 4 : 3 ?>" style="padding:8px;text-align:right;color:var(--text-muted)">Sisa Bayar</td><td style="padding:8px;text-align:right;color:#c05621">Rp <?= number_format($remainingAmount, 0, ',', '.') ?></td></tr>
        <?php else: ?>
            <tr><td colspan="<?= $canEditDraft ? 4 : 3 ?>" style="padding:8px;text-align:right;color:var(--text-muted)">Kembalian</td><td style="padding:8px;text-align:right;color:var(--success)">Rp <?= number_format((float) ($trx['kembalian'] ?? 0), 0, ',', '.') ?></td></tr>
        <?php endif; ?>
    </tfoot>
</table>
</div>
<div class="transaction-detail-invoice-note" style="margin-bottom:12px;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--bg)">
    <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:8px">Catatan kecil invoice ini ikut tampil di daftar transaksi dan riwayat pelanggan agar invoice lebih mudah dicari pada kondisi tertentu.</div>
    <form method="POST" action="<?= htmlspecialchars(pageUrl('transaksi.php')) ?>" class="transaction-detail-note-form" style="display:flex;gap:8px;flex-wrap:wrap">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="save_invoice_note">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input
            type="text"
            name="invoice_note"
            class="form-control"
            maxlength="120"
            value="<?= htmlspecialchars((string) ($trx['catatan_invoice'] ?? '')) ?>"
            placeholder="Contoh: Prioritas event Senin / pelanggan revisi cepat"
        >
        <button type="submit" class="btn btn-secondary btn-sm">
            <i class="fas fa-bookmark"></i> Simpan Catatan
        </button>
    </form>
</div>

<?php if (!empty($paymentHistory)): ?>
<hr>
<h6 style="margin-bottom:10px;font-weight:700">Riwayat Pembayaran</h6>
<div class="table-responsive transaction-detail-table-wrap">
<table class="transaction-detail-history-table" style="width:100%;font-size:.85rem">
    <thead>
        <tr style="background:var(--bg)">
            <th style="padding:6px 8px">Tanggal</th>
            <th style="padding:6px 8px">Nominal</th>
            <th style="padding:6px 8px">Metode</th>
            <th style="padding:6px 8px">Catatan</th>
            <th style="padding:6px 8px">Petugas</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($paymentHistory as $payment): ?>
        <tr>
            <td style="padding:6px 8px">
                <?= !empty($payment['tanggal']) ? date('d/m/Y', strtotime((string) $payment['tanggal'])) : '-' ?>
            </td>
            <td style="padding:6px 8px">Rp <?= number_format((float) ($payment['nominal'] ?? 0), 0, ',', '.') ?></td>
            <td style="padding:6px 8px"><?= htmlspecialchars(($payment['metode'] ?? '') !== '' ? strtoupper((string) $payment['metode']) : '-') ?></td>
            <td style="padding:6px 8px">
                <?= htmlspecialchars((string) ($payment['catatan'] ?? '-')) ?>
                <?php if (!empty($payment['referensi'])): ?>
                    <div class="text-muted" style="font-size:.78rem">Ref: <?= htmlspecialchars((string) $payment['referensi']) ?></div>
                <?php endif; ?>
            </td>
            <td style="padding:6px 8px">
                <?= htmlspecialchars((string) ($payment['nama_pembuat'] ?? '-')) ?>
                <?php if (!empty($payment['is_initial'])): ?>
                    <div class="text-muted" style="font-size:.78rem">Pembayaran awal</div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($tableExists): ?>
<hr>
<h6 style="margin-bottom:10px;font-weight:700">Bukti Pembayaran</h6>

<?php if (hasRole('superadmin', 'admin', 'service')): ?>
<div class="transaction-detail-upload-panel" style="margin-bottom:14px;padding:12px;background:var(--bg);border-radius:8px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" id="tipeFileUpload" value="bukti_transfer">
        <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:4px">Upload Bukti Transfer</label>
            <input type="file" id="fileInput" multiple style="font-size:.85rem">
        </div>
        <button class="btn btn-primary btn-sm" onclick="uploadFiles(<?= $id ?>)">
            <i class="fas fa-upload"></i> Upload
        </button>
    </div>
    <div id="uploadItemHint" style="margin-top:8px;font-size:.8rem;color:var(--text-muted)">
        Bukti transfer tetap tersimpan di transaksi. File referensi customer, mockup, list nama, dan file siap cetak sekarang dikelola dari menu Siap Cetak.
    </div>
    <div id="uploadStatus" style="margin-top:8px;font-size:.85rem"></div>
</div>
<?php endif; ?>

<div id="fileTerlampirList">
<?php if (empty($files)): ?>
    <p class="text-muted" style="font-size:.85rem;text-align:center;padding:12px 0">Belum ada file terlampir.</p>
<?php else: ?>
    <div class="table-responsive transaction-detail-table-wrap">
    <table class="transaction-detail-file-table" style="width:100%;font-size:.85rem">
        <thead>
            <tr style="background:var(--bg)">
                <th style="padding:6px 8px">Nama File</th>
                <th style="padding:6px 8px">Ukuran</th>
                <th style="padding:6px 8px">Tanggal Upload</th>
                <th style="padding:6px 8px">Uploader</th>
                <th style="padding:6px 8px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($files as $f): ?>
        <tr id="fileRow<?= $f['id'] ?>">
            <td style="padding:6px 8px"><?= htmlspecialchars($f['nama_asli']) ?></td>
            <td style="padding:6px 8px"><?= formatFileSize($f['ukuran']) ?></td>
            <td style="padding:6px 8px"><?= date('d/m/Y H:i', strtotime($f['created_at'])) ?></td>
            <td style="padding:6px 8px"><?= htmlspecialchars((string) ($f['nama_uploader'] ?? '-')) ?></td>
            <td style="padding:6px 8px">
                <a href="<?= pageUrl('file_download.php?id=' . (int) $f['id']) ?>" class="btn btn-primary btn-sm">Download</a>
                <?php if (hasRole('superadmin', 'admin', 'service')): ?>
                <button class="btn btn-danger btn-sm" onclick="hapusFile(<?= $f['id'] ?>)"><i class="fas fa-trash"></i></button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
