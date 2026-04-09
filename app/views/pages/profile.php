<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();
$pageTitle = 'Profil Saya';

$msg = '';
$uid = (int) ($_SESSION['user_id'] ?? 0);
$passwordMinLength = appPasswordMinLength();

if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $nama = trim((string) ($_POST['nama'] ?? ''));
    $passwordLama = (string) ($_POST['password_lama'] ?? '');
    $passwordBaru = (string) ($_POST['password_baru'] ?? '');
    $passwordKonfirmasi = (string) ($_POST['password_konfirmasi'] ?? '');

    if ($nama === '') {
        $msg = 'warning|Nama lengkap wajib diisi.';
    } elseif ($passwordBaru !== '') {
        if ($passwordLama === '') {
            $msg = 'warning|Password lama wajib diisi untuk mengganti password.';
        } elseif (strlen($passwordBaru) < $passwordMinLength) {
            $msg = 'warning|Password baru minimal harus ' . $passwordMinLength . ' karakter.';
        } elseif ($passwordBaru !== $passwordKonfirmasi) {
            $msg = 'warning|Konfirmasi password baru tidak cocok.';
        } else {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
            if (!$stmt) {
                $msg = 'danger|Profil tidak dapat diverifikasi saat ini.';
            } else {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row || !password_verify($passwordLama, (string) ($row['password'] ?? ''))) {
                    $msg = 'danger|Password lama salah.';
                } else {
                    $hash = password_hash($passwordBaru, PASSWORD_DEFAULT);
                    $stmt2 = $conn->prepare("UPDATE users SET nama=?, password=? WHERE id=?");
                    if (!$stmt2) {
                        $msg = 'danger|Profil tidak dapat diperbarui saat ini.';
                    } else {
                        $stmt2->bind_param('ssi', $nama, $hash, $uid);
                        if ($stmt2->execute()) {
                            $_SESSION['nama'] = $nama;
                            $msg = 'success|Profil dan password diperbarui.';
                        } else {
                            $msg = 'danger|Gagal menyimpan perubahan profil.';
                        }
                        $stmt2->close();
                    }
                }
            }
        }
    } else {
        $stmt = $conn->prepare("UPDATE users SET nama=? WHERE id=?");
        if (!$stmt) {
            $msg = 'danger|Profil tidak dapat diperbarui saat ini.';
        } else {
            $stmt->bind_param('si', $nama, $uid);
            if ($stmt->execute()) {
                $_SESSION['nama'] = $nama;
                $msg = 'success|Profil diperbarui.';
            } else {
                $msg = 'danger|Gagal menyimpan perubahan profil.';
            }
            $stmt->close();
        }
    }
}

$user = null;
$stmtUser = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param('i', $uid);
    $stmtUser->execute();
    $user = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();
}

if (!$user) {
    http_response_code(404);
    require_once dirname(__DIR__) . '/layouts/header.php';
    ?>
    <div class="empty-state" style="max-width:720px;margin:0 auto;">
        <i class="fas fa-user-slash"></i>
        <div>Profil pengguna tidak ditemukan.</div>
    </div>
    <?php
    require_once dirname(__DIR__) . '/layouts/footer.php';
    exit;
}

$displayName = trim((string) ($user['nama'] ?? ($_SESSION['nama'] ?? '')));
$displayRole = trim((string) ($user['role'] ?? ($_SESSION['role'] ?? '')));
$displayUsername = trim((string) ($user['username'] ?? ($_SESSION['username'] ?? '')));

if ($displayName === '') {
    $displayName = 'User';
}

if ($displayRole === '') {
    $displayRole = 'user';
}

if ($displayUsername === '') {
    $displayUsername = '-';
}

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack admin-panel profile-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-user"></i> Profil</div>
                <h1 class="page-title">Kelola identitas akun dan keamanan secara ringkas</h1>
                <p class="page-description">
                    Halaman profil dipadatkan agar fokus ke data akun utama dan perubahan password tanpa elemen yang berlebihan.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-user-tag"></i> <?= htmlspecialchars($displayUsername) ?></span>
                    <span class="page-meta-item"><i class="fas fa-shield-halved"></i> <?= strtoupper(htmlspecialchars($displayRole)) ?></span>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Username</span>
            <span class="metric-value"><?= htmlspecialchars($displayUsername) ?></span>
            <span class="metric-note">Identitas login akun Anda.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Role</span>
            <span class="metric-value"><?= strtoupper(htmlspecialchars($displayRole)) ?></span>
            <span class="metric-note">Hak akses aktif pada akun ini.</span>
        </div>
    </div>

    <div class="card" style="max-width: 720px; margin: 0 auto; width: 100%;">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-id-card"></i> Detail Akun</span>
                <div class="card-subtitle">Perbarui nama dan password bila diperlukan.</div>
            </div>
        </div>
        <form method="POST" class="setting-body profile-form" style="padding: 0 18px 18px;">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="update">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap</label>
                    <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($displayName) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($displayUsername) ?>" readonly>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Role</label>
                <input type="text" class="form-control" value="<?= strtoupper(htmlspecialchars($displayRole)) ?>" readonly>
            </div>

            <div class="setting-subcard profile-password-card">
                <div class="setting-subcard-title"><i class="fas fa-lock"></i> Ubah Password</div>
                <div class="form-row" style="margin-top: 14px;">
                    <div class="form-group">
                        <label class="form-label">Password Lama</label>
                        <input type="password" name="password_lama" class="form-control" placeholder="Kosongkan jika tidak ubah password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password Baru</label>
                        <input type="password" name="password_baru" class="form-control" placeholder="Minimal <?= $passwordMinLength ?> karakter">
                    </div>
                </div>
                <div class="form-group" style="margin-top: 14px;">
                    <label class="form-label">Konfirmasi Password Baru</label>
                    <input type="password" name="password_konfirmasi" class="form-control" placeholder="Ulangi password baru">
                </div>
            </div>

            <div class="admin-inline-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-outline"><i class="fas fa-home"></i> Kembali</a>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
