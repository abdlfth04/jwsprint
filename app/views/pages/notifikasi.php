<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service', 'kasir', 'user');
$pageTitle = 'Notification Center';

function notificationPageToneLabel(string $tone): string
{
    $labels = [
        'danger' => 'Urgent',
        'warning' => 'Perlu cek',
        'info' => 'Info',
        'success' => 'Aman',
    ];

    return $labels[$tone] ?? 'Umum';
}

function notificationPageSearchValue(array $item): string
{
    $search = trim(($item['title'] ?? '') . ' ' . ($item['message'] ?? ''));
    return function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
}

function notificationPageCategoryCounts(array $items): array
{
    $summary = [
        'all' => count($items),
        'urgent' => 0,
        'danger' => 0,
        'warning' => 0,
        'info' => 0,
        'success' => 0,
    ];

    foreach ($items as $item) {
        $tone = (string) ($item['tone'] ?? 'info');
        if (array_key_exists($tone, $summary)) {
            $summary[$tone]++;
        }
        if (in_array($tone, ['danger', 'warning'], true)) {
            $summary['urgent']++;
        }
    }

    return $summary;
}

function notificationPageCountTotalsByTone(array $items): array
{
    $summary = [
        'danger' => 0,
        'warning' => 0,
        'info' => 0,
        'success' => 0,
    ];

    foreach ($items as $item) {
        $tone = (string) ($item['tone'] ?? 'info');
        if (array_key_exists($tone, $summary)) {
            $summary[$tone] += (int) ($item['count'] ?? 0);
        }
    }

    return $summary;
}

