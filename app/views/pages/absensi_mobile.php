<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();
$pageTitle = 'Absensi Mobile';

$userId = (int) $_SESSION['user_id'];

function absensiMobileStorePhoto(string $imageBase64): array
{
    if ($imageBase64 === '' || strpos($imageBase64, ';base64,') === false) {
        throw new RuntimeException('Foto tidak valid atau gagal diambil dari kamera.');
    }

    $imageParts = explode(';base64,', $imageBase64, 2);
    $imageDecoded = base64_decode($imageParts[1], true);
    if ($imageDecoded === false) {
        throw new RuntimeException('Foto tidak valid atau rusak.');
    }

    $uploadDir = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'absensi' . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Folder upload absensi tidak tersedia.');
    }

    $fileName = uniqid('abs_', true) . '.jpg';
    $filePath = $uploadDir . $fileName;
    if (file_put_contents($filePath, $imageDecoded) === false) {
        throw new RuntimeException('Foto absensi gagal disimpan.');
    }

    return ['name' => $fileName, 'path' => $filePath];
}

// 1. Ambil ID Karyawan yang terhubung dengan user_id ini
$stmtEmp = $conn->prepare("SELECT id FROM karyawan WHERE user_id = ?");
$stmtEmp->bind_param('i', $userId);
$stmtEmp->execute();
$karyawanRes = $stmtEmp->get_result();
$karyawan = $karyawanRes->fetch_assoc();
$stmtEmp->close();

$karyawanId = $karyawan['id'] ?? null;

// 2. Handle POST Request (AJAX) untuk Upload Foto & Simpan Absensi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!$karyawanId) {
        echo json_encode(['success' => false, 'message' => 'Akun belum terhubung dengan data karyawan. Hubungi Admin.']);
        exit;
    }

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    if (!in_array($action, ['checkin', 'checkout'], true)) {
        echo json_encode(['success' => false, 'message' => 'Aksi absensi tidak dikenali.']);
        exit;
    }

    $imageBase64 = (string) ($_POST['image'] ?? '');
    if ($imageBase64 === '') {
        echo json_encode(['success' => false, 'message' => 'Foto tidak valid atau gagal diambil dari kamera.']);
        exit;
    }
    
    $today = date('Y-m-d');
    $currentTime = date('H:i:s');

    // Ambil konfigurasi jam kerja standar
    $resSet = $conn->query("SELECT jam_masuk_standar FROM setting LIMIT 1");
    $setting = $resSet ? $resSet->fetch_assoc() : null;
    $jamMasukStandar = $setting['jam_masuk_standar'] ?? '08:00:00';

    if ($action === 'checkin') {
        $stmtCheck = $conn->prepare("SELECT id FROM absensi WHERE karyawan_id = ? AND tanggal = ?");
        $stmtCheck->bind_param('is', $karyawanId, $today);
        $stmtCheck->execute();
        $hasCheckin = $stmtCheck->get_result()->num_rows > 0;
        $stmtCheck->close();
        if ($hasCheckin) {
            echo json_encode(['success' => false, 'message' => 'Anda sudah melakukan check-in hari ini.']);
            exit;
        }

        try {
            $photo = absensiMobileStorePhoto($imageBase64);
        } catch (RuntimeException $exception) {
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
            exit;
        }

        $status = ($currentTime > $jamMasukStandar) ? 'terlambat' : 'hadir';
        $stmtIn = $conn->prepare("INSERT INTO absensi (karyawan_id, user_id, tanggal, jam_masuk, foto_masuk, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtIn->bind_param('iissss', $karyawanId, $userId, $today, $currentTime, $photo['name'], $status);
        
        if ($stmtIn->execute()) {
            echo json_encode(['success' => true, 'message' => 'Check-in berhasil! Selamat bekerja.']);
        } else {
            if (is_file($photo['path'])) {
                @unlink($photo['path']);
            }
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data absensi server error.']);
        }
        $stmtIn->close();
        exit;

    } elseif ($action === 'checkout') {
        $stmtCheck = $conn->prepare("SELECT id, jam_keluar FROM absensi WHERE karyawan_id = ? AND tanggal = ?");
        $stmtCheck->bind_param('is', $karyawanId, $today);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $absensi = $resCheck->fetch_assoc();
        $stmtCheck->close();

        if (!$absensi) {
            echo json_encode(['success' => false, 'message' => 'Anda belum melakukan check-in hari ini.']);
            exit;
        }
        if (!empty($absensi['jam_keluar'])) {
            echo json_encode(['success' => false, 'message' => 'Anda sudah melakukan check-out hari ini.']);
            exit;
        }

        try {
            $photo = absensiMobileStorePhoto($imageBase64);
        } catch (RuntimeException $exception) {
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
            exit;
        }

        $stmtOut = $conn->prepare("UPDATE absensi SET jam_keluar = ?, foto_keluar = ? WHERE id = ?");
        $stmtOut->bind_param('ssi', $currentTime, $photo['name'], $absensi['id']);
        
        if ($stmtOut->execute()) {
            echo json_encode(['success' => true, 'message' => 'Check-out berhasil! Hati-hati di jalan.']);
        } else {
            if (is_file($photo['path'])) {
                @unlink($photo['path']);
            }
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data absensi server error.']);
        }
        $stmtOut->close();
        exit;
    }
}

