<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/settings_page.php';
requireRole('superadmin');
$pageTitle = 'Setting';

$msg = handleSettingPagePost($conn);
extract(loadSettingPageData($conn), EXTR_SKIP);

require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<?php
$roleBadgeMap = [
    'superadmin' => 'badge-role-superadmin',
    'admin' => 'badge-role-admin',
    'service' => 'badge-role-service',
    'kasir' => 'badge-role-kasir',
    'user' => 'badge-role-user',
];

$passwordMinLength = appPasswordMinLength();
?>

<div class="page-stack admin-panel setting-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-sliders"></i> Setting</div>
                <h1 class="page-title">Pengaturan inti sistem, user, permission, dan backup kini lebih terstruktur</h1>
                <p class="page-description">
                    Halaman setting dirapikan agar konfigurasi bisnis, user management, dan kontrol akses darurat lebih mudah dipindai tanpa harus berpindah-pindah pola tampilan.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-users"></i> <?= number_format(count($users)) ?> akun pengguna</span>
                    <span class="page-meta-item"><i class="fas fa-key"></i> <?= number_format($permissionCount) ?> permission darurat aktif</span>
                    <span class="page-meta-item"><i class="fas fa-shield-halved"></i> Maintenance: <?= $maintenanceMode ? 'Aktif' : 'Nonaktif' ?></span>
                </div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('modalTambahUser')"><i class="fas fa-user-plus"></i> Tambah User</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">User Aktif</span>
            <span class="metric-value"><?= number_format($activeUserCount) ?></span>
            <span class="metric-note">Akun yang masih bisa login dan mengakses sistem.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">User Nonaktif</span>
            <span class="metric-value"><?= number_format($inactiveUserCount) ?></span>
            <span class="metric-note">Perlu dicek jika ada akun lama yang sebaiknya diarsipkan.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Pajak POS</span>
            <span class="metric-value"><?= !empty($setting['pajak_aktif']) ? 'Aktif' : 'Off' ?></span>
            <span class="metric-note"><?= htmlspecialchars($setting['pajak_nama'] ?? 'PPN') ?> <?= number_format((float) ($setting['pajak_persen'] ?? 11), 0) ?>% pada transaksi POS.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Maintenance</span>
            <span class="metric-value"><?= $maintenanceMode ? 'On' : 'Off' ?></span>
            <span class="metric-note"><?= $maintenanceMode ? htmlspecialchars($setting['maintenance_msg'] ?: 'Pesan maintenance aktif.') : 'Sistem terbuka untuk akses normal.' ?></span>
        </div>
    </div>

    <?php if (!$settingTableExists): ?>
        <div class="info-banner warning">
            <strong>Tabel <code>setting</code> belum tersedia.</strong> Halaman tetap dibuka, tetapi pengaturan toko dan tema baru akan aktif setelah schema database dilengkapi.
        </div>
    <?php endif; ?>
    <?php if (!$usersTableExists): ?>
        <div class="info-banner warning">
            <strong>Tabel <code>users</code> belum tersedia.</strong> Manajemen user dan permission darurat belum bisa dipakai di hosting ini.
        </div>
    <?php endif; ?>

    <div class="settings-grid">
        <div class="card setting-card settings-span-7">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-store"></i> Profil Toko & Pajak</span>
                    <div class="card-subtitle">Identitas toko dan pengaturan pajak yang dipakai di POS.</div>
                </div>
            </div>
            <form method="POST" class="setting-body">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="setting">
                <input type="hidden" name="jam_masuk_standar" value="<?= htmlspecialchars($setting['jam_masuk_standar'] ?? '08:00') ?>">
                <input type="hidden" name="jam_keluar_standar" value="<?= htmlspecialchars($setting['jam_keluar_standar'] ?? '17:00') ?>">
                <input type="hidden" name="potongan_per_hari" value="<?= floatval($setting['potongan_per_hari'] ?? 0) ?>">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Nama Toko</label><input type="text" name="nama_toko" class="form-control" value="<?= htmlspecialchars($setting['nama_toko'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">Telepon</label><input type="text" name="telepon" class="form-control" value="<?= htmlspecialchars($setting['telepon'] ?? '') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($setting['email'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">Alamat</label><input type="text" name="alamat" class="form-control" value="<?= htmlspecialchars($setting['alamat'] ?? '') ?>"></div>
                </div>
                <div class="form-group"><label class="form-label">Footer Nota</label><textarea name="footer_nota" class="form-control" rows="2"><?= htmlspecialchars($setting['footer_nota'] ?? '') ?></textarea></div>
                <div class="setting-subcard">
                    <div class="setting-subcard-title"><i class="fas fa-percent"></i> Pengaturan Pajak</div>
                    <div class="form-row" style="margin-top: 14px;">
                        <div class="form-group">
                            <label class="form-label">Nama Pajak</label>
                            <input type="text" name="pajak_nama" class="form-control" value="<?= htmlspecialchars($setting['pajak_nama'] ?? 'PPN') ?>" placeholder="PPN, GST, dll">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Persentase (%)</label>
                            <input type="number" name="pajak_persen" class="form-control" value="<?= $setting['pajak_persen'] ?? 11 ?>" min="0" max="100" step="0.01">
                        </div>
                    </div>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:.9rem">
                        <div class="toggle-switch">
                            <input type="checkbox" name="pajak_aktif" id="pajakAktifCheck" value="1" <?= !empty($setting['pajak_aktif']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        Aktifkan pajak di POS
                        <span class="badge <?= !empty($setting['pajak_aktif']) ? 'badge-success' : 'badge-secondary' ?>" id="pajakStatusBadge">
                            <?= !empty($setting['pajak_aktif']) ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </label>
                </div>
                <div class="admin-inline-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Profil Toko</button>
                </div>
            </form>
        </div>

        <div class="card setting-card settings-span-5">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-clock"></i> Jam Kerja & Potongan</span>
                    <div class="card-subtitle">Dipakai pada absensi dan penggajian.</div>
                </div>
            </div>
            <form method="POST" class="setting-body">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="setting">
                <?php foreach (['nama_toko','alamat','telepon','email','footer_nota','pajak_aktif','pajak_persen','pajak_nama'] as $k): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($setting[$k] ?? '') ?>">
                <?php endforeach; ?>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Jam Masuk Standar</label>
                        <input type="time" name="jam_masuk_standar" class="form-control" value="<?= htmlspecialchars($setting['jam_masuk_standar'] ?? '08:00') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jam Keluar Standar</label>
                        <input type="time" name="jam_keluar_standar" class="form-control" value="<?= htmlspecialchars($setting['jam_keluar_standar'] ?? '17:00') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Potongan per Hari Alpha (Rp)</label>
                        <input type="number" name="potongan_per_hari" class="form-control" min="0" value="<?= floatval($setting['potongan_per_hari'] ?? 0) ?>">
                    </div>
                </div>
                <div class="info-banner primary">Digunakan untuk menentukan status terlambat dan perhitungan potongan gaji.</div>
                <div class="admin-inline-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Jam Kerja</button>
                </div>
            </form>
        </div>

        <div class="card setting-card settings-span-12">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-users-cog"></i> Manajemen User</span>
                    <div class="card-subtitle">Kelola akun internal beserta role dan status aksesnya.</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('modalTambahUser')"><i class="fas fa-plus"></i> Tambah User</button>
            </div>
            <div class="table-responsive table-desktop">
                <table>
                    <thead><tr><th>#</th><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $i => $u): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><?= htmlspecialchars($u['nama']) ?></td>
                        <td><span class="code-pill"><?= htmlspecialchars($u['username']) ?></span></td>
                        <td><span class="badge <?= $roleBadgeMap[$u['role']] ?? 'badge-role-user' ?>"><?= strtoupper($u['role']) ?></span></td>
                        <td><span class="badge <?= $u['status']==='aktif'?'badge-success':'badge-danger' ?>"><?= $u['status'] ?></span></td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-warning btn-sm" onclick='editUser(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i></button>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" onsubmit="confirmDelete(this);return false;">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="hapus_user">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mobile-data-list">
                <?php foreach ($users as $u): ?>
                <div class="mobile-data-card">
                    <div class="mobile-data-top">
                        <div>
                            <div class="mobile-data-title"><?= htmlspecialchars($u['nama']) ?></div>
                            <div class="mobile-data-subtitle"><?= htmlspecialchars($u['username']) ?></div>
                        </div>
                        <span class="badge <?= $u['status']==='aktif'?'badge-success':'badge-danger' ?>"><?= $u['status'] ?></span>
                    </div>
                    <div class="mobile-data-grid">
                        <div class="mobile-data-field">
                            <span class="mobile-data-label">Role</span>
                            <span class="mobile-data-value"><?= strtoupper(htmlspecialchars($u['role'])) ?></span>
                        </div>
                    </div>
                    <div class="mobile-data-actions">
                        <button type="button" class="btn btn-warning btn-sm" onclick='editUser(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i> Edit</button>
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" onsubmit="confirmDelete(this);return false;">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="hapus_user">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Hapus</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card setting-card settings-span-6">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-key" style="color:var(--warning)"></i> Permission Darurat</span>
                    <div class="card-subtitle">Akses sementara ke halaman tertentu untuk kondisi khusus.</div>
                </div>
                <button type="button" class="btn btn-warning btn-sm" onclick="openModal('modalPermission')"><i class="fas fa-plus"></i> Tambah Akses</button>
            </div>
            <?php if (!$tblPermOk): ?>
                <div class="info-banner warning">Tabel <code>user_permissions</code> belum ada. Jalankan migration sebelum fitur ini dipakai.</div>
            <?php elseif (empty($permissions)): ?>
                <div class="empty-state">
                    <i class="fas fa-shield-alt"></i>
                    <div>Tidak ada permission darurat aktif.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive table-desktop">
                    <table>
                        <thead><tr><th>User</th><th>Role</th><th>Halaman</th><th>Expired</th><th>Catatan</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php foreach ($permissions as $p): ?>
                        <tr>
                            <td><?=htmlspecialchars($p['nama_user'])?></td>
                            <td><span class="badge <?= $roleBadgeMap[$p['role']] ?? 'badge-role-user' ?>"><?=strtoupper($p['role'])?></span></td>
                            <td><span class="code-pill"><?=htmlspecialchars($p['halaman'])?></span></td>
                            <td><?= $p['expired_at'] ? '<span class="text-danger">' . date('d/m/Y H:i',strtotime($p['expired_at'])) . '</span>' : '<span class="text-muted">Tidak ada</span>' ?></td>
                            <td><?=htmlspecialchars($p['catatan']??'-')?></td>
                            <td>
                                <form method="POST" onsubmit="confirmDelete(this);return false;">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="cabut_permission">
                                    <input type="hidden" name="perm_id" value="<?=$p['id']?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Cabut</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-data-list">
                    <?php foreach ($permissions as $p): ?>
                    <div class="mobile-data-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?=htmlspecialchars($p['nama_user'])?></div>
                                <div class="mobile-data-subtitle"><?=htmlspecialchars($p['halaman'])?></div>
                            </div>
                            <span class="badge <?= $roleBadgeMap[$p['role']] ?? 'badge-role-user' ?>"><?=strtoupper($p['role'])?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Expired</span>
                                <span class="mobile-data-value"><?= $p['expired_at'] ? date('d/m/Y H:i',strtotime($p['expired_at'])) : 'Tidak ada' ?></span>
                            </div>
                            <?php if (!empty($p['catatan'])): ?>
                                <div class="mobile-data-field">
                                    <span class="mobile-data-label">Catatan</span>
                                    <span class="mobile-data-value"><?=htmlspecialchars($p['catatan'])?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mobile-data-actions">
                            <form method="POST" onsubmit="confirmDelete(this);return false;">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="cabut_permission">
                                <input type="hidden" name="perm_id" value="<?=$p['id']?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Cabut</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card setting-card settings-span-6" id="backup-restore-card">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-database"></i> Backup & Restore</span>
                    <div class="card-subtitle">Kelola cadangan database untuk keamanan data operasional.</div>
                </div>
            </div>
            <div class="restore-grid">
                <div class="restore-panel">
                    <div class="restore-panel-title"><i class="fas fa-download" style="color:var(--success)"></i> Backup Database</div>
                    <div class="restore-panel-copy">Download seluruh database menjadi file `.sql` untuk backup rutin atau pemindahan server.</div>
                    <?php if (!empty($backupDownloadEnabled)): ?>
                        <form method="POST" action="<?= htmlspecialchars(pageUrl('backup.php')) ?>">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="download">
                            <button type="submit" class="btn btn-success"><i class="fas fa-download"></i> Download Backup</button>
                        </form>
                        <div class="code-pill">Download memakai POST + CSRF untuk mengurangi risiko request lintas situs.</div>
                    <?php else: ?>
                        <div class="info-banner warning">Download backup via web dimatikan oleh konfigurasi environment server.</div>
                    <?php endif; ?>
                </div>
                <div class="restore-panel">
                    <div class="restore-panel-title"><i class="fas fa-upload" style="color:var(--warning)"></i> Restore Database</div>
                    <div class="restore-panel-copy">Upload file `.sql` untuk mengembalikan data. Proses ini menimpa data aktif, jadi gunakan dengan sangat hati-hati.</div>
                    <?php if (!empty($backupRestoreEnabled)): ?>
                        <div class="admin-inline-actions">
                            <input type="file" id="sqlRestoreFile" accept=".sql" class="form-control">
                            <input type="text" id="restoreConfirmPhrase" class="form-control" placeholder="RESTORE">
                            <button type="button" class="btn btn-warning" onclick="prosesRestore()"><i class="fas fa-upload"></i> Restore</button>
                        </div>
                        <div class="code-pill">Maks 50MB - ketik RESTORE</div>
                    <?php else: ?>
                        <div class="info-banner warning">Restore database via web dinonaktifkan secara default untuk keamanan production.</div>
                    <?php endif; ?>
                    <div id="restoreStatus" class="small text-muted"></div>
                </div>
            </div>
        </div>

        <div class="card setting-card settings-span-12">
            <div class="card-header">
                <div>
                    <span class="card-title"><i class="fas fa-palette"></i> Tema & Maintenance</span>
                    <div class="card-subtitle">Atur warna utama aplikasi dan kontrol maintenance mode saat perlu jeda layanan.</div>
                </div>
            </div>
            <form method="POST" class="setting-body">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="tema">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Warna Utama</label>
                        <div class="color-preview-row">
                            <input type="color" name="tema_primary" class="form-control" value="<?=htmlspecialchars($setting['tema_primary']??'#4f46e5')?>" oninput="document.getElementById('temaPreview').style.background=this.value">
                            <div id="temaPreview" class="color-preview-box" style="background:<?=htmlspecialchars($setting['tema_primary']??'#4f46e5')?>"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mode Maintenance</label>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px">
                            <div class="toggle-switch">
                                <input type="checkbox" name="maintenance_mode" value="1" <?=!empty($setting['maintenance_mode'])?'checked':''?>>
                                <span class="toggle-slider"></span>
                            </div>
                            <span style="font-size:.9rem">Aktifkan maintenance mode</span>
                        </label>
                        <input type="text" name="maintenance_msg" class="form-control" value="<?=htmlspecialchars($setting['maintenance_msg']??'')?>" placeholder="Pesan maintenance untuk pengguna">
                    </div>
                </div>
                <div class="info-banner primary">Warna utama terlihat setelah refresh halaman. Maintenance mode sebaiknya dipakai saat update sistem atau migrasi data.</div>
                <div class="admin-inline-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Tema</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tambah User -->
<div class="modal-overlay" id="modalTambahUser">
    <div class="modal-box">
        <div class="modal-header"><h5>Tambah User</h5><button class="modal-close" onclick="closeModal('modalTambahUser')">&times;</button></div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="tambah_user">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required></div>
                </div>
                <div class="form-group"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" placeholder="Minimal <?= $passwordMinLength ?> karakter" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <option value="user">User (Operator/Tim Produksi)</option>
                            <option value="kasir">Kasir</option>
                            <option value="service">Service / CS</option>
                            <option value="admin">Admin / HRD</option>
                            <option value="superadmin">Superadmin (CEO/Head Office)</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-control"><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambahUser')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit User -->
<div class="modal-overlay" id="modalEditUser">
    <div class="modal-box">
        <div class="modal-header"><h5>Edit User</h5><button class="modal-close" onclick="closeModal('modalEditUser')">&times;</button></div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="id" id="euId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" id="euNama" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Username *</label><input type="text" name="username" id="euUname" class="form-control" required></div>
                </div>
                <div class="form-group"><label class="form-label">Password Baru <small class="text-muted">(minimal <?= $passwordMinLength ?> karakter, kosongkan jika tidak diubah)</small></label><input type="password" name="password" class="form-control" placeholder="Minimal <?= $passwordMinLength ?> karakter"></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Role</label>
                        <select name="role" id="euRole" class="form-control">
                            <option value="user">User (Operator/Tim Produksi)</option>
                            <option value="kasir">Kasir</option>
                            <option value="service">Service / CS</option>
                            <option value="admin">Admin / HRD</option>
                            <option value="superadmin">Superadmin (CEO/Head Office)</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" id="euStatus" class="form-control"><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditUser')">Batal</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Permission Darurat -->
<div class="modal-overlay" id="modalPermission">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-key"></i> Tambah Akses Darurat</h5>
            <button class="modal-close" onclick="closeModal('modalPermission')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="tambah_permission">
            <div class="modal-body">
                <div class="alert alert-warning" style="font-size:.85rem">
                    <i class="fas fa-exclamation-triangle"></i>
                    Fitur ini memberikan akses sementara ke halaman yang biasanya tidak bisa diakses user tersebut.
                </div>
                <div class="form-group">
                    <label class="form-label">User *</label>
                    <select name="perm_user_id" class="form-control" required>
                        <option value="">-- Pilih User --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['nama'])?> (<?=strtoupper($u['role'])?>) - <?=htmlspecialchars($u['username'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Halaman yang Diizinkan *</label>
                    <select name="halaman" class="form-control" required>
                        <option value="">-- Pilih Halaman --</option>
                        <?php foreach ($halamanList as $file => $label): ?>
                            <option value="<?=$file?>"><?=$label?> (<?=$file?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Berlaku Sampai (opsional)</label>
                    <input type="datetime-local" name="expired_at" class="form-control">
                    <small class="text-muted">Kosongkan = tidak ada batas waktu</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Alasan / Catatan</label>
                    <input type="text" name="catatan" class="form-control" placeholder="Contoh: Backup kasir saat kasir utama sakit">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalPermission')">Batal</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Berikan Akses</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
