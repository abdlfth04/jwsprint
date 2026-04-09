<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$detailId = normalizeTransactionDetailId($_POST['detail_transaksi_id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID file wajib diisi.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, transaksi_id, detail_transaksi_id, tipe_file, nama_asli FROM file_transaksi WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'File tidak ditemukan.']);
    exit;
}

if (!canManageTransactionFiles((int) $file['transaksi_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengubah item file ini.']);
    exit;
}

if (($file['tipe_file'] ?? '') === 'bukti_transfer' && $detailId > 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Bukti transfer melekat ke transaksi dan tidak boleh dipetakan ke item produk.',
    ]);
    exit;
}

$category = getTransactionFileCategoryForTypes([(string) ($file['tipe_file'] ?? '')]);
if ($category !== null) {
    $candidateIds = getTransactionDetailIdsByCategory($conn, (int) $file['transaksi_id'], $category);

    if ($detailId > 0 && !in_array($detailId, $candidateIds, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Item yang dipilih tidak sesuai dengan tipe file ini.']);
        exit;
    }

    if (($file['tipe_file'] ?? '') === 'siap_cetak' && count($candidateIds) > 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File siap cetak untuk transaksi dengan beberapa item printing sebaiknya diunggah ulang dari JO yang benar.']);
        exit;
    }
}

$stmt = $conn->prepare("UPDATE file_transaksi SET detail_transaksi_id = NULLIF(?, 0) WHERE id = ?");
$stmt->bind_param('ii', $detailId, $id);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Gagal memperbarui item file.']);
    exit;
}

writeAuditLog(
    'file_assign_detail',
    'file_transaksi',
    'Item file transaksi ' . ($file['nama_asli'] ?? ('#' . $id)) . ' diperbarui.',
    [
        'entity_id' => (int) $file['id'],
        'metadata' => [
            'transaksi_id' => (int) $file['transaksi_id'],
            'detail_transaksi_id_sebelum' => (int) ($file['detail_transaksi_id'] ?? 0),
            'detail_transaksi_id_sesudah' => $detailId,
            'tipe_file' => $file['tipe_file'] ?? null,
            'nama_file' => $file['nama_asli'] ?? null,
        ],
    ]
);

echo json_encode([
    'success' => true,
    'message' => 'Item file berhasil diperbarui.',
    'transaksi_id' => (int) $file['transaksi_id'],
]);
