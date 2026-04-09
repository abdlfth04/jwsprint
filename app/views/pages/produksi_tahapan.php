<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();

$user    = currentUser();
$role    = $user['role'];
$userId  = (int)$user['id'];
$employeeId = currentUserEmployeeId();

function fetchProductionWorkflowContext(mysqli $conn, int $produksiId): array
{
    if ($produksiId <= 0) {
        return [];
    }

    $fields = [
        'pr.id',
        'pr.transaksi_id',
        'pr.detail_transaksi_id',
        'pr.karyawan_id',
        'pr.tipe_dokumen',
        't.no_transaksi',
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
        "SELECT
            " . implode(",\n            ", $fields) . "
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

function canManageProductionRecordActions(string $role): bool
{
    return in_array($role, ['superadmin', 'admin', 'service'], true);
}

function canChecklistProductionStage(array $tahapan, array $workflowContext, string $role, int $userId, ?int $employeeId): bool
{
    return in_array($role, ['superadmin', 'admin', 'service', 'user'], true);
}

// ??? POST ????????????????????????????????????????????????????????????????????
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action     = $_POST['action'] ?? '';
    $tahapanId  = intval($_POST['tahapan_id'] ?? 0);

    if (!$tahapanId) {
        echo json_encode(['success' => false, 'message' => 'ID tahapan tidak valid']);
        exit;
    }

    // Fetch tahapan record
    $stmt = $conn->prepare(
        "SELECT t.* FROM todo_list_tahapan t
         -- JOIN produksi p ON p.id = t.produksi_id -- Join tidak diperlukan jika hanya butuh produksi_id dari t
         WHERE t.id = ?"
    );
    $stmt->bind_param('i', $tahapanId);
    $stmt->execute();
    $tahapan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tahapan) {
        echo json_encode(['success' => false, 'message' => 'Tahapan tidak ditemukan']);
        exit;
    }

    if (!canAccessProductionRecord((int) ($tahapan['produksi_id'] ?? 0))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses ke tahapan produksi ini.']);
        exit;
    }

    // ?? action=assign ????????????????????????????????????????????????????????
    if ($action === 'assign') {
        // Only superadmin/admin/service may assign
        if (!in_array($role, ['superadmin', 'admin', 'service'])) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki izin untuk menugaskan operator']);
            exit;
        }

        $targetUserId = intval($_POST['user_id'] ?? 0);
        if (!$targetUserId) {
            echo json_encode(['success' => false, 'message' => 'User ID tidak valid']);
            exit;
        }

        // Validate target user role: must be user/service/kasir
        $stmt2 = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $stmt2->bind_param('i', $targetUserId);
        $stmt2->execute();
        $targetUser = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        if (!$targetUser) {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
            exit;
        }
        if (!in_array($targetUser['role'], ['user', 'service', 'kasir'])) {
            echo json_encode(['success' => false, 'message' => 'Role user yang ditugaskan tidak valid (harus user/service/kasir)']);
            exit;
        }

        $stmt3 = $conn->prepare("UPDATE todo_list_tahapan SET user_id = ? WHERE id = ?");
        $stmt3->bind_param('ii', $targetUserId, $tahapanId);
        if ($stmt3->execute()) {
            echo json_encode(['success' => true, 'message' => 'Operator berhasil ditugaskan']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan, coba lagi']);
        }
        $stmt3->close();
        exit;
    }

    // ?? action=checklist ?????????????????????????????????????????????????????
    if ($action === 'checklist') {
        $workflowContext = fetchProductionWorkflowContext($conn, (int) ($tahapan['produksi_id'] ?? 0));
        $canChecklistStage = canChecklistProductionStage($tahapan, $workflowContext, $role, $userId, $employeeId);
        $workflowStep = transactionWorkflowResolveStep($workflowContext);
        $isProductionLocked = !empty($workflowContext['transaksi_id'])
            && (int) ($tahapan['urutan'] ?? 0) > 1
            && !in_array($workflowStep, ['production', 'done'], true);

        if (!$canChecklistStage) {
            echo json_encode(['success' => false, 'message' => 'Tahapan ini belum bisa diperbarui dari akun Anda.']);
            exit;
        }

        if ($isProductionLocked) {
            echo json_encode([
                'success' => false,
                'message' => 'Tahap produksi masih dikunci. Invoice terkait belum membuka alur produksi sepenuhnya.',
            ]);
            exit;
        }

        if ($tahapan['status'] === 'selesai') {
            echo json_encode(['success' => false, 'message' => 'Tahapan sudah ditandai selesai']);
            exit;
        }

        // Start transaction
        $conn->begin_transaction();

        // Mark tahapan as done
        $stmt4 = $conn->prepare(
            "UPDATE todo_list_tahapan SET status='selesai', selesai_oleh=?, selesai_at=NOW() WHERE id=?"
        );
        $stmt4->bind_param('ii', $userId, $tahapanId);
        if (!$stmt4->execute()) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan, coba lagi']);
            $stmt4->close(); // stmt4 tetap di-close
            $conn->rollback(); // Batalkan transaksi
            exit;
        }
        $stmt4->close();

        $produksiId = (int)$tahapan['produksi_id'];

        // Check if ALL tahapan for this produksi are done
        $stmt5 = $conn->prepare(
            "SELECT COUNT(*) AS total, SUM(status='selesai') AS done FROM todo_list_tahapan WHERE produksi_id = ?"
        );
        $stmt5->bind_param('i', $produksiId);
        $stmt5->execute();
        $counts  = $stmt5->get_result()->fetch_assoc();
        $stmt5->close();

        $allDone = ($counts['total'] > 0 && (int)$counts['done'] === (int)$counts['total']);

        if ($allDone) {
            // Update this produksi status to selesai
            $stmtUpd1 = $conn->prepare("UPDATE produksi SET status='selesai' WHERE id = ?");
            $stmtUpd1->bind_param('i', $produksiId);
            $upd1_success = $stmtUpd1->execute();
            $stmtUpd1->close();

            // Also update paired SPK (jo_id = produksiId) or paired JO
            // Design: UPDATE produksi SET status='selesai' WHERE jo_id = produksi_id
            $stmtUpd2 = $conn->prepare("UPDATE produksi SET status='selesai' WHERE jo_id = ?");
            $stmtUpd2->bind_param('i', $produksiId);
            $upd2_success = $stmtUpd2->execute();
            $stmtUpd2->close();

            if (!$upd1_success || !$upd2_success) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status produksi.']);
                exit;
            }
        }

        transactionWorkflowRepairStoredSteps($conn);

        // If all queries succeeded, commit the transaction
        if ($conn->commit()) {
            echo json_encode([
                'success'  => true,
                'message'  => 'Tahapan berhasil diselesaikan',
                'all_done' => $allDone,
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menyelesaikan transaksi database.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal']);
    exit;
}

// ??? GET ?????????????????????????????????????????????????????????????????????
$produksiId = intval($_GET['produksi_id'] ?? 0);
if (!$produksiId) {
    echo '<p class="text-muted">ID produksi tidak valid.</p>';
    exit;
}
if (!canAccessProductionRecord($produksiId)) {
    http_response_code(403);
    echo '<p class="text-muted">Anda tidak memiliki akses ke tahapan produksi ini.</p>';
    exit;
}

// Fetch ringkasan produksi
$stmtInfo = $conn->prepare(
    "SELECT
        pr.*,
        t.no_transaksi,
        p.nama AS nama_pelanggan,
        k.nama AS nama_karyawan,
        u.nama AS nama_user
     FROM produksi pr
     LEFT JOIN transaksi t ON t.id = pr.transaksi_id
     LEFT JOIN pelanggan p ON p.id = t.pelanggan_id
     LEFT JOIN karyawan k ON k.id = pr.karyawan_id
     LEFT JOIN users u ON u.id = pr.user_id
     WHERE pr.id = ?
     LIMIT 1"
);
$produksiInfo = [];
if ($stmtInfo) {
    $stmtInfo->bind_param('i', $produksiId);
    $stmtInfo->execute();
    $produksiInfo = $stmtInfo->get_result()->fetch_assoc() ?: [];
    $stmtInfo->close();
}

if (!$produksiInfo) {
    echo '<p class="text-muted">Data produksi tidak ditemukan.</p>';
    exit;
}

// Fetch tahapan with operator name
$stmt = $conn->prepare(
    "SELECT t.*, u.nama AS nama_operator
     FROM todo_list_tahapan t
     LEFT JOIN users u ON u.id = t.user_id
     WHERE t.produksi_id = ?
     ORDER BY t.urutan ASC"
);
$stmt->bind_param('i', $produksiId);
$stmt->execute();
$tahapanList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch users eligible to be assigned (role: user/service/kasir)
$operatorList = [];
if (in_array($role, ['superadmin', 'admin', 'service'])) {
    $resOp = $conn->query(
        "SELECT id, nama, role FROM users WHERE role IN ('user','service','kasir') ORDER BY nama"
    );
    if ($resOp) {
        $operatorList = $resOp->fetch_all(MYSQLI_ASSOC);
    }
}

$workflowContext = fetchProductionWorkflowContext($conn, $produksiId);
$transaksiId = !empty($workflowContext['transaksi_id']) ? (int) $workflowContext['transaksi_id'] : 0;
$tipeDokumen = trim((string) ($workflowContext['tipe_dokumen'] ?? ($produksiInfo['tipe_dokumen'] ?? '')));
$noTransaksi = trim((string) ($workflowContext['no_transaksi'] ?? ($produksiInfo['no_transaksi'] ?? '')));
$workflowStep = transactionWorkflowResolveStep($workflowContext);
$isWorkflowLockedForProduction = $transaksiId > 0 && !in_array($workflowStep, ['production', 'done'], true);
$fileReferensi = [];
$fileSiapCetak = [];

$total = count($tahapanList);
$done  = 0;
foreach ($tahapanList as $t) {
    if ($t['status'] === 'selesai') $done++;
}
$progress = $total > 0 ? (int)floor($done / $total * 100) : 0;

$canAssign = in_array($role, ['superadmin', 'admin', 'service']);
$canManageProduction = canManageProductionRecordActions($role);
$statusBadgeMap = [
    'antrian' => 'badge-secondary',
    'proses' => 'badge-warning',
    'selesai' => 'badge-success',
    'batal' => 'badge-danger',
];
?>
<div class="tahapan-wrapper" data-produksi-id="<?= $produksiId ?>">

    <div data-produksi-detail-title="Detail <?= htmlspecialchars((string) ($produksiInfo['no_dokumen'] ?? ('#' . $produksiId)), ENT_QUOTES, 'UTF-8') ?>"></div>

    <div class="tahapan-header-stack" style="display:flex;flex-direction:column;gap:12px;margin-bottom:14px">
        <div class="tahapan-header-top" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
            <div>
                <div style="font-size:1rem;font-weight:800;color:var(--text)"><?= htmlspecialchars((string) ($produksiInfo['no_dokumen'] ?? '-')) ?></div>
                <div class="tahapan-header-meta" style="margin-top:4px;font-size:.8rem;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:8px 12px">
                    <span><i class="fas fa-file-alt"></i> <?= htmlspecialchars((string) strtoupper($tipeDokumen !== '' ? $tipeDokumen : ((string) ($produksiInfo['tipe_dokumen'] ?? 'JO')))) ?></span>
                    <span><i class="fas fa-receipt"></i> <?= htmlspecialchars($noTransaksi !== '' ? $noTransaksi : '-') ?></span>
                    <span><i class="fas fa-user"></i> <?= htmlspecialchars((string) ($produksiInfo['nama_pelanggan'] ?? 'Umum')) ?></span>
                </div>
            </div>
            <div class="tahapan-badge-row" style="display:flex;flex-wrap:wrap;gap:8px">
                <span class="badge <?= $statusBadgeMap[$produksiInfo['status']] ?? 'badge-secondary' ?>"><?= htmlspecialchars((string) ($produksiInfo['status'] ?? 'antrian')) ?></span>
                <?php if ($transaksiId > 0): ?>
                    <span class="badge badge-<?= htmlspecialchars(transactionWorkflowBadgeClass($workflowStep)) ?>"><?= htmlspecialchars(transactionWorkflowLabel($workflowStep)) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="tahapan-summary-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
            <div class="tahapan-summary-card" style="padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)">
                <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:4px">Nama Pekerjaan</div>
                <div style="font-weight:700;color:var(--text)"><?= htmlspecialchars((string) ($produksiInfo['nama_pekerjaan'] ?? '-')) ?></div>
            </div>
            <div class="tahapan-summary-card" style="padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)">
                <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:4px">Tanggal & Deadline</div>
                <div style="font-weight:700;color:var(--text)">
                    <?= !empty($produksiInfo['tanggal']) ? date('d/m/Y', strtotime((string) $produksiInfo['tanggal'])) : '-' ?>
                    <?php if (!empty($produksiInfo['deadline'])): ?>
                        <span style="font-weight:500;color:var(--text-muted)"> / <?= date('d/m/Y', strtotime((string) $produksiInfo['deadline'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tahapan-summary-card" style="padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)">
                <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:4px">Karyawan</div>
                <div style="font-weight:700;color:var(--text)"><?= htmlspecialchars((string) ($produksiInfo['nama_karyawan'] ?? '-')) ?></div>
            </div>
            <div class="tahapan-summary-card" style="padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)">
                <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:4px">Dibuat Oleh</div>
                <div style="font-weight:700;color:var(--text)"><?= htmlspecialchars((string) ($produksiInfo['nama_user'] ?? '-')) ?></div>
            </div>
        </div>

        <div class="tahapan-action-row" style="display:flex;flex-wrap:wrap;gap:8px">
            <?php if ($canManageProduction): ?>
                <button class="btn btn-primary btn-sm" type="button" onclick='editProduksi(<?= json_encode($produksiInfo, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                    <i class="fas fa-edit"></i> Edit
                </button>
            <?php endif; ?>
            <?php if (($produksiInfo['tipe_dokumen'] ?? 'JO') === 'JO'): ?>
                <button class="btn btn-secondary btn-sm" type="button" onclick="cetakDokumen(<?= (int) $produksiId ?>, 'JO')">
                    <i class="fas fa-print"></i> Cetak JO
                </button>
            <?php else: ?>
                <button class="btn btn-warning btn-sm" type="button" onclick="cetakDokumen(<?= (int) $produksiId ?>, 'SPK')">
                    <i class="fas fa-print"></i> Cetak SPK
                </button>
            <?php endif; ?>
            <?php if ($canManageProduction): ?>
                <form method="POST" action="<?= htmlspecialchars(pageUrl('produksi.php')) ?>" onsubmit="confirmDelete(this);return false;" style="margin:0">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="hapus">
                    <input type="hidden" name="id" value="<?= (int) $produksiId ?>">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Progress bar -->
    <div class="tahapan-progress" style="margin-bottom:12px">
        <div class="tahapan-progress-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <small style="color:var(--text-muted)">Progress</small>
            <small style="font-weight:600"><?= $done ?>/<?= $total ?> (<?= $progress ?>%)</small>
        </div>
    <div style="background:var(--border);border-radius:4px;height:8px;overflow:hidden">
        <div style="width:<?= $progress ?>%;background:var(--success,#28a745);height:100%;border-radius:4px;transition:width .3s"></div>
    </div>
    </div>

    <!-- Tahapan list -->
    <?php if ($transaksiId > 0): ?>
    <div class="tahapan-workflow-note" style="margin-bottom:12px;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:<?= $isWorkflowLockedForProduction ? 'rgba(59,130,246,.06)' : 'rgba(34,197,94,.08)' ?>;">
        <div style="font-size:.78rem;font-weight:700;color:var(--text);margin-bottom:4px">
            Tahap Order: <?= htmlspecialchars(transactionWorkflowLabel($workflowStep)) ?>
        </div>
        <div style="font-size:.74rem;color:var(--text-muted);line-height:1.5">
            <?php if ($isWorkflowLockedForProduction): ?>
                Job ini belum bisa diproses penuh karena invoice terkait belum membuka alur produksi. Setelah pelunasan selesai, seluruh tahapan produksi akan aktif.
            <?php else: ?>
                Order ini sudah siap diproses penuh di lini produksi.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($tahapanList)): ?>
        <p class="text-muted" style="text-align:center;padding:12px 0">Belum ada tahapan untuk produksi ini.</p>
    <?php else: ?>
    <div class="tahapan-stage-list" style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($tahapanList as $t):
            $isDone      = $t['status'] === 'selesai';
            $canUpdateStage = canChecklistProductionStage($t, $workflowContext, $role, $userId, $employeeId);
            $isLockedByWorkflow = $isWorkflowLockedForProduction && (int) ($t['urutan'] ?? 0) > 1 && !$isDone;
            $canChecklist = $canUpdateStage && !$isDone && !$isLockedByWorkflow;
        ?>
        <div class="tahapan-item" data-id="<?= $t['id'] ?>"
             style="border:1px solid var(--border);border-radius:6px;padding:10px 12px;background:var(--card-bg,#fff)">
            <div class="tahapan-item-top" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">

                <!-- Urutan -->
                <span style="min-width:24px;height:24px;border-radius:50%;background:var(--primary,#007bff);color:#fff;
                             display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0">
                    <?= $t['urutan'] ?>
                </span>

                <!-- Nama tahapan -->
                <span class="tahapan-item-title" style="flex:1;font-weight:500"><?= htmlspecialchars($t['nama_tahapan']) ?></span>

                <!-- Status badge -->
                <?php if ($isDone): ?>
                    <span class="badge badge-success">Selesai</span>
                <?php else: ?>
                    <span class="badge badge-secondary">Belum</span>
                <?php endif; ?>

                <!-- Checklist button -->
                <?php if ($canChecklist): ?>
                    <button class="btn btn-success btn-sm btn-checklist"
                            data-id="<?= $t['id'] ?>"
                            data-produksi="<?= $produksiId ?>"
                            title="Tandai selesai"
                            style="padding:3px 8px">
                        <i class="fas fa-check"></i>
                    </button>
                <?php elseif ($isDone): ?>
                    <span style="color:var(--success,#28a745);font-size:18px" title="Selesai"><i class="fas fa-check-circle"></i></span>
                <?php elseif ($isLockedByWorkflow): ?>
                    <span class="badge badge-warning" title="Menunggu kasir / pembayaran">Terkunci</span>
                <?php else: ?>
                    <span class="badge badge-secondary" title="Tahapan ini bisa dikerjakan tim produksi setelah job aktif">Siap Dibantu Tim</span>
                <?php endif; ?>
            </div>

            <!-- Operator row -->
            <div class="tahapan-operator-row" style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:var(--bg,#f8f9fa);padding:8px;border-radius:6px;">
                <small style="color:var(--text-muted)">Operator:</small>
                <?php if ($canAssign): ?>
                    <select class="form-control form-control-sm select-operator"
                            data-id="<?= $t['id'] ?>"
                            data-produksi="<?= $produksiId ?>"
                            style="flex:1;min-width:140px;font-size:13px">
                        <option value="">-- Opsional / belum ditugaskan --</option>
                        <?php foreach ($operatorList as $op): ?>
                            <option value="<?= $op['id'] ?>" <?= ((int)$t['user_id'] === (int)$op['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($op['nama']) ?> (<?= $op['role'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <small style="font-weight:500">
                        <?= $t['nama_operator'] ? htmlspecialchars($t['nama_operator']) : '<em style="color:var(--text-muted)">Belum ditugaskan, tim lain tetap bisa membantu.</em>' ?>
                    </small>
                <?php endif; ?>
            </div>
            <?php if ($isLockedByWorkflow): ?>
            <div class="tahapan-lock-note" style="margin-top:8px;font-size:.72rem;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:8px 10px">
                Tahap ini belum bisa dijalankan. Order masih menunggu pelunasan invoice sebelum lanjut ke tahap produksi berikutnya.
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Area File untuk Operator -->
    <?php if ($transaksiId > 0 && (!empty($fileReferensi) || !empty($fileSiapCetak))): ?>
    <div class="tahapan-file-section" style="margin-top:16px;border-top:1px dashed var(--border);padding-top:12px">
        <div style="font-size:.85rem;font-weight:700;color:var(--text);margin-bottom:10px"><i class="fas fa-paperclip"></i> File Terlampir</div>
        
        <?php if (!empty($fileReferensi)): ?>
        <div style="margin-bottom:10px">
            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:4px"><?= $tipeDokumen === 'SPK' ? 'Referensi SPK:' : 'Referensi Customer:' ?></div>
            <div class="tahapan-file-list" style="display:flex;flex-direction:column;gap:8px">
                <?php foreach ($fileReferensi as $f): ?>
                    <div class="tahapan-file-item" style="display:flex;flex-direction:column;gap:4px">
                        <a href="<?= pageUrl('file_download.php?id=' . (int) $f['id']) ?>" target="_blank" class="btn btn-outline btn-sm tahapan-file-link" style="padding:4px 8px;font-size:.75rem;border-color:var(--border-dark);align-self:flex-start">
                            <i class="fas fa-download"></i> <?= htmlspecialchars((string) ($f['nama_asli'] ?? 'File referensi')) ?>
                        </a>
                        <div class="tahapan-file-meta" style="font-size:.72rem;color:var(--text-muted)">
                            <span>Invoice <?= htmlspecialchars($noTransaksi !== '' ? $noTransaksi : ('#' . $transaksiId)) ?></span>
                            <?php if (!empty($f['nama_uploader'])): ?>
                                <span> · Upload oleh <?= htmlspecialchars((string) $f['nama_uploader']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($f['created_at'])): ?>
                                <span> · <?= date('d/m/Y H:i', strtotime((string) $f['created_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipeDokumen === 'JO'): ?>
        <div style="margin-bottom:8px">
            <div style="font-size:.75rem;color:var(--primary);font-weight:600;margin-bottom:4px">File Siap Cetak (Untuk Operator):</div>
            <?php if (!empty($fileSiapCetak)): ?>
            <div class="tahapan-file-list" style="display:flex;flex-direction:column;gap:8px">
                <?php foreach ($fileSiapCetak as $f): ?>
                    <div class="tahapan-file-item" style="display:flex;flex-direction:column;gap:4px">
                        <a href="<?= pageUrl('file_download.php?id=' . (int) $f['id']) ?>" target="_blank" class="btn btn-primary btn-sm tahapan-file-link" style="padding:4px 8px;font-size:.75rem;box-shadow:0 2px 5px rgba(59,130,246,0.3);align-self:flex-start">
                            <i class="fas fa-print"></i> <?= htmlspecialchars((string) ($f['nama_asli'] ?? 'File siap cetak')) ?>
                        </a>
                        <div class="tahapan-file-meta" style="font-size:.72rem;color:var(--text-muted)">
                            <?php if (!empty($f['nama_uploader'])): ?>
                                <span>Upload oleh <?= htmlspecialchars((string) $f['nama_uploader']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($f['created_at'])): ?>
                                <span> · <?= date('d/m/Y H:i', strtotime((string) $f['created_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="font-size:.75rem;color:var(--danger);padding:6px;background:#fee2e2;border-radius:4px;display:inline-block"><i class="fas fa-exclamation-circle"></i> Tim setting belum mengunggah file siap cetak.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="tahapan-footer-note" style="margin-top:16px;padding:12px 14px;border-radius:12px;border:1px dashed var(--border-dark);background:rgba(59,130,246,.05);font-size:.78rem;color:var(--text-muted);line-height:1.6">
        Penugasan operator tetap tersedia bila dibutuhkan, tetapi tidak lagi wajib agar tahapan bisa dikerjakan. Semua lampiran produksi sekarang dipusatkan di menu <strong>Siap Cetak</strong> supaya area kerja operator tetap ringkas.
    </div>
</div>
