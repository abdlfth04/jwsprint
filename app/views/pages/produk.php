<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service');
$pageTitle = 'Produk & Stok Bahan';

function produkFilterKategoriByType(array $kategori, string $type): array
{
    return array_values(array_filter($kategori, static function ($item) use ($type) {
        return ($item['tipe'] ?? '') === $type;
    }));
}

function produkCountLowStock(array $items): int
{
    return count(array_filter($items, static function ($item) {
        return (float) ($item['stok'] ?? 0) <= 5;
    }));
}

function produkDeletionDependencies(mysqli $conn, int $productId): array
{
    return schemaBuildDependencyList($conn, $productId, [
        'detail transaksi' => ['table' => 'detail_transaksi', 'column' => 'produk_id'],
        'penawaran item' => ['table' => 'penawaran_item', 'column' => 'produk_id'],
    ]);
}

function produkMaterialDeletionDependencies(mysqli $conn, int $materialId): array
{
    return schemaBuildDependencyList($conn, $materialId, [
        'item pembelian bahan' => ['table' => 'pembelian_bahan_item', 'column' => 'stok_bahan_id'],
        'mutasi bahan' => ['table' => 'stok_bahan_mutasi', 'column' => 'stok_bahan_id'],
        'pemakaian HPP' => ['table' => 'job_material_usage', 'column' => 'stok_bahan_id'],
    ]);
}

function produkTabUrl(string $tab, string $search = ''): string
{
    $params = ['tab' => $tab];
    if ($search !== '') {
        $params['q'] = $search;
    }

    return pageUrl('produk.php?' . http_build_query($params));
}

$tab = $_GET['tab'] ?? 'printing';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$msg = '';
$hasProdukTable = schemaTableExists($conn, 'produk');
$hasKategoriTable = schemaTableExists($conn, 'kategori');
$hasBahanTable = schemaTableExists($conn, 'stok_bahan');
$hasGrosirTable = schemaTableExists($conn, 'produk_harga_grosir');
$hasKategoriType = $hasKategoriTable && schemaColumnExists($conn, 'kategori', 'tipe');

// CRUD produk
if (isset($_POST['action']) && in_array($_POST['action'], ['tambah','edit','hapus'])) {
    $act = $_POST['action'];
    $conn->begin_transaction();
    try {
        if ($act === 'tambah') {
            $kode=$_POST['kode']; $nama=$_POST['nama']; $katId=intval($_POST['kategori_id']);
            $hb=floatval($_POST['harga_beli']); $hj=floatval($_POST['harga_jual']);
            $stok=intval($_POST['stok']); $satuan=trim($_POST['satuan']); $desk=trim($_POST['deskripsi']);
            $s=$conn->prepare("INSERT INTO produk (kode,nama,kategori_id,harga_beli,harga_jual,stok,satuan,deskripsi) VALUES (?,?,?,?,?,?,?,?)");
            $s->bind_param('ssiiddss',$kode,$nama,$katId,$hb,$hj,$stok,$satuan,$desk);
            $s->execute();
            $newProdId = $conn->insert_id;
            // Handle harga grosir
            if ($hasGrosirTable && isset($_POST['grosir_qty']) && is_array($_POST['grosir_qty'])) {
                $stmtGrosir = $conn->prepare("INSERT INTO produk_harga_grosir (produk_id, min_qty, harga) VALUES (?, ?, ?)");
                if ($stmtGrosir) {
                    foreach ($_POST['grosir_qty'] as $i => $qty) {
                        $qty_val = intval($qty);
                        $harga_val = floatval($_POST['grosir_harga'][$i] ?? 0);
                        if ($qty_val > 0 && $harga_val > 0) {
                            $stmtGrosir->bind_param('iid', $newProdId, $qty_val, $harga_val);
                            $stmtGrosir->execute();
                        }
                    }
                    $stmtGrosir->close();
                }
            }
            $msg='success|Produk ditambahkan.';
        } elseif ($act === 'edit') {
            $id=intval($_POST['id']); $kode=$_POST['kode']; $nama=$_POST['nama']; $katId=intval($_POST['kategori_id']);
            $hb=floatval($_POST['harga_beli']); $hj=floatval($_POST['harga_jual']);
            $stok=intval($_POST['stok']); $satuan=trim($_POST['satuan']); $desk=trim($_POST['deskripsi']);
            $s=$conn->prepare("UPDATE produk SET kode=?,nama=?,kategori_id=?,harga_beli=?,harga_jual=?,stok=?,satuan=?,deskripsi=? WHERE id=?");
            $s->bind_param('ssiiddssi',$kode,$nama,$katId,$hb,$hj,$stok,$satuan,$desk,$id);
            $s->execute();
            // Handle harga grosir: delete old, insert new
            if ($hasGrosirTable) {
                $stmtDeleteGrosir = $conn->prepare("DELETE FROM produk_harga_grosir WHERE produk_id = ?");
                if ($stmtDeleteGrosir) {
                    $stmtDeleteGrosir->bind_param('i', $id);
                    $stmtDeleteGrosir->execute();
                    $stmtDeleteGrosir->close();
                }
            }
            if ($hasGrosirTable && isset($_POST['grosir_qty']) && is_array($_POST['grosir_qty'])) {
                $stmtGrosir = $conn->prepare("INSERT INTO produk_harga_grosir (produk_id, min_qty, harga) VALUES (?, ?, ?)");
                if ($stmtGrosir) {
                    foreach ($_POST['grosir_qty'] as $i => $qty) {
                        $qty_val = intval($qty);
                        $harga_val = floatval($_POST['grosir_harga'][$i] ?? 0);
                        if ($qty_val > 0 && $harga_val > 0) {
                            $stmtGrosir->bind_param('iid', $id, $qty_val, $harga_val);
                            $stmtGrosir->execute();
                        }
                    }
                    $stmtGrosir->close();
                }
            }
            $msg='success|Produk diperbarui.';
        } elseif ($act === 'hapus') {
            $id=intval($_POST['id']);
            $dependencies = produkDeletionDependencies($conn, $id);
            if (!empty($dependencies)) {
                $msg = 'danger|Produk tidak dapat dihapus karena masih dipakai di ' . implode(', ', $dependencies) . '.';
            } else {
                if ($hasGrosirTable) {
                    $stmtDeleteGrosir = $conn->prepare("DELETE FROM produk_harga_grosir WHERE produk_id = ?");
                    if ($stmtDeleteGrosir) {
                        $stmtDeleteGrosir->bind_param('i', $id);
                        $stmtDeleteGrosir->execute();
                        $stmtDeleteGrosir->close();
                    }
                }

                $stmtDeleteProduk = $conn->prepare("DELETE FROM produk WHERE id = ?");
                if ($stmtDeleteProduk) {
                    $stmtDeleteProduk->bind_param('i', $id);
                    $stmtDeleteProduk->execute() ? $msg='success|Dihapus.' : $msg='danger|Gagal menghapus produk.';
                    $stmtDeleteProduk->close();
                } else {
                    $msg = 'danger|Gagal menyiapkan proses hapus produk.';
                }
            }
        }
        
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $msg = 'danger|Terjadi kesalahan: ' . $e->getMessage();
    }
}

