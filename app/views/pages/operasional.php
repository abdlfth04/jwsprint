<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
$pageTitle = 'Operasional / Pengeluaran';

function operasionalNormalizeMonth(string $month): string
{
    return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
}

function operasionalNormalizeDate(string $date, ?string $fallback = null): string
{
    $date = trim($date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false) {
        return $date;
    }

    return $fallback ?? date('Y-m-d');
}

function operasionalNormalizeDivision(string $division, bool $allowAll = false): string
{
    $division = strtolower(trim($division));
    $allowed = ['printing', 'apparel', 'umum'];
    if ($allowAll && $division === 'semua') {
        return 'semua';
    }

    return in_array($division, $allowed, true) ? $division : ($allowAll ? 'semua' : 'umum');
}

function operasionalPageUrlWithFilters(string $tab = 'semua', string $bulan = '', string $search = ''): string
{
    $params = [];
    if ($tab !== '' && $tab !== 'semua') {
        $params['tab'] = $tab;
    }
    if ($bulan !== '') {
        $params['bulan'] = $bulan;
    }
    if ($search !== '') {
        $params['q'] = $search;
    }

    $query = http_build_query($params);

    return pageUrl('operasional.php' . ($query !== '' ? '?' . $query : ''));
}

$uid = $_SESSION['user_id'];
$msg = '';
$hasOperasionalTable = schemaTableExists($conn, 'operasional');
$hasDivisi = $hasOperasionalTable && schemaColumnExists($conn, 'operasional', 'divisi');

if (isset($_POST['action'])) {
    $act = $_POST['action'];
    if (!$hasOperasionalTable) {
        $msg = 'danger|Tabel operasional belum tersedia. Jalankan migrasi database terlebih dahulu.';
    } elseif ($act === 'tambah') {
        $tgl    = operasionalNormalizeDate((string) ($_POST['tanggal'] ?? ''), date('Y-m-d'));
        $kat    = trim($_POST['kategori']);
        $ket    = trim($_POST['keterangan']);
        $jml    = floatval($_POST['jumlah']);
        $divisi = operasionalNormalizeDivision((string) ($_POST['divisi'] ?? 'umum'));
        if ($hasDivisi) {
            $stmt = $conn->prepare("INSERT INTO operasional (tanggal,kategori,keterangan,jumlah,divisi,user_id) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('sssdsi', $tgl,$kat,$ket,$jml,$divisi,$uid);
        } else {
            $stmt = $conn->prepare("INSERT INTO operasional (tanggal,kategori,keterangan,jumlah,user_id) VALUES (?,?,?,?,?)");
            $stmt->bind_param('sssdi', $tgl,$kat,$ket,$jml,$uid);
        }
        $stmt->execute() ? $msg = 'success|Data ditambahkan.' : $msg = 'danger|Gagal.';
    } elseif ($act === 'edit') {
        $id     = intval($_POST['id']);
        $tgl    = operasionalNormalizeDate((string) ($_POST['tanggal'] ?? ''), date('Y-m-d'));
        $kat    = trim($_POST['kategori']);
        $ket    = trim($_POST['keterangan']);
        $jml    = floatval($_POST['jumlah']);
        $divisi = operasionalNormalizeDivision((string) ($_POST['divisi'] ?? 'umum'));
        if ($hasDivisi) {
            $stmt = $conn->prepare("UPDATE operasional SET tanggal=?,kategori=?,keterangan=?,jumlah=?,divisi=? WHERE id=?");
            $stmt->bind_param('sssdsi', $tgl,$kat,$ket,$jml,$divisi,$id);
        } else {
            $stmt = $conn->prepare("UPDATE operasional SET tanggal=?,kategori=?,keterangan=?,jumlah=? WHERE id=?");
            $stmt->bind_param('sssdi', $tgl,$kat,$ket,$jml,$id);
        }
        $stmt->execute() ? $msg = 'success|Data diperbarui.' : $msg = 'danger|Gagal.';
    } elseif ($act === 'hapus') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM operasional WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute() ? $msg = 'success|Data dihapus.' : $msg = 'danger|Gagal menghapus data operasional.';
            $stmt->close();
        } else {
            $msg = 'danger|Gagal menyiapkan proses hapus operasional.';
        }
    }
}

