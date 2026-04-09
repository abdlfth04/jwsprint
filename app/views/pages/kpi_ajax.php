<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$periodeMulai   = trim($_POST['periode_mulai']  ?? '');
$periodeSelesai = trim($_POST['periode_selesai'] ?? '');

if (!$periodeMulai || !$periodeSelesai) {
    echo json_encode(['success' => false, 'message' => 'Periode tidak valid']);
    exit;
}

$periodeSelesaiExclusive = (new DateTimeImmutable($periodeSelesai))->modify('+1 day')->format('Y-m-d');

$hasKpiBobot  = schemaTableExists($conn, 'kpi_bobot');
$hasKpiHasil  = schemaTableExists($conn, 'kpi_hasil');
$hasTodoTahap = schemaTableExists($conn, 'todo_list_tahapan');
$hasProduksi  = schemaTableExists($conn, 'produksi');
$hasAbsensi   = schemaTableExists($conn, 'absensi');

if (!$hasKpiBobot || !$hasKpiHasil) {
    echo json_encode(['success' => false, 'message' => 'Tabel KPI belum ada. Jalankan migrasi database.']);
    exit;
}

// ?? Get bobot (first row) ?????????????????????????????????????????????????????
$bobot = $conn->query("SELECT * FROM kpi_bobot ORDER BY id LIMIT 1")->fetch_assoc();
if (!$bobot) {
    // Insert default if missing
    $conn->query("INSERT INTO kpi_bobot (bobot_pekerjaan, bobot_deadline, bobot_kehadiran, bobot_custom) VALUES (25,25,25,25)");
    $bobot = ['bobot_pekerjaan' => 25, 'bobot_deadline' => 25, 'bobot_kehadiran' => 25, 'bobot_custom' => 25];
}
$bobotPekerjaan = floatval($bobot['bobot_pekerjaan']);
$bobotDeadline  = floatval($bobot['bobot_deadline']);
$bobotKehadiran = floatval($bobot['bobot_kehadiran']);
$bobotCustom    = floatval($bobot['bobot_custom']);

// ?? Get target_pekerjaan default from kpi_hasil (global, no karyawan filter) ?
// Per spec: target_pekerjaan from kpi_hasil or default 10
// We'll read per-karyawan below; default is 10

// ?? Count weekdays (Mon?Sat) in periode ??????????????????????????????????????
function countHariKerja(string $mulai, string $selesai): int {
    $count = 0;
    $d = new DateTime($mulai);
    $end = new DateTime($selesai);
    while ($d <= $end) {
        $dow = (int)$d->format('N'); // 1=Mon ? 7=Sun
        if ($dow !== 7) $count++; // exclude Sunday
        $d->modify('+1 day');
    }
    return $count;
}

$hariKerja = countHariKerja($periodeMulai, $periodeSelesai);

$hasUserIdCol = schemaColumnExists($conn, 'karyawan', 'user_id');

if ($hasUserIdCol) {
    $karyawanList = $conn->query(
        "SELECT k.id, k.nama, k.jabatan, k.user_id FROM karyawan k WHERE k.status='aktif' ORDER BY k.nama"
    )->fetch_all(MYSQLI_ASSOC);
} else {
    $karyawanList = $conn->query(
        "SELECT id, nama, jabatan, NULL as user_id FROM karyawan WHERE status='aktif' ORDER BY nama"
    )->fetch_all(MYSQLI_ASSOC);
}

$kpiHasilCols = schemaTableColumns($conn, 'kpi_hasil');
$hasTargetPekerjaan = in_array('target_pekerjaan', $kpiHasilCols);

$result = [];

