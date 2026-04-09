<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service', 'kasir');
$pageTitle = 'Transaksi';
transactionWorkflowSupportReady($conn);
transactionPaymentEnsureSupportTables($conn);
transactionOrderEnsureSupport($conn);

function transactionStatusBadgeClass(string $status): string
{
    return transactionPaymentStatusBadgeClass(['status' => $status, 'bayar' => 0]);
}

function transactionStatusLabel(string $status): string
{
    return transactionPaymentStatusLabel(['status' => $status, 'bayar' => 0]);
}

function transaksiCustomerJoinConfig(mysqli $conn): array
{
    $hasCustomerJoin = schemaTableExists($conn, 'pelanggan')
        && schemaColumnExists($conn, 'transaksi', 'pelanggan_id');

    return [
        'join' => $hasCustomerJoin ? ' LEFT JOIN pelanggan p ON t.pelanggan_id = p.id' : '',
        'select' => $hasCustomerJoin
            ? ', p.nama AS nama_pelanggan, p.telepon AS tlp_pelanggan'
            : ', NULL AS nama_pelanggan, NULL AS tlp_pelanggan',
    ];
}

function transaksiCashierJoinConfig(mysqli $conn): array
{
    $hasCashierJoin = schemaTableExists($conn, 'users')
        && schemaColumnExists($conn, 'transaksi', 'user_id');

    return [
        'join' => $hasCashierJoin ? ' LEFT JOIN users u ON t.user_id = u.id' : '',
        'select' => $hasCashierJoin
            ? ', u.nama AS nama_kasir'
            : ', NULL AS nama_kasir',
    ];
}

function transaksiPageUrlWithFilters(string $status = '', string $workflow = '', string $search = ''): string
{
    $params = [];
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($workflow !== '') {
        $params['workflow'] = $workflow;
    }
    if ($search !== '') {
        $params['q'] = $search;
    }

    $query = http_build_query($params);

    return pageUrl('transaksi.php' . ($query !== '' ? '?' . $query : ''));
}

