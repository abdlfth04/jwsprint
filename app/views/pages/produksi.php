<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service', 'user');
$pageTitle = 'Produksi';
transactionWorkflowSupportReady($conn);

function produksiDeletionDependencies(mysqli $conn, array $record): array
{
    $dependencies = [];
    $produksiId = (int) ($record['id'] ?? 0);

    if ($produksiId > 0 && schemaTableExists($conn, 'todo_list_tahapan') && schemaColumnExists($conn, 'todo_list_tahapan', 'produksi_id')) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM todo_list_tahapan WHERE produksi_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $produksiId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            $totalStages = (int) ($row[0] ?? 0);
            if ($totalStages > 0) {
                $dependencies[] = 'tahapan produksi (' . number_format($totalStages) . ')';
            }
        }
    }

    if (!empty($record['transaksi_id'])) {
        $dependencies[] = 'transaksi terkait';
    }

    if (!empty($record['detail_transaksi_id'])) {
        $dependencies[] = 'item transaksi terkait';
    }

    return $dependencies;
}

function produksiFetchWorkflowContext(mysqli $conn, int $produksiId): array
{
    if ($produksiId <= 0 || !schemaTableExists($conn, 'produksi')) {
        return [];
    }

    $fields = [
        'pr.id',
        'pr.transaksi_id',
        't.status',
        't.total',
        't.bayar',
    ];
    if (schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
        $fields[] = 't.workflow_step';
    }
    if (schemaColumnExists($conn, 'transaksi', 'sisa_bayar')) {
        $fields[] = 't.sisa_bayar';
    }

    $stmt = $conn->prepare(
        "SELECT " . implode(', ', $fields) . "
         FROM produksi pr
         LEFT JOIN transaksi t ON t.id = pr.transaksi_id
         WHERE pr.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $produksiId);
    $stmt->execute();
    $context = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $context;
}

