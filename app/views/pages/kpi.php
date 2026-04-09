<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
$pageTitle = 'KPI';

$hasKpiBobot = schemaTableExists($conn, 'kpi_bobot');
$hasKpiHasil = schemaTableExists($conn, 'kpi_hasil');
$hasKaryawanTable = schemaTableExists($conn, 'karyawan');
$hasTargetPekerjaan = $hasKpiHasil && schemaColumnExists($conn, 'kpi_hasil', 'target_pekerjaan');
$hasKaryawanDivisi = $hasKaryawanTable && schemaColumnExists($conn, 'karyawan', 'divisi');
$hasKaryawanStatus = $hasKaryawanTable && schemaColumnExists($conn, 'karyawan', 'status');

$periodeMulai = $_GET['periode_mulai'] ?? date('Y-m-01');
$periodeSelesai = $_GET['periode_selesai'] ?? date('Y-m-t');
$msg = '';

if (isset($_POST['action']) && $_POST['action'] === 'update_bobot') {
    if ($hasKpiBobot) {
        $bobotPekerjaan = floatval($_POST['bobot_pekerjaan'] ?? 25);
        $bobotDeadline = floatval($_POST['bobot_deadline'] ?? 25);
        $bobotKehadiran = floatval($_POST['bobot_kehadiran'] ?? 25);
        $bobotCustom = floatval($_POST['bobot_custom'] ?? 25);

        $existing = schemaFetchAssoc($conn, "SELECT id FROM kpi_bobot ORDER BY id LIMIT 1");
        if ($existing) {
            $stmt = $conn->prepare("UPDATE kpi_bobot SET bobot_pekerjaan=?, bobot_deadline=?, bobot_kehadiran=?, bobot_custom=?, updated_by=? WHERE id=?");
            $stmt->bind_param('ddddii', $bobotPekerjaan, $bobotDeadline, $bobotKehadiran, $bobotCustom, $_SESSION['user_id'], $existing['id']);
        } else {
            $stmt = $conn->prepare("INSERT INTO kpi_bobot (bobot_pekerjaan, bobot_deadline, bobot_kehadiran, bobot_custom, updated_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param('ddddi', $bobotPekerjaan, $bobotDeadline, $bobotKehadiran, $bobotCustom, $_SESSION['user_id']);
        }
        $stmt->execute() ? $msg = 'success|Bobot KPI berhasil disimpan.' : $msg = 'danger|Gagal menyimpan bobot KPI.';
    } else {
        $msg = 'danger|Tabel KPI belum tersedia.';
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update_target') {
    if ($hasKpiHasil) {
        $karId = intval($_POST['karyawan_id']);
        $targetCustom = floatval($_POST['target_custom'] ?? 0);
        $pencapaianCustom = floatval($_POST['pencapaian_custom'] ?? 0);
        $targetPekerjaan = intval($_POST['target_pekerjaan'] ?? 10);
        $periodStart = $_POST['periode_mulai'] ?? $periodeMulai;
        $periodEnd = $_POST['periode_selesai'] ?? $periodeSelesai;

        if ($hasTargetPekerjaan) {
            $stmt = $conn->prepare(
                "INSERT INTO kpi_hasil (karyawan_id, periode_mulai, periode_selesai, skor_pekerjaan, skor_deadline, skor_kehadiran, skor_custom, skor_total, target_custom, pencapaian_custom, target_pekerjaan)
                 VALUES (?, ?, ?, 0, 0, 0, 0, 0, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE target_custom=VALUES(target_custom), pencapaian_custom=VALUES(pencapaian_custom), target_pekerjaan=VALUES(target_pekerjaan)"
            );
            $stmt->bind_param('issddi', $karId, $periodStart, $periodEnd, $targetCustom, $pencapaianCustom, $targetPekerjaan);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO kpi_hasil (karyawan_id, periode_mulai, periode_selesai, skor_pekerjaan, skor_deadline, skor_kehadiran, skor_custom, skor_total, target_custom, pencapaian_custom)
                 VALUES (?, ?, ?, 0, 0, 0, 0, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE target_custom=VALUES(target_custom), pencapaian_custom=VALUES(pencapaian_custom)"
            );
            $stmt->bind_param('issdd', $karId, $periodStart, $periodEnd, $targetCustom, $pencapaianCustom);
        }

        $stmt->execute() ? $msg = 'success|Target KPI berhasil diperbarui.' : $msg = 'danger|Gagal memperbarui target KPI.';
        $periodeMulai = $periodStart;
        $periodeSelesai = $periodEnd;
    } else {
        $msg = 'danger|Tabel hasil KPI belum tersedia.';
    }
}

$bobot = [
    'bobot_pekerjaan' => 25,
    'bobot_deadline' => 25,
    'bobot_kehadiran' => 25,
    'bobot_custom' => 25,
];
if ($hasKpiBobot) {
    $bobotDb = schemaFetchAssoc($conn, "SELECT * FROM kpi_bobot ORDER BY id LIMIT 1");
    if ($bobotDb) {
        $bobot = array_merge($bobot, $bobotDb);
    }
}

$rows = [];
if ($hasKpiHasil && $hasKaryawanTable) {
    $divisiSelect = $hasKaryawanDivisi ? 'k.divisi' : "'umum' AS divisi";
    $statusWhere = $hasKaryawanStatus ? "WHERE k.status='aktif'" : '';
    $sql = "SELECT k.id as karyawan_id, k.nama, k.jabatan, {$divisiSelect},
                   COALESCE(kh.skor_pekerjaan, 0) as skor_pekerjaan,
                   COALESCE(kh.skor_deadline, 0) as skor_deadline,
                   COALESCE(kh.skor_kehadiran, 0) as skor_kehadiran,
                   COALESCE(kh.skor_custom, 0) as skor_custom,
                   COALESCE(kh.skor_total, 0) as skor_total,
                   COALESCE(kh.target_custom, 0) as target_custom,
                   COALESCE(kh.pencapaian_custom, 0) as pencapaian_custom,
                   kh.created_at"
        . ($hasTargetPekerjaan ? ", COALESCE(kh.target_pekerjaan, 10) as target_pekerjaan" : "")
        . " FROM karyawan k
            LEFT JOIN kpi_hasil kh ON kh.karyawan_id = k.id AND kh.periode_mulai = ? AND kh.periode_selesai = ?
            {$statusWhere}
            ORDER BY COALESCE(kh.skor_total, 0) DESC, k.nama";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $periodeMulai, $periodeSelesai);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} elseif ($hasKaryawanTable) {
    $statusWhere = $hasKaryawanStatus ? "WHERE status='aktif'" : '';
    $divisiSelect = $hasKaryawanDivisi ? 'divisi' : "'umum' AS divisi";
    $rowsResult = $conn->query("SELECT id as karyawan_id, nama, jabatan, {$divisiSelect} FROM karyawan {$statusWhere} ORDER BY nama");
    $rows = $rowsResult ? $rowsResult->fetch_all(MYSQLI_ASSOC) : [];
}

$reviewedRows = [];
foreach ($rows as $row) {
    if (!empty($row['created_at'])) {
        $reviewedRows[] = $row;
    }
}
$reviewedCount = count($reviewedRows);
$avgScore = 0;
if ($reviewedCount > 0) {
    $reviewedScoreTotal = 0;
    foreach ($reviewedRows as $row) {
        $reviewedScoreTotal += (float) ($row['skor_total'] ?? 0);
    }
    $avgScore = round($reviewedScoreTotal / $reviewedCount, 1);
}
$topPerformer = null;
foreach ($rows as $row) {
    if (($row['skor_total'] ?? 0) > 0) {
        $topPerformer = $row;
        break;
    }
}
$attentionCount = 0;
$customCount = 0;
foreach ($rows as $row) {
    if ((float) ($row['skor_total'] ?? 0) < 70) {
        $attentionCount++;
    }
    if ((float) ($row['target_custom'] ?? 0) > 0) {
        $customCount++;
    }
}

$periodLabel = date('d M Y', strtotime($periodeMulai)) . ' - ' . date('d M Y', strtotime($periodeSelesai));
$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">';
$pageState = [
    'kpiCalcUrl' => pageUrl('kpi_ajax.php'),
    'kpiPeriod' => [
        'mulai' => $periodeMulai,
        'selesai' => $periodeSelesai,
    ],
];
$pageJs = 'kpi.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack admin-panel kpi-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-chart-line"></i> KPI</div>
                <h1 class="page-title">Pantau performa tim berdasarkan pekerjaan, deadline, kehadiran, dan target custom</h1>
                <p class="page-description">
                    Modul KPI ini menyatukan hasil evaluasi per karyawan dengan bobot yang bisa diatur ulang, sehingga admin dapat membaca performa tim dari browser desktop maupun mobile tanpa spreadsheet terpisah.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-calendar-days"></i> <?= htmlspecialchars($periodLabel) ?></span>
                    <span class="page-meta-item"><i class="fas fa-users"></i> <?= number_format(count($rows)) ?> karyawan aktif</span>
                    <span class="page-meta-item"><i class="fas fa-bullseye"></i> <?= number_format($customCount) ?> target custom aktif</span>
                </div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" id="btnHitungKpi" onclick="hitungKpi()" <?= (!$hasKpiBobot || !$hasKpiHasil) ? 'disabled' : '' ?>><i class="fas fa-bolt"></i> Hitung Ulang KPI</button>
                <button type="button" class="btn btn-secondary" onclick="openModal('modalBobot')" <?= !$hasKpiBobot ? 'disabled' : '' ?>><i class="fas fa-sliders"></i> Atur Bobot</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-outline"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Skor Rata-rata</span>
            <span class="metric-value"><?= number_format($avgScore, 1) ?></span>
            <span class="metric-note">Rata-rata skor total dari karyawan yang sudah pernah dihitung pada periode ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Top Performer</span>
            <span class="metric-value"><?= $topPerformer ? number_format((float) $topPerformer['skor_total'], 1) : '0.0' ?></span>
            <span class="metric-note"><?= $topPerformer ? htmlspecialchars($topPerformer['nama']) : 'Belum ada hasil KPI yang dihitung.' ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Sudah Dihitung</span>
            <span class="metric-value"><?= number_format($reviewedCount) ?></span>
            <span class="metric-note">Karyawan yang sudah memiliki hasil KPI tersimpan untuk periode ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Perlu Perhatian</span>
            <span class="metric-value"><?= number_format($attentionCount) ?></span>
            <span class="metric-note">Skor di bawah 70 atau belum terisi dapat jadi fokus evaluasi berikutnya.</span>
        </div>
    </div>

    <div class="toolbar-surface admin-filter-grid">
        <div class="section-heading">
            <div>
                <h2>Filter Periode & Pencarian</h2>
                <p>Atur rentang evaluasi KPI, hitung ulang hasil, lalu cari karyawan berdasarkan nama, jabatan, divisi, atau skor.</p>
            </div>
        </div>
        <?php if (!$hasKaryawanTable): ?>
            <div class="info-banner warning">
                <strong>Tabel <code>karyawan</code> belum tersedia.</strong> Modul KPI tetap dibuka, tetapi perhitungan membutuhkan master karyawan.
            </div>
        <?php elseif (!$hasKpiBobot || !$hasKpiHasil): ?>
            <div class="info-banner warning">
                <strong>Tabel KPI belum lengkap.</strong> Pastikan tabel <code>kpi_bobot</code> dan <code>kpi_hasil</code> tersedia sebelum modul ini dipakai penuh.
            </div>
        <?php elseif (!$hasTargetPekerjaan): ?>
            <div class="info-banner note">
                <strong>Kolom target pekerjaan belum tersedia di schema saat ini.</strong> Sistem tetap berjalan memakai default target 10 pekerjaan per periode pada proses hitung KPI.
            </div>
        <?php endif; ?>
        <div class="admin-toolbar">
            <form method="GET" class="admin-inline-actions" style="flex:1 1 520px;">
                <input type="date" name="periode_mulai" class="form-control" value="<?= htmlspecialchars($periodeMulai) ?>">
                <input type="date" name="periode_selesai" class="form-control" value="<?= htmlspecialchars($periodeSelesai) ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                <a href="<?= pageUrl('kpi.php') ?>" class="btn btn-outline"><i class="fas fa-rotate-left"></i> Reset</a>
            </form>
            <div class="search-bar">
                <input type="text" id="srchKpi" class="form-control" placeholder="Cari nama, jabatan, divisi, atau skor..." oninput="filterKpiView()">
            </div>
        </div>
        <div id="kpiCalcStatus" class="text-muted small"></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-ranking-star"></i> Hasil KPI Karyawan</span>
                <div class="card-subtitle">Nilai per komponen dan skor total ditampilkan ringkas untuk desktop maupun mobile.</div>
            </div>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblKpi">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Karyawan</th>
                            <th>Pekerjaan</th>
                            <th>Deadline</th>
                            <th>Kehadiran</th>
                            <th>Custom</th>
                            <th>Total</th>
                            <th>Terakhir Hitung</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $i => $row): ?>
                        <?php
                        $total = (float) ($row['skor_total'] ?? 0);
                        $scoreClass = $total >= 85 ? 'high' : ($total >= 70 ? 'mid' : ($total > 0 ? 'low' : 'empty'));
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="inventory-title">
                                    <strong><?= htmlspecialchars($row['nama']) ?></strong>
                                    <div class="inventory-meta">
                                        <span><i class="fas fa-briefcase"></i> <?= htmlspecialchars($row['jabatan'] ?: ucfirst($row['divisi'] ?? 'umum')) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="score-pill <?= ((float) ($row['skor_pekerjaan'] ?? 0)) > 0 ? (((float) ($row['skor_pekerjaan'] ?? 0)) >= 85 ? 'high' : (((float) ($row['skor_pekerjaan'] ?? 0)) >= 70 ? 'mid' : 'low')) : 'empty' ?>"><?= number_format((float) ($row['skor_pekerjaan'] ?? 0), 0) ?></span></td>
                            <td><span class="score-pill <?= ((float) ($row['skor_deadline'] ?? 0)) > 0 ? (((float) ($row['skor_deadline'] ?? 0)) >= 85 ? 'high' : (((float) ($row['skor_deadline'] ?? 0)) >= 70 ? 'mid' : 'low')) : 'empty' ?>"><?= number_format((float) ($row['skor_deadline'] ?? 0), 0) ?></span></td>
                            <td><span class="score-pill <?= ((float) ($row['skor_kehadiran'] ?? 0)) > 0 ? (((float) ($row['skor_kehadiran'] ?? 0)) >= 85 ? 'high' : (((float) ($row['skor_kehadiran'] ?? 0)) >= 70 ? 'mid' : 'low')) : 'empty' ?>"><?= number_format((float) ($row['skor_kehadiran'] ?? 0), 0) ?></span></td>
                            <td><span class="score-pill <?= ((float) ($row['skor_custom'] ?? 0)) > 0 ? (((float) ($row['skor_custom'] ?? 0)) >= 85 ? 'high' : (((float) ($row['skor_custom'] ?? 0)) >= 70 ? 'mid' : 'low')) : 'empty' ?>"><?= number_format((float) ($row['skor_custom'] ?? 0), 0) ?></span></td>
                            <td>
                                <div class="inventory-title">
                                    <strong><?= number_format($total, 1) ?></strong>
                                    <div class="score-bar"><div class="score-bar-fill <?= $total < 70 ? 'danger' : ($total < 85 ? 'warning' : '') ?>" style="width: <?= max(0, min(100, $total)) ?>%"></div></div>
                                </div>
                            </td>
                            <td><?= !empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '-' ?></td>
                            <td>
                                <button type="button" class="btn btn-secondary btn-sm" onclick='editTargetKpi(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="fas fa-pen-to-square"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileKpiList">
                <?php foreach ($rows as $row): ?>
                    <?php
                    $total = (float) ($row['skor_total'] ?? 0);
                    $scoreClass = $total >= 85 ? 'high' : ($total >= 70 ? 'mid' : ($total > 0 ? 'low' : 'empty'));
                    ?>
                    <div class="mobile-data-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars($row['nama']) ?></div>
                                <div class="mobile-data-subtitle"><?= htmlspecialchars($row['jabatan'] ?: ucfirst($row['divisi'] ?? 'umum')) ?></div>
                            </div>
                            <span class="score-pill <?= $scoreClass ?>"><?= number_format($total, 1) ?></span>
                        </div>
                        <div class="kpi-breakdown">
                            <div class="kpi-breakdown-item">
                                <span class="kpi-breakdown-label">Pekerjaan</span>
                                <div class="score-bar"><div class="score-bar-fill <?= ((float) ($row['skor_pekerjaan'] ?? 0)) < 70 ? 'danger' : ((((float) ($row['skor_pekerjaan'] ?? 0)) < 85) ? 'warning' : '') ?>" style="width: <?= max(0, min(100, (float) ($row['skor_pekerjaan'] ?? 0))) ?>%"></div></div>
                                <span class="kpi-breakdown-value"><?= number_format((float) ($row['skor_pekerjaan'] ?? 0), 0) ?></span>
                            </div>
                            <div class="kpi-breakdown-item">
                                <span class="kpi-breakdown-label">Deadline</span>
                                <div class="score-bar"><div class="score-bar-fill <?= ((float) ($row['skor_deadline'] ?? 0)) < 70 ? 'danger' : ((((float) ($row['skor_deadline'] ?? 0)) < 85) ? 'warning' : '') ?>" style="width: <?= max(0, min(100, (float) ($row['skor_deadline'] ?? 0))) ?>%"></div></div>
                                <span class="kpi-breakdown-value"><?= number_format((float) ($row['skor_deadline'] ?? 0), 0) ?></span>
                            </div>
                            <div class="kpi-breakdown-item">
                                <span class="kpi-breakdown-label">Kehadiran</span>
                                <div class="score-bar"><div class="score-bar-fill <?= ((float) ($row['skor_kehadiran'] ?? 0)) < 70 ? 'danger' : ((((float) ($row['skor_kehadiran'] ?? 0)) < 85) ? 'warning' : '') ?>" style="width: <?= max(0, min(100, (float) ($row['skor_kehadiran'] ?? 0))) ?>%"></div></div>
                                <span class="kpi-breakdown-value"><?= number_format((float) ($row['skor_kehadiran'] ?? 0), 0) ?></span>
                            </div>
                            <div class="kpi-breakdown-item">
                                <span class="kpi-breakdown-label">Custom</span>
                                <div class="score-bar"><div class="score-bar-fill <?= ((float) ($row['skor_custom'] ?? 0)) < 70 ? 'danger' : ((((float) ($row['skor_custom'] ?? 0)) < 85) ? 'warning' : '') ?>" style="width: <?= max(0, min(100, (float) ($row['skor_custom'] ?? 0))) ?>%"></div></div>
                                <span class="kpi-breakdown-value"><?= number_format((float) ($row['skor_custom'] ?? 0), 0) ?></span>
                            </div>
                        </div>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-secondary btn-sm" onclick='editTargetKpi(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen-to-square"></i> Target</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <div>Belum ada data karyawan aktif untuk dihitung di modul KPI.</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel-grid-2 single-panel-grid">
        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-sliders"></i> Bobot Aktif</span>
                    <div class="card-subtitle">Bobot ini dipakai saat tombol hitung ulang KPI dijalankan.</div>
                </div>
            </div>
            <div class="kpi-breakdown">
                <?php foreach ([
                    'Pekerjaan' => (float) $bobot['bobot_pekerjaan'],
                    'Deadline' => (float) $bobot['bobot_deadline'],
                    'Kehadiran' => (float) $bobot['bobot_kehadiran'],
                    'Custom' => (float) $bobot['bobot_custom'],
                ] as $label => $value): ?>
                    <div class="kpi-breakdown-item">
                        <span class="kpi-breakdown-label"><?= $label ?></span>
                        <div class="score-bar"><div class="score-bar-fill" style="width: <?= max(0, min(100, $value)) ?>%"></div></div>
                        <span class="kpi-breakdown-value"><?= number_format($value, 0) ?>%</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalBobot">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-sliders"></i> Atur Bobot KPI</h5>
            <button class="modal-close" onclick="closeModal('modalBobot')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="update_bobot">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Bobot Pekerjaan (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="bobot_pekerjaan" class="form-control" value="<?= htmlspecialchars($bobot['bobot_pekerjaan']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bobot Deadline (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="bobot_deadline" class="form-control" value="<?= htmlspecialchars($bobot['bobot_deadline']) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Bobot Kehadiran (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="bobot_kehadiran" class="form-control" value="<?= htmlspecialchars($bobot['bobot_kehadiran']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bobot Custom (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="bobot_custom" class="form-control" value="<?= htmlspecialchars($bobot['bobot_custom']) ?>">
                    </div>
                </div>
                <div class="info-banner note">
                    Idealnya total seluruh bobot adalah 100%. Sistem tidak memaksa, jadi pengaturan ini sebaiknya dicek ulang sebelum dipakai menghitung KPI.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalBobot')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Bobot</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalTarget">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-bullseye"></i> Atur Target KPI</h5>
            <button class="modal-close" onclick="closeModal('modalTarget')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="update_target">
            <input type="hidden" name="karyawan_id" id="targetKaryawanId">
            <input type="hidden" name="periode_mulai" id="targetPeriodeMulai">
            <input type="hidden" name="periode_selesai" id="targetPeriodeSelesai">
            <div class="modal-body">
                <div class="employee-note">
                    Target akan disimpan untuk <strong id="targetKaryawanName">-</strong> pada periode yang sedang aktif di halaman ini.
                </div>
                <?php if ($hasTargetPekerjaan): ?>
                    <div class="form-group">
                        <label class="form-label">Target Pekerjaan</label>
                        <input type="number" min="0" name="target_pekerjaan" id="targetPekerjaan" class="form-control" value="10">
                    </div>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Target Custom</label>
                        <input type="number" step="0.01" min="0" name="target_custom" id="targetCustom" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pencapaian Custom</label>
                        <input type="number" step="0.01" min="0" name="pencapaian_custom" id="pencapaianCustom" class="form-control" value="0">
                    </div>
                </div>
                <div class="info-banner primary">
                    Target custom cocok dipakai untuk sasaran non-operasional seperti kualitas pelayanan, kepatuhan SOP, atau target khusus divisi tertentu.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTarget')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Target</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
