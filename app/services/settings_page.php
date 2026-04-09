<?php

function settingPageAllowedRoles(): array
{
    return ['superadmin', 'admin', 'service', 'kasir', 'user'];
}

function settingPageNormalizeRole(string $role): string
{
    $role = strtolower(trim($role));
    return in_array($role, settingPageAllowedRoles(), true) ? $role : 'user';
}

function settingPageNormalizeUserStatus(string $status): string
{
    return strtolower(trim($status)) === 'nonaktif' ? 'nonaktif' : 'aktif';
}

function settingPageAllowedPages(): array
{
    return [
        'pos.php' => 'POS System',
        'transaksi.php' => 'Transaksi',
        'pelanggan.php' => 'Pelanggan',
        'produksi.php' => 'Produksi',
        'produk.php' => 'Produk / Stok',
        'pembelian_bahan.php' => 'Purchasing Bahan',
        'operasional.php' => 'Operasional',
        'karyawan.php' => 'Karyawan',
        'absensi.php' => 'Absensi Admin',
        'penggajian.php' => 'Penggajian',
        'kpi.php' => 'KPI',
        'hpp.php' => 'HPP & Margin',
        'laporan.php' => 'Laporan',
        'setting.php' => 'Setting',
        'finishing.php' => 'Finishing & Bahan',
        'siap_cetak.php' => 'Siap Cetak',
    ];
}

function settingPageUserDeletionDependencies(mysqli $conn, int $userId): array
{
    return schemaBuildDependencyList($conn, $userId, [
        'profil karyawan' => ['table' => 'karyawan', 'column' => 'user_id'],
        'transaksi' => ['table' => 'transaksi', 'column' => 'user_id'],
        'absensi' => ['table' => 'absensi', 'column' => 'user_id'],
        'produksi' => ['table' => 'produksi', 'column' => 'user_id'],
        'todo tahapan' => ['table' => 'todo_list_tahapan', 'column' => 'user_id'],
        'chat' => ['table' => 'chat_pesan', 'column' => 'user_id'],
        'audit log' => ['table' => 'audit_log', 'column' => 'user_id'],
        'slip gaji dibuat oleh' => ['table' => 'slip_gaji', 'column' => 'created_by'],
        'slip gaji dibayar oleh' => ['table' => 'slip_gaji', 'column' => 'dibayar_oleh'],
        'push subscription' => ['table' => 'push_subscriptions', 'column' => 'user_id'],
    ]);
}

