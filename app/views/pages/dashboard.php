<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/dashboard_page.php';
requireRole('superadmin', 'admin', 'service', 'kasir', 'user');
$pageTitle = 'Dashboard';
extract(buildDashboardPageData($conn), EXTR_SKIP);

$statusBadgeMap = [
    'draft' => 'secondary',
    'selesai' => 'success',
    'pending' => 'warning',
    'dp' => 'info',
    'tempo' => 'secondary',
    'batal' => 'danger',
];

$jobBadgeMap = [
    'antrian' => 'secondary',
    'proses' => 'warning',
    'selesai' => 'success',
    'batal' => 'danger',
];

$formatDate = static function ($value, string $format = 'd/m/Y'): string {
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime((string) $value);
    return $timestamp ? date($format, $timestamp) : '-';
};

$employeeProfile = is_array($currentEmployee ?? null) ? $currentEmployee : [];
$employeeName = trim((string) ($employeeProfile['nama'] ?? $userName));
$employeeInitial = strtoupper(substr($employeeName !== '' ? $employeeName : $userName, 0, 1));
$employeePhotoUrl = !empty($employeeProfile['foto']) ? employeePhotoUrl((string) $employeeProfile['foto']) : null;
$employeeTitle = trim((string) ($employeeProfile['jabatan'] ?? ''));
$employeeDivision = trim((string) ($employeeProfile['divisi'] ?? ''));
$employeePhone = trim((string) ($employeeProfile['telepon'] ?? ''));
$employeeEmail = trim((string) ($employeeProfile['email'] ?? ''));
$employeeNik = trim((string) ($employeeProfile['nik'] ?? ''));
$employeeStatus = trim((string) ($employeeProfile['status'] ?? ''));
$employeeStartDate = !empty($employeeProfile['tanggal_masuk']) ? $formatDate($employeeProfile['tanggal_masuk']) : '';
$employeeRoleLabel = $roleLabels[$userRole] ?? ucfirst($userRole);
$employeeHeadline = $employeeTitle !== '' ? $employeeTitle : $employeeRoleLabel;
$employeeSubline = $employeeDivision !== ''
    ? 'Divisi ' . ucfirst($employeeDivision)
    : (!empty($employeeProfile) ? 'Profil karyawan terhubung ke akun ini.' : 'Akun ini belum terhubung penuh ke profil karyawan.');

$employeeContactCards = [];

if ($employeePhone !== '') {
    $employeeContactCards[] = ['icon' => 'fa-phone', 'label' => 'Telepon', 'value' => $employeePhone];
}

if ($employeeEmail !== '') {
    $employeeContactCards[] = ['icon' => 'fa-envelope', 'label' => 'Email', 'value' => $employeeEmail];
}

if ($employeeStartDate !== '') {
    $employeeContactCards[] = ['icon' => 'fa-calendar-check', 'label' => 'Masuk', 'value' => $employeeStartDate];
}

if ($employeeNik !== '') {
    $employeeContactCards[] = ['icon' => 'fa-id-card', 'label' => 'NIK', 'value' => $employeeNik];
}

if (empty($employeeContactCards)) {
    $employeeContactCards[] = [
        'icon' => 'fa-circle-info',
        'label' => 'Profil',
        'value' => !empty($employeeProfile)
            ? 'Lengkapi kontak utama agar koordinasi tim lebih cepat.'
            : 'Hubungkan akun ke data karyawan agar profil kerja tampil lebih lengkap.',
    ];
}

$employeeContactCards = array_slice($employeeContactCards, 0, 4);
$dashboardMetaChips = [
    ['icon' => 'fa-user-shield', 'label' => $employeeRoleLabel],
    ['icon' => 'fa-bell', 'label' => $notificationCount > 0 ? number_format($notificationCount) . ' prioritas aktif' : 'Prioritas aman'],
    ['icon' => 'fa-calendar-day', 'label' => $todayLabel],
];
$taskInboxItems = array_slice($notificationItems, 0, 6);
$metricInfoCards = array_slice($metricCards, 0, 5);

