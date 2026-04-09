<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();
$user = currentUser();

$currentPage = basename($_SERVER['PHP_SELF']);
$temaColor = '#0f766e';
$resTema = $conn->query("SELECT tema_primary FROM setting WHERE id=1");
if ($resTema) {
    $rowTema = $resTema->fetch_assoc();
    if (!empty($rowTema['tema_primary'])) {
        $temaColor = $rowTema['tema_primary'];
    }
}

$notificationItems = getNotificationItems(6);
$notificationCount = getNotificationCount();
$notificationBadge = $notificationCount > 99 ? '99+' : (string) $notificationCount;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JWS - <?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></title>
    <meta name="theme-color" content="<?= htmlspecialchars($temaColor) ?>">
    <?= csrfMetaTag() ?>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="<?= baseUrl('manifest.webmanifest') ?>?v=20260403-pwa-logo">
    <link rel="icon" href="<?= pwaIconUrl() ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?= pwaTouchIconUrl() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= assetUrl('css/base.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/responsive-system.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/mobile-responsive.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/components.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/components-modern.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/utilities.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/mobile-shell.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/print.css') ?>">
    <?php if (isset($extraCss)) echo $extraCss; ?>
    <?php if ($temaColor !== '#0f766e'): ?>
    <style>:root { --primary: <?= htmlspecialchars($temaColor) ?>; --primary-hover: <?= htmlspecialchars($temaColor) ?>; }</style>
    <?php endif; ?>
    <script>window.APP_CSRF_TOKEN = <?= json_encode(csrfToken(), JSON_UNESCAPED_SLASHES) ?>;</script>
</head>
<body class="app-shell">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar(event)"></div>

<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main-wrapper">
    <header class="topbar">
        <div class="topbar-start">
            <button type="button" class="btn-toggle-sidebar" onclick="toggleSidebar(event)" aria-label="Buka navigasi" aria-controls="sidebar" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-page">
                <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></div>
            </div>
        </div>

        <div class="topbar-end">
            <div class="topbar-datetime" aria-live="polite">
                <span class="topbar-time" data-topbar-time>--:--:--</span>
                <span class="topbar-date" data-topbar-date>Memuat tanggal</span>
            </div>

            <div class="notification-center">
                <button type="button" class="notification-toggle" onclick="toggleNotificationMenu(event)" aria-label="Buka notifikasi" aria-expanded="false" aria-controls="notificationDropdown">
                    <i class="fas fa-bell"></i>
                    <span class="notification-count" id="notificationCountBadge" <?= $notificationCount > 0 ? '' : 'hidden' ?>><?= htmlspecialchars($notificationBadge) ?></span>
                </button>

                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-dropdown-header">
                        <div>
                            <strong>Notification Center</strong>
                            <div id="notificationDropdownSummary"><?= $notificationCount > 0 ? number_format($notificationCount) . ' antrian aktif' : 'Tidak ada antrian aktif' ?></div>
                        </div>
                        <a href="<?= pageUrl('notifikasi.php') ?>">Buka</a>
                    </div>

                    <div class="notification-dropdown-body" id="notificationDropdownBody">
                        <?php if (!empty($notificationItems)): ?>
                            <?php foreach ($notificationItems as $item): ?>
                                <a href="<?= htmlspecialchars($item['href']) ?>" class="notification-preview">
                                    <span class="notification-preview-icon tone-<?= htmlspecialchars($item['tone']) ?>">
                                        <i class="fas <?= htmlspecialchars($item['icon']) ?>"></i>
                                    </span>
                                    <span class="notification-preview-copy">
                                        <span class="notification-preview-title"><?= htmlspecialchars($item['title']) ?></span>
                                        <span class="notification-preview-text"><?= htmlspecialchars($item['message']) ?></span>
                                    </span>
                                    <span class="badge <?= getNotificationToneBadge($item['tone']) ?>"><?= number_format((int) $item['count']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notification-empty">
                                <i class="fas fa-check-circle"></i>
                                <div>Semua antrian utama sedang aman.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="notification-dropdown-footer">
                        <span class="notification-dropdown-sync" id="notificationDropdownSync">Sinkron saat halaman dibuka</span>
                        <div class="notification-dropdown-actions">
                            <span class="notification-browser-state" id="browserNotificationState" hidden></span>
                            <button type="button" class="notification-permission-btn" id="browserNotificationAction" hidden></button>
                            <button type="button" class="notification-mark-read-btn" id="notificationDropdownMarkAllButton" <?= $notificationCount > 0 ? '' : 'hidden' ?>>Tandai semua</button>
                            <a href="<?= pageUrl('notifikasi.php') ?>">Lihat semua</a>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" class="topbar-action topbar-install" data-pwa-install hidden aria-label="Pasang aplikasi">
                <i class="fas fa-download"></i>
                <span class="topbar-action-label">Install App</span>
            </button>

            <button type="button" class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">
                <i class="fas fa-sun" data-theme-icon="true"></i>
            </button>

            <form method="POST" action="<?= baseUrl('logout.php') ?>" class="topbar-logout-form">
                <?= csrfInput() ?>
                <button type="submit" class="topbar-action topbar-logout" aria-label="Keluar">
                    <i class="fas fa-arrow-right-from-bracket"></i>
                </button>
            </form>
        </div>
    </header>

    <section class="mobile-pwa-panel" id="mobilePwaPanel" hidden>
        <button type="button" class="mobile-pwa-panel-dismiss" id="mobilePwaDismissAction" aria-label="Tutup panel rekomendasi mobile">
            <i class="fas fa-times"></i>
        </button>
        <div class="mobile-pwa-panel-copy">
            <span class="mobile-pwa-panel-status" id="mobilePwaPanelStatus">Mode mobile</span>
            <strong class="mobile-pwa-panel-title" id="mobilePwaPanelTitle">Pasang aplikasi untuk akses yang lebih nyaman</strong>
            <span class="mobile-pwa-panel-text" id="mobilePwaPanelText">Install PWA lalu aktifkan notifikasi agar update penting tetap masuk saat aplikasi berjalan di background.</span>
        </div>
        <div class="mobile-pwa-panel-actions">
            <button type="button" class="btn btn-secondary btn-sm" id="mobilePwaInstallAction" data-pwa-install hidden>Install App</button>
            <button type="button" class="btn btn-primary btn-sm" id="mobilePwaPushAction" hidden>Aktifkan Notifikasi</button>
        </div>
    </section>

    <main class="content-area">