foreach ($karyawanList as $kar) {
    $karId  = intval($kar['id']);
    $userId = $kar['user_id'] ? intval($kar['user_id']) : null;

    // ?? 1. Skor Pekerjaan Selesai ?????????????????????????????????????????????
    // Get target_pekerjaan from kpi_hasil for this karyawan+periode
    $targetPekerjaan = 10; // default
    if ($hasTargetPekerjaan) {
        $stmtTarget = $conn->prepare(
            "SELECT target_pekerjaan FROM kpi_hasil
             WHERE karyawan_id=? AND periode_mulai=? AND periode_selesai=? LIMIT 1"
        );
        $stmtTarget->bind_param('iss', $karId, $periodeMulai, $periodeSelesai);
        $stmtTarget->execute();
        $rowTarget = $stmtTarget->get_result()->fetch_assoc();
        if ($rowTarget && $rowTarget['target_pekerjaan'] !== null) {
            $targetPekerjaan = intval($rowTarget['target_pekerjaan']);
        }
        $stmtTarget->close();
    }

    $countPekerjaan = 0;
    if ($hasTodoTahap && $userId) {
        $stmtP = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM todo_list_tahapan
             WHERE selesai_oleh=? AND status='selesai'
             AND selesai_at >= ? AND selesai_at < ?"
        );
        $stmtP->bind_param('iss', $userId, $periodeMulai, $periodeSelesaiExclusive);
        $stmtP->execute();
        $countPekerjaan = intval($stmtP->get_result()->fetch_assoc()['cnt']);
        $stmtP->close();
    }

    if ($targetPekerjaan <= 0) {
        $skorPekerjaan = 0;
    } else {
        $skorPekerjaan = min(100, (int)floor($countPekerjaan / $targetPekerjaan * 100));
    }

    // ?? 2. Skor Ketepatan Deadline ????????????????????????????????????????????
    $totalJo = 0;
    $tepat   = 0;
    if ($hasProduksi) {
        $stmtJo = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM produksi
             WHERE karyawan_id=? AND status='selesai' AND tipe_dokumen='JO'
             AND tanggal >= ? AND tanggal < ?"
        );
        $stmtJo->bind_param('iss', $karId, $periodeMulai, $periodeSelesaiExclusive);
        $stmtJo->execute();
        $totalJo = intval($stmtJo->get_result()->fetch_assoc()['cnt']);
        $stmtJo->close();

        $stmtTepat = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM produksi
             WHERE karyawan_id=? AND status='selesai' AND tipe_dokumen='JO'
             AND tanggal >= ? AND tanggal < ?
             AND (deadline IS NULL OR tanggal <= deadline)"
        );
        $stmtTepat->bind_param('iss', $karId, $periodeMulai, $periodeSelesaiExclusive);
        $stmtTepat->execute();
        $tepat = intval($stmtTepat->get_result()->fetch_assoc()['cnt']);
        $stmtTepat->close();
    }

    $skorDeadline = $totalJo > 0 ? (int)floor($tepat / $totalJo * 100) : 0;

    // ?? 3. Skor Kehadiran ?????????????????????????????????????????????????????
    $hadir = 0;
    if ($hasAbsensi) {
        $stmtH = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM absensi
             WHERE karyawan_id=? AND status IN ('hadir','terlambat')
             AND tanggal >= ? AND tanggal < ?"
        );
        $stmtH->bind_param('iss', $karId, $periodeMulai, $periodeSelesaiExclusive);
        $stmtH->execute();
        $hadir = intval($stmtH->get_result()->fetch_assoc()['cnt']);
        $stmtH->close();
    }

    $skorKehadiran = $hariKerja > 0 ? min(100, (int)floor($hadir / $hariKerja * 100)) : 0;

    // ?? 4. Skor Custom Target ?????????????????????????????????????????????????
    $targetCustom    = 0;
    $pencapaianCustom = 0;
    $stmtC = $conn->prepare(
        "SELECT target_custom, pencapaian_custom FROM kpi_hasil
         WHERE karyawan_id=? AND periode_mulai=? AND periode_selesai=? LIMIT 1"
    );
    $stmtC->bind_param('iss', $karId, $periodeMulai, $periodeSelesai);
    $stmtC->execute();
    $rowC = $stmtC->get_result()->fetch_assoc();
    $stmtC->close();
    if ($rowC) {
        $targetCustom    = floatval($rowC['target_custom']);
        $pencapaianCustom = floatval($rowC['pencapaian_custom']);
    }

    $skorCustom = $targetCustom > 0 ? min(100, (int)floor($pencapaianCustom / $targetCustom * 100)) : 0;

    // ?? 5. Skor Total ?????????????????????????????????????????????????????????
    $skorTotal = ($skorPekerjaan * $bobotPekerjaan
                + $skorDeadline  * $bobotDeadline
                + $skorKehadiran * $bobotKehadiran
                + $skorCustom    * $bobotCustom) / 100;
    $skorTotal = round($skorTotal, 2);

    // ?? 6. Save to kpi_hasil ??????????????????????????????????????????????????
    $stmtSave = $conn->prepare(
        "INSERT INTO kpi_hasil
            (karyawan_id, periode_mulai, periode_selesai,
             skor_pekerjaan, skor_deadline, skor_kehadiran, skor_custom, skor_total,
             target_custom, pencapaian_custom)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            skor_pekerjaan=VALUES(skor_pekerjaan),
            skor_deadline=VALUES(skor_deadline),
            skor_kehadiran=VALUES(skor_kehadiran),
            skor_custom=VALUES(skor_custom),
            skor_total=VALUES(skor_total)"
    );
    $stmtSave->bind_param(
        'issddddddd',
        $karId, $periodeMulai, $periodeSelesai,
        $skorPekerjaan, $skorDeadline, $skorKehadiran, $skorCustom, $skorTotal,
        $targetCustom, $pencapaianCustom
    );
    $stmtSave->execute();
    $stmtSave->close();

    // ?? Get last calculated date ??????????????????????????????????????????????
    $stmtLast = $conn->prepare(
        "SELECT created_at FROM kpi_hasil
         WHERE karyawan_id=? AND periode_mulai=? AND periode_selesai=? LIMIT 1"
    );
    $stmtLast->bind_param('iss', $karId, $periodeMulai, $periodeSelesai);
    $stmtLast->execute();
    $rowLast = $stmtLast->get_result()->fetch_assoc();
    $stmtLast->close();

    $result[] = [
        'karyawan_id'      => $karId,
        'nama'             => $kar['nama'],
        'jabatan'          => $kar['jabatan'] ?? '-',
        'skor_pekerjaan'   => $skorPekerjaan,
        'skor_deadline'    => $skorDeadline,
        'skor_kehadiran'   => $skorKehadiran,
        'skor_custom'      => $skorCustom,
        'skor_total'       => $skorTotal,
        'last_calculated'  => $rowLast['created_at'] ?? null,
    ];
}

echo json_encode(['success' => true, 'data' => $result]);
