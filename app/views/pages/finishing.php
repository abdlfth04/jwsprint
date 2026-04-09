<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
$pageTitle = 'Kelola Finishing & Bahan';

function finishingInsertStatement(mysqli $conn, string $table): ?mysqli_stmt
{
    switch ($table) {
        case 'finishing_printing':
            return $conn->prepare('INSERT INTO finishing_printing (nama, biaya) VALUES (?, ?)');
        case 'finishing_apparel':
            return $conn->prepare('INSERT INTO finishing_apparel (nama, biaya) VALUES (?, ?)');
        case 'bahan_apparel':
            return $conn->prepare('INSERT INTO bahan_apparel (nama) VALUES (?)');
        default:
            return null;
    }
}

function finishingDeleteStatement(mysqli $conn, string $table): ?mysqli_stmt
{
    switch ($table) {
        case 'finishing_printing':
            return $conn->prepare('DELETE FROM finishing_printing WHERE id = ?');
        case 'finishing_apparel':
            return $conn->prepare('DELETE FROM finishing_apparel WHERE id = ?');
        case 'bahan_apparel':
            return $conn->prepare('DELETE FROM bahan_apparel WHERE id = ?');
        default:
            return null;
    }
}

function finishingFetchRows(mysqli $conn, string $table): array
{
    if (!schemaTableExists($conn, $table)) {
        return [];
    }

    $result = $conn->query("SELECT * FROM {$table} ORDER BY nama");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$msg = '';
if (isset($_POST['action'])) {
    $act = $_POST['action'];
    $tbl = $_POST['tbl'] ?? '';
    $allowed = ['finishing_printing','finishing_apparel','bahan_apparel'];
    if (!in_array($tbl, $allowed)) { $msg = 'danger|Tabel tidak valid.'; goto render; }
    if (!schemaTableExists($conn, $tbl)) { $msg = 'danger|Tabel referensi belum tersedia di database.'; goto render; }

    if ($act === 'tambah') {
        $nama = trim($_POST['nama'] ?? '');
        $biaya = max(0, (float) ($_POST['biaya'] ?? 0));

        if ($nama === '') {
            $msg = 'danger|Nama wajib diisi.';
        } else {
            $stmt = finishingInsertStatement($conn, $tbl);
            if (!$stmt) {
                $msg = 'danger|Tabel tidak dapat diproses.';
            } elseif ($tbl === 'bahan_apparel') {
                $stmt->bind_param('s', $nama);
                $msg = $stmt->execute() ? 'success|Ditambahkan.' : 'danger|Gagal.';
                $stmt->close();
            } else {
                $stmt->bind_param('sd', $nama, $biaya);
                $msg = $stmt->execute() ? 'success|Ditambahkan.' : 'danger|Gagal.';
                $stmt->close();
            }
        }
    } elseif ($act === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $msg = 'danger|ID data tidak valid.';
        } else {
            $stmt = finishingDeleteStatement($conn, $tbl);
            if (!$stmt) {
                $msg = 'danger|Tabel tidak dapat diproses.';
            } else {
                $stmt->bind_param('i', $id);
                $msg = $stmt->execute() ? 'success|Dihapus.' : 'danger|Gagal.';
                $stmt->close();
            }
        }
    }
}
render:
$finPrinting  = finishingFetchRows($conn, 'finishing_printing');
$finApparel   = finishingFetchRows($conn, 'finishing_apparel');
$bahanApparel = finishingFetchRows($conn, 'bahan_apparel');
$missingReferenceTables = [];
foreach (['finishing_printing', 'finishing_apparel', 'bahan_apparel'] as $table) {
    if (!schemaTableExists($conn, $table)) {
        $missingReferenceTables[] = $table;
    }
}
$finPrintingCount = count($finPrinting);
$finApparelCount = count($finApparel);
$bahanApparelCount = count($bahanApparel);
$finPrintingTotal = array_sum(array_map(static function ($item) {
    return (float) ($item['biaya'] ?? 0);
}, $finPrinting));
$finApparelTotal = array_sum(array_map(static function ($item) {
    return (float) ($item['biaya'] ?? 0);
}, $finApparel));
$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack admin-panel finishing-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-layer-group"></i> Finishing & Bahan</div>
                <h1 class="page-title">Referensi finishing dan bahan dibuat lebih ringkas untuk update cepat</h1>
                <p class="page-description">
                    Halaman ini dipadatkan supaya admin cukup fokus ke nama referensi dan biaya tanpa terlalu banyak elemen tambahan.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-print"></i> <?= number_format($finPrintingCount) ?> finishing printing</span>
                    <span class="page-meta-item"><i class="fas fa-shirt"></i> <?= number_format($finApparelCount) ?> finishing apparel</span>
                    <span class="page-meta-item"><i class="fas fa-box"></i> <?= number_format($bahanApparelCount) ?> bahan apparel</span>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <?php if (!empty($missingReferenceTables)): ?>
        <div class="info-banner warning">
            <strong>Beberapa tabel referensi belum tersedia.</strong> Tabel yang belum ada: <?= htmlspecialchars(implode(', ', $missingReferenceTables)) ?>.
        </div>
    <?php endif; ?>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Printing</span>
            <span class="metric-value"><?= number_format($finPrintingCount) ?></span>
            <span class="metric-note">Jumlah opsi finishing printing.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Apparel</span>
            <span class="metric-value"><?= number_format($finApparelCount) ?></span>
            <span class="metric-note">Jumlah opsi finishing apparel.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Bahan</span>
            <span class="metric-value"><?= number_format($bahanApparelCount) ?></span>
            <span class="metric-note">Daftar bahan apparel aktif.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Total Biaya Dasar</span>
            <span class="metric-value">Rp <?= number_format($finPrintingTotal + $finApparelTotal, 0, ',', '.') ?></span>
            <span class="metric-note">Akumulasi biaya finishing printing dan apparel.</span>
        </div>
    </div>

    <div class="panel-grid-3">
        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-print"></i> Finishing Printing</span>
                    <div class="card-subtitle">Referensi biaya finishing untuk produk printing.</div>
                </div>
            </div>
            <form method="POST" class="admin-inline-actions finishing-form finishing-form-cost">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="tambah">
                <input type="hidden" name="tbl" value="finishing_printing">
                <input type="text" name="nama" class="form-control" placeholder="Nama finishing" required>
                <input type="number" name="biaya" class="form-control" placeholder="Biaya" style="max-width: 140px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah</button>
            </form>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Nama</th><th>Biaya</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($finPrinting as $f): ?>
                        <tr>
                            <td><?= htmlspecialchars($f['nama']) ?></td>
                            <td class="rp"><?= number_format($f['biaya'],0,',','.') ?></td>
                            <td>
                                <form method="POST" onsubmit="confirmDelete(this);return false;">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="hapus">
                                    <input type="hidden" name="tbl" value="finishing_printing">
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($finPrinting)): ?><tr><td colspan="3" class="text-center text-muted" style="padding:18px">Belum ada data.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-shirt"></i> Finishing Apparel</span>
                    <div class="card-subtitle">Referensi finishing per pcs untuk apparel.</div>
                </div>
            </div>
            <form method="POST" class="admin-inline-actions finishing-form finishing-form-cost">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="tambah">
                <input type="hidden" name="tbl" value="finishing_apparel">
                <input type="text" name="nama" class="form-control" placeholder="Nama finishing" required>
                <input type="number" name="biaya" class="form-control" placeholder="Biaya/pcs" style="max-width: 140px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah</button>
            </form>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Nama</th><th>Biaya/pcs</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($finApparel as $f): ?>
                        <tr>
                            <td><?= htmlspecialchars($f['nama']) ?></td>
                            <td class="rp"><?= number_format($f['biaya'],0,',','.') ?></td>
                            <td>
                                <form method="POST" onsubmit="confirmDelete(this);return false;">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="hapus">
                                    <input type="hidden" name="tbl" value="finishing_apparel">
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($finApparel)): ?><tr><td colspan="3" class="text-center text-muted" style="padding:18px">Belum ada data.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-box"></i> Bahan Apparel</span>
                    <div class="card-subtitle">Daftar bahan tanpa detail tambahan yang tidak perlu.</div>
                </div>
            </div>
            <form method="POST" class="admin-inline-actions finishing-form finishing-form-simple">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="tambah">
                <input type="hidden" name="tbl" value="bahan_apparel">
                <input type="text" name="nama" class="form-control" placeholder="Nama bahan" required>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah</button>
            </form>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Nama Bahan</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($bahanApparel as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['nama']) ?></td>
                            <td>
                                <form method="POST" onsubmit="confirmDelete(this);return false;">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="hapus">
                                    <input type="hidden" name="tbl" value="bahan_apparel">
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bahanApparel)): ?><tr><td colspan="2" class="text-center text-muted" style="padding:18px">Belum ada data.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