function notificationPageSerializeItems(array $items): array
{
    return array_map(static function (array $item): array {
        return [
            'icon' => (string) ($item['icon'] ?? 'fa-bell'),
            'tone' => (string) ($item['tone'] ?? 'info'),
            'count' => (int) ($item['count'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'message' => (string) ($item['message'] ?? ''),
            'href' => (string) ($item['href'] ?? pageUrl('notifikasi.php')),
            'priority' => (int) ($item['priority'] ?? 0),
            'kind' => (string) ($item['kind'] ?? 'generic'),
            'meta' => is_array($item['meta'] ?? null) ? $item['meta'] : [],
            'read_key' => (string) ($item['read_key'] ?? ''),
            'tone_label' => notificationPageToneLabel((string) ($item['tone'] ?? 'info')),
            'search' => notificationPageSearchValue($item),
            'badge_class' => getNotificationToneBadge((string) ($item['tone'] ?? 'info')),
        ];
    }, $items);
}

function notificationPageBuildPayload(array $items, int $count, array $categoryCounts, array $totalsByTone, string $userRole, array $roleLabels, string $generatedLabel): array
{
    return [
        'count' => $count,
        'items' => notificationPageSerializeItems($items),
        'category_counts' => $categoryCounts,
        'counts_by_tone' => $totalsByTone,
        'generated_at' => date(DATE_ATOM),
        'generated_label' => $generatedLabel,
        'role' => $userRole,
        'role_label' => $roleLabels[$userRole] ?? ucfirst($userRole),
    ];
}

function notificationPageBuildCurrentPayload(string $userRole, array $roleLabels, string $generatedLabel): array
{
    $items = getNotificationItems(20);

    return notificationPageBuildPayload(
        $items,
        getNotificationCount(),
        notificationPageCategoryCounts($items),
        notificationPageCountTotalsByTone($items),
        $userRole,
        $roleLabels,
        $generatedLabel
    );
}

$userRole = $_SESSION['role'] ?? 'user';
$roleLabels = [
    'superadmin' => 'Superadmin',
    'admin' => 'Admin',
    'service' => 'Service',
    'kasir' => 'Kasir',
    'user' => 'User',
];

$roleGuides = [
    'superadmin' => 'Prioritaskan transaksi, deadline produksi, dan notifikasi lintas modul yang menahan alur kerja tim.',
    'admin' => 'Gunakan inbox ini untuk membaca pekerjaan yang perlu keputusan atau tindak lanjut operasional cepat.',
    'service' => 'Fokus ke file siap cetak, revisi, dan pekerjaan produksi yang sedang menunggu dorongan berikutnya.',
    'kasir' => 'Pantau antrian kasir dan order yang sudah siap dibayar sebelum menambah checkout baru.',
    'user' => 'Gunakan daftar ini sebagai inbox kerja ringkas untuk melihat tugas yang perlu diselesaikan lebih dulu.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'msg' => 'Sesi login tidak valid.']);
        exit;
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $updated = 0;
    $message = 'Tidak ada perubahan pada notifikasi.';

    if ($action === 'mark_read') {
        $readKey = trim((string) ($_POST['read_key'] ?? ''));
        if ($readKey === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'msg' => 'Notifikasi yang dipilih tidak valid.']);
            exit;
        }

        $updated = notificationMarkItemsByReadKeys($userId, [$readKey]);
        $message = $updated > 0 ? 'Notifikasi ditandai sudah dibaca.' : 'Notifikasi ini sudah tidak aktif.';
    } elseif ($action === 'mark_all_read') {
        $updated = notificationMarkAllCurrentItemsAsRead($userId);
        $message = $updated > 0 ? $updated . ' notifikasi ditandai sudah dibaca.' : 'Tidak ada notifikasi aktif untuk ditandai.';
    } else {
        http_response_code(422);
        echo json_encode(['success' => false, 'msg' => 'Aksi notifikasi tidak dikenal.']);
        exit;
    }

    notificationResetCollectedItemsCache();
    $generatedLabel = date('d/m/Y H:i:s');
    $payload = notificationPageBuildCurrentPayload($userRole, $roleLabels, $generatedLabel);

    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'msg' => $message,
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$notificationItems = getNotificationItems(20);
$notificationCount = getNotificationCount();
$notificationCategoryCounts = notificationPageCategoryCounts($notificationItems);
$notificationTotalsByTone = notificationPageCountTotalsByTone($notificationItems);
$recentAudit = in_array($userRole, ['superadmin', 'admin'], true) ? fetchRecentAuditLogs(8) : [];
$generatedLabel = date('d/m/Y H:i');
$notificationPayload = notificationPageBuildPayload(
    $notificationItems,
    $notificationCount,
    $notificationCategoryCounts,
    $notificationTotalsByTone,
    $userRole,
    $roleLabels,
    $generatedLabel
);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode($notificationPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['format'] ?? '') === 'stream') {
    realtimeStreamPrepare();

    $startedAt = microtime(true);
    $maxDuration = 24;
    $lastSignature = '';
    $iteration = 0;

    while (!connection_aborted() && (microtime(true) - $startedAt) < $maxDuration) {
        $items = getNotificationItems(20);
        $count = getNotificationCount();
        $categoryCounts = notificationPageCategoryCounts($items);
        $totalsByTone = notificationPageCountTotalsByTone($items);
        $label = date('d/m/Y H:i:s');
        $payload = notificationPageBuildPayload(
            $items,
            $count,
            $categoryCounts,
            $totalsByTone,
            $userRole,
            $roleLabels,
            $label
        );

        $signature = realtimeStreamSignature([
            'count' => $payload['count'],
            'items' => $payload['items'],
            'category_counts' => $payload['category_counts'],
            'counts_by_tone' => $payload['counts_by_tone'],
        ]);

        if ($iteration === 0 || $signature !== $lastSignature) {
            realtimeStreamSend('notifications', $payload);
            $lastSignature = $signature;
        } else {
            realtimeStreamSend('ping', [
                'generated_at' => date(DATE_ATOM),
                'generated_label' => $label,
            ]);
        }

        $iteration++;
        usleep(3000000);
    }

    exit;
}

$urgentCount = (int) ($notificationTotalsByTone['danger'] ?? 0) + (int) ($notificationTotalsByTone['warning'] ?? 0);
$categoryCount = count($notificationItems);
$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/notifikasi.css') . '">';
$pageState = [
    'notificationPageRefreshMs' => 45000,
    'notificationActionEndpoint' => pageUrl('notifikasi.php'),
];
$pageJs = 'notifikasi.js';

require_once dirname(__DIR__) . '/layouts/header.php';
?>