$extraCss = ($extraCss ?? '') . <<<'HTML'
<style>
.dashboard-page { gap: 20px; }
.management-cockpit {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.85fr);
    gap: 18px;
    padding: 24px;
    border-radius: 30px;
    border: 1px solid rgba(255, 255, 255, 0.78);
    background: linear-gradient(160deg, rgba(255, 255, 255, 0.88), rgba(255, 255, 255, 0.7));
    box-shadow: 0 28px 64px rgba(15, 23, 42, 0.1);
}
body.dark .management-cockpit {
    border-color: rgba(173, 191, 215, 0.14);
    background: linear-gradient(160deg, rgba(11, 19, 31, 0.92), rgba(11, 19, 31, 0.8));
    box-shadow: 0 28px 64px rgba(2, 8, 23, 0.34);
}
.cockpit-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-muted);
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.cockpit-title {
    margin: 10px 0 0;
    font-size: clamp(1.6rem, 2.4vw, 2.3rem);
    line-height: 1.08;
    letter-spacing: -0.05em;
    color: var(--text);
}
.cockpit-description {
    max-width: 700px;
    margin: 12px 0 0;
    color: var(--text-muted);
    line-height: 1.72;
}
.management-cockpit-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 18px;
}
.management-cockpit-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 38px;
    padding: 8px 14px;
    border-radius: 999px;
    border: 1px solid rgba(221, 228, 236, 0.88);
    background: rgba(255, 255, 255, 0.78);
    color: var(--text-2);
    font-size: 0.78rem;
    font-weight: 700;
}
body.dark .management-cockpit-chip {
    border-color: rgba(173, 191, 215, 0.14);
    background: rgba(255, 255, 255, 0.04);
}
.management-cockpit-profile {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 18px;
    border-radius: 24px;
    border: 1px solid rgba(221, 228, 236, 0.84);
    background: rgba(255, 255, 255, 0.78);
}
body.dark .management-cockpit-profile {
    border-color: rgba(173, 191, 215, 0.14);
    background: rgba(255, 255, 255, 0.04);
}
.cockpit-profile-head { display: flex; align-items: center; gap: 14px; }
.cockpit-profile-avatar {
    width: 68px;
    height: 68px;
    border-radius: 20px;
    object-fit: cover;
    flex-shrink: 0;
}
.cockpit-profile-avatar-fallback {
    width: 68px;
    height: 68px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 118, 110, 0.12);
    color: var(--primary);
    font-size: 1.35rem;
    font-weight: 800;
    flex-shrink: 0;
}
.cockpit-profile-name { font-size: 1.04rem; font-weight: 700; color: var(--text); }
.cockpit-profile-role { margin-top: 4px; color: var(--text); font-weight: 600; }
.cockpit-profile-subline { margin-top: 4px; color: var(--text-muted); font-size: 0.82rem; line-height: 1.6; }
.cockpit-profile-badges { display: flex; flex-wrap: wrap; gap: 8px; }
.cockpit-profile-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.1);
    color: var(--primary);
    font-size: 0.75rem;
    font-weight: 700;
}
</style>
HTML;

require_once dirname(__DIR__) . '/layouts/header.php';
?>