$tab   = operasionalNormalizeDivision((string) ($_GET['tab'] ?? 'semua'), true);
$bulan = operasionalNormalizeMonth((string) ($_GET['bulan'] ?? date('Y-m')));
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$data = [];
if ($hasOperasionalTable && ($tab === 'semua' || $hasDivisi)) {
    $sqlData = "SELECT o.*, u.nama as nama_user
                FROM operasional o
                LEFT JOIN users u ON o.user_id = u.id
                WHERE DATE_FORMAT(o.tanggal, '%Y-%m') = ?";
    $types = 's';
    $params = [$bulan];
    if ($tab !== 'semua' && $hasDivisi) {
        $sqlData .= " AND o.divisi = ?";
        $types .= 's';
        $params[] = $tab;
    }
    if ($searchQuery !== '') {
        $sqlData .= " AND (
            o.kategori LIKE ?
            OR o.keterangan LIKE ?
            OR CAST(o.jumlah AS CHAR) LIKE ?
            OR DATE_FORMAT(o.tanggal, '%d/%m/%Y') LIKE ?
            OR COALESCE(u.nama, '') LIKE ?";
        $types .= 'sssss';
        $searchLike = '%' . $searchQuery . '%';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        if ($hasDivisi) {
            $sqlData .= " OR COALESCE(o.divisi, '') LIKE ?";
            $types .= 's';
            $params[] = $searchLike;
        }
        $sqlData .= ")";
    }
    $sqlData .= " ORDER BY o.tanggal DESC";

    $stmtData = $conn->prepare($sqlData);
    if ($stmtData) {
        $stmtData->bind_param($types, ...$params);
        $stmtData->execute();
        $data = $stmtData->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtData->close();
    }
}

// Totals per divisi untuk stat cards
$totals = ['semua'=>0,'printing'=>0,'apparel'=>0,'umum'=>0];
if ($hasOperasionalTable && $hasDivisi) {
    $stmtTotals = $conn->prepare("SELECT divisi, SUM(jumlah) as total FROM operasional WHERE DATE_FORMAT(tanggal,'%Y-%m') = ? GROUP BY divisi");
    if ($stmtTotals) {
        $stmtTotals->bind_param('s', $bulan);
        $stmtTotals->execute();
        $resT = $stmtTotals->get_result();
        foreach (($resT ? $resT->fetch_all(MYSQLI_ASSOC) : []) as $r) {
            $divisiKey = operasionalNormalizeDivision((string) ($r['divisi'] ?? 'umum'));
            $totals[$divisiKey] = floatval($r['total']);
            $totals['semua'] += floatval($r['total']);
        }
        $stmtTotals->close();
    }
} elseif ($hasOperasionalTable) {
    $stmtTotalAll = $conn->prepare("SELECT COALESCE(SUM(jumlah),0) FROM operasional WHERE DATE_FORMAT(tanggal,'%Y-%m') = ?");
    if ($stmtTotalAll) {
        $stmtTotalAll->bind_param('s', $bulan);
        $stmtTotalAll->execute();
        $rowTotalAll = $stmtTotalAll->get_result()->fetch_row();
        $totals['semua'] = floatval($rowTotalAll[0] ?? 0);
        $stmtTotalAll->close();
    }
}

// Preset kategori per divisi
$presetKat = [
    'printing' => ['Tinta','Media/Bahan','Perawatan Mesin','Spare Part','Lainnya'],
    'apparel'  => ['Kain/Bahan','Benang','Aksesoris','Perawatan Mesin Jahit','Lainnya'],
    'umum'     => ['Listrik','Internet','Air','Gaji','Sewa','ATK','Transportasi','Lainnya'],
];