<div class="page-stack notification-page">
    <section class="card notification-overview-card">
        <div class="notification-overview">
            <div class="notification-overview-copy">
                <div class="page-eyebrow"><i class="fas fa-bell"></i> Notification Center</div>
                <h1 class="page-title">Inbox kerja untuk <?= htmlspecialchars($roleLabels[$userRole] ?? ucfirst($userRole)) ?></h1>
                <div class="notification-guide" id="notificationPageGuide">
                    <?= htmlspecialchars($roleGuides[$userRole] ?? 'Pantau antrian penting dari sini sebelum membuka halaman detail.') ?>
                </div>
            </div>
            <div class="notification-overview-actions">
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
                <a href="<?= pageUrl('profile.php') ?>" class="btn btn-outline"><i class="fas fa-user"></i> Profil</a>
            </div>
        </div>

        <div class="notification-summary-grid">
            <div class="notification-summary-card">
                <span class="notification-summary-label">Total antrian</span>
                <strong class="notification-summary-value" id="notificationTotalValue"><?= number_format($notificationCount) ?></strong>
            </div>
            <div class="notification-summary-card">
                <span class="notification-summary-label">Urgent</span>
                <strong class="notification-summary-value" id="notificationUrgentValue"><?= number_format($urgentCount) ?></strong>
            </div>
            <div class="notification-summary-card">
                <span class="notification-summary-label">Kategori aktif</span>
                <strong class="notification-summary-value" id="notificationCategoryValue"><?= number_format($categoryCount) ?></strong>
            </div>
            <div class="notification-summary-card">
                <span class="notification-summary-label">Update</span>
                <strong class="notification-summary-value" id="notificationUpdatedValue"><?= htmlspecialchars($generatedLabel) ?></strong>
            </div>
        </div>

        <div class="notification-webpush-panel" id="webPushPanel">
            <div class="notification-webpush-copy">
                <div class="page-eyebrow"><i class="fas fa-tower-broadcast"></i> Web Push</div>
                <div class="notification-webpush-title">Aktifkan notifikasi real-time di perangkat ini</div>
                <div class="notification-guide" id="webPushDescription">
                    Saat aktif, chat baru dan reminder hutang supplier yang kritis akan dikirim langsung ke browser ini walau tab aplikasi sedang tidak terbuka.
                </div>
            </div>
            <div class="notification-webpush-actions">
                <span class="badge badge-secondary" id="webPushStatusBadge">Memeriksa...</span>
                <button type="button" class="btn btn-primary btn-sm" id="webPushPrimaryAction">Aktifkan</button>
                <button type="button" class="btn btn-outline btn-sm" id="webPushTestAction" hidden>Kirim Tes</button>
            </div>
        </div>
        <div class="notification-webpush-meta" id="webPushMeta">Status perangkat akan ditampilkan setelah sinkron pertama selesai.</div>
    </section>

    <div class="notification-layout<?= empty($recentAudit) ? ' no-aside' : '' ?>">
        <section class="card notification-primary-card">
            <div class="notification-toolbar">
                <div class="notification-toolbar-head">
                    <div>
                        <span class="card-title"><i class="fas fa-inbox"></i> Antrian Prioritas</span>
                        <div class="notification-toolbar-note" id="notificationSyncText">Sinkron <?= htmlspecialchars($generatedLabel) ?></div>
                    </div>
                    <div class="notification-toolbar-actions">
                        <button type="button" class="btn btn-secondary btn-sm" id="notificationRefreshButton">
                            <i class="fas fa-rotate"></i> Refresh
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" id="notificationMarkAllButton" <?= !empty($notificationItems) ? '' : 'disabled' ?>>
                            <i class="fas fa-check-double"></i> Tandai semua
                        </button>
                    </div>
                </div>

                <div class="notification-toolbar-controls">
                    <input type="text" id="notificationSearch" class="form-control" placeholder="Cari judul atau isi notifikasi...">
                    <div class="notification-filter-group" id="notificationFilterGroup">
                        <button type="button" class="notification-filter is-active" data-filter="all">
                            Semua
                            <span class="notification-filter-count" id="notificationChipCountAll"><?= number_format((int) ($notificationCategoryCounts['all'] ?? 0)) ?></span>
                        </button>
                        <button type="button" class="notification-filter" data-filter="urgent">
                            Urgent
                            <span class="notification-filter-count" id="notificationChipCountUrgent"><?= number_format((int) ($notificationCategoryCounts['urgent'] ?? 0)) ?></span>
                        </button>
                        <button type="button" class="notification-filter" data-filter="warning">
                            Perlu cek
                            <span class="notification-filter-count" id="notificationChipCountWarning"><?= number_format((int) ($notificationCategoryCounts['warning'] ?? 0)) ?></span>
                        </button>
                        <button type="button" class="notification-filter" data-filter="info">
                            Info
                            <span class="notification-filter-count" id="notificationChipCountInfo"><?= number_format((int) ($notificationCategoryCounts['info'] ?? 0)) ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="notification-task-list" id="notificationList">
                <?php foreach ($notificationItems as $item): ?>
                    <div
                        class="notification-task"
                        data-tone="<?= htmlspecialchars($item['tone']) ?>"
                        data-search="<?= htmlspecialchars(notificationPageSearchValue($item)) ?>"
                        data-count="<?= (int) ($item['count'] ?? 0) ?>"
                        data-read-key="<?= htmlspecialchars((string) ($item['read_key'] ?? '')) ?>">
                        <a href="<?= htmlspecialchars($item['href']) ?>" class="notification-task-main">
                            <span class="notification-task-icon tone-<?= htmlspecialchars($item['tone']) ?>">
                                <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i>
                            </span>
                            <span class="notification-task-copy">
                                <span class="notification-task-top">
                                    <span class="notification-task-title"><?= htmlspecialchars($item['title']) ?></span>
                                    <span class="badge <?= getNotificationToneBadge($item['tone']) ?>"><?= number_format((int) $item['count']) ?></span>
                                </span>
                                <span class="notification-task-text"><?= htmlspecialchars($item['message']) ?></span>
                            </span>
                            <span class="notification-task-arrow"><i class="fas fa-chevron-right"></i></span>
                        </a>
                        <div class="notification-task-actions">
                            <button type="button" class="notification-task-read" data-notification-action="mark-read" data-read-key="<?= htmlspecialchars((string) ($item['read_key'] ?? '')) ?>">
                                <i class="fas fa-check"></i> Sudah dibaca
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="empty-state" id="notificationEmptyState" <?= !empty($notificationItems) ? 'hidden' : '' ?>>
                <i class="fas fa-check-circle"></i>
                <div>Tidak ada notifikasi yang cocok dengan filter saat ini.</div>
            </div>
        </section>

        <?php if (!empty($recentAudit)): ?>
            <aside class="card notification-secondary-card">
                <div class="notification-side-head">
                    <div>
                        <span class="card-title"><i class="fas fa-shield-halved"></i> Aktivitas Sistem</span>
                        <div class="notification-toolbar-note">Cuplikan perubahan terbaru yang masih relevan.</div>
                    </div>
                    <a href="<?= pageUrl('audit_log.php') ?>" class="btn btn-outline btn-sm">Audit Log</a>
                </div>

                <div class="notification-audit-list">
                    <?php foreach ($recentAudit as $log): ?>
                        <div class="notification-audit-row">
                            <div class="notification-audit-top">
                                <div class="notification-audit-title"><?= htmlspecialchars(auditActionLabel((string) ($log['aksi'] ?? ''))) ?></div>
                                <span class="badge badge-secondary"><?= htmlspecialchars($log['aksi'] ?? '-') ?></span>
                            </div>
                            <div class="notification-audit-meta">
                                <span><?= htmlspecialchars(auditEntityLabel((string) ($log['entitas'] ?? ''))) ?></span>
                                <span><?= htmlspecialchars($log['user_name'] ?? $log['username'] ?? 'Sistem') ?></span>
                                <span><?= htmlspecialchars(formatAuditRelativeTime($log['created_at'] ?? null)) ?></span>
                            </div>
                            <div class="notification-audit-summary"><?= htmlspecialchars($log['ringkasan'] ?? '-') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