<style>
.cockpit-profile-list { display: grid; gap: 10px; }
.cockpit-profile-item {
    padding: 12px 14px;
    border-radius: 18px;
    border: 1px solid rgba(221, 228, 236, 0.74);
    background: rgba(248, 250, 252, 0.8);
}
body.dark .cockpit-profile-item {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.03);
}
.cockpit-profile-item span {
    display: block;
    color: var(--text-muted);
    font-size: 0.72rem;
}
.cockpit-profile-item strong {
    display: block;
    margin-top: 5px;
    color: var(--text);
    line-height: 1.5;
}
.cockpit-profile-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.management-cockpit-profile-panel {
    padding: 0;
    overflow: hidden;
}
.management-cockpit-profile-body {
    display: grid;
    gap: 16px;
    padding: 18px;
}
.dashboard-chart-grid,
.dashboard-section-grid { display: grid; gap: 18px; }
.dashboard-chart-grid { grid-template-columns: minmax(0, 1.25fr) minmax(300px, 0.8fr); }
.dashboard-section-grid {
    grid-template-columns: minmax(0, 1.08fr) minmax(0, 0.92fr);
    align-items: start;
}
.dashboard-stack { display: grid; gap: 18px; }
.dashboard-chart-box { min-height: 320px; }
.dashboard-chart-box.chart-center {
    display: flex;
    align-items: center;
    justify-content: center;
}
.role-summary-list { display: grid; gap: 12px; }
.role-summary-item {
    padding: 14px 16px;
    border-radius: 20px;
    border: 1px solid rgba(221, 228, 236, 0.76);
    background: rgba(248, 250, 252, 0.82);
}
body.dark .role-summary-item {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.03);
}
.role-summary-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
}
.role-summary-top strong {
    color: var(--text);
    font-size: 1rem;
    letter-spacing: -0.03em;
}
.role-summary-top span {
    color: var(--text-muted);
    font-size: 0.78rem;
    font-weight: 700;
    text-align: right;
}
.role-summary-item p {
    margin: 8px 0 0;
    color: var(--text-muted);
    font-size: 0.78rem;
    line-height: 1.65;
}
@media (max-width: 768px) {
    .dashboard-page,
    .dashboard-chart-grid,
    .dashboard-section-grid,
    .dashboard-stack {
        gap: 14px;
    }
    .management-cockpit {
        gap: 12px;
        padding: 14px;
        border-radius: 22px;
    }
    .cockpit-title {
        margin-top: 8px;
        font-size: clamp(1.18rem, 5.8vw, 1.48rem);
        line-height: 1.15;
    }
    .cockpit-description {
        display: none;
    }
    .management-cockpit-meta {
        margin-top: 12px;
        gap: 8px;
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: 4px;
        scrollbar-width: none;
        scroll-snap-type: x proximity;
    }
    .management-cockpit-meta::-webkit-scrollbar {
        display: none;
    }
    .management-cockpit-chip {
        flex: 0 0 auto;
        min-height: 32px;
        padding: 6px 10px;
        font-size: 0.72rem;
        scroll-snap-align: start;
    }
    .management-cockpit-profile-body {
        gap: 12px;
        padding: 14px;
    }
    .cockpit-profile-head {
        gap: 12px;
    }
    .cockpit-profile-avatar,
    .cockpit-profile-avatar-fallback {
        width: 56px;
        height: 56px;
        border-radius: 16px;
    }
    .cockpit-profile-name {
        font-size: 0.94rem;
    }
    .cockpit-profile-subline {
        font-size: 0.76rem;
    }
    .cockpit-profile-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .cockpit-profile-item {
        padding: 10px 12px;
        border-radius: 16px;
    }
    .cockpit-profile-item strong {
        font-size: 0.78rem;
    }
    .dashboard-chart-box {
        min-height: 220px;
    }
    .role-summary-item {
        padding: 12px 14px;
        border-radius: 18px;
    }
    .inbox-list,
    .audit-feed {
        gap: 10px;
    }
}
@media (max-width: 1100px) {
    .management-cockpit,
    .dashboard-chart-grid,
    .dashboard-section-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .management-cockpit {
        padding: 12px;
        border-radius: 20px;
    }
    .management-cockpit-profile-body { padding: 12px; }
    .cockpit-profile-head { align-items: flex-start; }
    .cockpit-profile-actions .btn { flex: 1 1 100%; }
    .cockpit-profile-list { grid-template-columns: 1fr; }
    .role-summary-top {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 520px) {
    .dashboard-page {
        gap: 12px;
    }
    .management-cockpit {
        padding: 10px;
        gap: 10px;
        border-radius: 18px;
    }
    .cockpit-kicker {
        gap: 6px;
        font-size: 0.66rem;
        letter-spacing: 0.1em;
    }
    .management-cockpit-meta {
        margin-top: 10px;
        gap: 6px;
    }
    .management-cockpit-chip {
        min-height: 30px;
        padding: 5px 9px;
        font-size: 0.68rem;
    }
    .management-cockpit-profile-body {
        gap: 10px;
        padding: 10px;
    }
    .cockpit-profile-badges {
        gap: 6px;
    }
    .cockpit-profile-badge {
        min-height: 30px;
        padding: 6px 9px;
        font-size: 0.68rem;
    }
    .cockpit-profile-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
    }
    .cockpit-profile-item {
        padding: 9px 10px;
        border-radius: 14px;
    }
    .cockpit-profile-item span {
        font-size: 0.68rem;
    }
    .cockpit-profile-item strong {
        font-size: 0.76rem;
        line-height: 1.45;
    }
    .cockpit-profile-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .cockpit-profile-actions .btn {
        width: 100%;
        min-width: 0;
        min-height: 36px;
        padding: 8px 10px;
        border-radius: 12px;
    }
    .dashboard-page .card-header {
        gap: 8px;
        margin-bottom: 12px;
        padding-bottom: 8px;
    }
    .dashboard-page .card-header .btn-sm {
        width: auto;
        min-width: 118px;
        min-height: 36px;
        padding: 8px 10px;
        border-radius: 12px;
        align-self: flex-start;
    }
    .dashboard-chart-box {
        min-height: 190px;
    }
    .inbox-list,
    .audit-feed,
    .delegated-task-list,
    .role-summary-list {
        gap: 8px;
    }
    .role-summary-item {
        padding: 11px 12px;
        border-radius: 16px;
    }
    .role-summary-top strong {
        font-size: 0.92rem;
    }
    .role-summary-top span,
    .role-summary-item p {
        font-size: 0.72rem;
    }
    .delegated-task-item {
        padding: 12px;
        gap: 10px;
        border-radius: 14px;
    }
    .delegated-task-meta {
        gap: 6px 10px;
        font-size: 0.72rem;
    }
    .delegated-task-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
        width: 100%;
    }
    .delegated-task-actions .btn {
        width: 100%;
        min-height: 36px;
        padding: 8px 10px;
    }
}