// 3. Load Data untuk UI
$todayDate = date('Y-m-d');
$absensiToday = null;
if ($karyawanId) {
    $stmtToday = $conn->prepare("SELECT * FROM absensi WHERE karyawan_id = ? AND tanggal = ?");
    $stmtToday->bind_param('is', $karyawanId, $todayDate);
    $stmtToday->execute();
    $absensiToday = $stmtToday->get_result()->fetch_assoc();
    $stmtToday->close();
}

// Tentukan State UI absensi ('belum', 'sudah_masuk', 'sudah_keluar')
$state = 'belum';
if ($absensiToday) {
    $state = empty($absensiToday['jam_keluar']) ? 'sudah_masuk' : 'sudah_keluar';
}

$dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$formattedDate = $dayNames[(int) date('w')] . ', ' . date('j') . ' ' . $monthNames[(int) date('n')] . ' ' . date('Y');

require_once dirname(__DIR__) . '/layouts/header.php';
?>

<div class="container-fluid px-0">
    <div class="card shadow-sm border-0 rounded-4 mx-3 mx-sm-auto my-3 my-sm-4" style="max-width:500px;">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4 text-center">
            <h5 class="card-title mb-0 fw-bold text-dark">Absensi Scanner</h5>
            <p class="text-muted small mt-1 mb-0"><?= htmlspecialchars($formattedDate) ?></p>
        </div>
        <div class="card-body p-4 text-center">
            <?php if (!$karyawanId): ?>
                <div class="alert alert-warning border-0 rounded-3 shadow-sm">
                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br>
                    Akun Anda belum terhubung ke data karyawan.<br>Silakan minta Admin untuk menautkan profil Anda.
                </div>
            <?php elseif ($state === 'sudah_keluar'): ?>
                <div class="alert alert-success border-0 rounded-3 shadow-sm">
                    <i class="fas fa-check-circle fa-3x mb-3 mt-2 text-success"></i><br>
                    <h6 class="fw-bold mb-1">Absensi Selesai</h6>
                    Anda sudah melakukan absensi masuk dan keluar untuk hari ini.
                </div>
                <div class="mt-4 row text-center g-2">
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="d-block small text-muted mb-1">Jam Masuk</span>
                            <span class="fw-bold text-dark"><?= date('H:i', strtotime($absensiToday['jam_masuk'])) ?></span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="d-block small text-muted mb-1">Jam Keluar</span>
                            <span class="fw-bold text-dark"><?= date('H:i', strtotime($absensiToday['jam_keluar'])) ?></span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div id="cameraContainer" class="mb-4 rounded-4 overflow-hidden position-relative bg-dark shadow-sm" style="width: 100%; aspect-ratio: 3/4; max-height: 55vh; display: flex; align-items: center; justify-content: center;">
                    <video id="cameraStream" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1);"></video>
                    <canvas id="cameraCanvas" style="display: none;"></canvas>
                    <div id="cameraError" class="text-white p-4" style="display: none;">
                        <i class="fas fa-camera-slash fa-2x mb-2"></i><br>
                        Kamera tidak tersedia. Gunakan browser yang mendukung akses kamera.
                    </div>
                </div>
                
                <div class="mb-4">
                    Status: <strong class="text-<?= $state === 'belum' ? 'danger' : 'primary' ?>"><?= $state === 'belum' ? 'Belum Check-in' : 'Sudah Check-in' ?></strong>
                </div>

                <button id="btnAbsen" class="btn btn-<?= $state === 'belum' ? 'primary' : 'warning' ?> btn-lg w-100 rounded-3 fw-bold" disabled>
                    <i class="fas fa-camera me-2"></i> <?= $state === 'belum' ? 'Ambil Foto & Check-in' : 'Ambil Foto & Check-out' ?>
                </button>
                
                <input type="hidden" id="absenAction" value="<?= $state === 'belum' ? 'checkin' : 'checkout' ?>">
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$pageJs = 'absensi_mobile.js';
require_once dirname(__DIR__) . '/layouts/footer.php';
?>
