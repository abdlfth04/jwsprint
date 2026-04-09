<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/quotation_manager.php';
requireRole('superadmin', 'admin', 'service', 'kasir');
$pageTitle = 'Penawaran';

function quotationPageBuildProductOptions(array $products, int $selectedId = 0): string
{
    $html = '<option value="">Custom item</option>';
    foreach ($products as $product) {
        $productId = (int) ($product['id'] ?? 0);
        $selected = $productId === $selectedId ? ' selected' : '';
        $html .= '<option value="' . $productId . '"' . $selected
            . ' data-name="' . htmlspecialchars((string) ($product['nama'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-price="' . htmlspecialchars((string) ((float) ($product['harga_jual'] ?? 0)), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-unit="' . htmlspecialchars((string) ($product['satuan'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-category="' . htmlspecialchars((string) ($product['kategori_tipe'] ?? 'lainnya'), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string) ($product['nama'] ?? '')) . '</option>';
    }

    return $html;
}

function quotationPageFetchQuotes(mysqli $conn, string $filter, string $search): array
{
    $sql = "SELECT q.*, p.nama AS nama_pelanggan, u.nama AS nama_pembuat, t.no_transaksi AS no_transaksi_konversi,
                (SELECT COUNT(*) FROM penawaran_item qi WHERE qi.penawaran_id = q.id) AS total_item
            FROM penawaran q
            LEFT JOIN pelanggan p ON p.id = q.pelanggan_id
            LEFT JOIN users u ON u.id = q.user_id
            LEFT JOIN transaksi t ON t.id = q.converted_transaksi_id
            WHERE 1=1";
    $types = '';
    $params = [];

    if ($filter !== '') {
        $sql .= " AND q.status = ?";
        $types .= 's';
        $params[] = $filter;
    }

    if ($search !== '') {
        $keyword = '%' . $search . '%';
        $sql .= " AND (q.no_penawaran LIKE ? OR COALESCE(p.nama, 'Umum') LIKE ? OR COALESCE(t.no_transaksi, '') LIKE ?)";
        $types .= 'sss';
        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
    }

    $sql .= " ORDER BY q.created_at DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function quotationPageRenderItemRow(int $index, array $item, array $products): void
{
    $productId = (int) ($item['produk_id'] ?? 0);
    $category = quotationNormalizeCategory((string) ($item['kategori_tipe'] ?? 'lainnya'));
    $qty = (float) ($item['qty'] ?? 1);
    $width = (float) ($item['lebar'] ?? 0);
    $height = (float) ($item['tinggi'] ?? 0);
    $price = (float) ($item['harga'] ?? 0);
    $finishCost = (float) ($item['finishing_biaya'] ?? 0);
    $rowSubtotal = (float) ($item['subtotal'] ?? (($qty * $price) + $finishCost));
    ?>
    <div class="quotation-item-row" data-quotation-row>
        <div class="quotation-item-row-head">
            <strong>Item <span class="quotation-item-index"><?= $index + 1 ?></span></strong>
            <button type="button" class="btn btn-danger btn-sm" data-remove-quotation-row><i class="fas fa-trash"></i></button>
        </div>
        <div class="quotation-item-grid">
            <div class="form-group">
                <label class="form-label">Produk</label>
                <select name="product_id[]" class="form-control quotation-product-select">
                    <?= quotationPageBuildProductOptions($products, $productId) ?>
                </select>
            </div>
            <div class="form-group quotation-item-name-group">
                <label class="form-label">Nama Item</label>
                <input type="text" name="item_name[]" class="form-control quotation-item-name" value="<?= htmlspecialchars((string) ($item['nama_item'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Kategori</label>
                <select name="kategori_tipe[]" class="form-control quotation-category-select">
                    <option value="printing" <?= $category === 'printing' ? 'selected' : '' ?>>Printing</option>
                    <option value="apparel" <?= $category === 'apparel' ? 'selected' : '' ?>>Apparel</option>
                    <option value="lainnya" <?= $category === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Satuan</label>
                <input type="text" name="satuan[]" class="form-control quotation-unit-input" value="<?= htmlspecialchars((string) ($item['satuan'] ?? 'pcs'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Qty</label>
                <input type="number" min="0.01" step="0.01" name="qty[]" class="form-control quotation-calc quotation-qty-input" value="<?= htmlspecialchars((string) $qty, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Lebar</label>
                <input type="number" min="0" step="0.01" name="lebar[]" class="form-control" value="<?= htmlspecialchars((string) $width, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Tinggi</label>
                <input type="number" min="0" step="0.01" name="tinggi[]" class="form-control" value="<?= htmlspecialchars((string) $height, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Harga</label>
                <input type="number" min="0" step="0.01" name="harga[]" class="form-control quotation-calc quotation-price-input" value="<?= htmlspecialchars((string) $price, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Finishing</label>
                <input type="text" name="finishing_nama[]" class="form-control" value="<?= htmlspecialchars((string) ($item['finishing_nama'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Biaya Finishing</label>
                <input type="number" min="0" step="0.01" name="finishing_biaya[]" class="form-control quotation-calc quotation-finish-input" value="<?= htmlspecialchars((string) $finishCost, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Subtotal</label>
                <input type="text" class="form-control quotation-row-subtotal" value="<?= number_format($rowSubtotal, 2, '.', '') ?>" readonly>
            </div>
            <div class="form-group quotation-item-note">
                <label class="form-label">Catatan Item</label>
                <textarea name="item_catatan[]" class="form-control" rows="2"><?= htmlspecialchars((string) ($item['catatan'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>
    </div>
    <?php
}

$quotationReady = quotationEnsureSupportTables($conn);
$msg = '';
$activeEditId = (int) ($_GET['edit'] ?? 0);

if ($quotationReady && isset($_POST['action'])) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        if ($action === 'save_quote') {
            $saved = quotationSave($conn, [
                'id' => (int) ($_POST['id'] ?? 0),
                'pelanggan_id' => (int) ($_POST['pelanggan_id'] ?? 0),
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'tanggal' => (string) ($_POST['tanggal'] ?? date('Y-m-d')),
                'berlaku_sampai' => (string) ($_POST['berlaku_sampai'] ?? date('Y-m-d', strtotime('+7 days'))),
                'diskon' => (float) ($_POST['diskon'] ?? 0),
                'pajak' => (float) ($_POST['pajak'] ?? 0),
                'catatan' => (string) ($_POST['catatan'] ?? ''),
                'product_id' => $_POST['product_id'] ?? [],
                'item_name' => $_POST['item_name'] ?? [],
                'kategori_tipe' => $_POST['kategori_tipe'] ?? [],
                'satuan' => $_POST['satuan'] ?? [],
                'qty' => $_POST['qty'] ?? [],
                'lebar' => $_POST['lebar'] ?? [],
                'tinggi' => $_POST['tinggi'] ?? [],
                'harga' => $_POST['harga'] ?? [],
                'finishing_nama' => $_POST['finishing_nama'] ?? [],
                'finishing_biaya' => $_POST['finishing_biaya'] ?? [],
                'item_catatan' => $_POST['item_catatan'] ?? [],
            ]);

            writeAuditLog(
                $saved['is_update'] ? 'penawaran_update' : 'penawaran_create',
                'penawaran',
                'Penawaran ' . $saved['no_penawaran'] . ($saved['is_update'] ? ' diperbarui.' : ' dibuat.'),
                [
                    'entity_id' => (int) $saved['id'],
                    'metadata' => [
                        'no_penawaran' => $saved['no_penawaran'],
                        'total' => (float) $saved['total'],
                        'item_count' => (int) $saved['item_count'],
                    ],
                ]
            );

            $activeEditId = (int) $saved['id'];
            $msg = 'success|Penawaran ' . $saved['no_penawaran'] . ' berhasil disimpan.';
        } elseif ($action === 'set_status') {
            $updated = quotationUpdateStatus($conn, (int) ($_POST['id'] ?? 0), (string) ($_POST['status_to'] ?? 'draft'));
            writeAuditLog(
                'penawaran_status',
                'penawaran',
                'Status penawaran ' . ($updated['no_penawaran'] ?? '') . ' diubah menjadi ' . quotationStatusLabel((string) ($updated['status'] ?? 'draft')) . '.',
                [
                    'entity_id' => (int) ($updated['id'] ?? 0),
                    'metadata' => [
                        'no_penawaran' => $updated['no_penawaran'] ?? '',
                        'status' => $updated['status'] ?? '',
                    ],
                ]
            );
            $msg = 'success|Status penawaran berhasil diperbarui.';
        } elseif ($action === 'convert_quote') {
            $converted = quotationConvertToTransaction($conn, (int) ($_POST['id'] ?? 0), (int) ($_SESSION['user_id'] ?? 0));
            writeAuditLog(
                'penawaran_convert',
                'penawaran',
                'Penawaran ' . $converted['no_penawaran'] . ' dikonversi menjadi transaksi ' . $converted['no_transaksi'] . '.',
                [
                    'entity_id' => (int) $converted['quotation_id'],
                    'metadata' => [
                        'no_penawaran' => $converted['no_penawaran'],
                        'transaksi_id' => (int) $converted['transaksi_id'],
                        'no_transaksi' => $converted['no_transaksi'],
                        'total' => (float) $converted['total'],
                    ],
                ]
            );
            $msg = 'success|Penawaran berhasil dikonversi menjadi transaksi ' . $converted['no_transaksi'] . '.';
        } elseif ($action === 'delete_quote') {
            $deleted = quotationDelete($conn, (int) ($_POST['id'] ?? 0));
            writeAuditLog(
                'penawaran_delete',
                'penawaran',
                'Penawaran ' . ($deleted['no_penawaran'] ?? '') . ' dihapus.',
                [
                    'entity_id' => (int) ($deleted['id'] ?? 0),
                    'metadata' => [
                        'no_penawaran' => $deleted['no_penawaran'] ?? '',
                        'total' => (float) ($deleted['total'] ?? 0),
                    ],
                ]
            );
            if ($activeEditId === (int) ($deleted['id'] ?? 0)) {
                $activeEditId = 0;
            }
            $msg = 'success|Penawaran berhasil dihapus.';
        }

        $conn->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        $msg = 'danger|' . $e->getMessage();
    }
}

$customers = $quotationReady
    ? $conn->query("SELECT id, nama, telepon, email FROM pelanggan ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC)
    : [];
$products = $quotationReady
    ? $conn->query("SELECT p.id, p.nama, p.harga_jual, p.satuan, COALESCE(k.tipe, 'lainnya') AS kategori_tipe FROM produk p LEFT JOIN kategori k ON k.id = p.kategori_id ORDER BY p.nama ASC")->fetch_all(MYSQLI_ASSOC)
    : [];

$allowedFilters = ['', 'draft', 'dikirim', 'disetujui', 'ditolak', 'dikonversi'];
$filter = trim((string) ($_GET['status'] ?? ''));
if (!in_array($filter, $allowedFilters, true)) {
    $filter = '';
}
$search = trim((string) ($_GET['q'] ?? ''));
$quotes = $quotationReady ? quotationPageFetchQuotes($conn, $filter, $search) : [];

$statusTotals = ['draft' => 0, 'dikirim' => 0, 'disetujui' => 0, 'ditolak' => 0, 'dikonversi' => 0];
if ($quotationReady) {
    $statusRows = $conn->query("SELECT status, COUNT(*) AS jumlah FROM penawaran GROUP BY status");
    if ($statusRows) {
        foreach ($statusRows->fetch_all(MYSQLI_ASSOC) as $row) {
            $statusKey = strtolower((string) ($row['status'] ?? ''));
            if (isset($statusTotals[$statusKey])) {
                $statusTotals[$statusKey] = (int) ($row['jumlah'] ?? 0);
            }
        }
    }
}

$editQuote = $activeEditId > 0 ? quotationLoadHeader($conn, $activeEditId) : null;
$editLocked = $editQuote ? !quotationCanBeModified($editQuote) : false;
$editItems = $editQuote ? quotationLoadItems($conn, (int) ($editQuote['id'] ?? 0)) : [];
if (empty($editItems)) {
    $editItems = [[
        'produk_id' => 0,
        'nama_item' => '',
        'kategori_tipe' => 'printing',
        'satuan' => 'pcs',
        'qty' => 1,
        'lebar' => 0,
        'tinggi' => 0,
        'harga' => 0,
        'finishing_nama' => '',
        'finishing_biaya' => 0,
        'subtotal' => 0,
        'catatan' => '',
    ]];
}

$visibleCount = count($quotes);
$visibleTotal = array_sum(array_map(static function (array $quote): float {
    return (float) ($quote['total'] ?? 0);
}, $quotes));
$convertedCount = count(array_filter($quotes, static function (array $quote): bool {
    return strtolower((string) ($quote['status'] ?? '')) === 'dikonversi';
}));
$followUpCount = count(array_filter($quotes, static function (array $quote): bool {
    return in_array(strtolower((string) ($quote['status'] ?? '')), ['draft', 'dikirim'], true);
}));

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/penawaran.css') . '">';
$pageJs = 'penawaran.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<?php if ($editLocked): ?>
    <div class="alert alert-info">
        Penawaran ini sudah dikonversi menjadi transaksi
        <?php if (!empty($editQuote['no_transaksi_konversi'])): ?>
            <strong><?= htmlspecialchars((string) $editQuote['no_transaksi_konversi']) ?></strong>
        <?php endif; ?>
        sehingga form ditampilkan sebagai referensi baca-saja.
    </div>
<?php endif; ?>

<div class="page-stack admin-panel quotation-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-file-signature"></i> Penawaran</div>
                <h1 class="page-title">Buat estimasi harga lalu ubah ke transaksi tanpa input ulang</h1>
                <p class="page-description">
                    Menu penawaran disiapkan untuk fase sebelum invoice, jadi tim bisa menyusun item, harga, dan catatan kerja lalu mengonversinya menjadi transaksi ketika customer sudah setuju.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-list-ol"></i> <?= number_format($visibleCount) ?> penawaran tampil</span>
                    <span class="page-meta-item"><i class="fas fa-arrow-right-arrow-left"></i> <?= number_format($convertedCount) ?> sudah dikonversi</span>
                    <span class="page-meta-item"><i class="fas fa-users"></i> <?= number_format(count($customers)) ?> pelanggan tersedia</span>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('transaksi.php') ?>" class="btn btn-primary"><i class="fas fa-receipt"></i> Lihat Transaksi</a>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip compact-metric-strip">
        <div class="metric-card">
            <span class="metric-label">Penawaran Aktif</span>
            <span class="metric-value"><?= number_format($visibleCount) ?></span>
            <span class="metric-note">Menyesuaikan filter daftar penawaran saat ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Nilai Penawaran</span>
            <span class="metric-value">Rp <?= number_format($visibleTotal, 0, ',', '.') ?></span>
            <span class="metric-note">Total nominal dari daftar penawaran yang sedang tampil.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Butuh Follow-up</span>
            <span class="metric-value"><?= number_format($followUpCount) ?></span>
            <span class="metric-note">Masih berstatus draft atau sudah dikirim tetapi belum dikonversi.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Sudah Jadi Order</span>
            <span class="metric-value"><?= number_format($convertedCount) ?></span>
            <span class="metric-note">Penawaran yang sudah berubah menjadi transaksi nyata.</span>
        </div>
    </div>

    <?php if (!$quotationReady): ?>
        <div class="info-banner warning">Fitur penawaran belum siap dipakai karena tabel database tidak dapat dibuat.</div>
    <?php else: ?>
        <details class="card quotation-form-card mobile-collapse-panel"<?= ($editQuote || empty($quotes)) ? ' open' : '' ?>>
            <summary>
                <span class="mobile-collapse-label">
                    <strong><?= $editQuote ? 'Edit Penawaran' : 'Buat Penawaran Baru' ?></strong>
                    <span><?= $editQuote ? 'Perbarui item tanpa memenuhi layar mobile.' : 'Buka form saat perlu membuat penawaran baru.' ?></span>
                </span>
            </summary>
            <div class="mobile-collapse-body">
                <div class="card-header">
                    <div>
                        <span class="card-title"><i class="fas fa-pen-to-square"></i> <?= $editQuote ? 'Edit Penawaran' : 'Buat Penawaran Baru' ?></span>
                        <div class="card-subtitle">
                            <?= $editQuote ? 'Perbarui item, harga, dan catatan sebelum customer menyetujui.' : 'Susun item penawaran lalu simpan sebagai draft sebelum dikirim ke customer.' ?>
                        </div>
                    </div>
                    <?php if ($editQuote): ?>
                        <a href="<?= pageUrl('penawaran.php') ?>" class="btn btn-outline btn-sm"><i class="fas fa-plus"></i> Penawaran Baru</a>
                    <?php endif; ?>
                </div>

                <form method="POST" class="quotation-form">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="save_quote">
                    <input type="hidden" name="id" value="<?= (int) ($editQuote['id'] ?? 0) ?>">
                    <fieldset <?= $editLocked ? 'disabled' : '' ?>>

                        <div class="quotation-form-grid">
                            <div class="form-group">
                                <label class="form-label">Pelanggan</label>
                                <select name="pelanggan_id" class="form-control">
                                    <option value="0">Umum / belum ditentukan</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= (int) $customer['id'] ?>" <?= (int) ($editQuote['pelanggan_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $customer['nama']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Tanggal Penawaran</label>
                                <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars((string) ($editQuote['tanggal'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Berlaku Sampai</label>
                                <input type="date" name="berlaku_sampai" class="form-control" value="<?= htmlspecialchars((string) ($editQuote['berlaku_sampai'] ?? date('Y-m-d', strtotime('+7 days'))), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Diskon</label>
                                <input type="number" min="0" step="0.01" name="diskon" id="quotationDiscount" class="form-control quotation-summary-input" value="<?= htmlspecialchars((string) ((float) ($editQuote['diskon'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Pajak</label>
                                <input type="number" min="0" step="0.01" name="pajak" id="quotationTax" class="form-control quotation-summary-input" value="<?= htmlspecialchars((string) ((float) ($editQuote['pajak'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Catatan Penawaran</label>
                            <textarea name="catatan" class="form-control" rows="3" placeholder="Catatan umum, syarat harga, estimasi pengerjaan, atau keterangan tambahan."><?= htmlspecialchars((string) ($editQuote['catatan'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <div class="quotation-items-panel">
                            <div class="quotation-items-header">
                                <div>
                                    <h2>Item Penawaran</h2>
                                    <p>Pilih produk yang sudah ada atau isi item custom secara manual.</p>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm" id="addQuotationRow"><i class="fas fa-plus"></i> Tambah Item</button>
                            </div>

                            <div id="quotationItems">
                                <?php foreach ($editItems as $index => $item): ?>
                                    <?php quotationPageRenderItemRow($index, $item, $products); ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="quotation-summary-card">
                                <div class="quotation-summary-line">
                                    <span>Subtotal Item</span>
                                    <strong id="quotationSubtotalDisplay">Rp 0</strong>
                                </div>
                                <div class="quotation-summary-line">
                                    <span>Diskon</span>
                                    <strong id="quotationDiscountDisplay">Rp 0</strong>
                                </div>
                                <div class="quotation-summary-line">
                                    <span>Pajak</span>
                                    <strong id="quotationTaxDisplay">Rp 0</strong>
                                </div>
                                <div class="quotation-summary-line total">
                                    <span>Total Penawaran</span>
                                    <strong id="quotationGrandTotalDisplay">Rp 0</strong>
                                </div>
                            </div>
                        </div>

                        <div class="admin-inline-actions">
                            <?php if (!$editLocked): ?>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editQuote ? 'Perbarui Penawaran' : 'Simpan Penawaran' ?></button>
                            <?php endif; ?>
                            <?php if ($editQuote): ?>
                                <a href="<?= pageUrl('penawaran_cetak.php?id=' . (int) $editQuote['id']) ?>" target="_blank" class="btn btn-secondary"><i class="fas fa-print"></i> Cetak Penawaran</a>
                            <?php endif; ?>
                            <?php if ($editLocked && !empty($editQuote['converted_transaksi_id'])): ?>
                                <a href="<?= pageUrl('transaksi_detail.php?id=' . (int) $editQuote['converted_transaksi_id']) ?>" class="btn btn-primary"><i class="fas fa-arrow-right"></i> Buka Transaksi</a>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                </form>
            </div>
        </details>

        <template id="quotationItemTemplate">
            <?php quotationPageRenderItemRow(0, [
                'produk_id' => 0,
                'nama_item' => '',
                'kategori_tipe' => 'printing',
                'satuan' => 'pcs',
                'qty' => 1,
                'lebar' => 0,
                'tinggi' => 0,
                'harga' => 0,
                'finishing_nama' => '',
                'finishing_biaya' => 0,
                'subtotal' => 0,
                'catatan' => '',
            ], $products); ?>
        </template>

        <details class="toolbar-surface admin-filter-grid mobile-collapse-panel compact-toolbar-panel">
            <summary>
                <span class="mobile-collapse-label">
                    <strong>Daftar Penawaran</strong>
                    <span><?= number_format($visibleCount) ?> penawaran - <?= htmlspecialchars($filter !== '' ? quotationStatusLabel($filter) : 'Semua status') ?></span>
                </span>
            </summary>
            <div class="mobile-collapse-body">
                <div class="section-heading">
                    <div>
                        <h2>Daftar Penawaran</h2>
                        <p>Saring berdasarkan status lalu cari nomor penawaran, pelanggan, atau nomor transaksi hasil konversi.</p>
                    </div>
                </div>
                <div class="filter-pills">
                    <a href="?status=&q=<?= urlencode($search) ?>" class="filter-pill <?= $filter === '' ? 'active' : '' ?>"><span>Semua</span></a>
                    <?php foreach ($statusTotals as $statusKey => $statusTotal): ?>
                        <a href="?status=<?= urlencode($statusKey) ?>&q=<?= urlencode($search) ?>" class="filter-pill <?= $filter === $statusKey ? 'active' : '' ?>">
                            <span><?= htmlspecialchars(quotationStatusLabel($statusKey)) ?></span>
                            <span class="filter-pill-count"><?= number_format($statusTotal) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <form method="GET" class="search-bar quotation-search-form">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" name="q" class="form-control" placeholder="Cari nomor penawaran, pelanggan, atau transaksi..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Cari</button>
                </form>
            </div>
        </details>

        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-table"></i> Riwayat Penawaran</span>
                    <div class="card-subtitle">Kelola status, cetak, dan konversi penawaran yang sudah dibuat.</div>
                </div>
            </div>
            <?php if (empty($quotes)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-lines"></i>
                    <div>Belum ada penawaran yang cocok dengan filter saat ini.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive table-desktop">
                    <table>
                        <thead>
                            <tr>
                                <th>No Penawaran</th>
                                <th>Tanggal</th>
                                <th>Pelanggan</th>
                                <th>Item</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Konversi</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quotes as $quote): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) ($quote['no_penawaran'] ?? '')) ?></strong><br>
                                        <span class="text-muted small">Valid s/d <?= htmlspecialchars((string) ($quote['berlaku_sampai'] ?? '-')) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($quote['tanggal'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars((string) ($quote['nama_pelanggan'] ?? 'Umum')) ?></td>
                                    <td><?= number_format((int) ($quote['total_item'] ?? 0)) ?></td>
                                    <td>Rp <?= number_format((float) ($quote['total'] ?? 0), 0, ',', '.') ?></td>
                                    <td><span class="badge <?= quotationStatusBadgeClass((string) ($quote['status'] ?? 'draft')) ?>"><?= htmlspecialchars(quotationStatusLabel((string) ($quote['status'] ?? 'draft'))) ?></span></td>
                                    <td>
                                        <?php if (!empty($quote['no_transaksi_konversi'])): ?>
                                            <a href="<?= pageUrl('transaksi_detail.php?id=' . (int) ($quote['converted_transaksi_id'] ?? 0)) ?>" class="code-pill"><?= htmlspecialchars((string) $quote['no_transaksi_konversi']) ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">Belum</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="quotation-action-stack">
                                            <div class="btn-group">
                                                <?php if (quotationCanBeModified($quote)): ?>
                                                    <a href="<?= pageUrl('penawaran.php?edit=' . (int) ($quote['id'] ?? 0) . '&status=' . urlencode($filter) . '&q=' . urlencode($search)) ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                                                <?php endif; ?>
                                                <a href="<?= pageUrl('penawaran_cetak.php?id=' . (int) ($quote['id'] ?? 0)) ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i></a>
                                            </div>
                                            <?php if (quotationCanBeConverted($quote)): ?>
                                                <form method="POST" onsubmit="return confirm('Konversi penawaran ini menjadi transaksi?');">
                                                    <?= csrfInput() ?>
                                                    <input type="hidden" name="action" value="convert_quote">
                                                    <input type="hidden" name="id" value="<?= (int) ($quote['id'] ?? 0) ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-arrow-right"></i> Konversi</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (quotationCanBeModified($quote)): ?>
                                                <form method="POST" class="quotation-status-form">
                                                    <?= csrfInput() ?>
                                                    <input type="hidden" name="action" value="set_status">
                                                    <input type="hidden" name="id" value="<?= (int) ($quote['id'] ?? 0) ?>">
                                                    <select name="status_to" class="form-control form-control-sm">
                                                        <option value="draft" <?= ($quote['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                        <option value="dikirim" <?= ($quote['status'] ?? '') === 'dikirim' ? 'selected' : '' ?>>Dikirim</option>
                                                        <option value="disetujui" <?= ($quote['status'] ?? '') === 'disetujui' ? 'selected' : '' ?>>Disetujui</option>
                                                        <option value="ditolak" <?= ($quote['status'] ?? '') === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-outline btn-sm">Ubah</button>
                                                </form>
                                                <form method="POST" onsubmit="confirmDelete(this);return false;">
                                                    <?= csrfInput() ?>
                                                    <input type="hidden" name="action" value="delete_quote">
                                                    <input type="hidden" name="id" value="<?= (int) ($quote['id'] ?? 0) ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-data-list quotation-mobile-list">
                    <?php foreach ($quotes as $quote): ?>
                        <?php
                        $quoteStatus = (string) ($quote['status'] ?? 'draft');
                        $quoteCanModify = quotationCanBeModified($quote);
                        $quoteCanConvert = quotationCanBeConverted($quote);
                        $convertedTransaksiId = (int) ($quote['converted_transaksi_id'] ?? 0);
                        ?>
                        <div class="mobile-data-card quotation-mobile-card">
                            <div class="mobile-data-top">
                                <div>
                                    <div class="mobile-data-title"><?= htmlspecialchars((string) ($quote['no_penawaran'] ?? '')) ?></div>
                                    <div class="mobile-data-subtitle">
                                        <?= htmlspecialchars((string) ($quote['nama_pelanggan'] ?? 'Umum')) ?> | <?= htmlspecialchars((string) ($quote['tanggal'] ?? '-')) ?>
                                    </div>
                                </div>
                                <span class="badge <?= quotationStatusBadgeClass($quoteStatus) ?>"><?= htmlspecialchars(quotationStatusLabel($quoteStatus)) ?></span>
                            </div>
                            <div class="mobile-data-grid">
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Item</span>
                                    <span class="mobile-data-value"><?= number_format((int) ($quote['total_item'] ?? 0)) ?> item</span>
                                </div>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Total</span>
                                    <span class="mobile-data-value">Rp <?= number_format((float) ($quote['total'] ?? 0), 0, ',', '.') ?></span>
                                </div>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Valid Sampai</span>
                                    <span class="mobile-data-value"><?= htmlspecialchars((string) ($quote['berlaku_sampai'] ?? '-')) ?></span>
                                </div>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Konversi</span>
                                    <span class="mobile-data-value">
                                        <?php if (!empty($quote['no_transaksi_konversi']) && $convertedTransaksiId > 0): ?>
                                            <a href="<?= pageUrl('transaksi_detail.php?id=' . $convertedTransaksiId) ?>" class="code-pill"><?= htmlspecialchars((string) $quote['no_transaksi_konversi']) ?></a>
                                        <?php else: ?>
                                            Belum dikonversi
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="mobile-data-actions">
                                <?php if ($quoteCanModify): ?>
                                    <a href="<?= pageUrl('penawaran.php?edit=' . (int) ($quote['id'] ?? 0) . '&status=' . urlencode($filter) . '&q=' . urlencode($search)) ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                <?php endif; ?>
                                <a href="<?= pageUrl('penawaran_cetak.php?id=' . (int) ($quote['id'] ?? 0)) ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Cetak</a>
                                <?php if ($quoteCanConvert): ?>
                                    <form method="POST" onsubmit="return confirm('Konversi penawaran ini menjadi transaksi?');">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="convert_quote">
                                        <input type="hidden" name="id" value="<?= (int) ($quote['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-arrow-right"></i> Konversi</button>
                                    </form>
                                <?php elseif (!empty($quote['no_transaksi_konversi']) && $convertedTransaksiId > 0): ?>
                                    <a href="<?= pageUrl('transaksi_detail.php?id=' . $convertedTransaksiId) ?>" class="btn btn-primary btn-sm"><i class="fas fa-arrow-right"></i> Buka</a>
                                <?php endif; ?>
                            </div>
                            <?php if ($quoteCanModify): ?>
                                <details class="quotation-mobile-tools">
                                    <summary>Ubah status / hapus</summary>
                                    <div class="quotation-mobile-tools-body">
                                        <form method="POST" class="quotation-status-form">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="id" value="<?= (int) ($quote['id'] ?? 0) ?>">
                                            <select name="status_to" class="form-control form-control-sm">
                                                <option value="draft" <?= $quoteStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                <option value="dikirim" <?= $quoteStatus === 'dikirim' ? 'selected' : '' ?>>Dikirim</option>
                                                <option value="disetujui" <?= $quoteStatus === 'disetujui' ? 'selected' : '' ?>>Disetujui</option>
                                                <option value="ditolak" <?= $quoteStatus === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                                            </select>
                                            <button type="submit" class="btn btn-outline btn-sm">Ubah</button>
                                        </form>
                                        <form method="POST" onsubmit="confirmDelete(this);return false;">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="delete_quote">
                                            <input type="hidden" name="id" value="<?= (int) ($quote['id'] ?? 0) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Hapus</button>
                                        </form>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
