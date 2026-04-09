<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/file_manager.php';
requireRole('superadmin', 'admin');
$pageTitle = 'Karyawan';
payrollScheduleSupportReady($conn);

$hasKaryawanTable = schemaTableExists($conn, 'karyawan');
$hasUsersTable = schemaTableExists($conn, 'users');
$newCols = $hasKaryawanTable ? schemaTableColumns($conn, 'karyawan') : [];
$hasNewCols = in_array('user_id', $newCols, true);
$hasKaryawanStatus = in_array('status', $newCols, true);

function karyawanDeletionDependencies(mysqli $conn, int $employeeId): array
{
    return schemaBuildDependencyList($conn, $employeeId, [
        'absensi' => ['table' => 'absensi', 'column' => 'karyawan_id'],
        'slip gaji' => ['table' => 'slip_gaji', 'column' => 'karyawan_id'],
        'hasil KPI' => ['table' => 'kpi_hasil', 'column' => 'karyawan_id'],
        'produksi' => ['table' => 'produksi', 'column' => 'karyawan_id'],
    ]);
}

function uploadFoto(string $fileKey, ?string &$errorMessage = null): ?string
{
    $errorMessage = null;

    if (isUploadRequestTooLarge()) {
        $errorMessage = buildUploadTooLargeMessage();
        return null;
    }

    if (empty($_FILES[$fileKey]['name'])) {
        return null;
    }

    $file = $_FILES[$fileKey];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        $errorMessage = uploadErrorMessage($errorCode, (string) ($file['name'] ?? 'Foto'));
        return null;
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $errorMessage = 'Foto harus berformat JPG, JPEG, PNG, GIF, atau WEBP.';
        return null;
    }

    $dir = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'karyawan' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        $errorMessage = 'Folder upload foto karyawan belum bisa ditulisi di server.';
        return null;
    }

    $fname = 'kar_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
        return $fname;
    }

    $errorMessage = 'Foto gagal dipindahkan ke server.';
    return null;
}