// CRUD stok bahan
if (isset($_POST['action']) && in_array($_POST['action'], ['tambah_bahan','edit_bahan','hapus_bahan'])) {
    $act = $_POST['action'];
    $tblOk = $hasBahanTable;
    if ($act === 'tambah_bahan' && $tblOk) {
        $kode=trim($_POST['kode']); $nama=trim($_POST['nama']); $kat=materialInventoryNormalizeDepartment((string) ($_POST['kategori'] ?? 'printing'));
        $satuan=trim($_POST['satuan']); $stok=floatval($_POST['stok']); $min=floatval($_POST['stok_minimum']??0);
        $hb=floatval($_POST['harga_beli']??0); $ket=trim($_POST['keterangan']);
        $conn->begin_transaction();
        try {
            $s=$conn->prepare("INSERT INTO stok_bahan (kode,nama,kategori,satuan,stok,stok_minimum,harga_beli,keterangan) VALUES (?,?,?,?,0,?,?,?)");
            if (!$s) {
                throw new RuntimeException('Form bahan tidak dapat diproses.');
            }
            $s->bind_param('ssssdss',$kode,$nama,$kat,$satuan,$min,$hb,$ket);
            if (!$s->execute()) {
                throw new RuntimeException('Gagal menambahkan bahan.');
            }
            $newMaterialId = (int) $conn->insert_id;
            $s->close();

            if ($stok > 0) {
                materialInventoryRecordMutation($conn, [
                    'stok_bahan_id' => $newMaterialId,
                    'departemen' => $kat,
                    'delta_qty' => $stok,
                    'harga_satuan' => $hb,
                    'update_harga_beli' => true,
                    'tipe' => 'saldo_awal',
                    'referensi_tipe' => 'stok_bahan',
                    'referensi_id' => $newMaterialId,
                    'keterangan' => 'Saldo awal bahan saat dibuat.',
                    'created_by' => (int) ($_SESSION['user_id'] ?? 0),
                ]);
            }

            $conn->commit();
            $msg='success|Bahan ditambahkan.';
        } catch (Throwable $e) {
            $conn->rollback();
            $msg='danger|'.$e->getMessage();
        }
    } elseif ($act === 'edit_bahan' && $tblOk) {
        $id=intval($_POST['id']); $kode=trim($_POST['kode']); $nama=trim($_POST['nama']); $kat=materialInventoryNormalizeDepartment((string) ($_POST['kategori'] ?? 'printing'));
        $satuan=trim($_POST['satuan']); $stok=floatval($_POST['stok']); $min=floatval($_POST['stok_minimum']??0);
        $hb=floatval($_POST['harga_beli']??0); $ket=trim($_POST['keterangan']);
        $conn->begin_transaction();
        try {
            $oldStmt = $conn->prepare("SELECT stok FROM stok_bahan WHERE id=? LIMIT 1 FOR UPDATE");
            if (!$oldStmt) {
                throw new RuntimeException('Data bahan tidak dapat dikunci.');
            }
            $oldStmt->bind_param('i', $id);
            $oldStmt->execute();
            $existing = $oldStmt->get_result()->fetch_assoc();
            $oldStmt->close();

            if (!$existing) {
                throw new RuntimeException('Bahan tidak ditemukan.');
            }

            $oldStock = (float) ($existing['stok'] ?? 0);
            $deltaStock = round($stok - $oldStock, 3);

            $s=$conn->prepare("UPDATE stok_bahan SET kode=?,nama=?,kategori=?,satuan=?,stok_minimum=?,harga_beli=?,keterangan=? WHERE id=?");
            if (!$s) {
                throw new RuntimeException('Form bahan tidak dapat diproses.');
            }
            $s->bind_param('ssssddsi',$kode,$nama,$kat,$satuan,$min,$hb,$ket,$id);
            if (!$s->execute()) {
                throw new RuntimeException('Gagal memperbarui bahan.');
            }
            $s->close();

            if (abs($deltaStock) > 0.000001) {
                materialInventoryRecordMutation($conn, [
                    'stok_bahan_id' => $id,
                    'departemen' => $kat,
                    'delta_qty' => $deltaStock,
                    'harga_satuan' => $hb,
                    'update_harga_beli' => $deltaStock > 0 && $hb > 0,
                    'tipe' => 'penyesuaian',
                    'referensi_tipe' => 'stok_bahan',
                    'referensi_id' => $id,
                    'keterangan' => 'Penyesuaian stok dari master bahan.',
                    'created_by' => (int) ($_SESSION['user_id'] ?? 0),
                ]);
            }

            $conn->commit();
            $msg='success|Bahan diperbarui.';
        } catch (Throwable $e) {
            $conn->rollback();
            $msg='danger|'.$e->getMessage();
        }
    } elseif ($act === 'hapus_bahan' && $tblOk) {
        $id=intval($_POST['id']);
        $dependencies = produkMaterialDeletionDependencies($conn, $id);
        if (!empty($dependencies)) {
            $msg = 'danger|Bahan tidak dapat dihapus karena masih dipakai di ' . implode(', ', $dependencies) . '.';
        } else {
            $stmtDeleteBahan = $conn->prepare("DELETE FROM stok_bahan WHERE id = ?");
            if ($stmtDeleteBahan) {
                $stmtDeleteBahan->bind_param('i', $id);
                $stmtDeleteBahan->execute() ? $msg='success|Dihapus.' : $msg='danger|Gagal menghapus bahan.';
                $stmtDeleteBahan->close();
            } else {
                $msg = 'danger|Gagal menyiapkan proses hapus bahan.';
            }
        }
    }
}

