<?php
$menus = [
    ['icon'=>'fas fa-grid-2',          'label'=>'Dashboard',         'href'=>'dashboard.php',     'roles'=>['superadmin','admin','service','kasir','user']],
    ['icon'=>'fas fa-cash-register',   'label'=>'POS System',        'href'=>'pos.php',            'roles'=>['superadmin','admin','service','kasir']],
    ['icon'=>'fas fa-file-signature',  'label'=>'Penawaran',         'href'=>'penawaran.php',      'roles'=>['superadmin','admin','service','kasir']],
    ['icon'=>'fas fa-receipt',         'label'=>'Transaksi',         'href'=>'transaksi.php',      'roles'=>['superadmin','admin','service','kasir']],
    ['icon'=>'fas fa-users',           'label'=>'Pelanggan',         'href'=>'pelanggan.php',      'roles'=>['superadmin','admin','service','kasir']],
    ['icon'=>'fas fa-industry',        'label'=>'Produksi',          'href'=>'produksi.php',       'roles'=>['superadmin','admin','service','user']],
    ['icon'=>'fas fa-print',           'label'=>'Siap Cetak',        'href'=>'siap_cetak.php',     'roles'=>['superadmin','admin','service','user']],
    ['icon'=>'fas fa-boxes',           'label'=>'Produk & Stok',     'href'=>'produk.php',         'roles'=>['superadmin','admin','service']],
    ['icon'=>'fas fa-cart-flatbed',    'label'=>'Purchasing Bahan',  'href'=>'pembelian_bahan.php','roles'=>['superadmin','admin','service']],
    ['icon'=>'fas fa-wallet',          'label'=>'Operasional',       'href'=>'operasional.php',    'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-id-badge',        'label'=>'Karyawan',          'href'=>'karyawan.php',       'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-calendar-check',  'label'=>'Absensi',           'href'=>'absensi.php',        'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-money-bill-wave', 'label'=>'Penggajian',        'href'=>'penggajian.php',     'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-chart-line',      'label'=>'KPI',               'href'=>'kpi.php',            'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-fingerprint',     'label'=>'Absensi Saya',      'href'=>'absensi_mobile.php', 'roles'=>['superadmin','admin','service','kasir','user']],
    ['icon'=>'fas fa-comments',        'label'=>'Room Chat',         'href'=>'chat.php',           'roles'=>['superadmin','admin','service','kasir','user']],
    ['icon'=>'fas fa-shield-halved',   'label'=>'Audit Log',         'href'=>'audit_log.php',      'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-calculator',      'label'=>'HPP & Margin',      'href'=>'hpp.php',            'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-chart-bar',       'label'=>'Laporan',           'href'=>'laporan.php',        'roles'=>['superadmin','admin']],
    ['icon'=>'fas fa-cog',             'label'=>'Setting',           'href'=>'setting.php',        'roles'=>['superadmin']],
    ['icon'=>'fas fa-paint-brush',     'label'=>'Finishing & Bahan', 'href'=>'finishing.php',      'roles'=>['superadmin','admin']],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <button type="button" class="sidebar-close-btn" onclick="closeSidebar(event)" aria-label="Tutup navigasi">
            <i class="fas fa-xmark"></i>
        </button>
        <a href="<?= pageUrl('dashboard.php') ?>" class="sidebar-brand-link" aria-label="JWS Printing & Apparel">
            <img src="<?= companyLogoUrl() ?>" alt="Logo perusahaan JWS Printing & Apparel" width="156" height="62">
        </a>
    </div>

    <div class="sidebar-scroll">
        <div class="sidebar-user">
            <div class="user-avatar">
                <?php if ($user['foto']): ?>
                    <img src="<?= htmlspecialchars(employeePhotoUrl((string) $user['foto'])) ?>" alt="">
                <?php else: ?>
                    <?= strtoupper(substr($user['nama'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($user['nama']) ?></div>
                <div class="user-role-badge"><?= strtoupper(htmlspecialchars($user['role'])) ?></div>
            </div>
        </div>

        <div class="sidebar-section-label">Menu Utama</div>

        <nav class="sidebar-nav" aria-label="Navigasi utama">
            <?php foreach ($menus as $menu): ?>
                <?php if (in_array($user['role'], $menu['roles'], true)): ?>
                <a href="<?= pageUrl($menu['href']) ?>" class="nav-item <?= $currentPage === $menu['href'] ? 'active' : '' ?>" <?= $currentPage === $menu['href'] ? 'aria-current="page"' : '' ?>>
                    <i class="<?= $menu['icon'] ?>"></i>
                    <span><?= $menu['label'] ?></span>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="<?= pageUrl('profile.php') ?>" class="nav-item">
                <i class="fas fa-user-circle"></i>
                <span>Profil Saya</span>
            </a>
        </div>
    </div>
</aside>