$msg = '';
if (isset($_POST['action'])) {
    $act = $_POST['action'];

    if ($act === 'tambah') {
        $nama = trim($_POST['nama']);
        $jab = trim($_POST['jabatan']);
        $tlp = trim($_POST['telepon']);
        $email = trim($_POST['email']);
        $alamat = trim($_POST['alamat']);
        $gaji = floatval($_POST['gaji'] ?? 0);
        $tgl = $_POST['tanggal_masuk'];
        $status = $_POST['status'];

        if ($hasNewCols) {
            $uid = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
            $nik = trim($_POST['nik'] ?? '');
            $divisi = $_POST['divisi'] ?? 'umum';
            $tglLhr = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
            $metode = payrollNormalizeMethod((string) ($_POST['metode_gaji'] ?? 'bulanan'));
            $gajiPok = floatval($_POST['gaji_pokok'] ?? 0);
            $tarif = floatval($_POST['tarif_borongan'] ?? 0);
            $fotoError = null;
            $foto = uploadFoto('foto', $fotoError);

            if ($fotoError !== null) {
                $msg = 'danger|' . $fotoError;
                goto render;
            }

            if ($nik !== '' && !preg_match('/^[0-9]{16}$/', $nik)) {
                $msg = 'danger|NIK harus 16 digit angka.';
                goto render;
            }

            if ($uid !== null) {
                $chk = $conn->prepare("SELECT id FROM karyawan WHERE user_id = ?");
                $chk->bind_param('i', $uid);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $msg = 'danger|User sudah terhubung ke karyawan lain.';
                    goto render;
                }
            }

            $nikVal = $nik !== '' ? $nik : null;
            $stmt = $conn->prepare("INSERT INTO karyawan (user_id,nama,jabatan,telepon,email,alamat,gaji,tanggal_masuk,status,nik,divisi,tanggal_lahir,foto,metode_gaji,gaji_pokok,tarif_borongan) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('isssssdsssssssdd', $uid, $nama, $jab, $tlp, $email, $alamat, $gaji, $tgl, $status, $nikVal, $divisi, $tglLhr, $foto, $metode, $gajiPok, $tarif);
        } else {
            $stmt = $conn->prepare("INSERT INTO karyawan (nama,jabatan,telepon,email,alamat,gaji,tanggal_masuk,status) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssdss', $nama, $jab, $tlp, $email, $alamat, $gaji, $tgl, $status);
        }

        $stmt->execute() ? $msg = 'success|Karyawan ditambahkan.' : $msg = 'danger|Gagal: ' . $conn->error;
    } elseif ($act === 'edit') {
        $id = intval($_POST['id']);
        $nama = trim($_POST['nama']);
        $jab = trim($_POST['jabatan']);
        $tlp = trim($_POST['telepon']);
        $email = trim($_POST['email']);
        $alamat = trim($_POST['alamat']);
        $gaji = floatval($_POST['gaji'] ?? 0);
        $tgl = $_POST['tanggal_masuk'];
        $status = $_POST['status'];

        if ($hasNewCols) {
            $uid = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
            $nik = trim($_POST['nik'] ?? '');
            $divisi = $_POST['divisi'] ?? 'umum';
            $tglLhr = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
            $metode = payrollNormalizeMethod((string) ($_POST['metode_gaji'] ?? 'bulanan'));
            $gajiPok = floatval($_POST['gaji_pokok'] ?? 0);
            $tarif = floatval($_POST['tarif_borongan'] ?? 0);
            $fotoError = null;
            $newFoto = uploadFoto('foto', $fotoError);

            if ($fotoError !== null) {
                $msg = 'danger|' . $fotoError;
                goto render;
            }

            if ($nik !== '' && !preg_match('/^[0-9]{16}$/', $nik)) {
                $msg = 'danger|NIK harus 16 digit angka.';
                goto render;
            }

            if ($uid !== null) {
                $chk = $conn->prepare("SELECT id FROM karyawan WHERE user_id = ? AND id != ?");
                $chk->bind_param('ii', $uid, $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $msg = 'danger|User sudah terhubung ke karyawan lain.';
                    goto render;
                }
            }

            $nikVal = $nik !== '' ? $nik : null;
            if ($newFoto) {
                $stmt = $conn->prepare("UPDATE karyawan SET user_id=?,nama=?,jabatan=?,telepon=?,email=?,alamat=?,gaji=?,tanggal_masuk=?,status=?,nik=?,divisi=?,tanggal_lahir=?,foto=?,metode_gaji=?,gaji_pokok=?,tarif_borongan=? WHERE id=?");
                $stmt->bind_param('isssssdsssssssddi', $uid, $nama, $jab, $tlp, $email, $alamat, $gaji, $tgl, $status, $nikVal, $divisi, $tglLhr, $newFoto, $metode, $gajiPok, $tarif, $id);
            } else {
                $stmt = $conn->prepare("UPDATE karyawan SET user_id=?,nama=?,jabatan=?,telepon=?,email=?,alamat=?,gaji=?,tanggal_masuk=?,status=?,nik=?,divisi=?,tanggal_lahir=?,metode_gaji=?,gaji_pokok=?,tarif_borongan=? WHERE id=?");
                $stmt->bind_param('isssssdssssssddi', $uid, $nama, $jab, $tlp, $email, $alamat, $gaji, $tgl, $status, $nikVal, $divisi, $tglLhr, $metode, $gajiPok, $tarif, $id);
            }
        } else {
            $stmt = $conn->prepare("UPDATE karyawan SET nama=?,jabatan=?,telepon=?,email=?,alamat=?,gaji=?,tanggal_masuk=?,status=? WHERE id=?");
            $stmt->bind_param('sssssdssi', $nama, $jab, $tlp, $email, $alamat, $gaji, $tgl, $status, $id);
        }

        $stmt->execute() ? $msg = 'success|Data diperbarui.' : $msg = 'danger|Gagal: ' . $conn->error;
    } elseif ($act === 'hapus') {
        $id = intval($_POST['id']);
        $dependencies = karyawanDeletionDependencies($conn, $id);
        if (!empty($dependencies)) {
            $msg = 'danger|Data tidak dapat dihapus karena masih dipakai di modul ' . implode(', ', $dependencies) . '. Ubah status menjadi nonaktif jika hanya ingin mengarsipkan.';
        } else {
            $stmt = $conn->prepare("DELETE FROM karyawan WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute() ? $msg = 'success|Data dihapus.' : $msg = 'danger|Gagal menghapus data karyawan.';
                $stmt->close();
            } else {
                $msg = 'danger|Gagal menyiapkan proses hapus karyawan.';
            }
        }
    }
}

render:
$tab = $_GET['tab'] ?? 'semua';
$where = '';
if ($hasKaryawanStatus) {
    switch ($tab) {
        case 'aktif':
            $where = "WHERE k.status = 'aktif'";
            break;
        case 'nonaktif':
            $where = "WHERE k.status = 'nonaktif'";
            break;
    }
}

if ($hasKaryawanTable && $hasNewCols) {
    $data = schemaFetchAllAssoc($conn, "SELECT k.* FROM karyawan k {$where} ORDER BY k.nama");
} elseif ($hasKaryawanTable) {
    $legacyWhere = $hasKaryawanStatus ? str_replace('k.status', 'status', $where) : '';
    $data = schemaFetchAllAssoc($conn, "SELECT * FROM karyawan {$legacyWhere} ORDER BY nama");
} else {
    $data = [];
}

$cntAll = $hasKaryawanTable ? schemaFetchCount($conn, 'SELECT COUNT(*) FROM karyawan') : 0;
$cntAktif = ($hasKaryawanTable && $hasKaryawanStatus)
    ? schemaFetchCount($conn, "SELECT COUNT(*) FROM karyawan WHERE status='aktif'")
    : $cntAll;
$cntNonaktif = ($hasKaryawanTable && $hasKaryawanStatus)
    ? schemaFetchCount($conn, "SELECT COUNT(*) FROM karyawan WHERE status='nonaktif'")
    : 0;
$cntLinked = $hasNewCols ? count(array_filter($data, static function ($item) {
    return !empty($item['user_id']);
})) : 0;
$cntBorongan = $hasNewCols ? count(array_filter($data, static function ($item) {
    return ($item['metode_gaji'] ?? '') === 'borongan';
})) : 0;
$cntProfitShare = $hasNewCols ? count(array_filter($data, static function ($item) {
    return ($item['metode_gaji'] ?? '') === 'bagi_hasil';
})) : 0;
$selectedCount = count($data);
$tabLabels = [
    'semua' => 'Semua karyawan',
    'aktif' => 'Karyawan aktif',
    'nonaktif' => 'Karyawan nonaktif',
];

$usersAvail = [];
if ($hasNewCols && $hasUsersTable && $hasKaryawanTable) {
    $res = $conn->query("SELECT id, nama, username FROM users WHERE id NOT IN (SELECT user_id FROM karyawan WHERE user_id IS NOT NULL) ORDER BY nama");
    if ($res) {
        $usersAvail = $res->fetch_all(MYSQLI_ASSOC);
    }
}
$missingKaryawanColumns = [];
foreach (['status', 'user_id', 'metode_gaji', 'divisi'] as $column) {
    if (!in_array($column, $newCols, true)) {
        $missingKaryawanColumns[] = $column;
    }
}

$divisiBadgeMap = [
    'printing' => 'badge-info',
    'apparel' => 'badge-mitra',
    'umum' => 'badge-secondary',
];
$metodeBadgeMap = [
    'bulanan' => 'badge-success',
    'mingguan' => 'badge-warning',
    'borongan' => 'badge-info',
    'bagi_hasil' => 'badge-mitra',
];

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">';
$pageState = [
    'hasNewCols' => (bool) $hasNewCols,
    'employeePhotoUrlBase' => pageUrl('media.php?type=karyawan&file='),
    'payrollScheduleRules' => [
        'bulanan' => payrollScheduleRuleLabel('bulanan'),
        'mingguan' => payrollScheduleRuleLabel('mingguan'),
        'borongan' => payrollScheduleRuleLabel('borongan'),
        'bagi_hasil' => payrollScheduleRuleLabel('bagi_hasil'),
    ],
];
$pageJs = 'karyawan.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack admin-panel karyawan-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-id-badge"></i> Karyawan</div>
                <h1 class="page-title">Data SDM kini lebih rapi untuk admin, HR, dan pengawasan operasional</h1>
                <p class="page-description">
                    Modul karyawan dirapikan agar informasi jabatan, divisi, kontak, metode gaji, dan status akun bisa dibaca cepat di desktop maupun mobile tanpa tabel yang terasa berat.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-filter"></i> Filter aktif: <?= htmlspecialchars($tabLabels[$tab] ?? 'Semua karyawan') ?></span>
                    <span class="page-meta-item"><i class="fas fa-users"></i> <?= number_format($selectedCount) ?> data tampil</span>
                    <?php if ($hasNewCols): ?>
                        <span class="page-meta-item"><i class="fas fa-link"></i> <?= number_format($cntLinked) ?> akun user terhubung</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('modalTambah')"><i class="fas fa-user-plus"></i> Tambah Karyawan</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Total Karyawan</span>
            <span class="metric-value"><?= number_format($cntAll) ?></span>
            <span class="metric-note">Seluruh data karyawan yang saat ini tercatat di sistem.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Aktif</span>
            <span class="metric-value"><?= number_format($cntAktif) ?></span>
            <span class="metric-note">Karyawan yang masih bekerja dan relevan untuk absensi serta payroll.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Nonaktif</span>
            <span class="metric-value"><?= number_format($cntNonaktif) ?></span>
            <span class="metric-note">Data lama tetap tersimpan untuk kebutuhan arsip dan riwayat.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label"><?= $hasNewCols ? 'Metode Borongan' : 'Akun Terhubung' ?></span>
            <span class="metric-value"><?= number_format($hasNewCols ? $cntBorongan : $cntLinked) ?></span>
            <span class="metric-note"><?= $hasNewCols ? 'Perlu perhatian khusus saat generate slip gaji dan evaluasi produksi.' : 'Hubungkan schema baru bila ingin sinkron ke user dan payroll.' ?></span>
        </div>
        <?php if ($hasNewCols): ?>
        <div class="metric-card">
            <span class="metric-label">Bagi Hasil</span>
            <span class="metric-value"><?= number_format($cntProfitShare) ?></span>
            <span class="metric-note">Akan mengikuti jadwal payout tetap setiap tanggal 5.</span>
        </div>
        <?php endif; ?>
    </div>

    <div class="toolbar-surface admin-filter-grid">
        <div class="section-heading">
            <div>
                <h2>Filter & Pencarian Karyawan</h2>
                <p>Pindah antara karyawan aktif dan nonaktif, lalu cari nama, jabatan, kontak, atau divisi dari layar yang sama.</p>
            </div>
        </div>
        <?php if (!$hasKaryawanTable): ?>
            <div class="info-banner warning">
                <strong>Tabel <code>karyawan</code> belum tersedia.</strong> Halaman tetap dibuka, tetapi data SDM baru akan tampil setelah schema database dilengkapi.
            </div>
        <?php elseif (!empty($missingKaryawanColumns)): ?>
            <div class="info-banner note">
                <strong>Schema karyawan di hosting belum lengkap.</strong> Kolom yang belum tersedia: <?= htmlspecialchars(implode(', ', $missingKaryawanColumns)) ?>.
            </div>
        <?php endif; ?>
        <div class="filter-pills">
            <a href="?tab=semua" class="filter-pill <?= $tab === 'semua' ? 'active' : '' ?>">
                <span>Semua</span>
                <span class="filter-pill-count"><?= number_format($cntAll) ?></span>
            </a>
            <a href="?tab=aktif" class="filter-pill <?= $tab === 'aktif' ? 'active' : '' ?>">
                <span>Aktif</span>
                <span class="filter-pill-count"><?= number_format($cntAktif) ?></span>
            </a>
            <a href="?tab=nonaktif" class="filter-pill <?= $tab === 'nonaktif' ? 'active' : '' ?>">
                <span>Nonaktif</span>
                <span class="filter-pill-count"><?= number_format($cntNonaktif) ?></span>
            </a>
        </div>
        <?php if (!$hasNewCols): ?>
            <div class="info-banner warning">
                <strong>Schema karyawan versi lama masih dipakai.</strong> Form tetap bekerja, tetapi field seperti divisi, foto, akun user, dan metode gaji baru akan aktif penuh setelah tabel memakai kolom baru.
            </div>
        <?php endif; ?>
        <div class="search-bar">
            <input type="text" id="srchKaryawan" class="form-control" placeholder="Cari nama, jabatan, telepon, email, divisi, atau status..." oninput="filterKaryawanView()">
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-address-book"></i> Direktori Karyawan</span>
                <div class="card-subtitle">Desktop memakai tabel ringkas, mobile memakai kartu personel dengan aksi edit dan hapus yang sama.</div>
            </div>
        </div>

        <?php if (!empty($data)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblKaryawan">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Profil</th>
                            <th>Kontak</th>
                            <th>Payroll</th>
                            <th>Tanggal Masuk</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($data as $i => $d): ?>
                        <?php
                        $divisi = $d['divisi'] ?? 'umum';
                        $metode = $d['metode_gaji'] ?? 'bulanan';
                        $fotoUrl = !empty($d['foto']) ? employeePhotoUrl((string) $d['foto']) : null;
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="avatar-stack">
                                    <span class="avatar-shell">
                                        <?php if ($fotoUrl): ?>
                                            <img src="<?= htmlspecialchars($fotoUrl) ?>" alt="<?= htmlspecialchars($d['nama']) ?>">
                                        <?php else: ?>
                                            <span class="avatar-fallback"><?= strtoupper(substr($d['nama'], 0, 1)) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <div class="inventory-title">
                                        <strong><?= htmlspecialchars($d['nama']) ?></strong>
                                        <div class="inventory-meta">
                                            <span><i class="fas fa-briefcase"></i> <?= htmlspecialchars($d['jabatan'] ?: '-') ?></span>
                                        </div>
                                        <?php if ($hasNewCols): ?>
                                            <div class="status-stack">
                                                <span class="badge <?= $divisiBadgeMap[$divisi] ?? 'badge-secondary' ?>"><?= ucfirst(htmlspecialchars($divisi)) ?></span>
                                                <span class="badge <?= $metodeBadgeMap[$metode] ?? 'badge-secondary' ?>"><?= htmlspecialchars(payrollMethodLabel($metode)) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="contact-stack">
                                    <span class="code-pill"><i class="fas fa-phone"></i> <?= htmlspecialchars($d['telepon'] ?: '-') ?></span>
                                    <span class="code-pill"><i class="fas fa-envelope"></i> <?= htmlspecialchars($d['email'] ?: '-') ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="inventory-title">
                                    <strong class="<?= ($d['gaji_pokok'] ?? 0) > 0 || ($d['gaji'] ?? 0) > 0 ? '' : 'text-muted' ?>">
                                        Rp <?= number_format((float) ($hasNewCols ? ($d['gaji_pokok'] ?? 0) : ($d['gaji'] ?? 0)), 0, ',', '.') ?>
                                    </strong>
                                    <?php if ($hasNewCols && $metode === 'borongan'): ?>
                                        <div class="inventory-meta">
                                            <span><i class="fas fa-tags"></i> Tarif Rp <?= number_format((float) ($d['tarif_borongan'] ?? 0), 0, ',', '.') ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($hasNewCols): ?>
                                        <div class="inventory-meta">
                                            <span><i class="fas fa-calendar-check"></i> <?= htmlspecialchars(payrollScheduleRuleLabel($metode)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="inventory-title">
                                    <strong><?= !empty($d['tanggal_masuk']) ? date('d/m/Y', strtotime($d['tanggal_masuk'])) : '-' ?></strong>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $d['status'] === 'aktif' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= htmlspecialchars(ucfirst($d['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-warning btn-sm btn-edit-karyawan" data-karyawan='<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
                                        <i class="fas fa-edit"></i>
                                    </button>
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
                </table>
            </div>

            <div class="mobile-data-list" id="mobileKaryawanList">
                <?php foreach ($data as $d): ?>
                    <?php
                    $divisi = $d['divisi'] ?? 'umum';
                    $metode = $d['metode_gaji'] ?? 'bulanan';
                    $fotoUrl = !empty($d['foto']) ? employeePhotoUrl((string) $d['foto']) : null;
                    ?>
                    <div class="mobile-data-card">
                        <div class="mobile-data-top">
                            <div class="avatar-stack">
                                <span class="avatar-shell">
                                    <?php if ($fotoUrl): ?>
                                        <img src="<?= htmlspecialchars($fotoUrl) ?>" alt="<?= htmlspecialchars($d['nama']) ?>">
                                    <?php else: ?>
                                        <span class="avatar-fallback"><?= strtoupper(substr($d['nama'], 0, 1)) ?></span>
                                    <?php endif; ?>
                                </span>
                                <div>
                                    <div class="mobile-data-title"><?= htmlspecialchars($d['nama']) ?></div>
                                    <div class="mobile-data-subtitle"><?= htmlspecialchars($d['jabatan'] ?: '-') ?></div>
                                </div>
                            </div>
                            <span class="badge <?= $d['status'] === 'aktif' ? 'badge-success' : 'badge-danger' ?>">
                                <?= htmlspecialchars(ucfirst($d['status'])) ?>
                            </span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Kontak</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($d['telepon'] ?: ($d['email'] ?: '-')) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Masuk</span>
                                <span class="mobile-data-value"><?= !empty($d['tanggal_masuk']) ? date('d/m/Y', strtotime($d['tanggal_masuk'])) : '-' ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Payroll</span>
                                <span class="mobile-data-value">Rp <?= number_format((float) ($hasNewCols ? ($d['gaji_pokok'] ?? 0) : ($d['gaji'] ?? 0)), 0, ',', '.') ?></span>
                            </div>
                            <?php if ($hasNewCols && $metode === 'borongan'): ?>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Tarif</span>
                                    <span class="mobile-data-value">Rp <?= number_format((float) ($d['tarif_borongan'] ?? 0), 0, ',', '.') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasNewCols): ?>
                            <div class="status-stack" style="margin-top: 12px;">
                                <span class="badge <?= $divisiBadgeMap[$divisi] ?? 'badge-secondary' ?>"><?= ucfirst(htmlspecialchars($divisi)) ?></span>
                                <span class="badge <?= $metodeBadgeMap[$metode] ?? 'badge-secondary' ?>"><?= htmlspecialchars(payrollMethodLabel($metode)) ?></span>
                            </div>
                            <div class="inventory-meta" style="margin-top:8px">
                                <span><i class="fas fa-calendar-check"></i> <?= htmlspecialchars(payrollScheduleRuleLabel($metode)) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-warning btn-sm btn-edit-karyawan" data-karyawan='<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'><i class="fas fa-edit"></i> Edit</button>
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
                <i class="fas fa-user-slash"></i>
                <div>Belum ada data karyawan pada filter ini.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalTambah">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <h5>Tambah Karyawan</h5>
            <button class="modal-close" onclick="closeModal('modalTambah')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="tambah">
            <div class="modal-body">
                <?php if ($hasNewCols): ?>
                    <div class="form-group">
                        <label class="form-label">Akun User (opsional)</label>
                        <select name="user_id" id="tUserId" class="form-control">
                            <option value="">-- Tidak dihubungkan --</option>
                            <?php foreach ($usersAvail as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nama']) ?> (<?= htmlspecialchars($u['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Jabatan</label>
                            <select name="jabatan" id="tJabatan" class="form-control">
                                <option value="">-- Pilih Jabatan --</option>
                                <option>CEO</option>
                                <option>Head Office</option>
                                <option>HRD</option>
                                <option>Admin</option>
                                <option>Customer Service</option>
                                <option>Kasir</option>
                                <option>Operator Printing</option>
                                <option>Operator Jahit</option>
                                <option>Tim Produksi Printing</option>
                                <option>Tim Produksi Apparel</option>
                                <option>Office Boy</option>
                            </select>
                            <div id="tRoleBadge" class="role-hint">
                                <small class="text-muted">Saran role akun (atur di Setting User):</small>
                                <span class="badge badge-secondary" id="tRoleBadgeText"></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Divisi</label>
                            <select name="divisi" class="form-control">
                                <option value="umum">Umum</option>
                                <option value="printing">Printing</option>
                                <option value="apparel">Apparel</option>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label">Jabatan</label>
                        <input type="text" name="jabatan" class="form-control">
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama *</label>
                        <input type="text" name="nama" class="form-control" required>
                    </div>
                    <?php if ($hasNewCols): ?>
                        <div class="form-group">
                            <label class="form-label">NIK (16 digit)</label>
                            <input type="text" name="nik" class="form-control" maxlength="16" pattern="[0-9]{16}" placeholder="16 digit angka">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="telepon" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <textarea name="alamat" class="form-control" rows="2"></textarea>
                </div>

                <?php if ($hasNewCols): ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Tanggal Lahir</label>
                            <input type="date" name="tanggal_lahir" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Foto</label>
                            <input type="file" name="foto" class="form-control" accept="image/*">
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Gaji Legacy</label>
                        <input type="number" name="gaji" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Masuk</label>
                        <input type="date" name="tanggal_masuk" class="form-control">
                    </div>
                </div>

                <?php if ($hasNewCols): ?>
                    <div class="form-group">
                        <label class="form-label">Metode Gaji</label>
                        <select name="metode_gaji" id="tMetodeGaji" class="form-control">
                            <option value="bulanan">Bulanan</option>
                            <option value="mingguan">Mingguan</option>
                            <option value="borongan">Borongan</option>
                            <option value="bagi_hasil">Bagi Hasil</option>
                        </select>
                        <div id="tPayrollScheduleHint" class="role-hint active">
                            <small class="text-muted">Jadwal payout:</small>
                            <span class="badge badge-secondary" id="tPayrollScheduleHintText"><?= htmlspecialchars(payrollScheduleRuleLabel('bulanan')) ?></span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" id="tGajiPokokWrap">
                            <label class="form-label">Gaji Pokok</label>
                            <input type="number" name="gaji_pokok" class="form-control" value="0">
                        </div>
                        <div class="form-group" id="tTarifWrap" style="display:none">
                            <label class="form-label">Tarif Borongan (per item)</label>
                            <input type="number" name="tarif_borongan" class="form-control" value="0">
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambah')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalEdit">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <h5>Edit Karyawan</h5>
            <button class="modal-close" onclick="closeModal('modalEdit')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="eId">
            <div class="modal-body">
                <?php if ($hasNewCols): ?>
                    <div class="form-group">
                        <label class="form-label">Akun User (opsional)</label>
                        <select name="user_id" id="eUserId" class="form-control">
                            <option value="">-- Tidak dihubungkan --</option>
                            <?php foreach ($usersAvail as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nama']) ?> (<?= htmlspecialchars($u['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Jabatan</label>
                            <select name="jabatan" id="eJabatan" class="form-control">
                                <option value="">-- Pilih Jabatan --</option>
                                <option>CEO</option>
                                <option>Head Office</option>
                                <option>HRD</option>
                                <option>Admin</option>
                                <option>Customer Service</option>
                                <option>Kasir</option>
                                <option>Operator Printing</option>
                                <option>Operator Jahit</option>
                                <option>Tim Produksi Printing</option>
                                <option>Tim Produksi Apparel</option>
                                <option>Office Boy</option>
                            </select>
                            <div id="eRoleBadge" class="role-hint">
                                <small class="text-muted">Saran role akun (atur di Setting User):</small>
                                <span class="badge badge-secondary" id="eRoleBadgeText"></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Divisi</label>
                            <select name="divisi" id="eDivisi" class="form-control">
                                <option value="umum">Umum</option>
                                <option value="printing">Printing</option>
                                <option value="apparel">Apparel</option>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label">Jabatan</label>
                        <input type="text" name="jabatan" id="eJabatanText" class="form-control">
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama *</label>
                        <input type="text" name="nama" id="eNama" class="form-control" required>
                    </div>
                    <?php if ($hasNewCols): ?>
                        <div class="form-group">
                            <label class="form-label">NIK (16 digit)</label>
                            <input type="text" name="nik" id="eNik" class="form-control" maxlength="16" pattern="[0-9]{16}" placeholder="16 digit angka">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="telepon" id="eTlp" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="eEmail" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <textarea name="alamat" id="eAlamat" class="form-control" rows="2"></textarea>
                </div>

                <?php if ($hasNewCols): ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Tanggal Lahir</label>
                            <input type="date" name="tanggal_lahir" id="eTglLhr" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Foto Baru (kosongkan jika tidak ganti)</label>
                            <input type="file" name="foto" class="form-control" accept="image/*">
                            <div id="eFotoPreview" class="modal-preview"></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Gaji Legacy</label>
                        <input type="number" name="gaji" id="eGaji" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Masuk</label>
                        <input type="date" name="tanggal_masuk" id="eTgl" class="form-control">
                    </div>
                </div>

                <?php if ($hasNewCols): ?>
                    <div class="form-group">
                        <label class="form-label">Metode Gaji</label>
                        <select name="metode_gaji" id="eMetodeGaji" class="form-control">
                            <option value="bulanan">Bulanan</option>
                            <option value="mingguan">Mingguan</option>
                            <option value="borongan">Borongan</option>
                            <option value="bagi_hasil">Bagi Hasil</option>
                        </select>
                        <div id="ePayrollScheduleHint" class="role-hint active">
                            <small class="text-muted">Jadwal payout:</small>
                            <span class="badge badge-secondary" id="ePayrollScheduleHintText"><?= htmlspecialchars(payrollScheduleRuleLabel('bulanan')) ?></span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" id="eGajiPokokWrap">
                            <label class="form-label">Gaji Pokok</label>
                            <input type="number" name="gaji_pokok" id="eGajiPokok" class="form-control" value="0">
                        </div>
                        <div class="form-group" id="eTarifWrap" style="display:none">
                            <label class="form-label">Tarif Borongan (per item)</label>
                            <input type="number" name="tarif_borongan" id="eTarif" class="form-control" value="0">
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="eStatus" class="form-control">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEdit')">Batal</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