// Data inventori
$kategoriResult = $hasKategoriTable
    ? $conn->query("SELECT * FROM kategori ORDER BY nama")
    : false;
$kategori = $kategoriResult ? $kategoriResult->fetch_all(MYSQLI_ASSOC) : [];
$katPrint = $hasKategoriType ? produkFilterKategoriByType($kategori, 'printing') : $kategori;
$katApp   = $hasKategoriType ? produkFilterKategoriByType($kategori, 'apparel') : [];

$produkPrint = [];
$produkApp = [];
if ($hasProdukTable && $hasKategoriType) {
    $produkPrintResult = $conn->query("SELECT p.*,k.nama as kat_nama FROM produk p LEFT JOIN kategori k ON p.kategori_id=k.id WHERE k.tipe='printing' ORDER BY p.nama");
    $produkAppResult = $conn->query("SELECT p.*,k.nama as kat_nama FROM produk p LEFT JOIN kategori k ON p.kategori_id=k.id WHERE k.tipe='apparel' ORDER BY p.nama");
    $produkPrint = $produkPrintResult ? $produkPrintResult->fetch_all(MYSQLI_ASSOC) : [];
    $produkApp = $produkAppResult ? $produkAppResult->fetch_all(MYSQLI_ASSOC) : [];
} elseif ($hasProdukTable) {
    $produkResult = $conn->query("SELECT p.*,k.nama as kat_nama FROM produk p LEFT JOIN kategori k ON p.kategori_id=k.id ORDER BY p.nama");
    $produkPrint = $produkResult ? $produkResult->fetch_all(MYSQLI_ASSOC) : [];
}

// Fetch all grosir prices at once
$allProdukIds = array_merge(array_column($produkPrint, 'id'), array_column($produkApp, 'id'));
$grosirPrices = [];
if ($hasGrosirTable && !empty($allProdukIds)) {
    $ids = implode(',', array_map('intval', $allProdukIds));
    $resGrosir = $conn->query("SELECT * FROM produk_harga_grosir WHERE produk_id IN ($ids) ORDER BY min_qty ASC");
    if ($resGrosir) {
        while ($row = $resGrosir->fetch_assoc()) {
            $grosirPrices[$row['produk_id']][] = $row;
        }
    }
}

// Inject grosir prices into produk arrays
foreach ($produkPrint as &$p) { $p['grosir_tiers'] = $grosirPrices[$p['id']] ?? []; } unset($p);
foreach ($produkApp as &$p) { $p['grosir_tiers'] = $grosirPrices[$p['id']] ?? []; } unset($p);

$tblBahan = $hasBahanTable;
$bahanPrint = $tblBahan ? schemaFetchAllAssoc($conn, "SELECT * FROM stok_bahan WHERE kategori='printing' ORDER BY nama") : [];
$bahanApp   = $tblBahan ? schemaFetchAllAssoc($conn, "SELECT * FROM stok_bahan WHERE kategori='apparel' ORDER BY nama") : [];

$satuanPrinting = ['m2','pcs','lembar'];
$satuanApparel  = ['pcs'];
$totalProduk = count($produkPrint) + count($produkApp);
$totalBahan = count($bahanPrint) + count($bahanApp);
$lowProduk = produkCountLowStock(array_merge($produkPrint, $produkApp));
$lowBahan = count(array_filter(array_merge($bahanPrint, $bahanApp), static function ($item) {
    $stok = (float) ($item['stok'] ?? 0);
    $minimum = (float) ($item['stok_minimum'] ?? 0);
    return $minimum > 0 && $stok <= $minimum;
}));
$selectedCount = $totalProduk;
switch ($tab) {
    case 'printing':
        $selectedCount = count($produkPrint);
        break;
    case 'apparel':
        $selectedCount = count($produkApp);
        break;
    case 'bahan_printing':
        $selectedCount = count($bahanPrint);
        break;
    case 'bahan_apparel':
        $selectedCount = count($bahanApp);
        break;
}
$selectedLabels = [
    'printing' => 'Produk printing',
    'apparel' => 'Produk apparel',
    'bahan_printing' => 'Bahan printing',
    'bahan_apparel' => 'Bahan apparel',
];
$needsInventoryInfo = !$hasProdukTable
    || !$hasKategoriTable
    || !$hasKategoriType
    || !$hasGrosirTable
    || (!$tblBahan && in_array($tab, ['bahan_printing', 'bahan_apparel'], true));
