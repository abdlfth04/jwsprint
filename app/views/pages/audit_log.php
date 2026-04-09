<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
$pageTitle = 'Audit Log';

$actionOptions = [];
$entityOptions = [];
if (auditLogTableReady()) {
    $resultActions = $conn->query("SELECT DISTINCT aksi FROM audit_log ORDER BY aksi ASC");
    if ($resultActions) {
        $actionOptions = array_map(static function (array $row): string {
            return (string) $row['aksi'];
        }, $resultActions->fetch_all(MYSQLI_ASSOC));
    }

    $resultEntities = $conn->query("SELECT DISTINCT entitas FROM audit_log ORDER BY entitas ASC");
    if ($resultEntities) {
        $entityOptions = array_map(static function (array $row): string {
            return (string) $row['entitas'];
        }, $resultEntities->fetch_all(MYSQLI_ASSOC));
    }
}

$actionFilter = trim((string) ($_GET['aksi'] ?? ''));
$entityFilter = trim((string) ($_GET['entitas'] ?? ''));
$dateFilter = trim((string) ($_GET['tanggal'] ?? ''));

if ($actionFilter !== '' && !in_array($actionFilter, $actionOptions, true)) {
    $actionFilter = '';
}
if ($entityFilter !== '' && !in_array($entityFilter, $entityOptions, true)) {
    $entityFilter = '';
}
if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}

$summary = getAuditSummaryCounts();
$logs = fetchRecentAuditLogs(60, [
    'aksi' => $actionFilter ?: null,
    'entitas' => $entityFilter ?: null,
    'date' => $dateFilter ?: null,
]);

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<div class="page-stack admin-panel audit-log-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-shield-halved"></i> Audit Log</div>
                <h1 class="page-title">Pantau jejak perubahan dan aktivitas sistem</h1>
                <p class="page-description">
                    Halaman ini membantu membaca siapa melakukan apa, kapan terjadi, dan area sistem mana yang paling aktif sepanjang hari kerja.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-calendar-day"></i> <?= date('d/m/Y') ?></span>
                    <span class="page-meta-item"><i class="fas fa-list-check"></i> <?= number_format(count($logs)) ?> log dimuat</span>
                    <span class="page-meta-item"><i class="fas fa-filter"></i> <?= $actionFilter !== '' || $entityFilter !== '' || $dateFilter !== '' ? 'Filter aktif' : 'Tanpa filter' ?></span>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
                <a href="<?= pageUrl('notifikasi.php') ?>" class="btn btn-outline"><i class="fas fa-bell"></i> Notification Center</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Aktivitas Hari Ini</span>
            <span class="metric-value"><?= number_format($summary['today']) ?></span>
            <span class="metric-note">Total log yang tercatat pada tanggal hari ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Login / Logout</span>
            <span class="metric-value"><?= number_format($summary['login']) ?></span>
            <span class="metric-note">Memudahkan membaca traffic akses sistem harian.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Aktivitas File</span>
            <span class="metric-value"><?= number_format($summary['file']) ?></span>
            <span class="metric-note">Upload dan hapus file transaksi dalam 7 hari terakhir.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Aktivitas Produksi</span>
            <span class="metric-value"><?= number_format($summary['produksi']) ?></span>
            <span class="metric-note">Perubahan produksi yang tercatat hari ini.</span>
        </div>
    </div>

    <div class="toolbar-surface">
        <div class="section-heading" style="margin-bottom: 14px;">
            <div>
                <h2>Filter Audit</h2>
                <p>Saring log berdasarkan aksi, entitas, atau tanggal tertentu untuk mempercepat investigasi.</p>
            </div>
        </div>
        <form method="GET" class="form-row" style="align-items:end;">
            <div class="form-group">
                <label class="form-label">Aksi</label>
                <select name="aksi" class="form-control">
                    <option value="">Semua aksi</option>
                    <?php foreach ($actionOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= $actionFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars(auditActionLabel($option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Entitas</label>
                <select name="entitas" class="form-control">
                    <option value="">Semua entitas</option>
                    <?php foreach ($entityOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= $entityFilter === $option ? 'selected' : '' ?>><?= htmlspecialchars(auditEntityLabel($option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Tanggal</label>
                <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($dateFilter) ?>">
            </div>
            <div class="form-group" style="display:flex;gap:10px;align-items:center;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                <a href="<?= pageUrl('audit_log.php') ?>" class="btn btn-outline">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-stream"></i> Riwayat Aktivitas</span>
                <div class="card-subtitle">Tabel desktop dan kartu mobile menampilkan ringkasan yang sama.</div>
            </div>
        </div>

        <?php if (!empty($logs)): ?>
            <div class="table-responsive table-desktop">
                <table>
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Aksi</th>
                            <th>Entitas</th>
                            <th>Pengguna</th>
                            <th>Ringkasan</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></td>
                            <td><span class="badge badge-secondary"><?= htmlspecialchars(auditActionLabel((string) ($log['aksi'] ?? ''))) ?></span></td>
                            <td><?= htmlspecialchars(auditEntityLabel((string) ($log['entitas'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars($log['user_name'] ?? $log['username'] ?? 'Sistem') ?></td>
                            <td><?= htmlspecialchars($log['ringkasan'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list">
                <?php foreach ($logs as $log): ?>
                    <div class="mobile-data-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars(auditActionLabel((string) ($log['aksi'] ?? ''))) ?></div>
                                <div class="mobile-data-subtitle"><?= htmlspecialchars(auditEntityLabel((string) ($log['entitas'] ?? ''))) ?> - <?= htmlspecialchars(formatAuditRelativeTime($log['created_at'] ?? null)) ?></div>
                            </div>
                            <span class="badge badge-secondary"><?= htmlspecialchars($log['aksi'] ?? '-') ?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Pengguna</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($log['user_name'] ?? $log['username'] ?? 'Sistem') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">IP</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Waktu</span>
                                <span class="mobile-data-value"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="audit-item-extra"><?= htmlspecialchars($log['ringkasan'] ?? '-') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <div>Belum ada data audit yang cocok dengan filter saat ini.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
