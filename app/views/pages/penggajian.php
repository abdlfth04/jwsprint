<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
$pageTitle = 'Penggajian';
payrollScheduleSupportReady($conn);

function payrollPeriodEndExclusive(string $periodEnd): string
{
    return (new DateTimeImmutable($periodEnd !== '' ? $periodEnd : date('Y-m-d')))
        ->modify('+1 day')
        ->format('Y-m-d');
}

$hasSlipGaji = schemaTableExists($conn, 'slip_gaji');
$hasAbsensi = schemaTableExists($conn, 'absensi');
$hasKaryawanTable = schemaTableExists($conn, 'karyawan');
$hasTodoTahapan = schemaTableExists($conn, 'todo_list_tahapan');
$hasKarNewCols = $hasKaryawanTable && schemaColumnExists($conn, 'karyawan', 'metode_gaji');
$hasSettingCols = schemaColumnExists($conn, 'setting', 'potongan_per_hari');
$hasKaryawanStatus = $hasKaryawanTable && schemaColumnExists($conn, 'karyawan', 'status');

$msg = '';
$duplicateFlag = false;

if (isset($_POST['action'])) {
    $act = $_POST['action'];

    if ($act === 'generate' || $act === 'generate_overwrite') {
        if (!$hasSlipGaji) {
            $msg = 'danger|Tabel slip_gaji belum ada. Jalankan migrasi database terlebih dahulu.';
            goto render;
        }

        $karId = intval($_POST['karyawan_id']);
        $periMulai = $_POST['periode_mulai'];
        $periSelesai = $_POST['periode_selesai'];
        $periSelesaiExclusive = payrollPeriodEndExclusive($periSelesai);
        $tunjangan = floatval($_POST['tunjangan'] ?? 0);
        $bonus = floatval($_POST['bonus'] ?? 0);
        $createdBy = $_SESSION['user_id'];

        if ($hasKarNewCols) {
            $stmtK = $conn->prepare("SELECT k.*, u.id as uid FROM karyawan k LEFT JOIN users u ON k.user_id = u.id WHERE k.id = ?");
        } else {
            $stmtK = $conn->prepare("SELECT * FROM karyawan WHERE id = ?");
        }
        $stmtK->bind_param('i', $karId);
        $stmtK->execute();
        $kar = $stmtK->get_result()->fetch_assoc();
        $stmtK->close();

        if (!$kar) {
            $msg = 'danger|Karyawan tidak ditemukan.';
            goto render;
        }

        $gajiPokok = 0;
        $metode = payrollNormalizeMethod((string) ($kar['metode_gaji'] ?? 'bulanan'));

        if (in_array($metode, ['bulanan', 'mingguan', 'bagi_hasil'], true)) {
            $gajiPokok = floatval($kar['gaji_pokok'] ?? 0);
        } elseif ($metode === 'borongan') {
            $userId = $kar['user_id'] ?? null;
            if ($userId && $hasTodoTahapan) {
                $stmtB = $conn->prepare(
                    "SELECT COUNT(*) as cnt FROM todo_list_tahapan
                     WHERE selesai_oleh = ? AND status = 'selesai'
                     AND selesai_at >= ? AND selesai_at < ?"
                );
                $stmtB->bind_param('iss', $userId, $periMulai, $periSelesaiExclusive);
                $stmtB->execute();
                $rowB = $stmtB->get_result()->fetch_assoc();
                $stmtB->close();
                $gajiPokok = intval($rowB['cnt']) * floatval($kar['tarif_borongan'] ?? 0);
            }
        }

        $potongan = 0;
        if ($hasAbsensi && $hasSettingCols) {
            $setting = schemaFetchAssoc($conn, "SELECT potongan_per_hari FROM setting WHERE id=1");
            $potonganPerHari = floatval($setting['potongan_per_hari'] ?? 0);

            $stmtA = $conn->prepare(
                "SELECT COUNT(*) as cnt FROM absensi
                 WHERE karyawan_id = ? AND status = 'alpha'
                 AND tanggal >= ? AND tanggal < ?"
            );
            $stmtA->bind_param('iss', $karId, $periMulai, $periSelesaiExclusive);
            $stmtA->execute();
            $rowA = $stmtA->get_result()->fetch_assoc();
            $stmtA->close();
            $potongan = intval($rowA['cnt']) * $potonganPerHari;
        }

        $totalGaji = $gajiPokok + $tunjangan + $bonus - $potongan;
        $jadwalBayar = payrollResolveScheduledPayDate($metode, $periSelesai);

        $stmtDup = $conn->prepare(
            "SELECT id, status_bayar FROM slip_gaji WHERE karyawan_id = ? AND periode_mulai = ? AND periode_selesai = ?"
        );
        $stmtDup->bind_param('iss', $karId, $periMulai, $periSelesai);
        $stmtDup->execute();
        $rowDup = $stmtDup->get_result()->fetch_assoc();
        $stmtDup->close();

        if ($rowDup && $act !== 'generate_overwrite') {
            $duplicateFlag = true;
            $_SESSION['_slip_form'] = [
                'karyawan_id' => $karId,
                'periode_mulai' => $periMulai,
                'periode_selesai' => $periSelesai,
                'tunjangan' => $tunjangan,
                'bonus' => $bonus,
                'can_overwrite' => ($rowDup['status_bayar'] ?? '') !== 'sudah_dibayar',
                'status_bayar' => $rowDup['status_bayar'] ?? '',
            ];
            goto render;
        }

        if ($rowDup && $act === 'generate_overwrite') {
            if (($rowDup['status_bayar'] ?? '') === 'sudah_dibayar') {
                $msg = 'danger|Slip yang sudah dibayar tidak dapat ditimpa. Buat koreksi manual terlebih dahulu bila perlu revisi.';
                unset($_SESSION['_slip_form']);
                goto render;
            }

            $stmtU = $conn->prepare(
                "UPDATE slip_gaji
                 SET metode_gaji=?, jadwal_bayar=?, gaji_pokok=?, tunjangan=?, bonus=?, potongan=?, total_gaji=?, created_by=?
                 WHERE id=?"
            );
            $stmtU->bind_param('ssddddddi', $metode, $jadwalBayar, $gajiPokok, $tunjangan, $bonus, $potongan, $totalGaji, $createdBy, $rowDup['id']);
            $stmtU->execute() ? $msg = 'success|Slip gaji berhasil diperbarui.' : $msg = 'danger|Gagal: ' . $conn->error;
            $stmtU->close();
        } else {
            $stmtI = $conn->prepare(
                "INSERT INTO slip_gaji (
                    karyawan_id, metode_gaji, periode_mulai, periode_selesai, jadwal_bayar,
                    gaji_pokok, tunjangan, bonus, potongan, total_gaji, created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtI->bind_param('issssdddddi', $karId, $metode, $periMulai, $periSelesai, $jadwalBayar, $gajiPokok, $tunjangan, $bonus, $potongan, $totalGaji, $createdBy);
            $stmtI->execute() ? $msg = 'success|Slip gaji berhasil dibuat.' : $msg = 'danger|Gagal: ' . $conn->error;
            $stmtI->close();
        }

        unset($_SESSION['_slip_form']);
    }

    if ($act === 'bayar') {
        if (!hasRole('superadmin')) {
            $msg = 'danger|Hanya superadmin yang dapat menandai slip sebagai dibayar.';
            goto render;
        }
        if (!$hasSlipGaji) {
            $msg = 'danger|Tabel slip_gaji belum ada.';
            goto render;
        }

        $slipId = intval($_POST['slip_id']);
        $bayarBy = $_SESSION['user_id'];
        $stmtByr = $conn->prepare(
            "UPDATE slip_gaji SET status_bayar='sudah_dibayar', tgl_bayar=CURDATE(), dibayar_oleh=? WHERE id=?"
        );
        $stmtByr->bind_param('ii', $bayarBy, $slipId);
        $stmtByr->execute() ? $msg = 'success|Slip gaji ditandai sudah dibayar.' : $msg = 'danger|Gagal: ' . $conn->error;
        $stmtByr->close();
    }
}

render:
$filterKarId = intval($_GET['karyawan_id'] ?? 0);
$filterBulan = $_GET['bulan'] ?? '';

$karyawanWhere = $hasKaryawanStatus ? "WHERE status='aktif'" : '';
$karyawanSelectMethod = $hasKarNewCols ? 'metode_gaji' : "'bulanan' AS metode_gaji";
$allKaryawan = $hasKaryawanTable
    ? schemaFetchAllAssoc($conn, "SELECT id, nama, jabatan, {$karyawanSelectMethod} FROM karyawan {$karyawanWhere} ORDER BY nama")
    : [];

$slipData = [];
if ($hasSlipGaji && $hasKaryawanTable) {
    $where = [];
    $params = [];
    $types = '';

    if ($filterKarId > 0) {
        $where[] = 'sg.karyawan_id = ?';
        $params[] = $filterKarId;
        $types .= 'i';
    }
    if ($filterBulan) {
        $where[] = "DATE_FORMAT(sg.periode_mulai, '%Y-%m') = ?";
        $params[] = $filterBulan;
        $types .= 's';
    }

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $metodeSlipSelect = $hasKarNewCols ? 'k.metode_gaji AS metode_gaji_karyawan' : "'bulanan' AS metode_gaji_karyawan";
    $sql = "SELECT sg.*, k.nama as nama_karyawan, k.jabatan, {$metodeSlipSelect}
            FROM slip_gaji sg
            JOIN karyawan k ON sg.karyawan_id = k.id
            $whereStr
            ORDER BY sg.periode_mulai DESC, k.nama ASC";

    if ($params) {
        $stmtS = $conn->prepare($sql);
        $stmtS->bind_param($types, ...$params);
        $stmtS->execute();
        $slipData = $stmtS->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtS->close();
    } else {
        $slipData = schemaFetchAllAssoc($conn, $sql);
    }
}

foreach ($slipData as $index => $row) {
    $metodeSlip = payrollNormalizeMethod((string) ($row['metode_gaji'] ?? ($row['metode_gaji_karyawan'] ?? 'bulanan')));
    $jadwalBayar = trim((string) ($row['jadwal_bayar'] ?? ''));
    if ($jadwalBayar === '') {
        $jadwalBayar = payrollResolveScheduledPayDate($metodeSlip, (string) ($row['periode_selesai'] ?? date('Y-m-d')));
    }

    $slipData[$index]['metode_gaji_view'] = $metodeSlip;
    $slipData[$index]['metode_gaji_label'] = payrollMethodLabel($metodeSlip);
    $slipData[$index]['jadwal_bayar_view'] = $jadwalBayar;
    $slipData[$index]['jadwal_rule_label'] = payrollScheduleRuleLabel($metodeSlip);
}

$payrollScheduleDefaults = [
    'bulanan' => payrollResolveSuggestedPeriod('bulanan'),
    'mingguan' => payrollResolveSuggestedPeriod('mingguan'),
    'borongan' => payrollResolveSuggestedPeriod('borongan'),
    'bagi_hasil' => payrollResolveSuggestedPeriod('bagi_hasil'),
];

$paidCount = 0;
$totalPayroll = 0;
$pendingPayroll = 0;
foreach ($slipData as $row) {
    $amount = (float) ($row['total_gaji'] ?? 0);
    $totalPayroll += $amount;
    if (($row['status_bayar'] ?? '') === 'sudah_dibayar') {
        $paidCount++;
    } else {
        $pendingPayroll += $amount;
    }
}
$unpaidCount = count($slipData) - $paidCount;
$periodLabel = $filterBulan ? date('F Y', strtotime($filterBulan . '-01')) : 'Semua periode';

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">';
$pageState = [
    'payrollDuplicateFlag' => (bool) $duplicateFlag,
    'payrollScheduleDefaults' => $payrollScheduleDefaults,
    'payrollScheduleRules' => [
        'bulanan' => payrollScheduleRuleLabel('bulanan'),
        'mingguan' => payrollScheduleRuleLabel('mingguan'),
        'borongan' => payrollScheduleRuleLabel('borongan'),
        'bagi_hasil' => payrollScheduleRuleLabel('bagi_hasil'),
    ],
];
$pageJs = 'penggajian.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack admin-panel penggajian-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-money-bill-wave"></i> Penggajian</div>
                <h1 class="page-title">Slip gaji lebih mudah dipantau, difilter, dan ditindaklanjuti tanpa tabel yang melelahkan</h1>
                <p class="page-description">
                    Modul payroll sekarang dibuat lebih ringkas untuk melihat periode, total gaji, status pembayaran, dan proses generate slip dari desktop maupun mobile browser.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-calendar"></i> <?= htmlspecialchars($periodLabel) ?></span>
                    <span class="page-meta-item"><i class="fas fa-file-invoice-dollar"></i> <?= number_format(count($slipData)) ?> slip tampil</span>
                    <span class="page-meta-item"><i class="fas fa-wallet"></i> Total payroll Rp <?= number_format($totalPayroll, 0, ',', '.') ?></span>
                </div>
            </div>
            <div class="page-actions">
                <?php if ($hasSlipGaji): ?>
                    <button type="button" class="btn btn-primary" onclick="openModal('modalGenerate')"><i class="fas fa-plus"></i> Generate Slip</button>
                <?php endif; ?>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Slip Dibayar</span>
            <span class="metric-value"><?= number_format($paidCount) ?></span>
            <span class="metric-note">Slip yang sudah dikonfirmasi pembayarannya.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Belum Dibayar</span>
            <span class="metric-value"><?= number_format($unpaidCount) ?></span>
            <span class="metric-note">Perlu follow-up sebelum payroll periode berjalan ditutup.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Total Payroll</span>
            <span class="metric-value">Rp <?= number_format($totalPayroll, 0, ',', '.') ?></span>
            <span class="metric-note">Akumulasi nilai slip pada hasil filter saat ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Pending Payroll</span>
            <span class="metric-value">Rp <?= number_format($pendingPayroll, 0, ',', '.') ?></span>
            <span class="metric-note">Total nominal slip yang belum ditandai dibayar.</span>
        </div>
    </div>

    <div class="toolbar-surface admin-filter-grid">
        <div class="section-heading">
            <div>
                <h2>Filter Slip Gaji</h2>
                <p>Pilih karyawan dan periode untuk mempersempit daftar slip, lalu cari cepat nama, jabatan, periode, atau status bayar.</p>
            </div>
        </div>
        <?php if (!$hasSlipGaji): ?>
            <div class="info-banner warning">
                <strong>Tabel <code>slip_gaji</code> belum tersedia.</strong> Jalankan migrasi database terlebih dahulu sebelum generate atau melihat slip gaji.
            </div>
        <?php elseif (!$hasKaryawanTable): ?>
            <div class="info-banner warning">
                <strong>Tabel <code>karyawan</code> belum tersedia.</strong> Modul payroll tetap dibuka, tetapi daftar slip dan form generate membutuhkan master karyawan.
            </div>
        <?php endif; ?>
        <div class="info-banner note">
            Jadwal payroll aktif: bulanan dibayar tiap tanggal 28, mingguan dan borongan dibayar tiap hari Sabtu, sedangkan bagi hasil dibayar tiap tanggal 5.
        </div>
        <div class="admin-toolbar">
            <form method="GET" class="admin-inline-actions" style="flex:1 1 540px;">
                <select name="karyawan_id" class="form-control" style="min-width:220px;">
                    <option value="">Semua karyawan</option>
                    <?php foreach ($allKaryawan as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $filterKarId === (int) $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="month" name="bulan" class="form-control" style="min-width:180px;" value="<?= htmlspecialchars($filterBulan) ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                <a href="<?= pageUrl('penggajian.php') ?>" class="btn btn-outline"><i class="fas fa-rotate-left"></i> Reset</a>
            </form>
            <div class="search-bar">
                <input type="text" id="srchPenggajian" class="form-control" placeholder="Cari nama karyawan, jabatan, periode, atau status bayar..." oninput="filterPenggajianView()">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-file-lines"></i> Daftar Slip Gaji</span>
                <div class="card-subtitle">Cetak slip, tandai dibayar, dan pantau nominal payroll dari tampilan yang tetap ringan di layar kecil.</div>
            </div>
        </div>

        <?php if (!empty($slipData)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblSlip">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Karyawan</th>
                            <th>Periode</th>
                            <th>Metode & Jadwal</th>
                            <th>Komponen</th>
                            <th>Total</th>
                            <th>Status Bayar</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($slipData as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="inventory-title">
                                    <strong><?= htmlspecialchars($s['nama_karyawan']) ?></strong>
                                    <div class="inventory-meta">
                                        <span><i class="fas fa-briefcase"></i> <?= htmlspecialchars($s['jabatan'] ?: '-') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="inventory-title">
                                    <strong><?= date('d/m/Y', strtotime($s['periode_mulai'])) ?> - <?= date('d/m/Y', strtotime($s['periode_selesai'])) ?></strong>
                                </div>
                            </td>
                            <td>
                                <div class="inventory-title">
                                    <strong><?= htmlspecialchars((string) ($s['metode_gaji_label'] ?? 'Bulanan')) ?></strong>
                                    <div class="inventory-meta">
                                        <span><i class="fas fa-calendar-check"></i> <?= htmlspecialchars((string) ($s['jadwal_rule_label'] ?? '-')) ?></span>
                                        <span><i class="fas fa-clock"></i> <?= !empty($s['jadwal_bayar_view']) ? date('d/m/Y', strtotime((string) $s['jadwal_bayar_view'])) : '-' ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="inventory-title">
                                    <strong>Pokok Rp <?= number_format($s['gaji_pokok'], 0, ',', '.') ?></strong>
                                    <div class="inventory-meta">
                                        <?php if ((float) $s['tunjangan'] > 0): ?>
                                            <span><i class="fas fa-plus"></i> Tunjangan Rp <?= number_format($s['tunjangan'], 0, ',', '.') ?></span>
                                        <?php endif; ?>
                                        <?php if ((float) $s['bonus'] > 0): ?>
                                            <span><i class="fas fa-gift"></i> Bonus Rp <?= number_format($s['bonus'], 0, ',', '.') ?></span>
                                        <?php endif; ?>
                                        <?php if ((float) $s['potongan'] > 0): ?>
                                            <span><i class="fas fa-minus-circle"></i> Potongan Rp <?= number_format($s['potongan'], 0, ',', '.') ?></span>
                                        <?php endif; ?>
                                        <?php if ((float) $s['tunjangan'] <= 0 && (float) $s['bonus'] <= 0 && (float) $s['potongan'] <= 0): ?>
                                            <span>Tanpa penyesuaian</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><strong class="money-positive">Rp <?= number_format($s['total_gaji'], 0, ',', '.') ?></strong></td>
                            <td>
                                <?php if (($s['status_bayar'] ?? '') === 'sudah_dibayar'): ?>
                                    <span class="badge badge-success">Sudah Dibayar</span>
                                    <?php if (!empty($s['tgl_bayar'])): ?>
                                        <div class="inventory-meta" style="margin-top: 6px;">
                                            <span><i class="fas fa-check"></i> <?= date('d/m/Y', strtotime($s['tgl_bayar'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-warning">Belum Dibayar</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="<?= pageUrl('slip_cetak.php?id=' . (int) $s['id']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Cetak Slip"><i class="fas fa-print"></i></a>
                                    <?php if (hasRole('superadmin') && ($s['status_bayar'] ?? '') !== 'sudah_dibayar'): ?>
                                        <form method="POST" onsubmit="return confirm('Tandai slip ini sebagai sudah dibayar?')">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="bayar">
                                            <input type="hidden" name="slip_id" value="<?= (int) $s['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm" title="Tandai Dibayar"><i class="fas fa-check"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileSlipList">
                <?php foreach ($slipData as $s): ?>
                    <div class="mobile-data-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars($s['nama_karyawan']) ?></div>
                                <div class="mobile-data-subtitle"><?= date('M Y', strtotime($s['periode_mulai'])) ?></div>
                            </div>
                            <span class="badge <?= ($s['status_bayar'] ?? '') === 'sudah_dibayar' ? 'badge-success' : 'badge-warning' ?>">
                                <?= ($s['status_bayar'] ?? '') === 'sudah_dibayar' ? 'Dibayar' : 'Pending' ?>
                            </span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Periode</span>
                                <span class="mobile-data-value"><?= date('d/m/Y', strtotime($s['periode_mulai'])) ?> - <?= date('d/m/Y', strtotime($s['periode_selesai'])) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Metode</span>
                                <span class="mobile-data-value"><?= htmlspecialchars((string) ($s['metode_gaji_label'] ?? 'Bulanan')) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Jadwal Bayar</span>
                                <span class="mobile-data-value"><?= !empty($s['jadwal_bayar_view']) ? date('d/m/Y', strtotime((string) $s['jadwal_bayar_view'])) : '-' ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Total Gaji</span>
                                <span class="mobile-data-value">Rp <?= number_format($s['total_gaji'], 0, ',', '.') ?></span>
                            </div>
                            <?php if (!empty($s['tgl_bayar'])): ?>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Dibayar</span>
                                    <span class="mobile-data-value"><?= date('d/m/Y', strtotime($s['tgl_bayar'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mobile-data-actions">
                            <a href="<?= pageUrl('slip_cetak.php?id=' . (int) $s['id']) ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Cetak</a>
                            <?php if (hasRole('superadmin') && ($s['status_bayar'] ?? '') !== 'sudah_dibayar'): ?>
                                <form method="POST" onsubmit="return confirm('Tandai slip ini sebagai sudah dibayar?')">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="bayar">
                                    <input type="hidden" name="slip_id" value="<?= (int) $s['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Dibayar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-file-circle-xmark"></i>
                <div><?= $hasSlipGaji ? 'Belum ada slip gaji pada filter ini.' : 'Tabel slip gaji belum tersedia.' ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalGenerate">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-file-invoice-dollar"></i> Generate Slip Gaji</h5>
            <button class="modal-close" onclick="closeModal('modalGenerate')">&times;</button>
        </div>
        <form method="POST" id="formGenerate">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="generate">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Karyawan *</label>
                    <select name="karyawan_id" id="payrollKaryawanId" class="form-control" required>
                        <option value="">-- Pilih Karyawan --</option>
                        <?php foreach ($allKaryawan as $k): ?>
                            <?php $metodeKaryawan = payrollNormalizeMethod((string) ($k['metode_gaji'] ?? 'bulanan')); ?>
                            <option value="<?= $k['id'] ?>" data-metode="<?= htmlspecialchars($metodeKaryawan) ?>">
                                <?= htmlspecialchars($k['nama']) ?><?= !empty($k['jabatan']) ? ' - ' . htmlspecialchars($k['jabatan']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Metode Payroll</label>
                        <input type="text" id="payrollMethodLabel" class="form-control" value="<?= htmlspecialchars(payrollMethodLabel('bulanan')) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jadwal Bayar</label>
                        <input type="date" name="jadwal_bayar_preview" id="payrollScheduleDate" class="form-control" value="<?= htmlspecialchars($payrollScheduleDefaults['bulanan']['jadwal_bayar']) ?>" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Periode Mulai *</label>
                        <input type="date" name="periode_mulai" id="payrollPeriodStart" class="form-control" required value="<?= htmlspecialchars($payrollScheduleDefaults['bulanan']['periode_mulai']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Periode Selesai *</label>
                        <input type="date" name="periode_selesai" id="payrollPeriodEnd" class="form-control" required value="<?= htmlspecialchars($payrollScheduleDefaults['bulanan']['periode_selesai']) ?>">
                    </div>
                </div>
                <div class="info-banner note" id="payrollScheduleHelp">
                    <?= htmlspecialchars(payrollScheduleRuleLabel('bulanan')) ?>. Periode dan jadwal akan menyesuaikan otomatis sesuai metode gaji karyawan.
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tunjangan (Rp)</label>
                        <input type="number" name="tunjangan" class="form-control" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bonus (Rp)</label>
                        <input type="number" name="bonus" class="form-control" value="0" min="0">
                    </div>
                </div>
                <div class="info-banner note">
                    Gaji pokok dan potongan alpha dihitung otomatis berdasarkan data karyawan, metode gaji, serta rekap absensi pada periode tersebut. Metode bagi hasil sementara mengikuti nominal dasar gaji pokok yang tersimpan di data karyawan.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalGenerate')">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-calculator"></i> Generate</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalDuplikat">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i> Slip Sudah Ada</h5>
            <button class="modal-close" onclick="closeModal('modalDuplikat')">&times;</button>
        </div>
        <div class="modal-body">
            <?php $canOverwriteSlip = !isset($_SESSION['_slip_form']['can_overwrite']) || !empty($_SESSION['_slip_form']['can_overwrite']); ?>
            <?php if ($canOverwriteSlip): ?>
                <p>Slip gaji untuk karyawan dan periode ini sudah ada. Anda bisa menimpa data lama bila ingin memperbarui perhitungan.</p>
            <?php else: ?>
                <p>Slip untuk periode ini sudah ditandai dibayar. Demi menjaga histori payroll, slip yang sudah dibayar tidak bisa ditimpa dari form generate.</p>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalDuplikat')">Batal</button>
            <?php if ($canOverwriteSlip): ?>
                <form method="POST">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="generate_overwrite">
                    <input type="hidden" name="karyawan_id" value="<?= htmlspecialchars($_SESSION['_slip_form']['karyawan_id'] ?? '') ?>">
                    <input type="hidden" name="periode_mulai" value="<?= htmlspecialchars($_SESSION['_slip_form']['periode_mulai'] ?? '') ?>">
                    <input type="hidden" name="periode_selesai" value="<?= htmlspecialchars($_SESSION['_slip_form']['periode_selesai'] ?? '') ?>">
                    <input type="hidden" name="tunjangan" value="<?= htmlspecialchars($_SESSION['_slip_form']['tunjangan'] ?? 0) ?>">
                    <input type="hidden" name="bonus" value="<?= htmlspecialchars($_SESSION['_slip_form']['bonus'] ?? 0) ?>">
                    <button type="submit" class="btn btn-warning"><i class="fas fa-sync-alt"></i> Timpa Slip</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