$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">';
$extraCss .= '<style>
.produk-status-tabs {
    padding-block: 14px;
}

.produk-status-tabs .filter-pills {
    flex-wrap: wrap;
    gap: 10px;
}

.produk-status-tabs .filter-pill {
    min-height: 42px;
    padding: 9px 14px;
    border-radius: 16px;
}

.produk-info-panel .section-heading {
    margin-bottom: 12px;
}

.inventory-search-panel {
    display: grid;
    gap: 14px;
    margin-bottom: 18px;
}

.inventory-search-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.inventory-search-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 10px;
    align-items: center;
}

.inventory-search-field {
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
}

.inventory-search-field i {
    position: absolute;
    left: 14px;
    color: var(--text-muted);
    pointer-events: none;
}

.inventory-search-field .form-control {
    min-width: 0;
    padding-left: 40px;
    padding-right: 42px;
}

.inventory-search-clear {
    position: absolute;
    top: 50%;
    right: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 999px;
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    transform: translateY(-50%);
    transition: var(--transition);
}

.inventory-search-clear:hover {
    color: var(--text);
    background: rgba(255, 255, 255, 0.6);
    border-color: rgba(15, 118, 110, 0.16);
}

.inventory-search-meta {
    color: var(--text-muted);
    font-size: .78rem;
    line-height: 1.5;
}

.inventory-search-meta strong {
    color: var(--text);
}

.inventory-search-empty[hidden] {
    display: none !important;
}

.inventory-search-empty {
    margin-top: 16px;
}

.produk-page .inventory-name-cell,
.produk-page .mobile-data-title,
.produk-page .mobile-data-subtitle,
.produk-page .mobile-data-value,
.produk-page .mobile-note {
    overflow-wrap: anywhere;
}

.produk-page .badge {
    min-height: 30px;
    max-width: 100%;
    padding: 6px 12px;
    line-height: 1.25;
    white-space: normal;
    text-align: center;
}

@media (max-width: 768px) {
    .produk-status-tabs .filter-pills {
        flex-wrap: nowrap;
    }

    .produk-status-tabs .filter-pill {
        min-height: 36px;
        padding: 7px 11px;
        border-radius: 14px;
    }

    .inventory-search-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .inventory-search-field {
        grid-column: 1 / -1;
    }

    .inventory-search-form .btn {
        width: 100%;
    }
}

@media (max-width: 520px) {
    .inventory-search-form {
        grid-template-columns: 1fr;
    }

    .inventory-search-form .btn,
    .inventory-search-form .btn-outline {
        width: 100%;
    }

    .produk-page .badge {
        font-size: .68rem;
        padding: 5px 10px;
    }
}
</style>';
$pageState = [
    'produkState' => [
        'satuanPrinting' => $satuanPrinting,
        'satuanApparel' => $satuanApparel,
    ],
];
$pageJs = 'produk.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
<div class="alert alert-<?=$type?>" data-dismiss="1"><?=htmlspecialchars($text)?></div>
<?php endif; ?>

<?php
$tabs = [
    'printing'       => ['label'=>'Produk Printing',  'icon'=>'fa-print',       'count'=>count($produkPrint)],
    'apparel'        => ['label'=>'Produk Apparel',   'icon'=>'fa-shirt',       'count'=>count($produkApp)],
    'bahan_printing' => ['label'=>'Bahan Printing',   'icon'=>'fa-layer-group', 'count'=>count($bahanPrint)],
    'bahan_apparel'  => ['label'=>'Bahan Apparel',    'icon'=>'fa-scissors',    'count'=>count($bahanApp)],
];
$activeTab = $tabs[$tab] ?? $tabs['printing'];
$isProdukTab = in_array($tab, ['printing', 'apparel'], true);
?>

