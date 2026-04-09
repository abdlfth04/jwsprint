<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
$pageTitle = 'Absensi Karyawan';

function absensiStatusBadgeClass(string $status): string
{
    switch (strtolower(trim($status))) {
        case 'hadir':
            return 'badge-success';
        case 'terlambat':
            return 'badge-warning';
        case 'izin':
        case 'sakit':
            return 'badge-info';
        case 'alpha':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

$hasKaryawanTable = schemaTableExists($conn, 'karyawan');
$hasAbsensiTable = schemaTableExists($conn, 'absensi');
$hasKaryawanStatus = $hasKaryawanTable && schemaColumnExists($conn, 'karyawan', 'status');

$msg = '';
if (isset($_POST['action']) && $_POST['action'] === 'manual') {
    $karId = intval($_POST['karyawan_id']);
    $tgl = $_POST['tanggal'];
    $status = $_POST['status'];
    $ket = trim($_POST['keterangan'] ?? '');

    $resUid = $conn->prepare("SELECT user_id FROM karyawan WHERE id = ? LIMIT 1");
    $resUid->bind_param('i', $karId);
    $resUid->execute();
    $rowUid = $resUid->get_result()->fetch_assoc();
    $resUid->close();
    $userId = $rowUid['user_id'] ?? $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "INSERT INTO absensi (karyawan_id, user_id, tanggal, status, is_manual, keterangan)
         VALUES (?, ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE status=VALUES(status), is_manual=1, keterangan=VALUES(keterangan)"
    );
    $stmt->bind_param('iisss', $karId, $userId, $tgl, $status, $ket);
    $stmt->execute()
        ? $msg = 'success|Data absensi manual berhasil disimpan.'
        : $msg = 'danger|Gagal menyimpan: ' . $conn->error;
    $stmt->close();
}

$filterKarId = intval($_GET['karyawan_id'] ?? 0);
$filterBulan = $_GET['bulan'] ?? date('Y-m');

$allKaryawan = [];
if ($hasKaryawanTable) {
    $karyawanWhere = $hasKaryawanStatus ? "WHERE status='aktif'" : '';
    $karyawanResult = $conn->query(
        "SELECT id, nama, jabatan FROM karyawan {$karyawanWhere} ORDER BY nama"
    );
    $allKaryawan = $karyawanResult ? $karyawanResult->fetch_all(MYSQLI_ASSOC) : [];
}

$absensiData = [];
$summary = ['hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpha' => 0];

if ($filterBulan && $hasAbsensiTable && $hasKaryawanTable) {
    if ($filterKarId > 0) {
        $stmt = $conn->prepare(
            "SELECT a.*, k.nama as nama_karyawan
             FROM absensi a
             JOIN karyawan k ON a.karyawan_id = k.id
             WHERE a.karyawan_id = ? AND DATE_FORMAT(a.tanggal, '%Y-%m') = ?
             ORDER BY a.tanggal DESC"
        );
        $stmt->bind_param('is', $filterKarId, $filterBulan);
    } else {
        $stmt = $conn->prepare(
            "SELECT a.*, k.nama as nama_karyawan
             FROM absensi a
             JOIN karyawan k ON a.karyawan_id = k.id
             WHERE DATE_FORMAT(a.tanggal, '%Y-%m') = ?
             ORDER BY a.tanggal DESC, k.nama ASC"
        );
        $stmt->bind_param('s', $filterBulan);
    }
    if ($stmt) {
        $stmt->execute();
        $absensiData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    foreach ($absensiData as $row) {
        $statusKey = $row['status'];
        if (isset($summary[$statusKey])) {
            $summary[$statusKey]++;
        }
    }
}

$selectedNama = 'Semua Karyawan';
if ($filterKarId > 0) {
    foreach ($allKaryawan as $k) {
        if ((int) $k['id'] === $filterKarId) {
            $selectedNama = $k['nama'];
            break;
        }
    }
}

$manualCount = 0;
$withPhotoCount = 0;
$closedShiftCount = 0;
foreach ($absensiData as $row) {
    if (!empty($row['is_manual'])) {
        $manualCount++;
    }
    if (!empty($row['foto_masuk']) || !empty($row['foto_keluar'])) {
        $withPhotoCount++;
    }
    if (!empty($row['jam_masuk']) && !empty($row['jam_keluar'])) {
        $closedShiftCount++;
    }
}
$selectedCount = count($absensiData);

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/absensi.css') . '">';
$pageJs = 'absensi.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack admin-panel absensi-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-calendar-check"></i> Absensi</div>
                <h1 class="page-title">Rekap kehadiran lebih jelas untuk pengecekan, koreksi manual, dan audit foto</h1>
                <p class="page-description">
                    Halaman absensi dirapikan agar admin bisa memantau status hadir, keterlambatan, data manual, dan dokumentasi selfie dengan alur yang tetap nyaman di desktop maupun mobile.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-user-check"></i> Fokus: <?= htmlspecialchars($selectedNama) ?></span>
                    <span class="page-meta-item"><i class="fas fa-calendar"></i> <?= date('F Y', strtotime($filterBulan . '-01')) ?></span>
                    <span class="page-meta-item"><i class="fas fa-list-ol"></i> <?= number_format($selectedCount) ?> data tampil</span>
                </div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('modalManual')"><i class="fas fa-plus"></i> Input Manual</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Hadir</span>
            <span class="metric-value"><?= number_format($summary['hadir']) ?></span>
            <span class="metric-note">Absensi hadir normal pada periode yang dipilih.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Terlambat</span>
            <span class="metric-value"><?= number_format($summary['terlambat']) ?></span>
            <span class="metric-note">Tetap dihitung hadir, namun perlu evaluasi kedisiplinan.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Input Manual</span>
            <span class="metric-value"><?= number_format($manualCount) ?></span>
            <span class="metric-note">Koreksi admin yang perlu mudah ditelusuri kembali.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Foto Tersedia</span>
            <span class="metric-value"><?= number_format($withPhotoCount) ?></span>
            <span class="metric-note"><?= number_format($closedShiftCount) ?> shift lengkap memiliki jam masuk dan jam keluar.</span>
        </div>
    </div>

    <div class="toolbar-surface admin-filter-grid">
        <div class="section-heading">
            <div>
                <h2>Filter Absensi</h2>
                <p>Pilih karyawan dan periode, lalu cari cepat data tanggal, status, keterangan, atau nama dari panel yang sama.</p>
            </div>
        </div>
        <?php if (!$hasAbsensiTable): ?>
            <div class="info-banner warning">
                <strong>Tabel <code>absensi</code> belum tersedia.</strong> Halaman tetap terbuka, tetapi riwayat kehadiran baru akan tampil setelah schema database dilengkapi.
            </div>
        <?php endif; ?>
        <div class="admin-toolbar">
            <form method="GET" class="admin-inline-actions" style="flex: 1 1 520px;">
                <select name="karyawan_id" class="form-control" style="min-width: 220px;">
                    <option value="">Semua karyawan</option>
                    <?php foreach ($allKaryawan as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $filterKarId === (int) $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="month" name="bulan" class="form-control" style="min-width: 180px;" value="<?= htmlspecialchars($filterBulan) ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                <a href="<?= pageUrl('absensi.php') ?>" class="btn btn-outline"><i class="fas fa-rotate-left"></i> Reset</a>
            </form>
            <div class="search-bar">
                <input type="text" id="srchAbsensi" class="form-control" placeholder="Cari nama, tanggal, status, jam, atau keterangan..." oninput="filterAbsensiView()">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-clipboard-list"></i> Rekap Kehadiran</span>
                <div class="card-subtitle">Tampilan tabel untuk desktop dan kartu ringkas untuk mobile dengan akses lightbox foto yang tetap cepat.</div>
            </div>
        </div>

        <?php if (!empty($absensiData)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblAbsensi">
                    <thead>
                        <tr>
                            <?php if ($filterKarId === 0): ?><th>Karyawan</th><?php endif; ?>
                            <th>Tanggal</th>
                            <th>Jam</th>
                            <th>Status</th>
                            <th>Keterangan</th>
                            <th>Foto Selfie</th>
                            <th>Manual</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($absensiData as $row): ?>
                        <?php
                        $durasi = '-';
                        if ($row['jam_masuk'] && $row['jam_keluar']) {
                            $diff = strtotime($row['jam_keluar']) - strtotime($row['jam_masuk']);
                            if ($diff > 0) {
                                $durasi = floor($diff / 3600) . 'j ' . floor(($diff % 3600) / 60) . 'm';
                            }
                        }
                        $badgeClass = absensiStatusBadgeClass((string) ($row['status'] ?? ''));
                        ?>
                        <tr>
                            <?php if ($filterKarId === 0): ?>
                                <td>
                                    <strong><?= htmlspecialchars($row['nama_karyawan']) ?></strong>
                                </td>
                            <?php endif; ?>
                            <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td>
                                <div class="inventory-title">
                                    <strong><?= $row['jam_masuk'] ? substr($row['jam_masuk'], 0, 5) : '-' ?> - <?= $row['jam_keluar'] ? substr($row['jam_keluar'], 0, 5) : '-' ?></strong>
                                    <div class="inventory-meta"><span><i class="fas fa-hourglass-half"></i> <?= $durasi ?></span></div>
                                </div>
                            </td>
                            <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($row['status']) ?></span></td>
                            <td><?= htmlspecialchars($row['keterangan'] ?: '-') ?></td>
                            <td>
                                <div class="photo-strip">
                                    <?php if (!empty($row['foto_masuk'])): ?>
                                        <img src="<?= htmlspecialchars(attendancePhotoUrl((string) $row['foto_masuk'])) ?>" alt="Masuk" class="selfie-thumb" onclick="openLightbox(this.src)" title="Foto check-in">
                                    <?php endif; ?>
                                    <?php if (!empty($row['foto_keluar'])): ?>
                                        <img src="<?= htmlspecialchars(attendancePhotoUrl((string) $row['foto_keluar'])) ?>" alt="Keluar" class="selfie-thumb" onclick="openLightbox(this.src)" title="Foto check-out">
                                    <?php endif; ?>
                                    <?php if (empty($row['foto_masuk']) && empty($row['foto_keluar'])): ?>
                                        <span class="photo-empty">Tidak ada foto</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= !empty($row['is_manual']) ? '<span class="badge badge-warning">Manual</span>' : '<span class="text-muted">-</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileAbsensiList">
                <?php foreach ($absensiData as $row): ?>
                    <?php $badgeClass = absensiStatusBadgeClass((string) ($row['status'] ?? '')); ?>
                    <div class="mobile-data-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars($row['nama_karyawan']) ?></div>
                                <div class="mobile-data-subtitle"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></div>
                            </div>
                            <span class="badge <?= $badgeClass ?>"><?= ucfirst($row['status']) ?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Jam</span>
                                <span class="mobile-data-value"><?= $row['jam_masuk'] ? substr($row['jam_masuk'], 0, 5) : '-' ?> - <?= $row['jam_keluar'] ? substr($row['jam_keluar'], 0, 5) : '-' ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Input</span>
                                <span class="mobile-data-value"><?= !empty($row['is_manual']) ? 'Manual' : 'Normal' ?></span>
                            </div>
                            <?php if (!empty($row['keterangan'])): ?>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Catatan</span>
                                    <span class="mobile-data-value"><?= htmlspecialchars($row['keterangan']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="photo-strip" style="margin-top: 12px;">
                            <?php if (!empty($row['foto_masuk'])): ?>
                                <img src="<?= htmlspecialchars(attendancePhotoUrl((string) $row['foto_masuk'])) ?>" alt="Masuk" class="selfie-thumb" onclick="openLightbox(this.src)">
                            <?php endif; ?>
                            <?php if (!empty($row['foto_keluar'])): ?>
                                <img src="<?= htmlspecialchars(attendancePhotoUrl((string) $row['foto_keluar'])) ?>" alt="Keluar" class="selfie-thumb" onclick="openLightbox(this.src)">
                            <?php endif; ?>
                            <?php if (empty($row['foto_masuk']) && empty($row['foto_keluar'])): ?>
                                <span class="photo-empty">Tanpa foto</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-calendar-xmark"></i>
                <div>Tidak ada data absensi untuk periode yang dipilih.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalManual">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-pencil-alt"></i> Input Absensi Manual</h5>
            <button class="modal-close" onclick="closeModal('modalManual')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="manual">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Karyawan *</label>
                    <select name="karyawan_id" class="form-control" required>
                        <option value="">-- Pilih Karyawan --</option>
                        <?php foreach ($allKaryawan as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $filterKarId === (int) $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal *</label>
                    <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Status *</label>
                    <select name="status" class="form-control" required>
                        <option value="izin">Izin</option>
                        <option value="sakit">Sakit</option>
                        <option value="alpha">Alpha</option>
                        <option value="hadir">Hadir</option>
                        <option value="terlambat">Terlambat</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Keterangan</label>
                    <textarea name="keterangan" class="form-control" rows="3" placeholder="Alasan atau catatan tambahan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalManual')">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<div id="lightboxOverlay" onclick="closeLightbox()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;cursor:zoom-out">
    <img id="lightboxImg" src="" alt="Foto Selfie" style="max-width:90vw;max-height:90vh;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.5)">
    <button onclick="closeLightbox()" style="position:absolute;top:16px;right:20px;background:none;border:none;color:#fff;font-size:2rem;cursor:pointer;line-height:1">&times;</button>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