$selectedTotal = array_sum(array_map(static function ($row) {
    return (float) ($row['jumlah'] ?? 0);
}, $data));
$selectedCount = count($data);
$tabLabels = [
    'semua' => 'Semua divisi',
    'printing' => 'Divisi printing',
    'apparel' => 'Divisi apparel',
    'umum' => 'Divisi umum',
];
$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">';
$pageState = [
    'opsPresetKat' => $presetKat,
];
$pageJs = 'operasional.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
<div class="alert alert-<?=$type?>" data-dismiss="1"><?=htmlspecialchars($text)?></div>
<?php endif; ?>

<div class="page-stack admin-panel operasional-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-wallet"></i> Operasional</div>
                <h1 class="page-title">Pengeluaran bulanan lebih mudah dibaca, dipilah, dan ditindaklanjuti</h1>
                <p class="page-description">
                    Alur operasional sekarang dibuat lebih ringan untuk admin: total per divisi langsung terlihat, filter bulan lebih jelas, dan daftar pengeluaran tetap nyaman dicek dari mobile.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-calendar"></i> Periode: <?= date('F Y', strtotime($bulan . '-01')) ?></span>
                    <span class="page-meta-item"><i class="fas fa-filter"></i> Filter aktif: <?= htmlspecialchars($tabLabels[$tab] ?? 'Semua divisi') ?></span>
                    <span class="page-meta-item"><i class="fas fa-list-ol"></i> <?= number_format($selectedCount) ?> data tampil</span>
                    <?php if ($searchQuery !== ''): ?>
                        <span class="page-meta-item"><i class="fas fa-magnifying-glass"></i> Kata kunci: <?= htmlspecialchars($searchQuery) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('modalTambah')"><i class="fas fa-plus"></i> Tambah Pengeluaran</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip compact-metric-strip">
        <div class="metric-card">
            <span class="metric-label">Total Bulan Ini</span>
            <span class="metric-value rp"><?= number_format($totals['semua'], 0, ',', '.') ?></span>
            <span class="metric-note">Akumulasi seluruh pengeluaran pada periode yang dipilih.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Printing</span>
            <span class="metric-value rp"><?= number_format($totals['printing'], 0, ',', '.') ?></span>
            <span class="metric-note">Pengeluaran khusus divisi printing.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Apparel</span>
            <span class="metric-value rp"><?= number_format($totals['apparel'], 0, ',', '.') ?></span>
            <span class="metric-note">Termasuk bahan, perawatan, dan biaya produksi apparel.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Umum</span>
            <span class="metric-value rp"><?= number_format($totals['umum'], 0, ',', '.') ?></span>
            <span class="metric-note">Biaya operasional kantor seperti listrik, internet, dan ATK.</span>
        </div>
    </div>

    <details class="toolbar-surface admin-filter-grid mobile-collapse-panel compact-toolbar-panel"<?= (!$hasOperasionalTable || $tab !== 'semua') ? ' open' : '' ?>>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Filter Operasional</strong>
                <span><?= number_format($selectedCount) ?> data - <?= htmlspecialchars($tabLabels[$tab] ?? 'Semua divisi') ?></span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
            <div class="section-heading">
                <div>
                    <h2>Filter Operasional</h2>
                    <p>Gunakan kombinasi divisi, periode, dan pencarian cepat untuk membaca pengeluaran tanpa membuka laporan terpisah.</p>
                </div>
            </div>
            <?php if (!$hasOperasionalTable): ?>
                <div class="info-banner warning">
                    <strong>Tabel <code>operasional</code> belum tersedia.</strong> Halaman tetap dibuka, tetapi data pengeluaran baru akan tampil setelah schema database dilengkapi.
                </div>
            <?php endif; ?>
            <div class="filter-pills">
                <a href="<?= htmlspecialchars(operasionalPageUrlWithFilters('semua', $bulan, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === 'semua' ? 'active' : '' ?>"><span>Semua</span></a>
                <a href="<?= htmlspecialchars(operasionalPageUrlWithFilters('printing', $bulan, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === 'printing' ? 'active' : '' ?>"><span>Printing</span></a>
                <a href="<?= htmlspecialchars(operasionalPageUrlWithFilters('apparel', $bulan, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === 'apparel' ? 'active' : '' ?>"><span>Apparel</span></a>
                <a href="<?= htmlspecialchars(operasionalPageUrlWithFilters('umum', $bulan, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === 'umum' ? 'active' : '' ?>"><span>Umum</span></a>
            </div>
            <div class="admin-toolbar">
                <form method="GET" class="search-bar" style="display:flex; gap:10px; align-items:center;">
                    <?php if ($tab !== 'semua'): ?>
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <input type="hidden" name="bulan" value="<?= htmlspecialchars($bulan, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" id="srchOperasional" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Cari kategori, keterangan, nominal, atau nama user..." oninput="filterOperasionalView()">
                    <button type="submit" class="btn btn-outline"><i class="fas fa-search"></i> Cari</button>
                </form>
                <div class="admin-toolbar-actions">
                    <form method="GET" class="admin-inline-actions toolbar-inline-form">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                        <?php if ($searchQuery !== ''): ?>
                            <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <input type="month" name="bulan" value="<?= htmlspecialchars($bulan) ?>" class="form-control" style="width: 170px;">
                        <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Terapkan</button>
                    </form>
                </div>
            </div>
        </div>
    </details>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-receipt"></i> Daftar Pengeluaran</span>
                <div class="card-subtitle">Tampilan tabel untuk desktop dan kartu ringkas untuk mobile dengan total yang tetap konsisten.</div>
            </div>
        </div>

        <?php if (!empty($data)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblOperasional">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tanggal</th>
                            <?php if ($tab === 'semua' && $hasDivisi): ?><th>Divisi</th><?php endif; ?>
                            <th>Kategori</th>
                            <th>Keterangan</th>
                            <th>Jumlah</th>
                            <th>Oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($data as $i => $d): ?>
                        <?php
                        $dv = $d['divisi'] ?? 'umum';
                        $badgeMap = [
                            'printing' => ['badge-info', 'fa-print', 'Printing'],
                            'apparel' => ['badge-mitra', 'fa-shirt', 'Apparel'],
                            'umum' => ['badge-secondary', 'fa-building', 'Umum'],
                        ];
                        $badgeParts = isset($badgeMap[$dv]) ? $badgeMap[$dv] : ['badge-secondary', 'fa-circle', ucfirst($dv)];
                        $badgeClass = $badgeParts[0];
                        $badgeIcon = $badgeParts[1];
                        $badgeLabel = $badgeParts[2];
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= date('d/m/Y', strtotime($d['tanggal'])) ?></td>
                            <?php if ($tab === 'semua' && $hasDivisi): ?>
                                <td><span class="badge <?= $badgeClass ?>"><i class="fas <?= $badgeIcon ?>"></i> <?= htmlspecialchars($badgeLabel) ?></span></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($d['kategori']) ?></td>
                            <td><?= htmlspecialchars($d['keterangan'] ?: '-') ?></td>
                            <td class="rp"><?= number_format($d['jumlah'], 0, ',', '.') ?></td>
                            <td><?= htmlspecialchars($d['nama_user'] ?? '-') ?></td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-warning btn-sm" onclick='editOps(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i></button>
                                    <form method="POST" onsubmit="confirmDelete(this);return false;">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="<?= ($tab === 'semua' && $hasDivisi) ? 5 : 4 ?>" style="text-align:right;">Total Ditampilkan</td>
                            <td class="rp"><?= number_format($selectedTotal, 0, ',', '.') ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileOperasionalList">
                <?php foreach ($data as $d): ?>
                    <?php
                    $dv = $d['divisi'] ?? 'umum';
                    $badgeMap = [
                        'printing' => ['badge-info', 'Printing'],
                        'apparel' => ['badge-mitra', 'Apparel'],
                        'umum' => ['badge-secondary', 'Umum'],
                    ];
                    $badgeParts = isset($badgeMap[$dv]) ? $badgeMap[$dv] : ['badge-secondary', ucfirst($dv)];
                    $badgeClass = $badgeParts[0];
                    $badgeLabel = $badgeParts[1];
                    ?>
                    <div class="mobile-data-card operasional-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars($d['kategori']) ?></div>
                                <div class="mobile-data-subtitle"><?= date('d/m/Y', strtotime($d['tanggal'])) ?></div>
                            </div>
                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Jumlah</span>
                                <span class="mobile-data-value rp"><?= number_format($d['jumlah'], 0, ',', '.') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Divisi</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($badgeLabel) ?></span>
                            </div>
                            <?php if (!empty($d['keterangan'])): ?>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Catatan</span>
                                    <span class="mobile-data-value"><?= htmlspecialchars($d['keterangan']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-warning btn-sm" onclick='editOps(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i> Edit</button>
                            <form method="POST" onsubmit="confirmDelete(this);return false;">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Hapus</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-wallet"></i>
                <div>Tidak ada data pengeluaran pada periode dan filter yang dipilih.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Tambah -->
<div class="modal-overlay" id="modalTambah">
    <div class="modal-box">
        <div class="modal-header"><h5>Tambah Pengeluaran</h5><button class="modal-close" onclick="closeModal('modalTambah')">&times;</button></div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="tambah">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Tanggal *</label><input type="date" name="tanggal" class="form-control" value="<?=date('Y-m-d')?>" required></div>
                    <?php if ($hasDivisi): ?>
                    <div class="form-group">
                        <label class="form-label">Divisi</label>
                        <select name="divisi" id="addDivisi" class="form-control" onchange="updateKatPreset(this.value,'addKat')">
                            <option value="printing" <?=$tab==='printing'?'selected':''?>>Printing</option>
                            <option value="apparel"  <?=$tab==='apparel'?'selected':''?>>Apparel</option>
                            <option value="umum"     <?=($tab==='umum'||$tab==='semua')?'selected':''?>>Umum</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Kategori</label>
                    <select name="kategori" id="addKat" class="form-control">
                        <?php
                        $initDivisi = in_array($tab,['printing','apparel']) ? $tab : 'umum';
                        foreach ($presetKat[$initDivisi] as $k):
                        ?><option value="<?=$k?>"><?=$k?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Keterangan</label><textarea name="keterangan" class="form-control" rows="2"></textarea></div>
                <div class="form-group"><label class="form-label">Jumlah (Rp) *</label><input type="number" name="jumlah" class="form-control" required></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambah')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-box">
        <div class="modal-header"><h5>Edit Pengeluaran</h5><button class="modal-close" onclick="closeModal('modalEdit')">&times;</button></div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="eId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Tanggal *</label><input type="date" name="tanggal" id="eTgl" class="form-control" required></div>
                    <?php if ($hasDivisi): ?>
                    <div class="form-group">
                        <label class="form-label">Divisi</label>
                        <select name="divisi" id="eDivisi" class="form-control" onchange="updateKatPreset(this.value,'eKat')">
                            <option value="printing">Printing</option>
                            <option value="apparel">Apparel</option>
                            <option value="umum">Umum</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Kategori</label>
                    <select name="kategori" id="eKat" class="form-control"></select>
                </div>
                <div class="form-group"><label class="form-label">Keterangan</label><textarea name="keterangan" id="eKet" class="form-control" rows="2"></textarea></div>
                <div class="form-group"><label class="form-label">Jumlah (Rp) *</label><input type="number" name="jumlah" id="eJml" class="form-control" required></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEdit')">Batal</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