$paymentSupportReady = transactionPaymentSupportReady($conn);
$msg = '';
if (isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_payment') {
        if (!hasRole('superadmin', 'admin', 'kasir')) {
            $msg = 'danger|Pembayaran hanya dapat diproses oleh admin atau kasir.';
        } else {
            $transactionStarted = false;
            $paymentProofUpload = [
                'attempted' => false,
                'success' => false,
                'files' => [],
                'errors' => [],
                'message' => '',
            ];

            try {
                $conn->begin_transaction();
                $transactionStarted = true;

                $settlementTransactionId = (int) ($_POST['transaksi_id'] ?? 0);
                if ($settlementTransactionId <= 0) {
                    throw new RuntimeException('Transaksi tidak valid.');
                }
                if (!transactionWorkflowCanProcessPayment($conn, $settlementTransactionId)) {
                    throw new RuntimeException('Transaksi belum ada di tahap pembayaran. Lanjutkan draft atau workflow order ke tahap yang sesuai terlebih dahulu.');
                }

                $payment = transactionPaymentRegisterSettlement($conn, [
                    'transaksi_id' => $settlementTransactionId,
                    'tanggal' => (string) ($_POST['tanggal_bayar'] ?? date('Y-m-d')),
                    'nominal' => (float) ($_POST['nominal_bayar'] ?? 0),
                    'metode' => (string) ($_POST['metode_bayar'] ?? 'cash'),
                    'referensi' => (string) ($_POST['referensi_bayar'] ?? ''),
                    'catatan' => (string) ($_POST['catatan_bayar'] ?? ''),
                    'created_by' => (int) ($_SESSION['user_id'] ?? 0),
                ]);

                transactionOrderSyncProductionJobsByPaymentState(
                    $conn,
                    (int) ($payment['transaksi_id'] ?? 0),
                    (int) ($_SESSION['user_id'] ?? 0)
                );
                transactionWorkflowRepairStoredSteps($conn);

                writeAuditLog(
                    'transaksi_pelunasan',
                    'transaksi',
                    'Pembayaran lanjutan untuk transaksi ' . ($payment['no_transaksi'] ?? ('#' . ((int) ($payment['transaksi_id'] ?? 0)))) . ' berhasil dicatat.',
                    [
                        'entity_id' => (int) ($payment['transaksi_id'] ?? 0),
                        'metadata' => [
                            'payment_id' => (int) ($payment['payment_id'] ?? 0),
                            'status_baru' => (string) ($payment['status'] ?? ''),
                            'bayar_total' => (float) ($payment['bayar'] ?? 0),
                            'sisa_bayar' => (float) ($payment['sisa_bayar'] ?? 0),
                        ],
                    ]
                );

                $conn->commit();
                $transactionStarted = false;
                $paymentProofUpload = transactionPaymentStoreProofUpload(
                    $conn,
                    (int) ($payment['transaksi_id'] ?? 0),
                    (int) ($_SESSION['user_id'] ?? 0),
                    'bukti_pembayaran'
                );

                $paymentMessage = 'Pembayaran transaksi ' . ($payment['no_transaksi'] ?? '') . ' berhasil disimpan.';
                if ($paymentProofUpload['attempted'] && !$paymentProofUpload['success']) {
                    $msg = 'warning|' . $paymentMessage . ' Namun upload bukti pembayaran gagal: ' . ($paymentProofUpload['message'] ?: 'silakan cek format atau ukuran file.');
                } elseif ($paymentProofUpload['attempted'] && $paymentProofUpload['success']) {
                    $msg = 'success|' . $paymentMessage . ' ' . ($paymentProofUpload['message'] ?: 'Bukti pembayaran berhasil diunggah.');
                } else {
                    $msg = 'success|' . $paymentMessage;
                }
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $msg = 'danger|' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_draft_item') {
        if (!hasRole('superadmin', 'admin', 'service', 'kasir')) {
            $msg = 'danger|Perubahan draft invoice hanya tersedia untuk admin, customer service, atau kasir.';
        } else {
            $transactionStarted = false;

            try {
                $conn->begin_transaction();
                $transactionStarted = true;

                $transaksiId = (int) ($_POST['transaksi_id'] ?? 0);
                $detailId = (int) ($_POST['detail_id'] ?? 0);
                $trx = transactionOrderFetchTransactionHeader($conn, $transaksiId, true);
                if (!$trx) {
                    throw new RuntimeException('Transaksi tidak ditemukan.');
                }
                if (!transactionOrderCanAmendTransaction($trx)) {
                    throw new RuntimeException('Draft invoice ini tidak bisa diubah lagi karena sudah masuk proses pembayaran atau dibatalkan.');
                }

                $updatedItem = transactionOrderUpdateDraftDetailItem($conn, $transaksiId, $detailId, [
                    'nama_produk' => (string) ($_POST['nama_produk'] ?? ''),
                    'qty' => (float) ($_POST['qty'] ?? 0),
                    'harga' => (float) ($_POST['harga'] ?? 0),
                    'lebar' => (float) ($_POST['lebar'] ?? 0),
                    'tinggi' => (float) ($_POST['tinggi'] ?? 0),
                    'size_detail' => (string) ($_POST['size_detail'] ?? ''),
                    'catatan' => (string) ($_POST['catatan_item'] ?? ''),
                ]);
                $updatedHeader = transactionOrderRefreshHeaderTotals($conn, $transaksiId);
                transactionOrderSyncProductionJobsByPaymentState(
                    $conn,
                    $transaksiId,
                    (int) ($_SESSION['user_id'] ?? 0)
                );
                transactionWorkflowRepairStoredSteps($conn);

                writeAuditLog(
                    'transaksi_update_draft_item',
                    'detail_transaksi',
                    'Item draft transaksi #' . $transaksiId . ' diperbarui.',
                    [
                        'entity_id' => $detailId,
                        'metadata' => [
                            'transaksi_id' => $transaksiId,
                            'nama_produk' => (string) ($updatedItem['nama_produk'] ?? ''),
                            'qty' => (float) ($updatedItem['qty'] ?? 0),
                            'subtotal' => (float) ($updatedItem['subtotal'] ?? 0),
                            'total_transaksi' => (float) ($updatedHeader['total'] ?? 0),
                        ],
                    ]
                );

                $conn->commit();
                $transactionStarted = false;
                $msg = 'success|Item draft berhasil diperbarui.';
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $msg = 'danger|' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_draft_item') {
        if (!hasRole('superadmin', 'admin', 'service', 'kasir')) {
            $msg = 'danger|Penghapusan item draft invoice hanya tersedia untuk admin, customer service, atau kasir.';
        } else {
            $transactionStarted = false;

            try {
                $conn->begin_transaction();
                $transactionStarted = true;

                $transaksiId = (int) ($_POST['transaksi_id'] ?? 0);
                $detailId = (int) ($_POST['detail_id'] ?? 0);
                $trx = transactionOrderFetchTransactionHeader($conn, $transaksiId, true);
                if (!$trx) {
                    throw new RuntimeException('Transaksi tidak ditemukan.');
                }
                if (!transactionOrderCanAmendTransaction($trx)) {
                    throw new RuntimeException('Draft invoice ini tidak bisa diubah lagi karena sudah masuk proses pembayaran atau dibatalkan.');
                }

                $deletedItem = transactionOrderDeleteDraftDetailItem($conn, $transaksiId, $detailId);
                $updatedHeader = transactionOrderRefreshHeaderTotals($conn, $transaksiId);
                transactionOrderSyncProductionJobsByPaymentState(
                    $conn,
                    $transaksiId,
                    (int) ($_SESSION['user_id'] ?? 0)
                );
                transactionWorkflowRepairStoredSteps($conn);

                writeAuditLog(
                    'transaksi_delete_draft_item',
                    'detail_transaksi',
                    'Item draft transaksi #' . $transaksiId . ' dihapus.',
                    [
                        'entity_id' => $detailId,
                        'metadata' => [
                            'transaksi_id' => $transaksiId,
                            'nama_produk' => (string) ($deletedItem['nama_produk'] ?? ''),
                            'total_transaksi' => (float) ($updatedHeader['total'] ?? 0),
                        ],
                    ]
                );

                $conn->commit();
                $transactionStarted = false;
                $msg = 'success|Item draft berhasil dihapus.';
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $msg = 'danger|' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_invoice_note') {
        $transaksiId = (int) ($_POST['id'] ?? 0);
        $invoiceNote = transactionOrderSanitizeInvoiceNote((string) ($_POST['invoice_note'] ?? ''));

        if ($transaksiId <= 0) {
            $msg = 'danger|Transaksi tidak valid.';
        } elseif (!transactionOrderHasInvoiceNoteColumn($conn)) {
            $msg = 'danger|Kolom catatan invoice belum siap di database.';
        } else {
            $stmt = $conn->prepare("UPDATE transaksi SET catatan_invoice = ? WHERE id = ?");
            if (!$stmt) {
                $msg = 'danger|Catatan invoice tidak dapat disimpan saat ini.';
            } else {
                $stmt->bind_param('si', $invoiceNote, $transaksiId);
                if ($stmt->execute()) {
                    writeAuditLog(
                        'transaksi_update_invoice_note',
                        'transaksi',
                        'Catatan invoice transaksi #' . $transaksiId . ' diperbarui.',
                        [
                            'entity_id' => $transaksiId,
                            'metadata' => [
                                'catatan_invoice' => $invoiceNote,
                            ],
                        ]
                    );
                    $msg = 'success|Catatan invoice berhasil diperbarui.';
                } else {
                    $msg = 'danger|Gagal memperbarui catatan invoice.';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'void') {
        if (!hasRole('superadmin', 'admin')) {
            $msg = 'danger|VOID transaksi hanya dapat dilakukan oleh superadmin atau admin.';
        } else {
            $transactionStarted = false;

            try {
                $conn->begin_transaction();
                $transactionStarted = true;

                $voided = transactionVoidSale($conn, [
                    'transaksi_id' => (int) ($_POST['id'] ?? 0),
                ]);

                writeAuditLog(
                    'transaksi_void',
                    'transaksi',
                    'Transaksi ' . ($voided['no_transaksi'] ?? ('#' . ((int) ($voided['transaksi_id'] ?? 0)))) . ' di-VOID.',
                    [
                        'entity_id' => (int) ($voided['transaksi_id'] ?? 0),
                        'metadata' => [
                            'no_transaksi' => (string) ($voided['no_transaksi'] ?? ''),
                            'bayar' => (float) ($voided['bayar'] ?? 0),
                            'restocked_items' => (int) ($voided['restocked_items'] ?? 0),
                            'cancelled_jobs' => (int) ($voided['cancelled_jobs'] ?? 0),
                        ],
                    ]
                );

                $conn->commit();
                $transactionStarted = false;
                $msg = 'success|Transaksi ' . ($voided['no_transaksi'] ?? '') . ' berhasil di-VOID.';
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $msg = 'danger|' . $e->getMessage();
            }
        }
    } elseif ($action === 'batal') {
        $id = (int) ($_POST['id'] ?? 0);
        $trx = null;

        if ($id > 0) {
            $customerJoin = transaksiCustomerJoinConfig($conn);
            $stmt = $conn->prepare(
                "SELECT t.id, t.no_transaksi, t.status, t.total" . $customerJoin['select'] . "
                 FROM transaksi t
                 " . $customerJoin['join'] . "
                 WHERE t.id = ?
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $trx = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }

        if (!$trx) {
            $msg = 'danger|Transaksi tidak ditemukan.';
        } elseif (transactionPaymentHasPaidAmount($trx)) {
            $msg = hasRole('superadmin', 'admin')
                ? 'warning|Transaksi yang sudah memiliki pembayaran harus dibatalkan melalui fitur VOID.'
                : 'danger|Transaksi yang sudah memiliki pembayaran tidak dapat dibatalkan dari akun ini.';
        } else {
            $transactionStarted = false;

            try {
                $conn->begin_transaction();
                $transactionStarted = true;

                $restockedItems = transactionVoidRollbackStock($conn, $id);
                $cancelledJobs = transactionCancelProductionJobs(
                    $conn,
                    $id,
                    '[Batal transaksi] Job draft dibatalkan dari menu transaksi.'
                );

                $stmt = $conn->prepare(
                    "UPDATE transaksi
                     SET status = 'batal', sisa_bayar = 0, tempo_tgl = NULL
                     WHERE id = ?"
                );
                if (!$stmt) {
                    throw new RuntimeException('Transaksi tidak dapat diperbarui saat ini.');
                }

                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('Gagal membatalkan transaksi.');
                }
                $stmt->close();

                transactionWorkflowSetStep($conn, $id, 'cancelled');

                writeAuditLog(
                    'transaksi_batal',
                    'transaksi',
                    'Transaksi ' . ($trx['no_transaksi'] ?? ('#' . $id)) . ' dibatalkan.',
                    [
                        'entity_id' => $id,
                        'metadata' => [
                            'no_transaksi' => $trx['no_transaksi'] ?? null,
                            'status_sebelum' => $trx['status'] ?? null,
                            'total' => (float) ($trx['total'] ?? 0),
                            'restocked_items' => $restockedItems,
                            'cancelled_jobs' => $cancelledJobs,
                        ],
                    ]
                );

                $conn->commit();
                $transactionStarted = false;
                $msg = 'success|Transaksi dibatalkan.';

                if (!empty($trx['tlp_pelanggan'])) {
                    $whatsAppServicePath = dirname(__DIR__, 2) . '/services/whatsapp_service.php';
                    if (is_file($whatsAppServicePath)) {
                        require_once $whatsAppServicePath;
                        if (function_exists('sendWaNotificationOrderCanceled')) {
                            sendWaNotificationOrderCanceled($trx['tlp_pelanggan'], $trx['nama_pelanggan'], $trx['no_transaksi']);
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $msg = 'danger|' . $e->getMessage();
            }
        }
    }
}

$allowedFilters = ['draft', 'selesai', 'pending', 'dp', 'tempo', 'batal'];
$filter = $_GET['status'] ?? '';
if (!in_array($filter, $allowedFilters, true)) {
    $filter = '';
}

$allowedWorkflowFilters = ['draft', 'cashier', 'production', 'done', 'cancelled'];
$workflowFilter = trim((string) ($_GET['workflow'] ?? ''));
if (!in_array($workflowFilter, $allowedWorkflowFilters, true)) {
    $workflowFilter = '';
}
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$customerJoin = transaksiCustomerJoinConfig($conn);
$cashierJoin = transaksiCashierJoinConfig($conn);
$invoiceNoteSelect = transactionOrderHasInvoiceNoteColumn($conn)
    ? ', t.catatan_invoice'
    : ", '' AS catatan_invoice";

$whereParts = [];
if ($filter !== '') {
    $whereParts[] = "t.status='" . $conn->real_escape_string($filter) . "'";
}
if ($workflowFilter !== '' && schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
    $whereParts[] = "t.workflow_step='" . $conn->real_escape_string($workflowFilter) . "'";
}
if ($searchQuery !== '') {
    $escapedSearch = $conn->real_escape_string($searchQuery);
    $searchLike = "'%" . $escapedSearch . "%'";
    $searchClauses = [
        "t.no_transaksi LIKE {$searchLike}",
        "t.status LIKE {$searchLike}",
        "CAST(t.total AS CHAR) LIKE {$searchLike}",
        "CAST(t.bayar AS CHAR) LIKE {$searchLike}",
        "CAST(t.kembalian AS CHAR) LIKE {$searchLike}",
        "COALESCE(t.metode_bayar, '') LIKE {$searchLike}",
    ];
    if ($customerJoin['join'] !== '') {
        $searchClauses[] = "COALESCE(p.nama, '') LIKE {$searchLike}";
        $searchClauses[] = "COALESCE(p.telepon, '') LIKE {$searchLike}";
    }
    if ($cashierJoin['join'] !== '') {
        $searchClauses[] = "COALESCE(u.nama, '') LIKE {$searchLike}";
    }
    if (transactionOrderHasInvoiceNoteColumn($conn)) {
        $searchClauses[] = "COALESCE(t.catatan_invoice, '') LIKE {$searchLike}";
    }
    if (schemaColumnExists($conn, 'transaksi', 'created_at')) {
        $searchClauses[] = "DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i') LIKE {$searchLike}";
    }
    $whereParts[] = '(' . implode(' OR ', $searchClauses) . ')';
}

$where = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$hasFileTable = schemaTableExists($conn, 'file_transaksi');
$canCountFiles = $hasFileTable && schemaColumnExists($conn, 'file_transaksi', 'transaksi_id');
$fileActiveCondition = ($canCountFiles && schemaColumnExists($conn, 'file_transaksi', 'is_active'))
    ? ' AND is_active = 1'
    : '';
$fileSelect = $canCountFiles
    ? ", (SELECT COUNT(*) FROM file_transaksi WHERE transaksi_id = t.id{$fileActiveCondition}) as jml_file"
    : ", 0 as jml_file";
$orderBy = schemaColumnExists($conn, 'transaksi', 'created_at') ? 't.created_at DESC' : 't.id DESC';
$dataQuery = $conn->query(
    "SELECT t.*" . $customerJoin['select'] . $cashierJoin['select'] . $invoiceNoteSelect . " $fileSelect
     FROM transaksi t" . $customerJoin['join'] . $cashierJoin['join'] . "
     $where
     ORDER BY {$orderBy}"
);
$data = $dataQuery ? $dataQuery->fetch_all(MYSQLI_ASSOC) : [];

$statusTotals = ['semua' => 0, 'draft' => 0, 'selesai' => 0, 'pending' => 0, 'dp' => 0, 'tempo' => 0, 'batal' => 0];
$statusQuery = $conn->query("SELECT status, COUNT(*) as jumlah FROM transaksi GROUP BY status");
if ($statusQuery) {
    foreach ($statusQuery->fetch_all(MYSQLI_ASSOC) as $row) {
        $statusKey = strtolower((string) ($row['status'] ?? ''));
        if (isset($statusTotals[$statusKey])) {
            $statusTotals[$statusKey] = (int) ($row['jumlah'] ?? 0);
        }
    }
}
$statusTotals['semua'] = $statusTotals['draft'] + $statusTotals['selesai'] + $statusTotals['pending'] + $statusTotals['dp'] + $statusTotals['tempo'] + $statusTotals['batal'];

$visibleCount = count($data);
$visibleTotal = 0;
$visibleOutstanding = 0;
$visibleFileCount = 0;
$visibleActionNeeded = 0;
foreach ($data as $index => $row) {
    $remaining = transactionPaymentResolveRemaining($row);
    $needsSettlement = transactionPaymentCanBeSettled($row);
    $canVoid = hasRole('superadmin', 'admin') && transactionPaymentCanBeVoided($row);
    $workflowStep = transactionWorkflowResolveStep($row);
    $canCollectPayment = $paymentSupportReady
        && hasRole('superadmin', 'admin', 'kasir')
        && transactionWorkflowCanCollectPayment($row);
    $data[$index]['remaining_amount'] = $remaining;
    $data[$index]['needs_settlement'] = $needsSettlement;
    $data[$index]['can_void'] = $canVoid;
    $data[$index]['status_badge_class'] = transactionPaymentStatusBadgeClass($row);
    $data[$index]['status_label'] = transactionPaymentStatusLabel($row);
    $data[$index]['workflow_step_label'] = transactionWorkflowLabel($workflowStep);
    $data[$index]['workflow_step_badge'] = transactionWorkflowBadgeClass($workflowStep);
    $data[$index]['workflow_step_value'] = $workflowStep;
    $data[$index]['can_print_invoice'] = transactionWorkflowIsProductionOpen($row);
    $data[$index]['can_collect_payment'] = $canCollectPayment;

    $visibleTotal += (float) ($row['total'] ?? 0);
    $visibleOutstanding += $remaining;
    $visibleFileCount += (int) ($row['jml_file'] ?? 0);
    if ($needsSettlement) {
        $visibleActionNeeded++;
    }
}

$filterLabels = [
    '' => 'Semua status',
    'draft' => 'Draft',
    'selesai' => 'Selesai',
    'pending' => 'Pending',
    'dp' => 'DP',
    'tempo' => 'Tempo',
    'batal' => 'Batal'
];
$workflowFilterLabels = [
    '' => 'Semua tahap',
    'draft' => transactionWorkflowLabel('draft'),
    'cashier' => transactionWorkflowLabel('cashier'),
    'production' => transactionWorkflowLabel('production'),
    'done' => transactionWorkflowLabel('done'),
    'cancelled' => transactionWorkflowLabel('cancelled'),
];

$extraCss = '<style>
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
.transaction-detail-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}
.transaction-detail-actions form {
    margin: 0;
}
.transaction-detail-actions .btn {
    min-width: 0;
}
.transaction-detail-note {
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: rgba(59,130,246,.06);
    color: var(--text-muted);
    font-size: .82rem;
    line-height: 1.5;
}
.transaction-status-tabs {
    padding: 14px 16px;
}
.transaction-status-tabs .filter-pills {
    margin-bottom: 0;
}
.transaction-status-tabs .filter-pill {
    min-height: 40px;
    padding: 8px 14px;
    font-size: .78rem;
    font-weight: 800;
}
.transaction-status-tabs .filter-pill.active {
    box-shadow: var(--shadow-xs);
}
.transaction-filter-panel {
    display: grid;
    gap: 14px;
}
.transaction-filter-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.transaction-filter-panel-title {
    margin: 0;
    font-size: .92rem;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -0.02em;
}
.transaction-filter-panel-copy {
    margin: 6px 0 0;
    max-width: 68ch;
    color: var(--text-muted);
    font-size: .8rem;
    line-height: 1.6;
}
.transaction-search-form {
    display: grid;
    grid-template-columns: minmax(0, 1.65fr) minmax(220px, .75fr) auto auto;
    gap: 10px;
    align-items: center;
}
.transaction-search-field {
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
}
.transaction-search-field i {
    position: absolute;
    left: 14px;
    color: var(--text-muted);
    pointer-events: none;
}
.transaction-search-field .form-control {
    min-width: 0;
    padding-left: 40px;
    padding-right: 42px;
}
.transaction-search-clear {
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
.transaction-search-clear:hover {
    color: var(--text);
    background: rgba(255, 255, 255, 0.6);
    border-color: rgba(15, 118, 110, 0.16);
}
.transaction-search-meta {
    color: var(--text-muted);
    font-size: .78rem;
    line-height: 1.5;
}
.transaction-search-meta strong {
    color: var(--text);
}
.transaksi-page .table-responsive {
    overflow-x: auto;
}
.transaksi-page table {
    min-width: 1180px;
}
.transaksi-page thead th,
.transaksi-page tbody td {
    line-height: 1.5;
}
.transaksi-page .transaction-note-cell,
.transaksi-page .mobile-data-subtitle {
    overflow-wrap: anywhere;
}
.transaksi-page td.rp,
.transaksi-page .mobile-data-value.rp {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.transaksi-page .badge {
    min-height: 30px;
    max-width: 100%;
    padding: 6px 12px;
    line-height: 1.25;
    white-space: normal;
    text-align: center;
}
.transaction-search-empty[hidden] {
    display: none !important;
}
.transaction-search-empty {
    margin-top: 16px;
}
@media (max-width: 768px) {
    .transaction-status-tabs {
        padding: 12px;
    }
    .transaction-filter-panel {
        padding: 14px;
    }
    .transaction-filter-panel-copy {
        font-size: .78rem;
    }
    .transaction-search-form {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
    .transaction-search-form .transaction-search-field,
    .transaction-search-form .form-control,
    .transaction-search-form .btn {
        width: 100%;
    }
    .transaksi-page .filter-pill {
        min-height: 34px;
        padding: 6px 10px;
        font-size: .72rem;
    }
    .transaksi-page .toolbar-inline-form .btn {
        min-width: 110px;
    }
    .transaksi-page .mobile-data-top {
        align-items: flex-start;
        gap: 8px;
    }
    .transaksi-page .mobile-data-actions {
        display: flex;
        justify-content: flex-start;
        gap: 8px;
    }
    .transaksi-page .mobile-data-actions .btn,
    .transaksi-page .mobile-data-actions form,
    .transaksi-page .mobile-data-actions form .btn {
        width: auto;
        min-width: 116px;
    }
    .transaction-detail-actions .btn {
        flex: 1 1 140px;
        justify-content: center;
    }
    #modalDetail .modal-header {
        padding: 14px 14px 12px;
    }
    #modalDetail .modal-body {
        padding: 14px;
    }
    #modalDetail .modal-close {
        width: 34px;
        height: 34px;
        border-radius: 12px;
    }
}
@media (max-width: 520px) {
    .transaction-status-tabs {
        padding: 10px;
    }
    .transaction-search-form {
        grid-template-columns: 1fr;
    }
    .transaction-search-form .btn,
    .transaction-search-form .btn-outline {
        width: 100%;
    }
    .transaksi-page .badge {
        font-size: .68rem;
        padding: 5px 10px;
    }
    #modalDetail .modal-box {
        max-height: min(86vh, 720px);
        border-top-left-radius: 22px;
        border-top-right-radius: 22px;
    }
    #modalDetail .modal-header h5 {
        font-size: .92rem;
        line-height: 1.35;
    }
    #modalDetail .modal-body {
        padding: 12px;
    }
}
</style>';

require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack transaksi-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-receipt"></i> Manajemen Transaksi</div>
                <h1 class="page-title">Draft, pelunasan, dan invoice dalam satu layar</h1>
                <p class="page-description">
                    Daftar transaksi sekarang menjadi pusat kerja draft invoice, amend item, pelunasan, sampai order bergerak langsung ke produksi.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-filter"></i> Filter aktif: <?= htmlspecialchars($filterLabels[$filter] ?? 'Semua status') ?></span>
                    <span class="page-meta-item"><i class="fas fa-diagram-project"></i> Tahap: <?= htmlspecialchars($workflowFilterLabels[$workflowFilter] ?? 'Semua tahap') ?></span>
                    <span class="page-meta-item"><i class="fas fa-list-ol"></i> <?= number_format($visibleCount) ?> transaksi ditampilkan</span>
                    <span class="page-meta-item"><i class="fas fa-paperclip"></i> <?= number_format($visibleFileCount) ?> lampiran aktif</span>
                    <?php if ($searchQuery !== ''): ?>
                        <span class="page-meta-item"><i class="fas fa-magnifying-glass"></i> Kata kunci: <?= htmlspecialchars($searchQuery) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('pos.php') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Transaksi Baru</a>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <section class="toolbar-surface transaction-status-tabs">
        <div class="filter-pills">
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters('', $workflowFilter, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $filter === '' ? 'active' : '' ?>">
                <span>Semua</span>
                <span class="filter-pill-count"><?= number_format($statusTotals['semua']) ?></span>
            </a>
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters('pending', $workflowFilter, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $filter === 'pending' ? 'active' : '' ?>">
                <span>Pending</span>
                <span class="filter-pill-count"><?= number_format($statusTotals['pending']) ?></span>
            </a>
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters('dp', $workflowFilter, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $filter === 'dp' ? 'active' : '' ?>">
                <span>DP</span>
                <span class="filter-pill-count"><?= number_format($statusTotals['dp']) ?></span>
            </a>
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters('tempo', $workflowFilter, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $filter === 'tempo' ? 'active' : '' ?>">
                <span>Tempo</span>
                <span class="filter-pill-count"><?= number_format($statusTotals['tempo']) ?></span>
            </a>
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters('draft', $workflowFilter, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $filter === 'draft' ? 'active' : '' ?>">
                <span>Draft</span>
                <span class="filter-pill-count"><?= number_format($statusTotals['draft']) ?></span>
            </a>
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters('selesai', $workflowFilter, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $filter === 'selesai' ? 'active' : '' ?>">
                <span>Selesai</span>
                <span class="filter-pill-count"><?= number_format($statusTotals['selesai']) ?></span>
            </a>
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters('batal', $workflowFilter, $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $filter === 'batal' ? 'active' : '' ?>">
                <span>Batal</span>
                <span class="filter-pill-count"><?= number_format($statusTotals['batal']) ?></span>
            </a>
        </div>
    </section>

    <section class="toolbar-surface transaction-filter-panel">
        <div class="transaction-filter-panel-header">
            <div>
                <h2 class="transaction-filter-panel-title">Cari dan sempitkan daftar transaksi</h2>
                <p class="transaction-filter-panel-copy">Gunakan kata kunci dan tahap workflow untuk menemukan draft, invoice pelunasan, atau order produksi lebih cepat tanpa area kosong di halaman.</p>
            </div>
            <div class="transaction-search-meta" id="transactionSearchSummary">
                <strong><?= number_format($visibleCount) ?></strong> transaksi pada halaman ini
            </div>
        </div>
        <div class="page-inline-summary compact-summary-row">
            <div class="page-inline-pill">
                <span>Tampil</span>
                <strong><?= number_format($visibleCount) ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Nilai</span>
                <strong>Rp <?= number_format($visibleTotal, 0, ',', '.') ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Pelunasan</span>
                <strong><?= number_format($visibleActionNeeded) ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Sisa</span>
                <strong>Rp <?= number_format($visibleOutstanding, 0, ',', '.') ?></strong>
            </div>
        </div>
        <form method="GET" class="transaction-search-form">
            <?php if ($filter !== ''): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <label class="sr-only" for="transactionSearchInput">Cari transaksi</label>
            <div class="transaction-search-field">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input
                    type="search"
                    id="transactionSearchInput"
                    name="q"
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    class="form-control"
                    placeholder="Cari nomor transaksi, pelanggan, kasir, invoice, atau status..."
                    data-transaction-search-input
                >
                <button
                    type="button"
                    class="transaction-search-clear"
                    data-transaction-search-clear
                    aria-label="Kosongkan pencarian"
                    <?= $searchQuery === '' ? 'hidden' : '' ?>
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <select name="workflow" class="form-control" aria-label="Filter tahap workflow">
                <?php foreach ($workflowFilterLabels as $workflowValue => $workflowLabel): ?>
                    <option value="<?= htmlspecialchars($workflowValue, ENT_QUOTES, 'UTF-8') ?>" <?= $workflowFilter === $workflowValue ? 'selected' : '' ?>><?= htmlspecialchars($workflowLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
            <a href="<?= htmlspecialchars(transaksiPageUrlWithFilters(), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline">Reset</a>
        </form>
        <div class="transaction-search-meta">
            Pencarian langsung menyaring daftar yang terlihat di layar. Tombol <strong>Terapkan</strong> akan menyimpan pencarian ke URL supaya filter tetap aktif saat halaman dibuka ulang.
        </div>
    </section>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-table"></i> Daftar Transaksi</span>
                <div class="card-subtitle">Status pembayaran sekarang dipakai seperti tab halaman, jadi Anda bisa fokus ke satu jenis transaksi dalam satu tampilan.</div>
            </div>
        </div>

        <?php if (!empty($data)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblTrx">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>#</th>
                            <th>No. Transaksi</th>
                            <th>Pelanggan</th>
                            <th>Catatan Invoice</th>
                            <th>Kasir</th>
                            <th>Total</th>
                            <th>Bayar</th>
                            <th>Sisa / Kembali</th>
                            <th>File</th>
                            <th>Tahap</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($data as $i => $row): ?>
                        <tr>
                            <td><?= !empty($row['created_at']) ? date('d/m/Y H:i', strtotime((string) $row['created_at'])) : '-' ?></td>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <button type="button" class="record-link-button" onclick="lihatDetail(<?= (int) $row['id'] ?>)">
                                    <?= htmlspecialchars((string) ($row['no_transaksi'] ?? '')) ?>
                                </button>
                            </td>
                            <td class="transaction-text-cell"><?= htmlspecialchars((string) ($row['nama_pelanggan'] ?? 'Umum')) ?></td>
                            <td class="transaction-note-cell"><?= htmlspecialchars((string) ($row['catatan_invoice'] ?? '-') ?: '-') ?></td>
                            <td class="transaction-text-cell"><?= htmlspecialchars((string) ($row['nama_kasir'] ?? '-')) ?></td>
                            <td class="rp"><?= number_format((float) ($row['total'] ?? 0), 0, ',', '.') ?></td>
                            <td class="rp"><?= number_format((float) ($row['bayar'] ?? 0), 0, ',', '.') ?></td>
                            <td>
                                <?php if ((float) ($row['remaining_amount'] ?? 0) > 0): ?>
                                    <span class="badge badge-warning">Sisa Rp <?= number_format((float) ($row['remaining_amount'] ?? 0), 0, ',', '.') ?></span>
                                <?php elseif ((float) ($row['kembalian'] ?? 0) > 0): ?>
                                    <span class="badge badge-success">Kembali Rp <?= number_format((float) ($row['kembalian'] ?? 0), 0, ',', '.') ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $row['jml_file'] > 0): ?>
                                    <span class="badge badge-info" title="<?= (int) $row['jml_file'] ?> file terlampir"><i class="fas fa-paperclip"></i> <?= (int) $row['jml_file'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?= htmlspecialchars((string) ($row['workflow_step_badge'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($row['workflow_step_label'] ?? '-')) ?></span></td>
                            <td><span class="badge badge-<?= htmlspecialchars((string) ($row['status_badge_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($row['status_label'] ?? '-')) ?></span></td>
                            <td>
                                <button class="btn btn-info btn-sm" onclick="lihatDetail(<?= (int) $row['id'] ?>)" title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileTrxList">
                <?php foreach ($data as $row): ?>
                    <div class="mobile-data-card mobile-transaction-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title">
                                    <button type="button" class="record-link-button" onclick="lihatDetail(<?= (int) $row['id'] ?>)">
                                        <?= htmlspecialchars((string) ($row['no_transaksi'] ?? '')) ?>
                                    </button>
                                </div>
                                <div class="mobile-data-subtitle"><?= htmlspecialchars((string) ($row['nama_pelanggan'] ?? 'Umum')) ?></div>
                                <?php if (!empty($row['catatan_invoice'])): ?>
                                    <div class="mobile-data-subtitle" style="margin-top:4px;color:var(--text-muted)">
                                        <?= htmlspecialchars((string) $row['catatan_invoice']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="badge badge-<?= htmlspecialchars((string) ($row['status_badge_class'] ?? 'secondary')) ?>"><?= htmlspecialchars((string) ($row['status_label'] ?? '-')) ?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Tanggal</span>
                                <span class="mobile-data-value"><?= !empty($row['created_at']) ? date('d/m/Y H:i', strtotime((string) $row['created_at'])) : '-' ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Tahap</span>
                                <span class="mobile-data-value"><?= htmlspecialchars((string) ($row['workflow_step_label'] ?? '-')) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Total</span>
                                <span class="mobile-data-value rp"><?= number_format((float) ($row['total'] ?? 0), 0, ',', '.') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Sisa</span>
                                <span class="mobile-data-value rp"><?= number_format((float) ($row['remaining_amount'] ?? 0), 0, ',', '.') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Kasir</span>
                                <span class="mobile-data-value"><?= htmlspecialchars((string) ($row['nama_kasir'] ?? '-')) ?></span>
                            </div>
                        </div>
                        <div class="mobile-data-actions">
                            <button class="btn btn-info btn-sm" onclick="lihatDetail(<?= (int) $row['id'] ?>)"><i class="fas fa-eye"></i> Detail</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-state transaction-search-empty" id="transactionSearchEmpty" hidden>
                <i class="fas fa-magnifying-glass"></i>
                <div>Tidak ada transaksi yang cocok dengan kata kunci yang sedang dipakai.</div>
                <p>Coba ubah kata kunci, pilih tahap lain, atau tekan tombol reset untuk menampilkan semua transaksi lagi.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <div>Belum ada transaksi pada filter yang dipilih.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalDetail">
    <div class="modal-box modal-lg" style="max-width: 1040px;">
        <div class="modal-header">
            <h5 id="modalDetailTitle">Detail Transaksi</h5>
            <button class="modal-close" onclick="closeModal('modalDetail')">&times;</button>
        </div>
        <div class="modal-body" id="detailContent"><p class="text-center">Memuat...</p></div>
    </div>
</div>

<?php if ($paymentSupportReady): ?>
<div class="modal-overlay" id="modalPayment">
    <div class="modal-box">
        <div class="modal-header">
            <h5>Pelunasan / Pembayaran Lanjutan</h5>
            <button class="modal-close" onclick="closeModal('modalPayment')">&times;</button>
        </div>
        <form method="POST" id="transactionPaymentForm" enctype="multipart/form-data">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="save_payment">
            <input type="hidden" name="transaksi_id" id="paymentTransaksiId" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Transaksi</label>
                    <input type="text" id="paymentTransactionInfo" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tanggal Bayar</label>
                        <input type="date" name="tanggal_bayar" id="paymentDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metode</label>
                        <select name="metode_bayar" id="paymentMethod" class="form-control">
                            <option value="cash">Tunai</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                            <option value="giro">Giro</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nominal Bayar</label>
                        <input type="number" step="0.01" min="0.01" name="nominal_bayar" id="paymentAmount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Referensi</label>
                        <input type="text" name="referensi_bayar" id="paymentReference" class="form-control" placeholder="Nomor transfer / bukti bayar">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="catatan_bayar" id="paymentNote" class="form-control" placeholder="Contoh: pelunasan down payment invoice">
                </div>
                <div class="form-group">
                    <label class="form-label">Upload Bukti Pembayaran</label>
                    <input type="file" name="bukti_pembayaran" id="paymentProofInput" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                    <div style="margin-top:6px;font-size:.78rem;color:var(--text-muted)">
                        Opsional. Format yang didukung: JPG, PNG, atau PDF. File akan tersimpan di detail transaksi sebagai bukti transfer.
                    </div>
                </div>
                <div class="metric-strip">
                    <div class="metric-card">
                        <span class="metric-label">Grand Total</span>
                        <span class="metric-value" id="paymentGrandTotal">Rp 0</span>
                        <span class="metric-note">Nilai total invoice transaksi.</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Sudah Dibayar</span>
                        <span class="metric-value" id="paymentPaidTotal">Rp 0</span>
                        <span class="metric-note">Akumulasi pembayaran sebelum transaksi ini.</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Sisa Tagihan</span>
                        <span class="metric-value" id="paymentRemaining">Rp 0</span>
                        <span class="metric-note">Nominal maksimal pembayaran saat ini.</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalPayment')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$pageJs = 'transaksi.js';
require_once dirname(__DIR__) . '/layouts/footer.php';
?>