function handleSettingPagePost(mysqli $conn): string
{
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === '') {
        return '';
    }

    $passwordMinLength = appPasswordMinLength();

    if ($action === 'setting') {
        $nama = trim((string) ($_POST['nama_toko'] ?? ''));
        $alamat = trim((string) ($_POST['alamat'] ?? ''));
        $telepon = trim((string) ($_POST['telepon'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $footer = trim((string) ($_POST['footer_nota'] ?? ''));
        $pajak = !empty($_POST['pajak_aktif']) ? 1 : 0;
        $pajakPersen = (float) ($_POST['pajak_persen'] ?? 11);
        $pajakNama = trim((string) ($_POST['pajak_nama'] ?? 'PPN'));
        $jamMasuk = trim((string) ($_POST['jam_masuk_standar'] ?? '08:00')) ?: '08:00';
        $jamKeluar = trim((string) ($_POST['jam_keluar_standar'] ?? '17:00')) ?: '17:00';
        $potongan = (float) ($_POST['potongan_per_hari'] ?? 0);

        if (schemaColumnExists($conn, 'setting', 'jam_masuk_standar')) {
            $stmt = $conn->prepare("UPDATE setting SET nama_toko=?,alamat=?,telepon=?,email=?,footer_nota=?,pajak_aktif=?,pajak_persen=?,pajak_nama=?,jam_masuk_standar=?,jam_keluar_standar=?,potongan_per_hari=? WHERE id=1");
            if (!$stmt) {
                return 'danger|Setting tidak dapat diperbarui saat ini.';
            }

            $stmt->bind_param('sssssidsssd', $nama, $alamat, $telepon, $email, $footer, $pajak, $pajakPersen, $pajakNama, $jamMasuk, $jamKeluar, $potongan);
        } else {
            $stmt = $conn->prepare("UPDATE setting SET nama_toko=?,alamat=?,telepon=?,email=?,footer_nota=?,pajak_aktif=?,pajak_persen=?,pajak_nama=? WHERE id=1");
            if (!$stmt) {
                return 'danger|Setting tidak dapat diperbarui saat ini.';
            }

            $stmt->bind_param('sssssids', $nama, $alamat, $telepon, $email, $footer, $pajak, $pajakPersen, $pajakNama);
        }

        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'success|Setting berhasil disimpan.' : 'danger|Gagal menyimpan setting.';
    }

    if ($action === 'tambah_user') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = settingPageNormalizeRole((string) ($_POST['role'] ?? 'user'));
        $status = settingPageNormalizeUserStatus((string) ($_POST['status'] ?? 'aktif'));

        if ($nama === '' || $username === '' || $password === '') {
            return 'danger|Nama, username, dan password wajib diisi.';
        }
        if (strlen($password) < $passwordMinLength) {
            return 'danger|Password user minimal ' . $passwordMinLength . ' karakter.';
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (nama,username,password,role,status) VALUES (?,?,?,?,?)");
        if (!$stmt) {
            return 'danger|User tidak dapat ditambahkan saat ini.';
        }

        $stmt->bind_param('sssss', $nama, $username, $passwordHash, $role, $status);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'success|User ditambahkan.' : 'danger|Gagal: username mungkin sudah ada.';
    }

    if ($action === 'edit_user') {
        $id = (int) ($_POST['id'] ?? 0);
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $role = settingPageNormalizeRole((string) ($_POST['role'] ?? 'user'));
        $status = settingPageNormalizeUserStatus((string) ($_POST['status'] ?? 'aktif'));

        if ($id <= 0 || $nama === '' || $username === '') {
            return 'danger|Data user tidak lengkap.';
        }
        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            if ($status !== 'aktif') {
                return 'danger|Akun sendiri tidak boleh dinonaktifkan dari menu setting.';
            }
            if ($role !== (string) ($_SESSION['role'] ?? '')) {
                return 'danger|Role akun sendiri tidak boleh diubah dari menu setting untuk mencegah kehilangan akses.';
            }
        }

        if (!empty($_POST['password'])) {
            $newPassword = (string) $_POST['password'];
            if (strlen($newPassword) < $passwordMinLength) {
                return 'danger|Password user minimal ' . $passwordMinLength . ' karakter.';
            }
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET nama=?,username=?,password=?,role=?,status=? WHERE id=?");
            if (!$stmt) {
                return 'danger|User tidak dapat diperbarui saat ini.';
            }

            $stmt->bind_param('sssssi', $nama, $username, $passwordHash, $role, $status, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET nama=?,username=?,role=?,status=? WHERE id=?");
            if (!$stmt) {
                return 'danger|User tidak dapat diperbarui saat ini.';
            }

            $stmt->bind_param('ssssi', $nama, $username, $role, $status, $id);
        }

        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'success|User diperbarui.' : 'danger|Gagal memperbarui user.';
    }

    if ($action === 'hapus_user') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            return 'danger|User tidak valid.';
        }

        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            return 'danger|Tidak bisa hapus akun sendiri.';
        }

        $dependencies = settingPageUserDeletionDependencies($conn, $id);
        if (!empty($dependencies)) {
            return 'danger|User tidak dapat dihapus karena masih dipakai di ' . implode(', ', $dependencies) . '. Ubah status menjadi nonaktif jika hanya ingin menonaktifkan akses.';
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        if (!$stmt) {
            return 'danger|User tidak dapat dihapus saat ini.';
        }

        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'success|User dihapus.' : 'danger|Gagal menghapus user.';
    }

    if ($action === 'tambah_permission') {
        if (!schemaTableExists($conn, 'user_permissions')) {
            return 'danger|Tabel user_permissions belum ada. Jalankan SQL migration.';
        }

        $userId = (int) ($_POST['perm_user_id'] ?? 0);
        $halaman = basename(trim((string) ($_POST['halaman'] ?? '')));
        $expiredAt = !empty($_POST['expired_at']) ? (string) $_POST['expired_at'] : null;
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $createdBy = (int) ($_SESSION['user_id'] ?? 0);
        $allowedPages = settingPageAllowedPages();

        if ($userId <= 0 || $halaman === '') {
            return 'danger|User dan halaman wajib dipilih.';
        }
        if (!array_key_exists($halaman, $allowedPages)) {
            return 'danger|Permission darurat hanya bisa diberikan ke halaman yang sudah diizinkan.';
        }

        $stmt = $conn->prepare("INSERT INTO user_permissions (user_id,halaman,aktif,expired_at,catatan,created_by) VALUES (?,?,1,?,?,?) ON DUPLICATE KEY UPDATE aktif=1, expired_at=VALUES(expired_at), catatan=VALUES(catatan)");
        if (!$stmt) {
            return 'danger|Permission tidak dapat disimpan saat ini.';
        }

        $stmt->bind_param('isssi', $userId, $halaman, $expiredAt, $catatan, $createdBy);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'success|Permission darurat ditambahkan.' : 'danger|Gagal menambahkan permission.';
    }

    if ($action === 'cabut_permission') {
        $permissionId = (int) ($_POST['perm_id'] ?? 0);
        if ($permissionId <= 0) {
            return 'danger|Permission tidak valid.';
        }

        $stmt = $conn->prepare("UPDATE user_permissions SET aktif=0 WHERE id=?");
        if (!$stmt) {
            return 'danger|Permission tidak dapat dicabut saat ini.';
        }

        $stmt->bind_param('i', $permissionId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'success|Permission dicabut.' : 'danger|Gagal mencabut permission.';
    }

    if ($action === 'tema') {
        $tema = trim((string) ($_POST['tema_primary'] ?? '#4f46e5'));
        $maintenanceMode = !empty($_POST['maintenance_mode']) ? 1 : 0;
        $maintenanceMessage = trim((string) ($_POST['maintenance_msg'] ?? ''));

        if (!schemaColumnExists($conn, 'setting', 'tema_primary')) {
            return 'warning|Kolom tema belum ada. Jalankan SQL migration.';
        }

        $stmt = $conn->prepare("UPDATE setting SET tema_primary=?,maintenance_mode=?,maintenance_msg=? WHERE id=1");
        if (!$stmt) {
            return 'danger|Tema tidak dapat diperbarui saat ini.';
        }

        $stmt->bind_param('sis', $tema, $maintenanceMode, $maintenanceMessage);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? 'success|Tema & maintenance disimpan.' : 'danger|Gagal menyimpan tema.';
    }

    return '';
}

function loadSettingPageData(mysqli $conn): array
{
    $settingTableExists = schemaTableExists($conn, 'setting');
    $usersTableExists = schemaTableExists($conn, 'users');
    $settingResult = $settingTableExists ? $conn->query("SELECT * FROM setting WHERE id=1") : false;
    $setting = $settingResult ? ($settingResult->fetch_assoc() ?: []) : [];
    $usersResult = $usersTableExists ? $conn->query("SELECT * FROM users ORDER BY role, nama") : false;
    $users = $usersResult ? $usersResult->fetch_all(MYSQLI_ASSOC) : [];

    $permissions = [];
    $tablePermissionExists = schemaTableExists($conn, 'user_permissions');
    if ($tablePermissionExists && $usersTableExists) {
        $permissionResult = $conn->query("SELECT up.*, u.nama as nama_user, u.role FROM user_permissions up JOIN users u ON up.user_id=u.id WHERE up.aktif=1 ORDER BY up.created_at DESC");
        $permissions = $permissionResult ? $permissionResult->fetch_all(MYSQLI_ASSOC) : [];
    }

    $activeUserCount = count(array_filter($users, static function ($user) {
        return ($user['status'] ?? '') === 'aktif';
    }));
    $inactiveUserCount = count($users) - $activeUserCount;
    $permissionCount = count($permissions);
    $maintenanceMode = !empty($setting['maintenance_mode']);
    $halamanList = settingPageAllowedPages();

    return [
        'setting' => $setting,
        'users' => $users,
        'permissions' => $permissions,
        'settingTableExists' => $settingTableExists,
        'usersTableExists' => $usersTableExists,
        'tblPermOk' => $tablePermissionExists,
        'activeUserCount' => $activeUserCount,
        'inactiveUserCount' => $inactiveUserCount,
        'permissionCount' => $permissionCount,
        'maintenanceMode' => $maintenanceMode,
        'backupDownloadEnabled' => function_exists('appBackupDownloadEnabled') ? appBackupDownloadEnabled() : true,
        'backupRestoreEnabled' => function_exists('appBackupRestoreEnabled') ? appBackupRestoreEnabled() : false,
        'extraCss' => '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">',
        'pageState' => [
            'setting' => [
                'backupEndpoint' => pageUrl('backup.php'),
                'maxRestoreSizeMb' => 50,
                'backupRestoreEnabled' => function_exists('appBackupRestoreEnabled') ? appBackupRestoreEnabled() : false,
            ],
        ],
        'pageJs' => 'setting.js',
        'halamanList' => $halamanList,
    ];
}