<div class="page-stack admin-panel produk-page">
    <div class="toolbar-surface admin-shell-header">
        <div class="admin-shell-top">
            <div class="admin-shell-copy">
                <span class="admin-shell-label"><i class="fas fa-boxes-stacked"></i> Produk & Stok</span>
                <h2><?= htmlspecialchars($activeTab['label']) ?></h2>
                <p>Katalog, tier grosir, dan stok tampil lebih ringkas agar tim lebih cepat memindai data.</p>
            </div>
            <div class="admin-shell-actions">
                <?php if ($isProdukTab || $tblBahan): ?>
                    <button type="button" class="btn btn-primary" onclick="openModal('<?= $isProdukTab ? 'modalTambah' : 'modalTambahBahan' ?>')">
                        <i class="fas fa-plus"></i> <?= $isProdukTab ? 'Tambah Produk' : 'Tambah Bahan' ?>
                    </button>
                <?php endif; ?>
                <?php if ($tblBahan): ?>
                    <a href="<?= pageUrl('pembelian_bahan.php') ?>?departemen=<?= strpos($tab, 'apparel') !== false ? 'apparel' : 'printing' ?>" class="btn btn-outline">
                        <i class="fas fa-cart-flatbed"></i> Purchasing Bahan
                    </a>
                <?php endif; ?>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-outline"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
        <div class="admin-shell-pills">
            <span class="code-pill"><strong><?= number_format($selectedCount) ?></strong> data aktif</span>
            <span class="code-pill"><strong><?= number_format($totalProduk) ?></strong> total produk</span>
            <span class="code-pill"><strong><?= number_format($totalBahan) ?></strong> total bahan</span>
            <span class="code-pill"><strong><?= number_format($lowProduk + $lowBahan) ?></strong> perlu perhatian</span>
        </div>
    </div>

    <section class="toolbar-surface produk-status-tabs">
        <div class="filter-pills">
            <?php foreach ($tabs as $key => $t): ?>
                <a href="<?= htmlspecialchars(produkTabUrl($key, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === $key ? 'active' : '' ?>">
                    <span><i class="fas <?= $t['icon'] ?>"></i> <?= htmlspecialchars($t['label']) ?></span>
                    <span class="filter-pill-count"><?= number_format($t['count']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($needsInventoryInfo): ?>
    <details class="toolbar-surface admin-filter-grid mobile-collapse-panel compact-toolbar-panel produk-info-panel" open>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Info Inventori</strong>
                <span>Schema dan dukungan fitur untuk <?= htmlspecialchars($activeTab['label']) ?></span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
            <div class="section-heading">
                <div>
                    <h2>Info Inventori</h2>
                    <p>Tab produk dan bahan tetap bisa dipakai, sementara catatan ini membantu saat schema belum lengkap.</p>
                </div>
            </div>
            <?php if (!$hasProdukTable): ?>
                <div class="info-banner warning">
                    <strong>Tabel <code>produk</code> belum tersedia.</strong> Halaman tetap dibuka, tetapi katalog produk baru akan tampil setelah schema database dilengkapi.
                </div>
            <?php endif; ?>
            <?php if (!$hasKategoriTable): ?>
                <div class="info-banner warning">
                    <strong>Tabel <code>kategori</code> belum tersedia.</strong> Produk tetap dibuka dengan data kosong sampai master kategori tersedia di database.
                </div>
            <?php endif; ?>
            <?php if (!$hasKategoriType): ?>
                <div class="info-banner warning">
                    <strong>Kolom <code>kategori.tipe</code> belum tersedia.</strong> Data produk tetap dibuka, tetapi sementara ditampilkan dalam satu kelompok sampai schema database diperbarui.
                </div>
            <?php endif; ?>
            <?php if (!$hasGrosirTable): ?>
                <div class="info-banner note">
                    Tabel <code>produk_harga_grosir</code> belum tersedia. Halaman tetap berjalan, tetapi fitur tier harga grosir belum aktif.
                </div>
            <?php endif; ?>
            <?php if (!$tblBahan && in_array($tab, ['bahan_printing', 'bahan_apparel'], true)): ?>
                <div class="info-banner warning">
                    <strong>Tabel `stok_bahan` belum tersedia.</strong> Jalankan migration database terlebih dahulu agar modul bahan bisa dipakai penuh.
                </div>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>

<?php if ($tab === 'printing' || $tab === 'apparel'):
    $isPrint  = $tab === 'printing';
    $dataProd = $isPrint ? $produkPrint : $produkApp;
    $katList  = $isPrint ? $katPrint : $katApp;
    $satuanList = $isPrint ? $satuanPrinting : $satuanApparel;
    $tipeLabel  = $isPrint ? 'Printing' : 'Apparel';
?>
    <details class="card mobile-collapse-panel mobile-record-panel product-record-panel" open>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Produk <?= htmlspecialchars($tipeLabel) ?></strong>
                <span><?= number_format(count($dataProd)) ?> item siap dipindai</span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas <?= $isPrint ? 'fa-print' : 'fa-shirt' ?>"></i> Produk <?= $tipeLabel ?></span>
                <div class="card-subtitle">Cek harga jual, tier grosir, stok, dan satuan dari layar yang lebih ringan dibaca.</div>
            </div>
        </div>

        <div class="inventory-search-panel">
            <div class="inventory-search-header">
                <div class="inventory-search-meta" id="inventorySearchSummary">
                    <strong><?= number_format(count($dataProd)) ?></strong> produk pada tab ini
                </div>
            </div>
            <form method="GET" class="inventory-search-form">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                <label class="sr-only" for="inventorySearchInput">Cari produk</label>
                <div class="inventory-search-field">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="inventorySearchInput"
                        class="form-control"
                        name="q"
                        value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Cari kode, nama produk, kategori, atau satuan..."
                        data-inventory-search-input
                        data-inventory-type="produk"
                        data-inventory-label="produk"
                    >
                    <button
                        type="button"
                        class="inventory-search-clear"
                        data-inventory-search-clear
                        aria-label="Kosongkan pencarian"
                        <?= $searchQuery === '' ? 'hidden' : '' ?>
                    >
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                <a href="<?= htmlspecialchars(produkTabUrl($tab), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline">Reset</a>
            </form>
            <div class="inventory-search-meta">
                Pencarian langsung menyaring daftar pada tab ini. Tombol <strong>Terapkan</strong> akan menyimpan kata kunci ke URL supaya tetap aktif saat halaman dibuka ulang.
            </div>
        </div>

        <?php if (!empty($dataProd)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblProd">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Produk</th>
                            <th>Harga Jual</th>
                            <th>Tier Grosir</th>
                            <th>Stok</th>
                            <th>Satuan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dataProd as $i => $d): ?>
                        <?php
                        $productSearchIndex = strtolower(trim(implode(' ', array_filter([
                            (string) ($d['kode'] ?? ''),
                            (string) ($d['nama'] ?? ''),
                            (string) ($d['kat_nama'] ?? ''),
                            (string) ($d['satuan'] ?? ''),
                            (string) ($d['deskripsi'] ?? ''),
                            (string) ($d['harga_jual'] ?? 0),
                            (string) ($d['stok'] ?? 0),
                            $isPrint ? 'printing' : 'apparel',
                        ]))));
                        ?>
                        <tr data-search="<?= htmlspecialchars($productSearchIndex, ENT_QUOTES, 'UTF-8') ?>">
                            <td><?=$i+1?></td>
                            <td class="inventory-name-cell">
                                <strong><?=htmlspecialchars($d['nama'])?></strong>
                            </td>
                            <td class="rp"><?=number_format($d['harga_jual'],0,',','.')?></td>
                            <td>
                                <?php if (!empty($d['grosir_tiers'])): ?>
                                    <span class="badge badge-secondary"><?= count($d['grosir_tiers']) ?> tier</span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $d['stok'] <= 5 ? 'badge-danger' : 'badge-success' ?>"><?= number_format((float) $d['stok']) ?></span>
                            </td>
                            <td><?=htmlspecialchars($d['satuan'])?></td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-warning btn-sm" onclick='editProduk(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode(array_values($satuanList), JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i></button>
                                    <form method="POST" onsubmit="confirmDelete(this);return false;">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id" value="<?=$d['id']?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileProdList">
                <?php foreach ($dataProd as $d): ?>
                    <?php
                    $productSearchIndex = strtolower(trim(implode(' ', array_filter([
                        (string) ($d['kode'] ?? ''),
                        (string) ($d['nama'] ?? ''),
                        (string) ($d['kat_nama'] ?? ''),
                        (string) ($d['satuan'] ?? ''),
                        (string) ($d['deskripsi'] ?? ''),
                        (string) ($d['harga_jual'] ?? 0),
                        (string) ($d['stok'] ?? 0),
                        $isPrint ? 'printing' : 'apparel',
                    ]))));
                    ?>
                    <div class="mobile-data-card product-card-mobile" data-search="<?= htmlspecialchars($productSearchIndex, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?=htmlspecialchars($d['nama'])?></div>
                                <div class="mobile-data-subtitle"><?=htmlspecialchars($d['kode'])?></div>
                            </div>
                            <span class="badge <?= $d['stok'] <= 5 ? 'badge-danger' : 'badge-success' ?>"><?= number_format((float) $d['stok']) ?> stok</span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Harga Jual</span>
                                <span class="mobile-data-value rp"><?=number_format($d['harga_jual'],0,',','.')?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Satuan</span>
                                <span class="mobile-data-value"><?=htmlspecialchars($d['satuan'])?></span>
                            </div>
                        </div>
                        <div class="mobile-note">
                            <?php if (!empty($d['grosir_tiers'])): ?>
                                <?= count($d['grosir_tiers']) ?> tier grosir tersedia untuk quantity besar.
                            <?php else: ?>
                                Belum ada tier grosir untuk produk ini.
                            <?php endif; ?>
                        </div>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-warning btn-sm" onclick='editProduk(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode(array_values($satuanList), JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i> Edit</button>
                            <form method="POST" onsubmit="confirmDelete(this);return false;">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?=$d['id']?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Hapus</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-state inventory-search-empty" id="inventorySearchEmpty" hidden>
                <i class="fas fa-magnifying-glass"></i>
                <div>Tidak ada produk yang cocok dengan kata kunci yang sedang dipakai.</div>
                <p>Coba ubah kata kunci atau tekan reset untuk menampilkan semua produk lagi.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <div>Belum ada produk <?=strtolower($tipeLabel)?>.</div>
            </div>
        <?php endif; ?>
        </div>
    </details>

    <!-- Modal Tambah Produk -->
    <div class="modal-overlay" id="modalTambah">
        <div class="modal-box modal-lg">
            <div class="modal-header"><h5>Tambah Produk <?=$tipeLabel?></h5><button class="modal-close" onclick="closeModal('modalTambah')">&times;</button></div>
            <form method="POST">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="tambah">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Kode *</label><input type="text" name="kode" class="form-control" required></div>
                        <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" class="form-control" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Kategori</label>
                            <select name="kategori_id" class="form-control">
                                <option value="">-- Pilih --</option>
                                <?php foreach ($katList as $k): ?><option value="<?=$k['id']?>"><?=htmlspecialchars($k['nama'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Satuan</label>
                            <select name="satuan" class="form-control">
                                <?php foreach ($satuanList as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-3">
                        <div class="form-group"><label class="form-label">Harga Beli</label><input type="number" name="harga_beli" class="form-control" value="0"></div>
                        <div class="form-group"><label class="form-label">Harga Jual</label><input type="number" name="harga_jual" class="form-control" value="0"></div>
                        <div class="form-group"><label class="form-label">Stok</label><input type="number" name="stok" class="form-control" value="0"></div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:12px;margin-bottom:12px">
                        <div class="d-flex justify-between align-center mb-2">
                            <div style="font-weight:600;font-size:.85rem;color:var(--primary)"><i class="fas fa-tags"></i> Harga Grosir (opsional)</div>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addGrosirTier('tambah')"><i class="fas fa-plus"></i> Tambah Tier</button>
                        </div>
                        <div id="grosirTiersContainerTambah">
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Deskripsi</label><textarea name="deskripsi" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambah')">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Produk -->
    <div class="modal-overlay" id="modalEdit">
        <div class="modal-box modal-lg">
            <div class="modal-header"><h5>Edit Produk <?=$tipeLabel?></h5><button class="modal-close" onclick="closeModal('modalEdit')">&times;</button></div>
            <form method="POST">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Kode *</label><input type="text" name="kode" id="eKode" class="form-control" required></div>
                        <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" id="eNama" class="form-control" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Kategori</label>
                            <select name="kategori_id" id="eKat" class="form-control">
                                <option value="">-- Pilih --</option>
                                <?php foreach ($katList as $k): ?><option value="<?=$k['id']?>"><?=htmlspecialchars($k['nama'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Satuan</label>
                            <select name="satuan" id="eSatuan" class="form-control">
                                <?php foreach ($satuanList as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row-3">
                        <div class="form-group"><label class="form-label">Harga Beli</label><input type="number" name="harga_beli" id="eHb" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Harga Jual</label><input type="number" name="harga_jual" id="eHj" class="form-control"></div>
                        <div class="form-group"><label class="form-label">Stok</label><input type="number" name="stok" id="eStok" class="form-control"></div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:12px;margin-bottom:12px">
                        <div class="d-flex justify-between align-center mb-2">
                            <div style="font-weight:600;font-size:.85rem;color:var(--primary)"><i class="fas fa-tags"></i> Harga Grosir</div>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addGrosirTier('edit')"><i class="fas fa-plus"></i> Tambah Tier</button>
                        </div>
                        <div id="grosirTiersContainerEdit">
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Deskripsi</label><textarea name="deskripsi" id="eDesk" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalEdit')">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'bahan_printing' || $tab === 'bahan_apparel'):
    $isBPrint  = $tab === 'bahan_printing';
    $dataBahan = $isBPrint ? $bahanPrint : $bahanApp;
    $katBahan  = $isBPrint ? 'printing' : 'apparel';
    $labelBahan = $isBPrint ? 'Printing' : 'Apparel';
    $satuanBahan = $isBPrint
        ? ['roll','liter','kg','gram','lembar','pcs','meter']
        : ['meter','yard','kg','gram','pcs','roll','lusin'];
?>
    <?php if (!$tblBahan): ?>
    <div class="card">
        <div class="empty-state">
            <i class="fas fa-layer-group"></i>
            <div>Tabel `stok_bahan` belum ada. Jalankan migration database terlebih dahulu.</div>
        </div>
    </div>
    <?php else: ?>
    <details class="card mobile-collapse-panel mobile-record-panel product-record-panel" open>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Bahan <?= htmlspecialchars($labelBahan) ?></strong>
                <span><?= number_format(count($dataBahan)) ?> bahan siap dipantau</span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas <?= $isBPrint ? 'fa-layer-group' : 'fa-scissors' ?>"></i> Bahan <?= $labelBahan ?></span>
                <div class="card-subtitle">Pantau bahan baku, minimum stok, dan harga beli dengan tampilan yang lebih rapi.</div>
            </div>
        </div>

        <div class="inventory-search-panel">
            <div class="inventory-search-header">
                <div class="inventory-search-meta" id="inventorySearchSummary">
                    <strong><?= number_format(count($dataBahan)) ?></strong> bahan pada tab ini
                </div>
            </div>
            <form method="GET" class="inventory-search-form">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                <label class="sr-only" for="inventorySearchInput">Cari bahan</label>
                <div class="inventory-search-field">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="inventorySearchInput"
                        class="form-control"
                        name="q"
                        value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Cari kode, nama bahan, satuan, atau keterangan..."
                        data-inventory-search-input
                        data-inventory-type="bahan"
                        data-inventory-label="bahan"
                    >
                    <button
                        type="button"
                        class="inventory-search-clear"
                        data-inventory-search-clear
                        aria-label="Kosongkan pencarian"
                        <?= $searchQuery === '' ? 'hidden' : '' ?>
                    >
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                <a href="<?= htmlspecialchars(produkTabUrl($tab), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline">Reset</a>
            </form>
            <div class="inventory-search-meta">
                Pencarian langsung menyaring daftar bahan pada tab ini. Tombol <strong>Terapkan</strong> akan menyimpan kata kunci ke URL supaya tetap aktif saat halaman dibuka ulang.
            </div>
        </div>

        <?php if (!empty($dataBahan)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblBahan">
                    <thead><tr><th>#</th><th>Bahan</th><th>Satuan</th><th>Stok</th><th>Min. Stok</th><th>Harga Beli</th><th>Keterangan</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($dataBahan as $i => $b): ?>
                    <?php $low = $b['stok'] <= $b['stok_minimum'] && $b['stok_minimum'] > 0; ?>
                    <?php
                    $materialSearchIndex = strtolower(trim(implode(' ', array_filter([
                        (string) ($b['kode'] ?? ''),
                        (string) ($b['nama'] ?? ''),
                        (string) ($b['satuan'] ?? ''),
                        (string) ($b['keterangan'] ?? ''),
                        (string) ($b['stok'] ?? 0),
                        (string) ($b['stok_minimum'] ?? 0),
                        (string) ($b['harga_beli'] ?? 0),
                        $katBahan,
                    ]))));
                    ?>
                    <tr data-search="<?= htmlspecialchars($materialSearchIndex, ENT_QUOTES, 'UTF-8') ?>">
                        <td><?=$i+1?></td>
                        <td class="inventory-name-cell">
                            <strong><?=htmlspecialchars($b['nama'])?></strong>
                        </td>
                        <td><?=htmlspecialchars($b['satuan'])?></td>
                        <td>
                            <span class="badge <?=$low?'badge-danger':'badge-success'?>"><?=number_format($b['stok'],2,',','.')?></span>
                            <?php if ($low): ?><div class="inventory-stock-note">Stok menipis</div><?php endif; ?>
                        </td>
                        <td><?=number_format($b['stok_minimum'],2,',','.')?></td>
                        <td class="rp"><?=number_format($b['harga_beli'],0,',','.')?></td>
                        <td><?=htmlspecialchars($b['keterangan'] ?: '-')?></td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-warning btn-sm" onclick='editBahan(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i></button>
                                <form method="POST" onsubmit="confirmDelete(this);return false;">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="hapus_bahan"><input type="hidden" name="id" value="<?=$b['id']?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileBahanList">
                <?php foreach ($dataBahan as $b): ?>
                    <?php $low = $b['stok'] <= $b['stok_minimum'] && $b['stok_minimum'] > 0; ?>
                    <?php
                    $materialSearchIndex = strtolower(trim(implode(' ', array_filter([
                        (string) ($b['kode'] ?? ''),
                        (string) ($b['nama'] ?? ''),
                        (string) ($b['satuan'] ?? ''),
                        (string) ($b['keterangan'] ?? ''),
                        (string) ($b['stok'] ?? 0),
                        (string) ($b['stok_minimum'] ?? 0),
                        (string) ($b['harga_beli'] ?? 0),
                        $katBahan,
                    ]))));
                    ?>
                    <div class="mobile-data-card bahan-card-mobile" data-search="<?= htmlspecialchars($materialSearchIndex, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?=htmlspecialchars($b['nama'])?></div>
                                <div class="mobile-data-subtitle"><?=htmlspecialchars($b['kode'])?></div>
                            </div>
                            <span class="badge <?=$low?'badge-danger':'badge-success'?>"><?=number_format($b['stok'],2,',','.')?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Satuan</span>
                                <span class="mobile-data-value"><?=htmlspecialchars($b['satuan'])?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Min. Stok</span>
                                <span class="mobile-data-value"><?=number_format($b['stok_minimum'],2,',','.')?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Harga Beli</span>
                                <span class="mobile-data-value rp"><?=number_format($b['harga_beli'],0,',','.')?></span>
                            </div>
                        </div>
                        <div class="mobile-note"><?= $low ? 'Bahan ini sudah menyentuh batas minimum stok.' : 'Stok bahan masih berada di atas batas minimum.' ?></div>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-warning btn-sm" onclick='editBahan(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i> Edit</button>
                            <form method="POST" onsubmit="confirmDelete(this);return false;">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="hapus_bahan"><input type="hidden" name="id" value="<?=$b['id']?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Hapus</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-state inventory-search-empty" id="inventorySearchEmpty" hidden>
                <i class="fas fa-magnifying-glass"></i>
                <div>Tidak ada bahan yang cocok dengan kata kunci yang sedang dipakai.</div>
                <p>Coba ubah kata kunci atau tekan reset untuk menampilkan semua bahan lagi.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-layer-group"></i>
                <div>Belum ada bahan <?=strtolower($labelBahan)?>.</div>
            </div>
        <?php endif; ?>
        </div>
    </details>

    <!-- Modal Tambah Bahan -->
    <div class="modal-overlay" id="modalTambahBahan">
        <div class="modal-box modal-lg">
            <div class="modal-header"><h5>Tambah Bahan <?=$labelBahan?></h5><button class="modal-close" onclick="closeModal('modalTambahBahan')">&times;</button></div>
            <form method="POST">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="tambah_bahan">
                <input type="hidden" name="kategori" value="<?=$katBahan?>">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Kode *</label><input type="text" name="kode" class="form-control" required></div>
                        <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" class="form-control" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Satuan *</label>
                            <select name="satuan" class="form-control">
                                <?php foreach ($satuanBahan as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Harga Beli</label><input type="number" name="harga_beli" class="form-control" value="0"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Stok Saat Ini</label><input type="number" name="stok" class="form-control" value="0" step="0.001"></div>
                        <div class="form-group"><label class="form-label">Minimum Stok (alert)</label><input type="number" name="stok_minimum" class="form-control" value="0" step="0.001"></div>
                    </div>
                    <div class="form-group"><label class="form-label">Keterangan</label><textarea name="keterangan" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambahBahan')">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Bahan -->
    <div class="modal-overlay" id="modalEditBahan">
        <div class="modal-box modal-lg">
            <div class="modal-header"><h5>Edit Bahan <?=$labelBahan?></h5><button class="modal-close" onclick="closeModal('modalEditBahan')">&times;</button></div>
            <form method="POST">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="edit_bahan">
                <input type="hidden" name="id" id="ebId">
                <input type="hidden" name="kategori" value="<?=$katBahan?>">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Kode *</label><input type="text" name="kode" id="ebKode" class="form-control" required></div>
                        <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" id="ebNama" class="form-control" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Satuan *</label>
                            <select name="satuan" id="ebSatuan" class="form-control">
                                <?php foreach ($satuanBahan as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Harga Beli</label><input type="number" name="harga_beli" id="ebHb" class="form-control"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Stok</label><input type="number" name="stok" id="ebStok" class="form-control" step="0.001"></div>
                        <div class="form-group"><label class="form-label">Minimum Stok</label><input type="number" name="stok_minimum" id="ebMin" class="form-control" step="0.001"></div>
                    </div>
                    <div class="form-group"><label class="form-label">Keterangan</label><textarea name="keterangan" id="ebKet" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditBahan')">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
require_once dirname(__DIR__) . '/layouts/footer.php';
?>
