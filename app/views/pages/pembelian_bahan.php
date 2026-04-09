<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service');
$pageTitle = 'Purchasing Bahan';

$bulan = (string) ($_GET['bulan'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}

$departemen = materialInventoryNormalizeDepartment((string) ($_GET['departemen'] ?? 'printing'));
$monthLabel = date('F Y', strtotime($bulan . '-01'));

function purchasingFormatMoney(float $amount, bool $signed = false): string
{
    if ($signed) {
        return ($amount < 0 ? '-Rp ' : 'Rp ') . number_format(abs($amount), 0, ',', '.');
    }

    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function purchasingFormatQty(float $qty): string
{
    return number_format($qty, 3, ',', '.');
}

function purchasingDepartmentLabel(string $departemen): string
{
    return $departemen === 'apparel' ? 'Apparel' : 'Printing';
}

function purchasingDepartmentIcon(string $departemen): string
{
    return $departemen === 'apparel' ? 'fa-shirt' : 'fa-print';
}

function purchasingMutationBadge(string $tipe): string
{
    return [
        'pembelian' => 'badge-success',
        'penyesuaian' => 'badge-warning',
        'saldo_awal' => 'badge-info',
        'pemakaian_job' => 'badge-danger',
    ][$tipe] ?? 'badge-secondary';
}

function purchasingPaymentStatusBadge(string $status): string
{
    return [
        'lunas' => 'badge-success',
        'parsial' => 'badge-warning',
        'belum_lunas' => 'badge-danger',
    ][$status] ?? 'badge-secondary';
}

function purchasingBuildOverview(string $departemen): array
{
    return [
        'departemen' => $departemen,
        'material_count' => 0,
        'stock_value' => 0,
        'low_stock' => 0,
        'purchase_count' => 0,
        'purchase_total' => 0,
        'qty_in' => 0,
        'qty_out' => 0,
        'supplier_count' => 0,
        'payable_total' => 0,
        'overdue_count' => 0,
        'due_soon_count' => 0,
    ];
}

$tableReady = materialInventorySupportReady($conn);
$msg = '';

if (isset($_POST['action']) && !$tableReady) {
    $tableReady = materialInventoryEnsureSupportTables($conn);
}

if ($tableReady && isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_supplier' || $action === 'update_supplier') {
            $deptValue = materialInventoryNormalizeDepartment((string) ($_POST['departemen'] ?? $departemen));
            $supplierId = materialInventoryUpsertSupplier($conn, [
                'id' => $action === 'update_supplier' ? (int) ($_POST['supplier_id'] ?? 0) : 0,
                'nama' => (string) ($_POST['nama'] ?? ''),
                'departemen' => $deptValue,
                'telepon' => (string) ($_POST['telepon'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'alamat' => (string) ($_POST['alamat'] ?? ''),
                'catatan' => (string) ($_POST['catatan_supplier'] ?? ''),
                'status' => (string) ($_POST['status_supplier'] ?? 'aktif'),
                'created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ]);

            $msg = $action === 'update_supplier'
                ? 'success|Supplier bahan berhasil diperbarui.'
                : 'success|Supplier bahan berhasil ditambahkan dengan ID #' . $supplierId . '.';
            $departemen = $deptValue;
        } elseif ($action === 'save_purchase') {
            $deptValue = materialInventoryNormalizeDepartment((string) ($_POST['departemen'] ?? $departemen));
            $purchaseItems = materialInventorySanitizePurchaseItems($conn, $_POST['purchase_items_json'] ?? '[]', $deptValue);
            $purchaseId = materialInventoryCreatePurchase($conn, [
                'tanggal' => (string) ($_POST['tanggal'] ?? date('Y-m-d')),
                'departemen' => $deptValue,
                'supplier_id' => (int) ($_POST['supplier_id'] ?? 0),
                'supplier_nama' => (string) ($_POST['supplier_nama'] ?? ''),
                'metode_pembayaran' => (string) ($_POST['metode_pembayaran'] ?? 'tunai'),
                'jatuh_tempo' => (string) ($_POST['jatuh_tempo'] ?? ''),
                'referensi_nota' => (string) ($_POST['referensi_nota'] ?? ''),
                'ongkir' => (float) ($_POST['ongkir'] ?? 0),
                'diskon' => (float) ($_POST['diskon'] ?? 0),
                'initial_payment' => (float) ($_POST['initial_payment'] ?? 0),
                'catatan' => (string) ($_POST['catatan'] ?? ''),
                'created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ], $purchaseItems);

            $msg = 'success|Pembelian bahan berhasil disimpan dengan ID #' . $purchaseId . '.';
            $departemen = $deptValue;
        } elseif ($action === 'save_purchase_payment') {
            $deptValue = materialInventoryNormalizeDepartment((string) ($_POST['departemen'] ?? $departemen));
            $conn->begin_transaction();
            try {
                $paymentId = materialInventoryRegisterPurchasePayment($conn, [
                    'pembelian_id' => (int) ($_POST['pembelian_id'] ?? 0),
                    'tanggal' => (string) ($_POST['tanggal_bayar'] ?? date('Y-m-d')),
                    'nominal' => (float) ($_POST['nominal_bayar'] ?? 0),
                    'metode' => (string) ($_POST['metode_bayar'] ?? 'transfer'),
                    'referensi' => (string) ($_POST['referensi_bayar'] ?? ''),
                    'catatan' => (string) ($_POST['catatan_bayar'] ?? ''),
                    'created_by' => (int) ($_SESSION['user_id'] ?? 0),
                ]);
                $conn->commit();
                $msg = 'success|Pembayaran supplier berhasil disimpan dengan ID #' . $paymentId . '.';
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            $departemen = $deptValue;
        } elseif ($action === 'adjust_stock') {
            $deptValue = materialInventoryNormalizeDepartment((string) ($_POST['departemen'] ?? $departemen));
            materialInventoryApplyAdjustment($conn, [
                'stok_bahan_id' => (int) ($_POST['stok_bahan_id'] ?? 0),
                'departemen' => $deptValue,
                'arah' => (string) ($_POST['arah'] ?? 'masuk'),
                'qty' => (float) ($_POST['qty'] ?? 0),
                'harga_satuan' => (float) ($_POST['harga_satuan'] ?? 0),
                'keterangan' => (string) ($_POST['keterangan'] ?? ''),
                'created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ]);

            $msg = 'success|Penyesuaian stok bahan berhasil dicatat.';
            $departemen = $deptValue;
        }
    } catch (Throwable $e) {
        $msg = 'danger|' . $e->getMessage();
    }
}

$materialCatalog = materialInventoryLoadCatalog($conn);
$suppliers = materialInventoryLoadSuppliers($conn, $departemen);
$overviewByDepartment = [
    'printing' => purchasingBuildOverview('printing'),
    'apparel' => purchasingBuildOverview('apparel'),
];

foreach ($materialCatalog as $deptKey => $items) {
    foreach ($items as $item) {
        $stock = (float) ($item['stok'] ?? 0);
        $minimum = (float) ($item['stok_minimum'] ?? 0);
        $overviewByDepartment[$deptKey]['material_count']++;
        $overviewByDepartment[$deptKey]['stock_value'] += $stock * (float) ($item['harga_beli'] ?? 0);
        if ($minimum > 0 && $stock <= $minimum) {
            $overviewByDepartment[$deptKey]['low_stock']++;
        }
    }
}

foreach (['printing', 'apparel'] as $deptKey) {
    $overviewByDepartment[$deptKey]['supplier_count'] = count(materialInventoryLoadSuppliers($conn, $deptKey, true));
}

if ($tableReady) {
    $stmtPurchaseSummary = $conn->prepare(
        "SELECT departemen, COUNT(*) AS total_data, COALESCE(SUM(grand_total), 0) AS total_nominal
         FROM pembelian_bahan
         WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?
         GROUP BY departemen"
    );
    if ($stmtPurchaseSummary) {
        $stmtPurchaseSummary->bind_param('s', $bulan);
        $stmtPurchaseSummary->execute();
        $rows = $stmtPurchaseSummary->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtPurchaseSummary->close();

        foreach ($rows as $row) {
            $deptKey = materialInventoryNormalizeDepartment((string) ($row['departemen'] ?? 'printing'));
            $overviewByDepartment[$deptKey]['purchase_count'] = (int) ($row['total_data'] ?? 0);
            $overviewByDepartment[$deptKey]['purchase_total'] = (float) ($row['total_nominal'] ?? 0);
        }
    }

    $resultPayableSummary = $conn->query(
        "SELECT
            departemen,
            COALESCE(SUM(sisa_tagihan), 0) AS total_tagihan,
            SUM(CASE WHEN status_pembayaran <> 'lunas' AND jatuh_tempo IS NOT NULL AND jatuh_tempo < CURDATE() THEN 1 ELSE 0 END) AS total_overdue,
            SUM(CASE WHEN status_pembayaran <> 'lunas' AND jatuh_tempo IS NOT NULL AND jatuh_tempo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS total_due_soon
         FROM pembelian_bahan
         GROUP BY departemen"
    );
    if ($resultPayableSummary) {
        while ($row = $resultPayableSummary->fetch_assoc()) {
            $deptKey = materialInventoryNormalizeDepartment((string) ($row['departemen'] ?? 'printing'));
            $overviewByDepartment[$deptKey]['payable_total'] = (float) ($row['total_tagihan'] ?? 0);
            $overviewByDepartment[$deptKey]['overdue_count'] = (int) ($row['total_overdue'] ?? 0);
            $overviewByDepartment[$deptKey]['due_soon_count'] = (int) ($row['total_due_soon'] ?? 0);
        }
    }

    $stmtMutationSummary = $conn->prepare(
        "SELECT departemen, arah, COALESCE(SUM(qty), 0) AS total_qty
         FROM stok_bahan_mutasi
         WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
         GROUP BY departemen, arah"
    );
    if ($stmtMutationSummary) {
        $stmtMutationSummary->bind_param('s', $bulan);
        $stmtMutationSummary->execute();
        $rows = $stmtMutationSummary->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtMutationSummary->close();

        foreach ($rows as $row) {
            $deptKey = materialInventoryNormalizeDepartment((string) ($row['departemen'] ?? 'printing'));
            if (($row['arah'] ?? '') === 'keluar') {
                $overviewByDepartment[$deptKey]['qty_out'] = (float) ($row['total_qty'] ?? 0);
            } else {
                $overviewByDepartment[$deptKey]['qty_in'] = (float) ($row['total_qty'] ?? 0);
            }
        }
    }
}

$purchaseRows = [];
$purchaseItemsById = [];
$purchasePaymentsById = [];
$payableRows = [];
$mutationRows = [];

if ($tableReady) {
    $stmtPurchases = $conn->prepare(
        "SELECT
            pb.*,
            ms.nama AS nama_supplier_master,
            u.nama AS created_by_name,
            COUNT(pbi.id) AS item_count,
            COALESCE(SUM(pbi.qty), 0) AS total_qty
         FROM pembelian_bahan pb
         LEFT JOIN pembelian_bahan_item pbi ON pbi.pembelian_id = pb.id
         LEFT JOIN material_suppliers ms ON ms.id = pb.supplier_id
         LEFT JOIN users u ON u.id = pb.created_by
         WHERE pb.departemen = ?
           AND DATE_FORMAT(pb.tanggal, '%Y-%m') = ?
         GROUP BY pb.id
         ORDER BY pb.tanggal DESC, pb.id DESC"
    );
    if ($stmtPurchases) {
        $stmtPurchases->bind_param('ss', $departemen, $bulan);
        $stmtPurchases->execute();
        $purchaseRows = $stmtPurchases->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtPurchases->close();
    }

    if (!empty($purchaseRows)) {
        $purchaseIds = implode(',', array_map(static fn(array $row): int => (int) $row['id'], $purchaseRows));
        $resultItems = $conn->query(
            "SELECT pembelian_id, stok_bahan_id, nama_bahan, satuan, qty, harga_beli, subtotal
             FROM pembelian_bahan_item
             WHERE pembelian_id IN ({$purchaseIds})
             ORDER BY pembelian_id ASC, id ASC"
        );
        if ($resultItems) {
            while ($itemRow = $resultItems->fetch_assoc()) {
                $purchaseId = (int) ($itemRow['pembelian_id'] ?? 0);
                $purchaseItemsById[$purchaseId][] = [
                    'stok_bahan_id' => (int) ($itemRow['stok_bahan_id'] ?? 0),
                    'nama_bahan' => (string) ($itemRow['nama_bahan'] ?? ''),
                    'satuan' => (string) ($itemRow['satuan'] ?? ''),
                    'qty' => (float) ($itemRow['qty'] ?? 0),
                    'harga_beli' => (float) ($itemRow['harga_beli'] ?? 0),
                    'subtotal' => (float) ($itemRow['subtotal'] ?? 0),
                ];
            }
        }

        $resultPayments = $conn->query(
            "SELECT pembelian_id, tanggal, nominal, metode, referensi, catatan
             FROM pembelian_bahan_pembayaran
             WHERE pembelian_id IN ({$purchaseIds})
             ORDER BY tanggal ASC, id ASC"
        );
        if ($resultPayments) {
            while ($paymentRow = $resultPayments->fetch_assoc()) {
                $purchaseId = (int) ($paymentRow['pembelian_id'] ?? 0);
                $purchasePaymentsById[$purchaseId][] = [
                    'tanggal' => (string) ($paymentRow['tanggal'] ?? ''),
                    'nominal' => (float) ($paymentRow['nominal'] ?? 0),
                    'metode' => (string) ($paymentRow['metode'] ?? ''),
                    'referensi' => (string) ($paymentRow['referensi'] ?? ''),
                    'catatan' => (string) ($paymentRow['catatan'] ?? ''),
                ];
            }
        }
    }

    $stmtPayables = $conn->prepare(
        "SELECT
            pb.*,
            ms.nama AS nama_supplier_master,
            DATEDIFF(pb.jatuh_tempo, CURDATE()) AS sisa_hari
         FROM pembelian_bahan pb
         LEFT JOIN material_suppliers ms ON ms.id = pb.supplier_id
         WHERE pb.departemen = ?
           AND pb.status_pembayaran <> 'lunas'
         ORDER BY
            CASE WHEN pb.jatuh_tempo IS NULL THEN 2 WHEN pb.jatuh_tempo < CURDATE() THEN 0 ELSE 1 END,
            pb.jatuh_tempo ASC,
            pb.tanggal ASC"
    );
    if ($stmtPayables) {
        $stmtPayables->bind_param('s', $departemen);
        $stmtPayables->execute();
        $payableRows = $stmtPayables->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtPayables->close();
    }

    $stmtMutations = $conn->prepare(
        "SELECT
            sbm.*,
            sb.kode AS kode_bahan,
            u.nama AS created_by_name
         FROM stok_bahan_mutasi sbm
         LEFT JOIN stok_bahan sb ON sb.id = sbm.stok_bahan_id
         LEFT JOIN users u ON u.id = sbm.created_by
         WHERE sbm.departemen = ?
           AND DATE_FORMAT(sbm.created_at, '%Y-%m') = ?
         ORDER BY sbm.created_at DESC, sbm.id DESC
         LIMIT 200"
    );
    if ($stmtMutations) {
        $stmtMutations->bind_param('ss', $departemen, $bulan);
        $stmtMutations->execute();
        $mutationRows = $stmtMutations->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtMutations->close();
    }
}

$activeOverview = $overviewByDepartment[$departemen] ?? purchasingBuildOverview($departemen);
$activeMaterials = $materialCatalog[$departemen] ?? [];
$activeSuppliers = $suppliers;
$lowStockRows = array_values(array_filter($activeMaterials, static function (array $item): bool {
    $minimum = (float) ($item['stok_minimum'] ?? 0);
    $stok = (float) ($item['stok'] ?? 0);
    return $minimum > 0 && $stok <= $minimum;
}));
usort($lowStockRows, static function (array $left, array $right): int {
    return ((float) ($left['stok'] ?? 0) <=> (float) ($right['stok'] ?? 0));
});
$lowStockRows = array_slice($lowStockRows, 0, 8);

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">';
$pageState = [
    'purchasingState' => [
        'activeDepartment' => $departemen,
        'materialsByDepartment' => $materialCatalog,
        'suppliersByDepartment' => [
            'printing' => materialInventoryLoadSuppliers($conn, 'printing'),
            'apparel' => materialInventoryLoadSuppliers($conn, 'apparel'),
        ],
    ],
];
$pageJs = 'pembelian_bahan.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>
<?php if (!$tableReady): ?>
    <div class="alert alert-danger">Tabel dasar inventori bahan belum siap. Pastikan `stok_bahan` tersedia agar modul purchasing bisa berjalan.</div>
<?php endif; ?>

<div class="page-stack admin-panel purchasing-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-cart-flatbed"></i> Purchasing & Mutasi Bahan</div>
                <h1 class="page-title">Pembelian bahan baku dan pergerakan stok dipisah per departemen agar printing dan apparel tetap rapi</h1>
                <p class="page-description">
                    Halaman ini menangani pembelian bahan, penyesuaian stok masuk-keluar, dan riwayat mutasi bahan baku. Semua transaksi dibatasi per departemen supaya pembukuan stok tidak tercampur.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas <?= purchasingDepartmentIcon($departemen) ?>"></i> Fokus: <?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?></span>
                    <span class="page-meta-item"><i class="fas fa-calendar"></i> <?= htmlspecialchars($monthLabel) ?></span>
                    <span class="page-meta-item"><i class="fas fa-boxes-stacked"></i> <?= number_format((int) ($activeOverview['material_count'] ?? 0)) ?> bahan aktif</span>
                </div>
            </div>
            <div class="page-actions">
                <?php if ($tableReady): ?>
                    <button type="button" class="btn btn-primary" onclick="openPurchaseModal()"><i class="fas fa-plus"></i> Input Pembelian</button>
                    <button type="button" class="btn btn-secondary" onclick="openSupplierModal()"><i class="fas fa-truck-field"></i> Supplier</button>
                    <button type="button" class="btn btn-secondary" onclick="openAdjustmentModal()"><i class="fas fa-sliders"></i> Penyesuaian Stok</button>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="metric-strip compact-metric-strip">
        <?php foreach (['printing', 'apparel'] as $deptKey): ?>
            <?php $summary = $overviewByDepartment[$deptKey] ?? purchasingBuildOverview($deptKey); ?>
            <div class="metric-card">
                <span class="metric-label"><?= htmlspecialchars(purchasingDepartmentLabel($deptKey)) ?></span>
                <span class="metric-value"><?= purchasingFormatMoney((float) ($summary['purchase_total'] ?? 0)) ?></span>
                <span class="metric-note">
                    <?= number_format((int) ($summary['purchase_count'] ?? 0)) ?> pembelian bulan ini, stok aktif <?= number_format((int) ($summary['material_count'] ?? 0)) ?> bahan.
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <details class="toolbar-surface admin-filter-grid mobile-collapse-panel compact-toolbar-panel"<?= (!$tableReady || $departemen !== 'printing') ? ' open' : '' ?>>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Filter Purchasing</strong>
                <span><?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?> - <?= htmlspecialchars($monthLabel) ?></span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
            <div class="section-heading">
                <div>
                    <h2>Filter Purchasing</h2>
                    <p>Ganti departemen atau bulan untuk membaca histori pembelian dan mutasi bahan secara terpisah.</p>
                </div>
            </div>
            <div class="admin-toolbar">
                <form method="GET" class="admin-inline-actions toolbar-inline-form" style="flex:1 1 420px;">
                    <input type="hidden" name="departemen" value="<?= htmlspecialchars($departemen) ?>">
                    <input type="month" name="bulan" value="<?= htmlspecialchars($bulan) ?>" class="form-control" style="min-width:180px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                </form>
                <div class="report-meta-chip">
                    <i class="fas fa-triangle-exclamation"></i>
                    <?= number_format(count($lowStockRows)) ?> stok kritis di departemen ini
                </div>
            </div>
            <div class="filter-pills">
                <?php foreach (['printing', 'apparel'] as $deptKey): ?>
                    <?php $summary = $overviewByDepartment[$deptKey] ?? purchasingBuildOverview($deptKey); ?>
                    <a href="?departemen=<?= urlencode($deptKey) ?>&bulan=<?= urlencode($bulan) ?>" class="filter-pill <?= $departemen === $deptKey ? 'active' : '' ?>">
                        <span><i class="fas <?= purchasingDepartmentIcon($deptKey) ?>"></i> <?= htmlspecialchars(purchasingDepartmentLabel($deptKey)) ?></span>
                        <span class="filter-pill-count"><?= number_format((int) ($summary['material_count'] ?? 0)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <div class="metric-strip compact-metric-strip">
        <div class="metric-card">
            <span class="metric-label">Nilai Stok Aktif</span>
            <span class="metric-value"><?= purchasingFormatMoney((float) ($activeOverview['stock_value'] ?? 0)) ?></span>
            <span class="metric-note">Akumulasi stok berjalan dikali harga beli terakhir dari bahan departemen aktif.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Mutasi Masuk</span>
            <span class="metric-value"><?= purchasingFormatQty((float) ($activeOverview['qty_in'] ?? 0)) ?></span>
            <span class="metric-note">Termasuk pembelian dan penyesuaian stok masuk selama periode ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Mutasi Keluar</span>
            <span class="metric-value"><?= purchasingFormatQty((float) ($activeOverview['qty_out'] ?? 0)) ?></span>
            <span class="metric-note">Termasuk pemakaian job dan penyesuaian stok keluar selama periode ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Low Stock</span>
            <span class="metric-value"><?= number_format((int) ($activeOverview['low_stock'] ?? 0)) ?></span>
            <span class="metric-note">Bahan yang sudah menyentuh batas minimum stok pada departemen ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Hutang Supplier</span>
            <span class="metric-value"><?= purchasingFormatMoney((float) ($activeOverview['payable_total'] ?? 0)) ?></span>
            <span class="metric-note"><?= number_format((int) ($activeOverview['overdue_count'] ?? 0)) ?> lewat jatuh tempo, <?= number_format((int) ($activeOverview['due_soon_count'] ?? 0)) ?> jatuh tempo 7 hari ke depan.</span>
        </div>
    </div>

    <details class="toolbar-surface mobile-collapse-panel compact-toolbar-panel purchasing-insight-panel">
        <summary>
            <span class="mobile-collapse-label">
                <strong>Insight Stok &amp; Alur</strong>
                <span><?= number_format(count($lowStockRows)) ?> stok kritis - <?= number_format((int) ($activeOverview['material_count'] ?? 0)) ?> bahan aktif</span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
            <div class="panel-grid-3">
        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-triangle-exclamation"></i> Stok Kritis</span>
                    <div class="card-subtitle">Prioritas pembelian ulang untuk departemen <?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?>.</div>
                </div>
            </div>
            <?php if (!empty($lowStockRows)): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th>Stok</th>
                                <th>Min.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lowStockRows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string) ($row['nama'] ?? '-')) ?></strong>
                                    <div class="text-muted small"><?= htmlspecialchars((string) ($row['kode'] ?? '')) ?></div>
                                </td>
                                <td><span class="badge badge-danger"><?= purchasingFormatQty((float) ($row['stok'] ?? 0)) ?> <?= htmlspecialchars((string) ($row['satuan'] ?? '')) ?></span></td>
                                <td><?= purchasingFormatQty((float) ($row['stok_minimum'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-circle-check"></i>
                    <div>Belum ada bahan yang menyentuh batas minimum stok.</div>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-layer-group"></i> Snapshot Bahan</span>
                    <div class="card-subtitle">Melihat stok berjalan, harga beli terakhir, dan nilai stok per bahan.</div>
                </div>
            </div>
            <?php if (!empty($activeMaterials)): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th>Stok</th>
                                <th>Harga</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($activeMaterials, 0, 8) as $row): ?>
                            <?php $low = (float) ($row['stok_minimum'] ?? 0) > 0 && (float) ($row['stok'] ?? 0) <= (float) ($row['stok_minimum'] ?? 0); ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string) ($row['nama'] ?? '-')) ?></strong>
                                    <div class="text-muted small"><?= htmlspecialchars((string) ($row['kode'] ?? '')) ?></div>
                                </td>
                                <td><span class="badge <?= $low ? 'badge-danger' : 'badge-success' ?>"><?= purchasingFormatQty((float) ($row['stok'] ?? 0)) ?> <?= htmlspecialchars((string) ($row['satuan'] ?? '')) ?></span></td>
                                <td><?= purchasingFormatMoney((float) ($row['harga_beli'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <div>Belum ada master bahan di departemen ini.</div>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-route"></i> Alur Stok</span>
                    <div class="card-subtitle">Urutan kerja yang sekarang sudah tersambung di modul bahan baku.</div>
                </div>
            </div>
            <div style="padding: 0 18px 18px;">
                <div class="mobile-note">1. Input pembelian bahan dari supplier sesuai departemen.</div>
                <div class="mobile-note">2. Stok bahan otomatis bertambah dan harga beli terakhir ikut diperbarui.</div>
                <div class="mobile-note">3. Pemakaian bahan dari job costing akan mengurangi stok dan masuk ke mutasi keluar.</div>
                <div class="mobile-note">4. Penyesuaian manual tetap dicatat agar stok fisik dan sistem bisa direkonsiliasi.</div>
            </div>
        </div>
            </div>
        </div>
    </details>

    <details class="card mobile-collapse-panel"<?= empty($activeSuppliers) ? ' open' : '' ?>>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Supplier Bahan</strong>
                <span><?= number_format(count($activeSuppliers)) ?> supplier - <?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?></span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-truck-field"></i> Supplier Bahan <?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?></span>
                    <div class="card-subtitle">Master supplier dipisah per departemen agar pembelian bahan dan hutang vendor tetap terstruktur.</div>
                </div>
                <?php if ($tableReady): ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openSupplierModal()"><i class="fas fa-plus"></i> Tambah Supplier</button>
                <?php endif; ?>
            </div>

            <?php if (!empty($activeSuppliers)): ?>
                <div class="table-responsive table-desktop">
                    <table id="tblSuppliers">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Supplier</th>
                                <th>Kontak</th>
                                <th>Status</th>
                                <th>Catatan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeSuppliers as $index => $supplier): ?>
                            <tr class="supplier-row">
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string) ($supplier['nama'] ?? '-')) ?></strong>
                                    <div class="text-muted small"><?= htmlspecialchars((string) ($supplier['alamat'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string) (($supplier['telepon'] ?? '') !== '' ? $supplier['telepon'] : '-')) ?>
                                    <div class="text-muted small"><?= htmlspecialchars((string) (($supplier['email'] ?? '') !== '' ? $supplier['email'] : '-')) ?></div>
                                </td>
                                <td><span class="badge <?= ($supplier['status'] ?? 'aktif') === 'aktif' ? 'badge-success' : 'badge-secondary' ?>"><?= htmlspecialchars((string) ($supplier['status'] ?? 'aktif')) ?></span></td>
                                <td><?= htmlspecialchars((string) (($supplier['catatan'] ?? '') !== '' ? $supplier['catatan'] : '-')) ?></td>
                                <td>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick='openSupplierModal(<?= json_encode($supplier, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen-to-square"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-data-list">
                    <?php foreach ($activeSuppliers as $supplier): ?>
                        <div class="mobile-data-card">
                            <div class="mobile-data-top">
                                <div>
                                    <div class="mobile-data-title"><?= htmlspecialchars((string) ($supplier['nama'] ?? '-')) ?></div>
                                    <div class="mobile-data-subtitle"><?= htmlspecialchars((string) (($supplier['telepon'] ?? '') !== '' ? $supplier['telepon'] : '-')) ?></div>
                                </div>
                                <span class="badge <?= ($supplier['status'] ?? 'aktif') === 'aktif' ? 'badge-success' : 'badge-secondary' ?>"><?= htmlspecialchars((string) ($supplier['status'] ?? 'aktif')) ?></span>
                            </div>
                            <div class="mobile-note"><?= htmlspecialchars((string) (($supplier['alamat'] ?? '') !== '' ? $supplier['alamat'] : (($supplier['catatan'] ?? '') !== '' ? $supplier['catatan'] : 'Belum ada catatan supplier.'))) ?></div>
                            <div class="mobile-data-actions">
                                <button type="button" class="btn btn-secondary btn-sm" onclick='openSupplierModal(<?= json_encode($supplier, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen-to-square"></i> Edit</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-truck-ramp-box"></i>
                    <div>Belum ada supplier bahan untuk departemen ini.</div>
                </div>
            <?php endif; ?>
        </div>
    </details>

    <details class="card mobile-collapse-panel"<?= ((int) ($activeOverview['overdue_count'] ?? 0)) > 0 ? ' open' : '' ?>>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Hutang Pembelian</strong>
                <span><?= number_format(count($payableRows)) ?> dokumen - <?= purchasingFormatMoney((float) ($activeOverview['payable_total'] ?? 0)) ?></span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-file-invoice-dollar"></i> Hutang Pembelian & Jatuh Tempo</span>
                    <div class="card-subtitle">Pantau supplier yang belum lunas, sisa tagihan, dan jatuh tempo pembelian bahan.</div>
                </div>
            </div>

            <?php if (!empty($payableRows)): ?>
                <div class="table-responsive table-desktop">
                    <table id="tblPayables">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>No. Pembelian</th>
                                <th>Supplier</th>
                                <th>Metode</th>
                                <th>Jatuh Tempo</th>
                                <th>Sisa Tagihan</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payableRows as $index => $row): ?>
                            <?php
                            $supplierDisplay = (string) (($row['nama_supplier_master'] ?? '') !== '' ? $row['nama_supplier_master'] : (($row['supplier_nama'] ?? '') !== '' ? $row['supplier_nama'] : 'Supplier umum'));
                            $payments = $purchasePaymentsById[(int) ($row['id'] ?? 0)] ?? [];
                            $payablePayload = [
                                'id' => (int) ($row['id'] ?? 0),
                                'no_pembelian' => (string) ($row['no_pembelian'] ?? ''),
                                'supplier' => $supplierDisplay,
                                'grand_total' => (float) ($row['grand_total'] ?? 0),
                                'dibayar_total' => (float) ($row['dibayar_total'] ?? 0),
                                'sisa_tagihan' => (float) ($row['sisa_tagihan'] ?? 0),
                                'jatuh_tempo' => (string) ($row['jatuh_tempo'] ?? ''),
                                'status_pembayaran' => (string) ($row['status_pembayaran'] ?? ''),
                                'payments' => $payments,
                            ];
                            $sisaHari = isset($row['sisa_hari']) ? (int) $row['sisa_hari'] : null;
                            $tempoBadge = $sisaHari === null ? 'badge-secondary' : ($sisaHari < 0 ? 'badge-danger' : ($sisaHari <= 7 ? 'badge-warning' : 'badge-info'));
                            $tempoLabel = $sisaHari === null ? 'Tanpa tempo' : ($sisaHari < 0 ? 'Lewat ' . abs($sisaHari) . ' hari' : ($sisaHari === 0 ? 'Hari ini' : $sisaHari . ' hari lagi'));
                            ?>
                            <tr class="payable-row">
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string) ($row['no_pembelian'] ?? '-')) ?></strong>
                                    <div class="text-muted small"><?= htmlspecialchars((string) ($row['tanggal'] ?? '-')) ?></div>
                                </td>
                                <td><?= htmlspecialchars($supplierDisplay) ?></td>
                                <td><?= htmlspecialchars((string) ($row['metode_pembayaran'] ?? '-')) ?></td>
                                <td>
                                    <?= htmlspecialchars((string) (($row['jatuh_tempo'] ?? '') !== '' ? $row['jatuh_tempo'] : '-')) ?>
                                    <div class="text-muted small"><span class="badge <?= $tempoBadge ?>"><?= htmlspecialchars($tempoLabel) ?></span></div>
                                </td>
                                <td><?= purchasingFormatMoney((float) ($row['sisa_tagihan'] ?? 0)) ?></td>
                                <td><span class="badge <?= purchasingPaymentStatusBadge((string) ($row['status_pembayaran'] ?? '')) ?>"><?= htmlspecialchars((string) ($row['status_pembayaran'] ?? '-')) ?></span></td>
                                <td>
                                    <button type="button" class="btn btn-primary btn-sm" onclick='openPaymentModal(<?= json_encode($payablePayload, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-money-bill-wave"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-data-list">
                    <?php foreach ($payableRows as $row): ?>
                        <?php
                        $supplierDisplay = (string) (($row['nama_supplier_master'] ?? '') !== '' ? $row['nama_supplier_master'] : (($row['supplier_nama'] ?? '') !== '' ? $row['supplier_nama'] : 'Supplier umum'));
                        $payments = $purchasePaymentsById[(int) ($row['id'] ?? 0)] ?? [];
                        $payablePayload = [
                            'id' => (int) ($row['id'] ?? 0),
                            'no_pembelian' => (string) ($row['no_pembelian'] ?? ''),
                            'supplier' => $supplierDisplay,
                            'grand_total' => (float) ($row['grand_total'] ?? 0),
                            'dibayar_total' => (float) ($row['dibayar_total'] ?? 0),
                            'sisa_tagihan' => (float) ($row['sisa_tagihan'] ?? 0),
                            'jatuh_tempo' => (string) ($row['jatuh_tempo'] ?? ''),
                            'status_pembayaran' => (string) ($row['status_pembayaran'] ?? ''),
                            'payments' => $payments,
                        ];
                        ?>
                        <div class="mobile-data-card">
                            <div class="mobile-data-top">
                                <div>
                                    <div class="mobile-data-title"><?= htmlspecialchars((string) ($row['no_pembelian'] ?? '-')) ?></div>
                                    <div class="mobile-data-subtitle"><?= htmlspecialchars($supplierDisplay) ?></div>
                                </div>
                                <span class="badge <?= purchasingPaymentStatusBadge((string) ($row['status_pembayaran'] ?? '')) ?>"><?= htmlspecialchars((string) ($row['status_pembayaran'] ?? '-')) ?></span>
                            </div>
                            <div class="mobile-data-grid">
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Sisa Tagihan</span>
                                    <span class="mobile-data-value"><?= purchasingFormatMoney((float) ($row['sisa_tagihan'] ?? 0)) ?></span>
                                </div>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Jatuh Tempo</span>
                                    <span class="mobile-data-value"><?= htmlspecialchars((string) (($row['jatuh_tempo'] ?? '') !== '' ? $row['jatuh_tempo'] : '-')) ?></span>
                                </div>
                            </div>
                            <div class="mobile-data-actions">
                                <button type="button" class="btn btn-primary btn-sm" onclick='openPaymentModal(<?= json_encode($payablePayload, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-money-bill-wave"></i> Bayar</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-circle-check"></i>
                    <div>Tidak ada hutang pembelian aktif pada departemen ini.</div>
                </div>
            <?php endif; ?>
        </div>
    </details>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-cart-flatbed"></i> Pembelian Bahan <?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?></span>
                <div class="card-subtitle">Pembelian masuk pada periode ini. Satu dokumen bisa berisi banyak item bahan dalam departemen yang sama.</div>
            </div>
        </div>

        <div class="admin-toolbar" style="margin-bottom:18px;">
            <div class="search-bar">
                <input type="text" id="srchPurchasing" class="form-control" placeholder="Cari nomor pembelian, supplier, referensi nota, atau nama bahan..." oninput="filterPurchasingView()">
            </div>
            <?php if ($tableReady): ?>
                <div class="admin-toolbar-actions">
                    <button type="button" class="btn btn-primary" onclick="openPurchaseModal()"><i class="fas fa-plus"></i> Input Pembelian</button>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($purchaseRows)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblPurchasing">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>No. Pembelian</th>
                            <th>Tanggal</th>
                            <th>Supplier</th>
                            <th>Item</th>
                            <th>Total Qty</th>
                            <th>Grand Total</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($purchaseRows as $index => $row): ?>
                        <?php
                        $items = $purchaseItemsById[(int) ($row['id'] ?? 0)] ?? [];
                        $payments = $purchasePaymentsById[(int) ($row['id'] ?? 0)] ?? [];
                        $supplierDisplay = (string) (($row['nama_supplier_master'] ?? '') !== '' ? $row['nama_supplier_master'] : (($row['supplier_nama'] ?? '') !== '' ? $row['supplier_nama'] : 'Supplier umum'));
                        $payload = [
                            'id' => (int) ($row['id'] ?? 0),
                            'no_pembelian' => (string) ($row['no_pembelian'] ?? ''),
                            'tanggal' => (string) ($row['tanggal'] ?? ''),
                            'supplier_nama' => $supplierDisplay,
                            'referensi_nota' => (string) ($row['referensi_nota'] ?? ''),
                            'metode_pembayaran' => (string) ($row['metode_pembayaran'] ?? 'tunai'),
                            'jatuh_tempo' => (string) ($row['jatuh_tempo'] ?? ''),
                            'dibayar_total' => (float) ($row['dibayar_total'] ?? 0),
                            'sisa_tagihan' => (float) ($row['sisa_tagihan'] ?? 0),
                            'status_pembayaran' => (string) ($row['status_pembayaran'] ?? 'lunas'),
                            'subtotal' => (float) ($row['subtotal'] ?? 0),
                            'ongkir' => (float) ($row['ongkir'] ?? 0),
                            'diskon' => (float) ($row['diskon'] ?? 0),
                            'grand_total' => (float) ($row['grand_total'] ?? 0),
                            'catatan' => (string) ($row['catatan'] ?? ''),
                            'items' => $items,
                            'payments' => $payments,
                        ];
                        ?>
                        <tr class="purchase-row">
                            <td><?= $index + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars((string) ($row['no_pembelian'] ?? '-')) ?></strong>
                                <div class="text-muted small"><?= htmlspecialchars((string) ($row['referensi_nota'] ?? '-')) ?></div>
                            </td>
                            <td><?= htmlspecialchars((string) ($row['tanggal'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($supplierDisplay) ?></td>
                            <td>
                                <span class="badge badge-secondary"><?= number_format((int) ($row['item_count'] ?? 0)) ?> item</span>
                                <div class="text-muted small"><?= !empty($items) ? htmlspecialchars((string) ($items[0]['nama_bahan'] ?? '-')) . (count($items) > 1 ? ' +' . (count($items) - 1) . ' lainnya' : '') : '-' ?></div>
                            </td>
                            <td><?= purchasingFormatQty((float) ($row['total_qty'] ?? 0)) ?></td>
                            <td>
                                <?= purchasingFormatMoney((float) ($row['grand_total'] ?? 0)) ?>
                                <div class="text-muted small"><span class="badge <?= purchasingPaymentStatusBadge((string) ($row['status_pembayaran'] ?? '')) ?>"><?= htmlspecialchars((string) ($row['status_pembayaran'] ?? 'lunas')) ?></span></div>
                            </td>
                            <td>
                                <button type="button" class="btn btn-secondary btn-sm" onclick='openPurchaseDetail(<?= json_encode($payload, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobilePurchasingList">
                <?php foreach ($purchaseRows as $row): ?>
                    <?php
                    $items = $purchaseItemsById[(int) ($row['id'] ?? 0)] ?? [];
                    $payments = $purchasePaymentsById[(int) ($row['id'] ?? 0)] ?? [];
                    $supplierDisplay = (string) (($row['nama_supplier_master'] ?? '') !== '' ? $row['nama_supplier_master'] : (($row['supplier_nama'] ?? '') !== '' ? $row['supplier_nama'] : 'Supplier umum'));
                    $payload = [
                        'id' => (int) ($row['id'] ?? 0),
                        'no_pembelian' => (string) ($row['no_pembelian'] ?? ''),
                        'tanggal' => (string) ($row['tanggal'] ?? ''),
                        'supplier_nama' => $supplierDisplay,
                        'referensi_nota' => (string) ($row['referensi_nota'] ?? ''),
                        'metode_pembayaran' => (string) ($row['metode_pembayaran'] ?? 'tunai'),
                        'jatuh_tempo' => (string) ($row['jatuh_tempo'] ?? ''),
                        'dibayar_total' => (float) ($row['dibayar_total'] ?? 0),
                        'sisa_tagihan' => (float) ($row['sisa_tagihan'] ?? 0),
                        'status_pembayaran' => (string) ($row['status_pembayaran'] ?? 'lunas'),
                        'subtotal' => (float) ($row['subtotal'] ?? 0),
                        'ongkir' => (float) ($row['ongkir'] ?? 0),
                        'diskon' => (float) ($row['diskon'] ?? 0),
                        'grand_total' => (float) ($row['grand_total'] ?? 0),
                        'catatan' => (string) ($row['catatan'] ?? ''),
                        'items' => $items,
                        'payments' => $payments,
                    ];
                    ?>
                    <div class="mobile-data-card purchase-mobile-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars((string) ($row['no_pembelian'] ?? '-')) ?></div>
                                <div class="mobile-data-subtitle"><?= htmlspecialchars((string) ($row['tanggal'] ?? '-')) ?></div>
                            </div>
                            <span class="badge badge-secondary"><?= number_format((int) ($row['item_count'] ?? 0)) ?> item</span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Supplier</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($supplierDisplay) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Total Qty</span>
                                <span class="mobile-data-value"><?= purchasingFormatQty((float) ($row['total_qty'] ?? 0)) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Grand Total</span>
                                <span class="mobile-data-value"><?= purchasingFormatMoney((float) ($row['grand_total'] ?? 0)) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Status Bayar</span>
                                <span class="mobile-data-value"><?= htmlspecialchars((string) ($row['status_pembayaran'] ?? 'lunas')) ?></span>
                            </div>
                        </div>
                        <div class="mobile-note"><?= !empty($items) ? htmlspecialchars((string) ($items[0]['nama_bahan'] ?? '-')) . (count($items) > 1 ? ' dan ' . (count($items) - 1) . ' bahan lain.' : '.') : 'Belum ada detail bahan.' ?></div>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-secondary btn-sm" onclick='openPurchaseDetail(<?= json_encode($payload, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-eye"></i> Detail</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-cart-flatbed-empty"></i>
                <div>Belum ada pembelian bahan <?= strtolower(purchasingDepartmentLabel($departemen)) ?> pada periode ini.</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-right-left"></i> Mutasi Stok <?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?></span>
                <div class="card-subtitle">Semua stok masuk dan keluar dicatat di sini, termasuk pembelian, penyesuaian, dan pemakaian job.</div>
            </div>
        </div>

        <div class="admin-toolbar" style="margin-bottom:18px;">
            <div class="search-bar">
                <input type="text" id="srchMutation" class="form-control" placeholder="Cari bahan, tipe mutasi, referensi, atau keterangan..." oninput="filterMutationView()">
            </div>
            <?php if ($tableReady): ?>
                <div class="admin-toolbar-actions">
                    <button type="button" class="btn btn-secondary" onclick="openAdjustmentModal()"><i class="fas fa-sliders"></i> Penyesuaian Stok</button>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($mutationRows)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblMutation">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Waktu</th>
                            <th>Bahan</th>
                            <th>Tipe</th>
                            <th>Arah</th>
                            <th>Qty</th>
                            <th>Stok</th>
                            <th>Nilai</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mutationRows as $index => $row): ?>
                        <tr class="mutation-row">
                            <td><?= $index + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars((string) ($row['created_at'] ?? '-')) ?></strong>
                                <div class="text-muted small"><?= htmlspecialchars((string) ($row['created_by_name'] ?? 'Sistem')) ?></div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars((string) ($row['nama_bahan'] ?? '-')) ?></strong>
                                <div class="text-muted small"><?= htmlspecialchars((string) ($row['kode_bahan'] ?? '')) ?></div>
                            </td>
                            <td><span class="badge <?= purchasingMutationBadge((string) ($row['tipe'] ?? '')) ?>"><?= htmlspecialchars((string) ($row['tipe'] ?? '-')) ?></span></td>
                            <td><span class="badge <?= ($row['arah'] ?? '') === 'keluar' ? 'badge-danger' : 'badge-success' ?>"><?= htmlspecialchars((string) ($row['arah'] ?? '-')) ?></span></td>
                            <td><?= purchasingFormatQty((float) ($row['qty'] ?? 0)) ?></td>
                            <td><?= purchasingFormatQty((float) ($row['stok_sebelum'] ?? 0)) ?> -> <?= purchasingFormatQty((float) ($row['stok_sesudah'] ?? 0)) ?></td>
                            <td><?= purchasingFormatMoney((float) ($row['total_nilai'] ?? 0)) ?></td>
                            <td><?= htmlspecialchars((string) (($row['keterangan'] ?? '') !== '' ? $row['keterangan'] : '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileMutationList">
                <?php foreach ($mutationRows as $row): ?>
                    <div class="mobile-data-card mutation-mobile-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars((string) ($row['nama_bahan'] ?? '-')) ?></div>
                                <div class="mobile-data-subtitle"><?= htmlspecialchars((string) ($row['created_at'] ?? '-')) ?></div>
                            </div>
                            <span class="badge <?= ($row['arah'] ?? '') === 'keluar' ? 'badge-danger' : 'badge-success' ?>"><?= htmlspecialchars((string) ($row['arah'] ?? '-')) ?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Tipe</span>
                                <span class="mobile-data-value"><?= htmlspecialchars((string) ($row['tipe'] ?? '-')) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Qty</span>
                                <span class="mobile-data-value"><?= purchasingFormatQty((float) ($row['qty'] ?? 0)) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Stok</span>
                                <span class="mobile-data-value"><?= purchasingFormatQty((float) ($row['stok_sebelum'] ?? 0)) ?> -> <?= purchasingFormatQty((float) ($row['stok_sesudah'] ?? 0)) ?></span>
                            </div>
                        </div>
                        <div class="mobile-note"><?= htmlspecialchars((string) (($row['keterangan'] ?? '') !== '' ? $row['keterangan'] : 'Tidak ada catatan mutasi.')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-arrows-rotate"></i>
                <div>Belum ada mutasi stok bahan pada periode ini.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalPembelianBahan">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <h5>Input Pembelian Bahan</h5>
            <button class="modal-close" onclick="closeModal('modalPembelianBahan')">&times;</button>
        </div>
        <form method="POST" id="purchaseForm">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="save_purchase">
            <input type="hidden" name="departemen" value="<?= htmlspecialchars($departemen) ?>">
            <input type="hidden" name="purchase_items_json" id="purchaseItemsJson" value="[]">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Departemen</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(purchasingDepartmentLabel($departemen)) ?>" readonly style="background:var(--bg)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="tanggal" id="purchaseDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier Terdaftar</label>
                        <select name="supplier_id" id="purchaseSupplierSelect" class="form-control" onchange="syncSelectedSupplier()"></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nama Supplier</label>
                        <input type="text" name="supplier_nama" id="purchaseSupplier" class="form-control" placeholder="Bisa dikosongkan jika memakai supplier umum">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Referensi Nota</label>
                        <input type="text" name="referensi_nota" id="purchaseNota" class="form-control" placeholder="Nomor invoice supplier">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metode Pembayaran</label>
                        <select name="metode_pembayaran" id="purchasePaymentMethod" class="form-control" onchange="togglePurchasePaymentFields()">
                            <option value="tunai">Tunai / Langsung Lunas</option>
                            <option value="tempo">Tempo / Hutang Supplier</option>
                        </select>
                    </div>
                </div>
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-boxes-stacked"></i> Item Pembelian</span>
                            <div class="card-subtitle">Pilih bahan dari departemen aktif, isi qty, lalu sistem menghitung subtotal otomatis.</div>
                        </div>
                    </div>
                    <div style="padding:0 18px 18px;">
                        <div class="admin-toolbar" style="margin-bottom:12px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addPurchaseItemRow()"><i class="fas fa-plus"></i> Tambah Bahan</button>
                            <div class="report-meta-chip">
                                <i class="fas fa-receipt"></i>
                                <span id="purchaseItemCount">0 item</span> | subtotal <span id="purchaseSubtotal">Rp 0</span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="purchaseItemTable">
                                <thead>
                                    <tr>
                                        <th>Bahan</th>
                                        <th>Qty</th>
                                        <th>Satuan</th>
                                        <th>Harga Beli</th>
                                        <th>Subtotal</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="purchaseItemRows"></tbody>
                            </table>
                        </div>
                        <div class="empty-state" id="purchaseItemEmpty" style="display:none; margin-top:12px;">
                            <i class="fas fa-box-open"></i>
                            <div>Belum ada item pembelian. Tambahkan minimal satu bahan baku.</div>
                        </div>
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Subtotal</label>
                        <input type="text" id="purchaseSubtotalPreview" class="form-control" readonly style="background:var(--bg)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ongkir</label>
                        <input type="number" step="0.01" min="0" name="ongkir" id="purchaseOngkir" class="form-control" value="0" oninput="syncPurchaseItems()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Diskon</label>
                        <input type="number" step="0.01" min="0" name="diskon" id="purchaseDiskon" class="form-control" value="0" oninput="syncPurchaseItems()">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Grand Total</label>
                        <input type="text" id="purchaseGrandTotalPreview" class="form-control" readonly style="background:var(--bg)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pembayaran Awal</label>
                        <input type="number" step="0.01" min="0" name="initial_payment" id="purchaseInitialPayment" class="form-control" value="0" oninput="syncPurchaseItems()">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" id="purchaseDueDateGroup" style="display:none;">
                        <label class="form-label">Jatuh Tempo</label>
                        <input type="date" name="jatuh_tempo" id="purchaseDueDate" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catatan</label>
                        <input type="text" name="catatan" id="purchaseCatatan" class="form-control" placeholder="Contoh: beli stok safety untuk order besar minggu depan">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalPembelianBahan')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Pembelian</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalAdjustmentStock">
    <div class="modal-box">
        <div class="modal-header">
            <h5>Penyesuaian Stok Bahan</h5>
            <button class="modal-close" onclick="closeModal('modalAdjustmentStock')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="adjust_stock">
            <input type="hidden" name="departemen" value="<?= htmlspecialchars($departemen) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Bahan Baku</label>
                    <select name="stok_bahan_id" id="adjustMaterialSelect" class="form-control" required></select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Arah Mutasi</label>
                        <select name="arah" class="form-control">
                            <option value="masuk">Masuk</option>
                            <option value="keluar">Keluar</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Qty</label>
                        <input type="number" step="0.001" min="0.001" name="qty" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Harga Satuan</label>
                        <input type="number" step="0.01" min="0" name="harga_satuan" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catatan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Contoh: stok opname, barang rusak, atau retur supplier">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalAdjustmentStock')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Mutasi</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalPurchaseDetail">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <h5>Detail Pembelian Bahan</h5>
            <button class="modal-close" onclick="closeModal('modalPurchaseDetail')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">No. Pembelian</label>
                    <input type="text" id="detailPurchaseNumber" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal</label>
                    <input type="text" id="detailPurchaseDate" class="form-control" readonly style="background:var(--bg)">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <input type="text" id="detailPurchaseSupplier" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Referensi Nota</label>
                    <input type="text" id="detailPurchaseNota" class="form-control" readonly style="background:var(--bg)">
                </div>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Metode Bayar</label>
                    <input type="text" id="detailPurchaseMethod" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Jatuh Tempo</label>
                    <input type="text" id="detailPurchaseDueDate" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Status Bayar</label>
                    <input type="text" id="detailPurchaseStatus" class="form-control" readonly style="background:var(--bg)">
                </div>
            </div>
            <div class="table-responsive" style="margin-bottom:16px;">
                <table>
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th>Qty</th>
                            <th>Harga Beli</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="detailPurchaseItems"></tbody>
                </table>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label">Subtotal</label>
                    <input type="text" id="detailPurchaseSubtotal" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Ongkir</label>
                    <input type="text" id="detailPurchaseOngkir" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Diskon</label>
                    <input type="text" id="detailPurchaseDiskon" class="form-control" readonly style="background:var(--bg)">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Grand Total</label>
                    <input type="text" id="detailPurchaseGrandTotal" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Dibayar / Sisa</label>
                    <input type="text" id="detailPurchasePaidSummary" class="form-control" readonly style="background:var(--bg)">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Catatan</label>
                    <input type="text" id="detailPurchaseCatatan" class="form-control" readonly style="background:var(--bg)">
                </div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Nominal</th>
                            <th>Metode</th>
                            <th>Referensi</th>
                        </tr>
                    </thead>
                    <tbody id="detailPurchasePayments"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalSupplier">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <h5 id="supplierModalTitle">Tambah Supplier Bahan</h5>
            <button class="modal-close" onclick="closeModal('modalSupplier')">&times;</button>
        </div>
        <form method="POST" id="supplierForm">
            <?= csrfInput() ?>
            <input type="hidden" name="action" id="supplierAction" value="save_supplier">
            <input type="hidden" name="supplier_id" id="supplierId" value="0">
            <input type="hidden" name="departemen" value="<?= htmlspecialchars($departemen) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama Supplier</label>
                        <input type="text" name="nama" id="supplierName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status_supplier" id="supplierStatus" class="form-control">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="telepon" id="supplierPhone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="supplierEmail" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <textarea name="alamat" id="supplierAddress" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan_supplier" id="supplierNote" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalSupplier')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Supplier</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalPurchasePayment">
    <div class="modal-box">
        <div class="modal-header">
            <h5>Bayar Hutang Supplier</h5>
            <button class="modal-close" onclick="closeModal('modalPurchasePayment')">&times;</button>
        </div>
        <form method="POST" id="purchasePaymentForm">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="save_purchase_payment">
            <input type="hidden" name="departemen" value="<?= htmlspecialchars($departemen) ?>">
            <input type="hidden" name="pembelian_id" id="paymentPurchaseId" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Pembelian</label>
                    <input type="text" id="paymentPurchaseInfo" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tanggal Bayar</label>
                        <input type="date" name="tanggal_bayar" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metode</label>
                        <select name="metode_bayar" id="paymentMethod" class="form-control">
                            <option value="transfer">Transfer</option>
                            <option value="tunai">Tunai</option>
                            <option value="giro">Giro</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nominal Bayar</label>
                        <input type="number" step="0.01" min="0.01" name="nominal_bayar" id="paymentAmount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Referensi</label>
                        <input type="text" name="referensi_bayar" id="paymentReference" class="form-control" placeholder="Nomor transfer / bukti bayar">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="catatan_bayar" id="paymentNote" class="form-control" placeholder="Contoh: pelunasan invoice supplier">
                </div>
                <div class="metric-strip">
                    <div class="metric-card">
                        <span class="metric-label">Grand Total</span>
                        <span class="metric-value" id="paymentGrandTotal">Rp 0</span>
                        <span class="metric-note">Nilai total pembelian supplier.</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Sisa Tagihan</span>
                        <span class="metric-value" id="paymentRemaining">Rp 0</span>
                        <span class="metric-note">Nominal maksimal pembayaran saat ini.</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalPurchasePayment')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