@media (max-width: 390px) {
    .cockpit-profile-list,
    .cockpit-profile-actions,
    .delegated-task-actions {
        grid-template-columns: 1fr;
    }
    .dashboard-page .card-header .btn-sm {
        width: 100%;
        align-self: stretch;
    }
    .dashboard-chart-box {
        min-height: 170px;
    }
}
</style>

<div class="page-stack dashboard-page">
    <section class="management-cockpit">
        <div class="management-cockpit-main">
            <div class="cockpit-kicker"><i class="fas fa-compass"></i> Management cockpit</div>
            <h1 class="cockpit-title"><?= htmlspecialchars((string) ($roleFocus[$userRole]['title'] ?? 'Dashboard operasional')) ?></h1>
            <p class="cockpit-description"><?= htmlspecialchars((string) ($roleFocus[$userRole]['description'] ?? 'Pantau ringkasan kerja, tugas prioritas, dan informasi per role dari satu layar.')) ?></p>

            <div class="management-cockpit-meta">
                <?php foreach ($dashboardMetaChips as $chip): ?>
                    <span class="management-cockpit-chip">
                        <i class="fas <?= htmlspecialchars($chip['icon']) ?>"></i>
                        <?= htmlspecialchars($chip['label']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <details class="management-cockpit-profile management-cockpit-profile-panel mobile-collapse-panel">
            <summary>
                <span class="mobile-collapse-label">
                    <strong>Profil & Kontak</strong>
                    <span><?= htmlspecialchars($employeeName) ?> - <?= htmlspecialchars($employeeHeadline) ?></span>
                </span>
            </summary>
            <div class="mobile-collapse-body management-cockpit-profile-body">
                <div class="cockpit-profile-head">
                    <?php if ($employeePhotoUrl): ?>
                        <img src="<?= htmlspecialchars($employeePhotoUrl) ?>" alt="<?= htmlspecialchars($employeeName) ?>" class="cockpit-profile-avatar">
                    <?php else: ?>
                        <span class="cockpit-profile-avatar-fallback"><?= htmlspecialchars($employeeInitial) ?></span>
                    <?php endif; ?>

                    <div>
                        <div class="cockpit-profile-name"><?= htmlspecialchars($employeeName) ?></div>
                        <div class="cockpit-profile-role"><?= htmlspecialchars($employeeHeadline) ?></div>
                        <div class="cockpit-profile-subline"><?= htmlspecialchars($employeeSubline) ?></div>
                    </div>
                </div>

                <div class="cockpit-profile-badges">
                    <span class="cockpit-profile-badge"><i class="fas fa-user-shield"></i> <?= htmlspecialchars($employeeRoleLabel) ?></span>
                    <span class="cockpit-profile-badge"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($employeeStatus !== '' ? ucfirst($employeeStatus) : 'Akun aktif') ?></span>
                    <?php if ($employeeDivision !== ''): ?>
                        <span class="cockpit-profile-badge"><i class="fas fa-building-user"></i> <?= htmlspecialchars(ucfirst($employeeDivision)) ?></span>
                    <?php endif; ?>
                </div>

                <div class="cockpit-profile-list">
                    <?php foreach ($employeeContactCards as $card): ?>
                        <div class="cockpit-profile-item">
                            <span><i class="fas <?= htmlspecialchars($card['icon']) ?>"></i> <?= htmlspecialchars($card['label']) ?></span>
                            <strong><?= htmlspecialchars($card['value']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cockpit-profile-actions">
                    <a href="<?= pageUrl('profile.php') ?>" class="btn btn-primary"><i class="fas fa-user-pen"></i> Edit Profil</a>
                    <?php if (in_array($userRole, ['superadmin', 'admin'], true)): ?>
                        <a href="<?= pageUrl('karyawan.php') ?>" class="btn btn-outline"><i class="fas fa-id-badge"></i> Data Karyawan</a>
                    <?php endif; ?>
                </div>
            </div>
        </details>
    </section>

    <div class="section-heading">
        <div>
            <h2>Grafik Ringkasan</h2>
            <p>Ringkasan visual yang membantu membaca ritme kerja dan distribusi prioritas sesuai role Anda.</p>
        </div>
    </div>

    <section class="dashboard-chart-grid">
        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-chart-line"></i> <?= htmlspecialchars((string) ($chartPrimary['title'] ?? 'Aktivitas 7 hari')) ?></span>
                    <div class="card-subtitle">Melihat pola aktivitas utama dalam 7 hari terakhir.</div>
                </div>
            </div>
            <div class="chart-container dashboard-chart-box">
                <canvas id="omzetChart"></canvas>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-chart-pie"></i> <?= htmlspecialchars((string) ($chartBreakdown['title'] ?? 'Komposisi')) ?></span>
                    <div class="card-subtitle">Distribusi fokus kerja yang paling perlu diperhatikan saat ini.</div>
                </div>
            </div>
            <div class="chart-container dashboard-chart-box chart-center">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </section>

    <div class="section-heading">
        <div>
            <h2>Tugas dan Informasi</h2>
            <p>Dashboard hanya menampilkan tugas yang harus dikerjakan dan informasi yang perlu diketahui untuk role Anda.</p>
        </div>
    </div>

    <section class="dashboard-section-grid">
        <div class="dashboard-stack">
            <div class="card">
                <div class="card-header">
                    <div>
                        <span class="card-title"><i class="fas fa-inbox"></i> Tugas Prioritas</span>
                        <div class="card-subtitle">Antrian kerja yang paling relevan dengan role Anda saat ini.</div>
                    </div>
                    <a href="<?= pageUrl('notifikasi.php') ?>" class="btn btn-outline btn-sm">Buka Semua</a>
                </div>

                <?php if (!empty($taskInboxItems)): ?>
                    <div class="inbox-list">
                        <?php foreach ($taskInboxItems as $item): ?>
                            <a href="<?= htmlspecialchars($item['href']) ?>" class="inbox-item">
                                <span class="inbox-item-icon tone-<?= htmlspecialchars($item['tone']) ?>">
                                    <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i>
                                </span>
                                <span class="inbox-item-copy">
                                    <span class="inbox-item-title"><?= htmlspecialchars($item['title']) ?></span>
                                    <span class="inbox-item-text"><?= htmlspecialchars($item['message']) ?></span>
                                </span>
                                <span class="badge <?= getNotificationToneBadge($item['tone']) ?>"><?= number_format((int) $item['count']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <div>Belum ada prioritas aktif untuk role Anda saat ini.</div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($supportsDelegatedStageInbox): ?>
                <div class="card" id="delegasi-saya">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-list-check"></i> Tugas Delegasi Saya</span>
                            <div class="card-subtitle">
                                <?php if ($myAssignedStageCount > 0): ?>
                                    <?= $myOverdueAssignedStages > 0
                                        ? number_format($myOverdueAssignedStages) . ' tugas sudah melewati deadline dan perlu diprioritaskan.'
                                        : 'Tahapan yang didelegasikan ke Anda tetap tampil di sini sampai selesai.' ?>
                                <?php else: ?>
                                    Daftar ini akan terisi otomatis saat ada tahapan kerja yang diberikan kepada Anda.
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="<?= pageUrl('produksi.php') ?>" class="btn btn-outline btn-sm">Buka Produksi</a>
                    </div>

                    <?php if (!empty($assignedStageRows)): ?>
                        <div class="delegated-task-list">
                            <?php foreach ($assignedStageRows as $stage): ?>
                                <?php
                                $deadlineAt = !empty($stage['deadline']) ? strtotime((string) $stage['deadline']) : false;
                                $isOverdue = $deadlineAt && $deadlineAt < strtotime(date('Y-m-d'));
                                ?>
                                <div class="delegated-task-item" data-dashboard-task-row data-tahapan-id="<?= (int) ($stage['id'] ?? 0) ?>">
                                    <div class="delegated-task-main">
                                        <div class="delegated-task-top">
                                            <div class="delegated-task-title"><?= htmlspecialchars($stage['nama_tahapan'] ?? 'Tahapan produksi') ?></div>
                                            <span class="badge badge-<?= $isOverdue ? 'danger' : 'info' ?>">
                                                <?= $isOverdue ? 'Deadline lewat' : htmlspecialchars($stage['tipe_dokumen'] ?? 'Tahapan') ?>
                                            </span>
                                        </div>
                                        <div class="delegated-task-meta">
                                            <span><i class="fas fa-file-lines"></i> <?= htmlspecialchars($stage['no_dokumen'] ?? '-') ?></span>
                                            <span><i class="fas fa-briefcase"></i> <?= htmlspecialchars($stage['nama_pekerjaan'] ?? 'Pekerjaan produksi') ?></span>
                                            <?php if (!empty($stage['no_transaksi'])): ?>
                                                <span><i class="fas fa-receipt"></i> <?= htmlspecialchars($stage['no_transaksi']) ?></span>
                                            <?php endif; ?>
                                            <span><i class="fas fa-calendar-day"></i> <?= $deadlineAt ? 'Deadline ' . date('d/m/Y', $deadlineAt) : 'Tanpa deadline' ?></span>
                                        </div>
                                    </div>
                                    <div class="delegated-task-actions">
                                        <a href="<?= pageUrl('produksi.php') ?>" class="btn btn-outline btn-sm">Lihat Detail</a>
                                        <button type="button" class="btn btn-primary btn-sm btn-dashboard-checklist" data-tahapan-id="<?= (int) ($stage['id'] ?? 0) ?>">
                                            <i class="fas fa-check"></i> Selesai
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <div>Belum ada tugas delegasi yang menunggu update dari Anda.</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-stack">
            <div class="card">
                <div class="card-header">
                    <div>
                        <span class="card-title"><i class="fas fa-circle-info"></i> Info Untuk <?= htmlspecialchars($employeeRoleLabel) ?></span>
                        <div class="card-subtitle">Angka dan konteks yang perlu Anda ketahui sebelum lanjut ke modul kerja.</div>
                    </div>
                </div>

                <div class="role-summary-list">
                    <?php foreach ($metricInfoCards as $metric): ?>
                        <div class="role-summary-item">
                            <div class="role-summary-top">
                                <strong><?= htmlspecialchars((string) ($metric['value'] ?? '-')) ?></strong>
                                <span><?= htmlspecialchars((string) ($metric['label'] ?? 'Ringkasan')) ?></span>
                            </div>
                            <p><?= htmlspecialchars((string) ($metric['note'] ?? 'Ringkasan peran Anda tampil di sini untuk membantu menentukan fokus kerja berikutnya.')) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (in_array($userRole, ['superadmin', 'admin'], true)): ?>
                <div class="card">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-receipt"></i> Transaksi Terbaru</span>
                            <div class="card-subtitle">Invoice terbaru yang perlu diketahui dari sisi operasional.</div>
                        </div>
                        <a href="<?= pageUrl('transaksi.php') ?>" class="btn btn-outline btn-sm">Buka Transaksi</a>
                    </div>

                    <?php if (!empty($recentTransactions)): ?>
                        <div class="audit-feed">
                            <?php foreach ($recentTransactions as $trx): ?>
                                <div class="audit-item">
                                    <div class="audit-item-top">
                                        <div>
                                            <div class="audit-item-title"><?= htmlspecialchars($trx['no_transaksi']) ?></div>
                                            <div class="audit-item-meta">
                                                <span><?= htmlspecialchars($trx['nama_pelanggan'] ?? 'Umum') ?></span>
                                                <span><?= $formatDate($trx['created_at'], 'd/m/Y H:i') ?></span>
                                            </div>
                                        </div>
                                        <span class="badge badge-<?= $statusBadgeMap[$trx['status']] ?? 'secondary' ?>"><?= htmlspecialchars($trx['status']) ?></span>
                                    </div>
                                    <div class="audit-item-summary">Total Rp <?= number_format((float) ($trx['total'] ?? 0), 0, ',', '.') ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <div>Belum ada transaksi terbaru yang tercatat.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-boxes-stacked"></i> Stok Menipis</span>
                            <div class="card-subtitle">Produk yang perlu dipantau agar operasional tidak tertahan.</div>
                        </div>
                        <a href="<?= pageUrl('produk.php') ?>" class="btn btn-outline btn-sm">Kelola Stok</a>
                    </div>

                    <?php if (!empty($lowStock)): ?>
                        <div class="audit-feed">
                            <?php foreach ($lowStock as $product): ?>
                                <div class="audit-item">
                                    <div class="audit-item-top">
                                        <div>
                                            <div class="audit-item-title"><?= htmlspecialchars($product['nama']) ?></div>
                                            <div class="audit-item-meta">
                                                <span>Satuan <?= htmlspecialchars($product['satuan']) ?></span>
                                            </div>
                                        </div>
                                        <span class="badge badge-danger"><?= number_format((float) ($product['stok'] ?? 0)) ?></span>
                                    </div>
                                    <div class="audit-item-summary">Segera evaluasi restock agar penjualan tetap lancar.</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <div>Belum ada produk yang masuk ambang minimum.</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($userRole === 'service'): ?>
                <div class="card">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-industry"></i> Job Perlu Follow-up</span>
                            <div class="card-subtitle">Urutan pekerjaan yang paling perlu didorong dari sisi koordinasi service.</div>
                        </div>
                        <a href="<?= pageUrl('produksi.php') ?>" class="btn btn-outline btn-sm">Buka Produksi</a>
                    </div>

                    <?php if (!empty($serviceProduksiRows)): ?>
                        <div class="audit-feed">
                            <?php foreach ($serviceProduksiRows as $job): ?>
                                <div class="audit-item">
                                    <div class="audit-item-top">
                                        <div>
                                            <div class="audit-item-title"><?= htmlspecialchars($job['no_dokumen']) ?> - <?= htmlspecialchars($job['nama_pekerjaan']) ?></div>
                                            <div class="audit-item-meta">
                                                <span>Invoice <?= htmlspecialchars($job['no_transaksi'] ?? '-') ?></span>
                                                <span><?= !empty($job['deadline']) ? 'Deadline ' . $formatDate($job['deadline']) : 'Belum ada deadline' ?></span>
                                                <span><?= htmlspecialchars($job['nama_karyawan'] ?? 'Belum ada PIC') ?></span>
                                            </div>
                                        </div>
                                        <span class="badge badge-<?= $jobBadgeMap[$job['status'] ?? ''] ?? 'secondary' ?>"><?= htmlspecialchars($job['status'] ?? '-') ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <div>Tidak ada job aktif yang perlu ditampilkan saat ini.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-print"></i> File Siap Cetak Terbaru</span>
                            <div class="card-subtitle">Gunakan daftar ini untuk membaca handoff file final yang baru masuk.</div>
                        </div>
                        <a href="<?= pageUrl('siap_cetak.php') ?>" class="btn btn-outline btn-sm">Buka File</a>
                    </div>

                    <?php if (!empty($serviceReadyPrintRows)): ?>
                        <div class="audit-feed">
                            <?php foreach ($serviceReadyPrintRows as $file): ?>
                                <div class="audit-item">
                                    <div class="audit-item-top">
                                        <div>
                                            <div class="audit-item-title"><?= htmlspecialchars($file['nama_asli']) ?></div>
                                            <div class="audit-item-meta">
                                                <span>Invoice <?= htmlspecialchars($file['no_transaksi'] ?? '-') ?></span>
                                                <span>Uploader <?= htmlspecialchars($file['nama_uploader'] ?? 'Sistem') ?></span>
                                                <span><?= $formatDate($file['created_at'], 'd/m/Y H:i') ?></span>
                                            </div>
                                        </div>
                                        <span class="badge badge-info">siap_cetak</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <div>Belum ada file siap cetak baru yang tercatat.</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($userRole === 'kasir'): ?>
                <div class="card">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-receipt"></i> Transaksi Terakhir Saya</span>
                            <div class="card-subtitle">Pantau invoice yang Anda input dan rapikan transaksi yang masih terbuka.</div>
                        </div>
                        <a href="<?= pageUrl('transaksi.php') ?>" class="btn btn-outline btn-sm">Buka Transaksi</a>
                    </div>

                    <?php if (!empty($kasirRecentRows)): ?>
                        <div class="audit-feed">
                            <?php foreach ($kasirRecentRows as $row): ?>
                                <div class="audit-item">
                                    <div class="audit-item-top">
                                        <div>
                                            <div class="audit-item-title"><?= htmlspecialchars($row['no_transaksi']) ?></div>
                                            <div class="audit-item-meta">
                                                <span><?= $formatDate($row['created_at'], 'd/m/Y H:i') ?></span>
                                                <span>Bayar Rp <?= number_format((float) ($row['bayar'] ?? 0), 0, ',', '.') ?></span>
                                                <span>Kembalian Rp <?= number_format((float) ($row['kembalian'] ?? 0), 0, ',', '.') ?></span>
                                            </div>
                                        </div>
                                        <span class="badge badge-<?= $statusBadgeMap[$row['status']] ?? 'secondary' ?>"><?= htmlspecialchars($row['status']) ?></span>
                                    </div>
                                    <div class="audit-item-summary">Total Rp <?= number_format((float) ($row['total'] ?? 0), 0, ',', '.') ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <div>Belum ada transaksi yang Anda input pada periode terakhir.</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php if ($employeeId > 0): ?>
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <span class="card-title"><i class="fas fa-industry"></i> Job Saya</span>
                                <div class="card-subtitle">Daftar pekerjaan aktif yang ditugaskan langsung kepada Anda.</div>
                            </div>
                            <a href="<?= pageUrl('produksi.php') ?>" class="btn btn-outline btn-sm">Buka Produksi</a>
                        </div>

                        <?php if (!empty($userJobs)): ?>
                            <div class="audit-feed">
                                <?php foreach ($userJobs as $job): ?>
                                    <div class="audit-item">
                                        <div class="audit-item-top">
                                            <div>
                                                <div class="audit-item-title"><?= htmlspecialchars($job['no_dokumen']) ?> - <?= htmlspecialchars($job['nama_pekerjaan']) ?></div>
                                                <div class="audit-item-meta">
                                                    <span>Invoice <?= htmlspecialchars($job['no_transaksi'] ?? '-') ?></span>
                                                    <span><?= !empty($job['deadline']) ? 'Deadline ' . $formatDate($job['deadline']) : 'Belum ada deadline' ?></span>
                                                </div>
                                            </div>
                                            <span class="badge badge-<?= $jobBadgeMap[$job['status'] ?? ''] ?? 'secondary' ?>"><?= htmlspecialchars($job['status'] ?? '-') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <div>Belum ada job aktif yang ditugaskan kepada Anda saat ini.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div>
                                <span class="card-title"><i class="fas fa-user-clock"></i> Ringkasan Pribadi</span>
                                <div class="card-subtitle">Informasi kerja pribadi yang perlu Anda ketahui hari ini.</div>
                            </div>
                            <a href="<?= pageUrl('absensi_mobile.php') ?>" class="btn btn-outline btn-sm">Absensi Saya</a>
                        </div>

                        <div class="role-summary-list">
                            <div class="role-summary-item">
                                <div class="role-summary-top">
                                    <strong><?= htmlspecialchars($myAttendanceLabel) ?></strong>
                                    <span>Absensi Hari Ini</span>
                                </div>
                                <p><?= htmlspecialchars($myAttendanceNote) ?></p>
                            </div>
                            <div class="role-summary-item">
                                <div class="role-summary-top">
                                    <strong><?= number_format($myPendingStages) ?></strong>
                                    <span>Tahapan Belum</span>
                                </div>
                                <p>Gunakan angka ini untuk menentukan fokus pengerjaan berikutnya.</p>
                            </div>
                            <div class="role-summary-item">
                                <div class="role-summary-top">
                                    <strong><?= number_format($myDoneStagesToday) ?></strong>
                                    <span>Selesai Hari Ini</span>
                                </div>
                                <p>Membantu membaca progres pribadi sepanjang hari kerja.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <span class="card-title"><i class="fas fa-link"></i> Profil Karyawan Belum Terhubung</span>
                                <div class="card-subtitle">Dashboard personal akan lebih akurat setelah akun ini ditautkan ke data karyawan.</div>
                            </div>
                        </div>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <div>Mintalah admin menghubungkan akun ini ke profil karyawan agar job pribadi dan ringkasan kerja tampil otomatis.</div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