$msg = '';
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $canManageProduksi = hasRole('superadmin', 'admin', 'service');

    if ($action === 'tambah_manual') {
        $tipeDokumen = ($_POST['tipe_dokumen'] ?? 'JO') === 'SPK' ? 'SPK' : 'JO';
        $namaPekerjaan = trim($_POST['nama_pekerjaan'] ?? '');
        $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
        $deadline = $_POST['deadline'] ?? null;
        $status = $_POST['status'] ?? 'antrian';
        $karyawanId = (int) ($_POST['karyawan_id'] ?? 0) ?: null;
        $keterangan = trim($_POST['keterangan'] ?? '');
        $allowedStatus = ['antrian', 'proses', 'selesai', 'batal'];

        if (!$canManageProduksi) {
            $msg = 'danger|Anda tidak memiliki izin untuk menambah JO/SPK manual.';
        } elseif ($namaPekerjaan === '') {
            $msg = 'danger|Nama pekerjaan wajib diisi.';
        } elseif (!in_array($status, $allowedStatus, true)) {
            $msg = 'danger|Status produksi tidak valid.';
        } else {
            $noDokumen = $tipeDokumen . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare("INSERT INTO produksi (no_dokumen, tipe_dokumen, nama_pekerjaan, tanggal, deadline, status, karyawan_id, user_id, keterangan) VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, 0), ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssssssiis', $noDokumen, $tipeDokumen, $namaPekerjaan, $tanggal, $deadline, $status, $karyawanId, $userId, $keterangan);
                if ($stmt->execute()) {
                    $newId = (int) ($stmt->insert_id ?: $conn->insert_id);
                    $msg = 'success|JO/SPK manual berhasil ditambahkan.';
                    writeAuditLog(
                        'produksi_create_manual',
                        'produksi',
                        $tipeDokumen . ' manual ' . $noDokumen . ' ditambahkan.',
                        [
                            'entity_id' => $newId,
                            'metadata' => [
                                'no_dokumen' => $noDokumen,
                                'tipe_dokumen' => $tipeDokumen,
                                'nama_pekerjaan' => $namaPekerjaan,
                                'status' => $status,
                                'karyawan_id' => $karyawanId,
                                'deadline' => $deadline,
                            ],
                        ]
                    );
                } else {
                    $msg = 'danger|Gagal menambahkan data manual.';
                }
                $stmt->close();
            } else {
                $msg = 'danger|Form tidak dapat diproses saat ini.';
            }
        }
    }

    if ($action === 'update_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'antrian';
        $karyawanId = (int) ($_POST['karyawan_id'] ?? 0) ?: null;
        $deadline = $_POST['deadline'] ?? null;
        $keterangan = trim($_POST['keterangan'] ?? '');
        $allowedStatus = ['antrian', 'proses', 'selesai', 'batal'];

        if (!$canManageProduksi) {
            $msg = 'danger|Anda tidak memiliki izin untuk mengubah status produksi.';
        } elseif (!in_array($status, $allowedStatus, true)) {
            $msg = 'danger|Status produksi tidak valid.';
        } else {
            $workflowContext = produksiFetchWorkflowContext($conn, $id);
            $workflowStep = transactionWorkflowResolveStep($workflowContext);
            $isLockedByWorkflow = !empty($workflowContext['transaksi_id'])
                && !in_array($workflowStep, ['production', 'done'], true)
                && $status !== 'batal';

            if ($isLockedByWorkflow) {
                $msg = 'danger|Job transaksi ini belum boleh diproses. Selesaikan pelunasan invoice terlebih dahulu agar workflow masuk ke produksi.';
            } else {
                $before = null;
                $stmtBefore = $conn->prepare("SELECT id, no_dokumen, nama_pekerjaan, status, karyawan_id, deadline FROM produksi WHERE id = ? LIMIT 1");
                if ($stmtBefore) {
                    $stmtBefore->bind_param('i', $id);
                    $stmtBefore->execute();
                    $before = $stmtBefore->get_result()->fetch_assoc();
                    $stmtBefore->close();
                }

                $stmt = $conn->prepare("UPDATE produksi SET status = ?, karyawan_id = NULLIF(?, 0), deadline = NULLIF(?, ''), keterangan = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('sissi', $status, $karyawanId, $deadline, $keterangan, $id);
                    if ($stmt->execute()) {
                        transactionWorkflowRepairStoredSteps($conn);
                        $msg = 'success|Status produksi diperbarui.';
                        if ($before) {
                            writeAuditLog(
                                'produksi_update',
                                'produksi',
                                'Produksi ' . ($before['no_dokumen'] ?? ('#' . $id)) . ' diperbarui.',
                                [
                                    'entity_id' => $id,
                                    'metadata' => [
                                        'status_sebelum' => $before['status'] ?? null,
                                        'status_sesudah' => $status,
                                        'karyawan_sebelum' => isset($before['karyawan_id']) ? (int) $before['karyawan_id'] : null,
                                        'karyawan_sesudah' => $karyawanId,
                                        'deadline_sebelum' => $before['deadline'] ?? null,
                                        'deadline_sesudah' => $deadline,
                                    ],
                                ]
                            );
                        }

                        // [WA INTEGRATION] Kirim WA jika status diubah menjadi Selesai
                        if ($status === 'selesai' && (!isset($before['status']) || $before['status'] !== 'selesai')) {
                            $stmtWa = $conn->prepare(
                                "SELECT p.nama, p.telepon, t.no_transaksi
                                FROM produksi pr
                                JOIN transaksi t ON pr.transaksi_id = t.id
                                JOIN pelanggan p ON t.pelanggan_id = p.id
                                WHERE pr.id = ?
                                LIMIT 1"
                            );
                            if ($stmtWa) {
                                $stmtWa->bind_param('i', $id);
                                $stmtWa->execute();
                                $rowWa = $stmtWa->get_result()->fetch_assoc();
                                $stmtWa->close();

                                if (!empty($rowWa['telepon'])) {
                                    $whatsAppServicePath = dirname(__DIR__, 2) . '/services/whatsapp_service.php';
                                    if (is_file($whatsAppServicePath)) {
                                        require_once $whatsAppServicePath;
                                        if (function_exists('sendWaNotificationOrderFinished')) {
                                            sendWaNotificationOrderFinished($rowWa['telepon'], $rowWa['nama'], $rowWa['no_transaksi']);
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $msg = 'danger|Gagal memperbarui produksi.';
                    }
                    $stmt->close();
                } else {
                    $msg = 'danger|Produksi tidak dapat diperbarui saat ini.';
                }
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        $before = null;
        $beforeFields = ['id', 'no_dokumen', 'nama_pekerjaan', 'status'];
        if (schemaColumnExists($conn, 'produksi', 'transaksi_id')) {
            $beforeFields[] = 'transaksi_id';
        }
        if (schemaColumnExists($conn, 'produksi', 'detail_transaksi_id')) {
            $beforeFields[] = 'detail_transaksi_id';
        }

        if (!$canManageProduksi) {
            $msg = 'danger|Anda tidak memiliki izin untuk menghapus data produksi.';
        } else {
            $stmtBefore = $conn->prepare("SELECT " . implode(', ', $beforeFields) . " FROM produksi WHERE id = ? LIMIT 1");
            if ($stmtBefore) {
                $stmtBefore->bind_param('i', $id);
                $stmtBefore->execute();
                $before = $stmtBefore->get_result()->fetch_assoc();
                $stmtBefore->close();
            }

            if (!$before) {
                $msg = 'danger|Data produksi tidak ditemukan.';
            } else {
                $dependencies = produksiDeletionDependencies($conn, $before);
                if (!empty($dependencies)) {
                    $msg = 'danger|Data produksi tidak dapat dihapus karena masih dipakai di ' . implode(', ', $dependencies) . '. Ubah status menjadi batal bila pekerjaan ingin dihentikan.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM produksi WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param('i', $id);
                        if ($stmt->execute()) {
                            $msg = 'success|Data produksi dihapus.';
                            writeAuditLog(
                                'produksi_delete',
                                'produksi',
                                'Produksi ' . ($before['no_dokumen'] ?? ('#' . $id)) . ' dihapus.',
                                [
                                    'entity_id' => $id,
                                    'metadata' => [
                                        'no_dokumen' => $before['no_dokumen'] ?? null,
                                        'nama_pekerjaan' => $before['nama_pekerjaan'] ?? null,
                                        'status' => $before['status'] ?? null,
                                    ],
                                ]
                            );
                        } else {
                            $msg = 'danger|Gagal menghapus data produksi.';
                        }
                        $stmt->close();
                    } else {
                        $msg = 'danger|Data produksi tidak dapat dihapus saat ini.';
                    }
                }
            }
        }
    }
}

$hasProduksiTable = schemaTableExists($conn, 'produksi');
$hasJoId = $hasProduksiTable && schemaColumnExists($conn, 'produksi', 'jo_id');
$hasTahapanTable = schemaTableExists($conn, 'todo_list_tahapan');
$hasProduksiTransaksiId = $hasProduksiTable && schemaColumnExists($conn, 'produksi', 'transaksi_id');
$hasProduksiKaryawanId = $hasProduksiTable && schemaColumnExists($conn, 'produksi', 'karyawan_id');
$hasProduksiUserId = $hasProduksiTable && schemaColumnExists($conn, 'produksi', 'user_id');
$hasProduksiCreatedAt = $hasProduksiTable && schemaColumnExists($conn, 'produksi', 'created_at');
$hasKaryawanStatus = schemaColumnExists($conn, 'karyawan', 'status');
$currentProduksiUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentProduksiEmployeeId = currentUserEmployeeId();
$canViewAllProduksiRecords = hasRole('superadmin', 'admin', 'service') || hasCustomPermission('produksi.php');

$allowedStatusFilters = ['antrian', 'proses', 'selesai', 'batal'];
$allowedProgressFilters = ['selesai', 'belum'];
$filterStatus = $_GET['status'] ?? '';
$filterProgress = $_GET['progress'] ?? '';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
if (!in_array($filterStatus, $allowedStatusFilters, true)) {
    $filterStatus = '';
}
if (!in_array($filterProgress, $allowedProgressFilters, true)) {
    $filterProgress = '';
}
if (!$hasTahapanTable) {
    $filterProgress = '';
}

$baseWhere = "WHERE pr.tipe_dokumen IN ('JO', 'SPK')";
if ($hasJoId) {
    $baseWhere .= " AND pr.jo_id IS NULL";
}
if ($hasProduksiTransaksiId && schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
    $baseWhere .= " AND (
        pr.transaksi_id IS NULL
        OR pr.transaksi_id = 0
        OR pr.transaksi_id IN (
            SELECT id
            FROM transaksi
            WHERE workflow_step <> 'cancelled'
        )
    )";
}
if (!$canViewAllProduksiRecords) {
    $accessClauses = ["pr.status IN ('antrian', 'proses')"];
    if ($hasTahapanTable && schemaColumnExists($conn, 'todo_list_tahapan', 'produksi_id') && schemaColumnExists($conn, 'todo_list_tahapan', 'user_id') && $currentProduksiUserId > 0) {
        $accessClauses[] = "EXISTS (
            SELECT 1
            FROM todo_list_tahapan tt_access
            WHERE tt_access.produksi_id = pr.id
              AND tt_access.user_id = " . $currentProduksiUserId . "
        )";
    }
    if ($hasProduksiKaryawanId && $currentProduksiEmployeeId !== null) {
        $accessClauses[] = "pr.karyawan_id = " . (int) $currentProduksiEmployeeId;
    }

    $baseWhere .= !empty($accessClauses)
        ? " AND (" . implode(' OR ', $accessClauses) . ")"
        : " AND 1 = 0";
}

$data = [];
$karyawan = [];
$progressData = [];
$statusOverview = ['antrian' => 0, 'proses' => 0, 'selesai' => 0, 'batal' => 0];
if ($hasProduksiTable) {
    $resProgress = $hasTahapanTable
        ? $conn->query("SELECT produksi_id, COUNT(*) as total, SUM(status='selesai') as done FROM todo_list_tahapan GROUP BY produksi_id")
        : false;
    if ($resProgress) {
        foreach ($resProgress->fetch_all(MYSQLI_ASSOC) as $row) {
            $progressData[(int) $row['produksi_id']] = [
                'total' => (int) ($row['total'] ?? 0),
                'done' => (int) ($row['done'] ?? 0)
            ];
        }
    }

    $whereProgress = $baseWhere;
    if ($hasTahapanTable && $filterProgress === 'selesai') {
        $whereProgress .= " AND pr.id IN (SELECT produksi_id FROM todo_list_tahapan GROUP BY produksi_id HAVING COUNT(*) > 0 AND SUM(status='selesai') = COUNT(*))";
    } elseif ($hasTahapanTable && $filterProgress === 'belum') {
        $whereProgress .= " AND (pr.id NOT IN (SELECT produksi_id FROM todo_list_tahapan GROUP BY produksi_id HAVING SUM(status='selesai') = COUNT(*)) OR pr.id NOT IN (SELECT DISTINCT produksi_id FROM todo_list_tahapan))";
    }

    $resStatusOverview = $conn->query("SELECT pr.status, COUNT(*) as jumlah FROM produksi pr $whereProgress GROUP BY pr.status");
    if ($resStatusOverview) {
        foreach ($resStatusOverview->fetch_all(MYSQLI_ASSOC) as $row) {
            if (isset($statusOverview[$row['status']])) {
                $statusOverview[$row['status']] = (int) $row['jumlah'];
            }
        }
    }

    $where = $whereProgress;
    $paramTypes = '';
    $paramValues = [];
    if ($filterStatus !== '') {
        $where .= " AND pr.status = ?";
        $paramTypes .= 's';
        $paramValues[] = $filterStatus;
    }
    if ($searchQuery !== '') {
        $searchLike = '%' . $searchQuery . '%';
        $searchConditions = [
            'pr.no_dokumen LIKE ?',
            'pr.tipe_dokumen LIKE ?',
            'pr.nama_pekerjaan LIKE ?',
            'pr.status LIKE ?',
            "COALESCE(pr.keterangan, '') LIKE ?",
            "DATE_FORMAT(pr.tanggal, '%d/%m/%Y') LIKE ?",
            "DATE_FORMAT(pr.deadline, '%d/%m/%Y') LIKE ?",
        ];
        if ($hasProduksiTransaksiId) {
            $searchConditions[] = "COALESCE(t.no_transaksi, '') LIKE ?";
        }
        if ($hasProduksiKaryawanId) {
            $searchConditions[] = "COALESCE(k.nama, '') LIKE ?";
        }
        if ($hasProduksiUserId) {
            $searchConditions[] = "COALESCE(u.nama, '') LIKE ?";
        }

        $where .= ' AND (' . implode(' OR ', $searchConditions) . ')';
        $paramTypes .= str_repeat('s', count($searchConditions));
        foreach ($searchConditions as $_unused) {
            $paramValues[] = $searchLike;
        }
    }

    $transaksiJoin = $hasProduksiTransaksiId ? "LEFT JOIN transaksi t ON pr.transaksi_id = t.id" : '';
    $transaksiSelect = $hasProduksiTransaksiId ? 't.no_transaksi' : 'NULL AS no_transaksi';
    $karyawanJoin = $hasProduksiKaryawanId ? "LEFT JOIN karyawan k ON pr.karyawan_id = k.id" : '';
    $karyawanSelect = $hasProduksiKaryawanId ? 'k.nama as nama_karyawan' : 'NULL AS nama_karyawan';
    $userJoin = $hasProduksiUserId ? "LEFT JOIN users u ON pr.user_id = u.id" : '';
    $userSelect = $hasProduksiUserId ? 'u.nama as nama_user' : 'NULL AS nama_user';
    $orderBy = $hasProduksiCreatedAt ? 'pr.created_at DESC' : 'pr.id DESC';

    $sql = "SELECT pr.*, {$transaksiSelect}, {$karyawanSelect}, {$userSelect}
        FROM produksi pr
        {$transaksiJoin}
        {$karyawanJoin}
        {$userJoin}
        $where
        ORDER BY {$orderBy}";

    if ($paramTypes !== '') {
        $stmtData = $conn->prepare($sql);
        if ($stmtData) {
            $stmtData->bind_param($paramTypes, ...$paramValues);
            $stmtData->execute();
            $data = $stmtData->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtData->close();
        }
    } else {
        $res = $conn->query($sql);
        if ($res) {
            $data = $res->fetch_all(MYSQLI_ASSOC);
        }
    }
}

$karyawanWhere = $hasKaryawanStatus ? "WHERE status='aktif'" : '';
$resKar = $conn->query("SELECT * FROM karyawan {$karyawanWhere} ORDER BY nama");
if ($resKar) {
    $karyawan = $resKar->fetch_all(MYSQLI_ASSOC);
}

$visibleCount = count($data);
$statusCounts = ['antrian' => 0, 'proses' => 0, 'selesai' => 0, 'batal' => 0];
$totalStages = 0;
$doneStages = 0;
$overdueCount = 0;
$today = date('Y-m-d');

foreach ($data as $row) {
    $statusKey = $row['status'] ?? '';
    if (isset($statusCounts[$statusKey])) {
        $statusCounts[$statusKey]++;
    }

    $progress = $progressData[(int) $row['id']] ?? ['total' => 0, 'done' => 0];
    $totalStages += (int) $progress['total'];
    $doneStages += (int) $progress['done'];

    if (!empty($row['deadline']) && $row['deadline'] < $today && !in_array($statusKey, ['selesai', 'batal'], true)) {
        $overdueCount++;
    }
}

$completionRate = $totalStages > 0 ? (int) round(($doneStages / $totalStages) * 100) : 0;
$filterLabels = [
    '' => 'Semua status',
    'antrian' => 'Antrian',
    'proses' => 'Proses',
    'selesai' => 'Selesai',
    'batal' => 'Batal'
];
$progressLabels = [
    '' => 'Semua progress',
    'selesai' => 'Semua tahap selesai',
    'belum' => 'Masih ada tahapan terbuka'
];
$buildFilterUrl = static function (string $status, string $progress, string $search = ''): string {
    $params = [];
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($progress !== '') {
        $params['progress'] = $progress;
    }
    if ($search !== '') {
        $params['q'] = $search;
    }
    return $params ? '?' . http_build_query($params) : '?status=';
};

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/produksi.css') . '">';
$extraCss .= '<style>
.record-link-button {
    display: inline-flex;
    align-items: center;
    max-width: 100%;
    padding: 6px 10px;
    border: 1px solid rgba(255, 255, 255, 0.64);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.56);
    color: var(--primary);
    font: inherit;
    font-weight: 700;
    text-align: left;
    cursor: pointer;
    line-height: 1.45;
    word-break: break-word;
    box-shadow: var(--shadow-xs);
    transition: var(--transition);
}
.record-link-button:hover {
    text-decoration: none;
    border-color: rgba(15, 118, 110, 0.24);
    background: rgba(255, 255, 255, 0.74);
    transform: translateY(-1px);
}
.produksi-search-panel {
    display: grid;
    gap: 14px;
}
.produksi-search-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.produksi-search-title {
    margin: 0;
    font-size: .94rem;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -0.02em;
}
.produksi-search-copy {
    margin: 6px 0 0;
    max-width: 70ch;
    color: var(--text-muted);
    font-size: .8rem;
    line-height: 1.6;
}
.produksi-search-form {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(220px, .8fr) auto auto;
    gap: 10px;
    align-items: center;
}
.produksi-search-field {
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
}
.produksi-search-field i {
    position: absolute;
    left: 14px;
    color: var(--text-muted);
    pointer-events: none;
}
.produksi-search-field .form-control {
    min-width: 0;
    padding-left: 40px;
    padding-right: 42px;
}
.produksi-search-clear {
    position: absolute;
    top: 50%;
    right: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 999px;
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    transform: translateY(-50%);
    transition: var(--transition);
}
.produksi-search-clear:hover {
    color: var(--text);
    background: rgba(255, 255, 255, 0.6);
    border-color: rgba(15, 118, 110, 0.16);
}
.produksi-search-meta {
    color: var(--text-muted);
    font-size: .78rem;
    line-height: 1.5;
}
.produksi-search-meta strong {
    color: var(--text);
}
.produksi-page .table-responsive {
    overflow-x: auto;
}
.produksi-page table {
    min-width: 1220px;
}
.produksi-page thead th,
.produksi-page tbody td {
    line-height: 1.5;
}
.produksi-page .produksi-job-cell,
.produksi-page .mobile-data-subtitle,
.produksi-page .mobile-data-value {
    overflow-wrap: anywhere;
}
.produksi-page .progress-cell {
    min-width: 158px;
}
.produksi-page .progress-caption {
    display: inline-block;
    line-height: 1.4;
    font-variant-numeric: tabular-nums;
}
.produksi-page .badge {
    min-height: 30px;
    max-width: 100%;
    padding: 6px 12px;
    line-height: 1.25;
    white-space: normal;
    text-align: center;
}
.produksi-search-empty[hidden] {
    display: none !important;
}
.produksi-search-empty {
    margin-top: 16px;
}
@media (max-width: 768px) {
    .produksi-search-panel {
        padding: 14px;
    }
    .produksi-search-copy {
        font-size: .78rem;
    }
    .produksi-search-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .produksi-search-field {
        grid-column: 1 / -1;
    }
    .produksi-search-form .btn {
        width: 100%;
    }
}
@media (max-width: 520px) {
    .produksi-search-form {
        grid-template-columns: 1fr;
    }
    .produksi-search-form .btn,
    .produksi-search-form .btn-outline {
        width: 100%;
    }
    .produksi-page .badge {
        font-size: .68rem;
        padding: 5px 10px;
    }
}
</style>';
$pageJs = 'produksi.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack produksi-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-industry"></i> Produksi</div>
                <h1 class="page-title">Daftar JO, SPK, progres, dan deadline yang lebih ringkas</h1>
                <p class="page-description">
                    Tampilan produksi kini lebih mudah dipindai di desktop dan tetap ringkas di mobile, lengkap dengan jalur cepat untuk filter, edit, cetak dokumen, dan cek tahapan tanpa wajib menetapkan PIC di awal.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-layer-group"></i> <?= number_format($visibleCount) ?> job ditampilkan</span>
                    <span class="page-meta-item"><i class="fas fa-filter"></i> <?= htmlspecialchars($filterLabels[$filterStatus] ?? 'Semua status') ?></span>
                    <span class="page-meta-item"><i class="fas fa-tasks"></i> <?= htmlspecialchars($progressLabels[$filterProgress] ?? 'Semua progress') ?></span>
                    <?php if ($searchQuery !== ''): ?>
                        <span class="page-meta-item"><i class="fas fa-magnifying-glass"></i> Kata kunci: <?= htmlspecialchars($searchQuery) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="page-actions">
                <button class="btn btn-primary" type="button" onclick="openModal('modalTambahJOManual')"><i class="fas fa-plus"></i> Tambah JO Manual</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <section class="toolbar-surface produksi-search-panel">
        <div class="produksi-search-header">
            <div>
                <h2 class="produksi-search-title">Cari job produksi dan sempitkan prioritas lebih cepat</h2>
                <p class="produksi-search-copy">Gunakan status, progress, dan kata kunci untuk menemukan JO, SPK, invoice, nama pekerjaan, atau operator yang perlu ditindaklanjuti tanpa area kosong di halaman.</p>
            </div>
            <div class="produksi-search-meta" id="produksiSearchSummary">
                <strong><?= number_format($visibleCount) ?></strong> job pada halaman ini
            </div>
        </div>
        <div class="page-inline-summary compact-summary-row">
            <div class="page-inline-pill">
                <span>Job</span>
                <strong><?= number_format($visibleCount) ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Proses</span>
                <strong><?= number_format($statusCounts['proses']) ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Progress</span>
                <strong><?= $completionRate ?>%</strong>
            </div>
            <div class="page-inline-pill">
                <span>Terlambat</span>
                <strong><?= number_format($overdueCount) ?></strong>
            </div>
        </div>
        <div class="filter-pills">
            <a href="<?= htmlspecialchars($buildFilterUrl('', $filterProgress, $searchQuery)) ?>" class="filter-pill <?= $filterStatus === '' ? 'active' : '' ?>">
                <span>Semua</span>
                <span class="filter-pill-count"><?= number_format($statusOverview['antrian'] + $statusOverview['proses'] + $statusOverview['selesai'] + $statusOverview['batal']) ?></span>
            </a>
            <a href="<?= htmlspecialchars($buildFilterUrl('antrian', $filterProgress, $searchQuery)) ?>" class="filter-pill <?= $filterStatus === 'antrian' ? 'active' : '' ?>">
                <span>Antrian</span>
                <span class="filter-pill-count"><?= number_format($statusOverview['antrian']) ?></span>
            </a>
            <a href="<?= htmlspecialchars($buildFilterUrl('proses', $filterProgress, $searchQuery)) ?>" class="filter-pill <?= $filterStatus === 'proses' ? 'active' : '' ?>">
                <span>Proses</span>
                <span class="filter-pill-count"><?= number_format($statusOverview['proses']) ?></span>
            </a>
            <a href="<?= htmlspecialchars($buildFilterUrl('selesai', $filterProgress, $searchQuery)) ?>" class="filter-pill <?= $filterStatus === 'selesai' ? 'active' : '' ?>">
                <span>Selesai</span>
                <span class="filter-pill-count"><?= number_format($statusOverview['selesai']) ?></span>
            </a>
            <a href="<?= htmlspecialchars($buildFilterUrl('batal', $filterProgress, $searchQuery)) ?>" class="filter-pill <?= $filterStatus === 'batal' ? 'active' : '' ?>">
                <span>Batal</span>
                <span class="filter-pill-count"><?= number_format($statusOverview['batal']) ?></span>
            </a>
        </div>
        <form method="GET" class="produksi-search-form">
            <?php if ($filterStatus !== ''): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <?php endif; ?>
            <label class="sr-only" for="produksiSearchInput">Cari produksi</label>
            <div class="produksi-search-field">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input
                    type="search"
                    id="produksiSearchInput"
                    name="q"
                    value="<?= htmlspecialchars($searchQuery) ?>"
                    class="form-control"
                    placeholder="Cari dokumen, invoice, pekerjaan, atau karyawan..."
                    data-produksi-search-input
                >
                <button
                    type="button"
                    class="produksi-search-clear"
                    data-produksi-search-clear
                    aria-label="Kosongkan pencarian"
                    <?= $searchQuery === '' ? 'hidden' : '' ?>
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <select name="progress" class="form-control" aria-label="Filter progress produksi">
                <option value="">Semua Progress</option>
                <option value="selesai" <?= $filterProgress === 'selesai' ? 'selected' : '' ?>>Semua tahap selesai</option>
                <option value="belum" <?= $filterProgress === 'belum' ? 'selected' : '' ?>>Masih ada tahapan terbuka</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
            <a href="<?= pageUrl('produksi.php') ?>" class="btn btn-outline">Reset</a>
        </form>
        <div class="produksi-search-meta">
            Pencarian langsung menyaring daftar yang terlihat di layar. Tombol <strong>Terapkan</strong> akan menyimpan filter ke URL supaya status dan progress tetap aktif saat halaman dibuka ulang.
        </div>
    </section>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-stream"></i> Daftar JO / SPK</span>
                <div class="card-subtitle">Desktop memakai tabel detail, mobile memakai kartu ringkas dengan aksi yang sama. Penugasan karyawan sekarang opsional, bukan syarat agar pekerjaan bisa berjalan.</div>
            </div>
        </div>

        <?php if (!empty($data)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblProd">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>No. JO</th>
                            <th>No. SPK</th>
                            <th>Invoice</th>
                            <th>Nama Pekerjaan</th>
                            <th>Tanggal</th>
                            <th>Deadline</th>
                            <th>Karyawan</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($data as $i => $row): ?>
                        <?php
                        $progress = $progressData[(int) $row['id']] ?? ['total' => 0, 'done' => 0];
                        $pct = $progress['total'] > 0 ? (int) floor(($progress['done'] / $progress['total']) * 100) : 0;
                        $badgeMap = ['antrian' => 'badge-secondary', 'proses' => 'badge-warning', 'selesai' => 'badge-success', 'batal' => 'badge-danger'];
                        $searchIndex = strtolower(trim(implode(' ', array_filter([
                            (string) ($row['no_dokumen'] ?? ''),
                            (string) ($row['tipe_dokumen'] ?? ''),
                            (string) ($row['no_transaksi'] ?? ''),
                            (string) ($row['nama_pekerjaan'] ?? ''),
                            (string) ($row['tanggal'] ?? ''),
                            (string) ($row['deadline'] ?? ''),
                            (string) ($row['nama_karyawan'] ?? ''),
                            (string) ($row['status'] ?? ''),
                            (string) ($progress['done'] ?? 0),
                            (string) ($progress['total'] ?? 0),
                            (string) $pct,
                        ]))));
                        ?>
                        <tr class="produksi-row" data-produksi-id="<?= (int) $row['id'] ?>" data-search="<?= htmlspecialchars($searchIndex, ENT_QUOTES, 'UTF-8') ?>">
                            <td><?= $i + 1 ?></td>
                            <td>
                                <?php if ($row['tipe_dokumen'] === 'JO'): ?>
                                    <button type="button" class="record-link-button" onclick="openProduksiDetail(<?= (int) $row['id'] ?>)">
                                        <?= htmlspecialchars($row['no_dokumen']) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['tipe_dokumen'] === 'SPK'): ?>
                                    <button type="button" class="record-link-button" onclick="openProduksiDetail(<?= (int) $row['id'] ?>)">
                                        <?= htmlspecialchars($row['no_dokumen']) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['no_transaksi'])): ?>
                                    <button type="button" class="record-link-button" onclick="openProduksiDetail(<?= (int) $row['id'] ?>)">
                                        <?= htmlspecialchars($row['no_transaksi']) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="produksi-job-cell"><?= htmlspecialchars($row['nama_pekerjaan']) ?></td>
                            <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td><?= !empty($row['deadline']) ? date('d/m/Y', strtotime($row['deadline'])) : '-' ?></td>
                            <td class="produksi-job-cell"><?= htmlspecialchars($row['nama_karyawan'] ?? '-') ?></td>
                            <td class="progress-cell">
                                <div class="progress-mini">
                                    <div class="progress-mini-bar" style="width:<?= $pct ?>%"></div>
                                </div>
                                <small class="progress-caption"><?= $progress['done'] ?>/<?= $progress['total'] ?> (<?= $pct ?>%)</small>
                            </td>
                            <td><span class="badge <?= $badgeMap[$row['status']] ?? 'badge-secondary' ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td>
                                <button class="btn btn-info btn-sm" type="button" onclick="openProduksiDetail(<?= (int) $row['id'] ?>)" title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list">
                <?php foreach ($data as $row): ?>
                    <?php
                    $progress = $progressData[(int) $row['id']] ?? ['total' => 0, 'done' => 0];
                    $pct = $progress['total'] > 0 ? (int) floor(($progress['done'] / $progress['total']) * 100) : 0;
                    $badgeMap = ['antrian' => 'badge-secondary', 'proses' => 'badge-warning', 'selesai' => 'badge-success', 'batal' => 'badge-danger'];
                    $searchIndex = strtolower(trim(implode(' ', array_filter([
                        (string) ($row['no_dokumen'] ?? ''),
                        (string) ($row['tipe_dokumen'] ?? ''),
                        (string) ($row['no_transaksi'] ?? ''),
                        (string) ($row['nama_pekerjaan'] ?? ''),
                        (string) ($row['tanggal'] ?? ''),
                        (string) ($row['deadline'] ?? ''),
                        (string) ($row['nama_karyawan'] ?? ''),
                        (string) ($row['status'] ?? ''),
                        (string) ($progress['done'] ?? 0),
                        (string) ($progress['total'] ?? 0),
                        (string) $pct,
                    ]))));
                    ?>
                    <div class="mobile-data-card mobile-produksi-card" data-produksi-id="<?= (int) $row['id'] ?>" data-search="<?= htmlspecialchars($searchIndex, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title">
                                    <button type="button" class="record-link-button" onclick="openProduksiDetail(<?= (int) $row['id'] ?>)">
                                        <?= htmlspecialchars($row['no_dokumen']) ?>
                                    </button>
                                </div>
                                <div class="mobile-data-subtitle"><?= htmlspecialchars($row['nama_pekerjaan']) ?></div>
                            </div>
                            <span class="badge <?= $badgeMap[$row['status']] ?? 'badge-secondary' ?>"><?= htmlspecialchars($row['status']) ?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Invoice</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($row['no_transaksi'] ?? '-') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Deadline</span>
                                <span class="mobile-data-value"><?= !empty($row['deadline']) ? date('d/m/Y', strtotime($row['deadline'])) : '-' ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Karyawan</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($row['nama_karyawan'] ?? '-') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Progress</span>
                                <span class="mobile-data-value"><?= $progress['done'] ?>/<?= $progress['total'] ?> (<?= $pct ?>%)</span>
                            </div>
                        </div>
                        <div class="progress-mini" style="margin-top: 14px;">
                            <div class="progress-mini-bar" style="width:<?= $pct ?>%"></div>
                        </div>
                        <div class="mobile-data-actions">
                            <button class="btn btn-info btn-sm" type="button" onclick="openProduksiDetail(<?= (int) $row['id'] ?>)"><i class="fas fa-eye"></i> Detail</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-state produksi-search-empty" id="produksiSearchEmpty" hidden>
                <i class="fas fa-magnifying-glass"></i>
                <div>Tidak ada job produksi yang cocok dengan kata kunci yang sedang dipakai.</div>
                <p>Coba ubah kata kunci, pilih status lain, atau tekan reset untuk menampilkan semua job lagi.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <div>Belum ada data produksi pada filter yang dipilih.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalProduksiDetail">
    <div class="modal-box modal-lg" style="max-width: 980px;">
        <div class="modal-header">
            <h5 id="modalProduksiDetailTitle">Detail Produksi</h5>
            <button class="modal-close" onclick="closeModal('modalProduksiDetail')">&times;</button>
        </div>
        <div class="modal-body" id="produksiDetailContent">
            <p class="text-center text-muted">Memuat...</p>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalTambahJOManual">
    <div class="modal-box">
        <div class="modal-header">
            <h5>Tambah JO / SPK Manual</h5>
            <button class="modal-close" onclick="closeModal('modalTambahJOManual')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="tambah_manual">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tipe Dokumen</label>
                        <select name="tipe_dokumen" class="form-control">
                            <option value="JO">JO</option>
                            <option value="SPK">SPK</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status Awal</label>
                        <select name="status" class="form-control">
                            <option value="antrian">Antrian</option>
                            <option value="proses">Proses</option>
                            <option value="selesai">Selesai</option>
                            <option value="batal">Batal</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Pekerjaan</label>
                    <input type="text" name="nama_pekerjaan" class="form-control" placeholder="Contoh: Jersey Tim Futsal / Banner Promo" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deadline</label>
                        <input type="date" name="deadline" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Tugaskan ke Karyawan</label>
                    <select name="karyawan_id" class="form-control">
                        <option value="">-- Belum Ditugaskan --</option>
                        <?php foreach ($karyawan as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"><?= htmlspecialchars($item['nama']) ?> - <?= htmlspecialchars($item['jabatan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Keterangan</label>
                    <textarea name="keterangan" class="form-control" rows="3" placeholder="Catatan tambahan untuk tim produksi..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambahJOManual')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalEdit">
    <div class="modal-box">
        <div class="modal-header">
            <h5>Update Produksi</h5>
            <button class="modal-close" onclick="closeModal('modalEdit')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" id="eId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">No. Dokumen</label>
                    <input type="text" id="eNoDok" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Pekerjaan</label>
                    <input type="text" id="eNama" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="eStatus" class="form-control">
                            <option value="antrian">Antrian</option>
                            <option value="proses">Proses</option>
                            <option value="selesai">Selesai</option>
                            <option value="batal">Batal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deadline</label>
                        <input type="date" name="deadline" id="eDeadline" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Tugaskan ke Karyawan</label>
                    <select name="karyawan_id" id="eKaryawan" class="form-control">
                        <option value="">-- Belum Ditugaskan --</option>
                        <?php foreach ($karyawan as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"><?= htmlspecialchars($item['nama']) ?> - <?= htmlspecialchars($item['jabatan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Keterangan</label>
                    <textarea name="keterangan" id="eKet" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEdit')">Batal</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
