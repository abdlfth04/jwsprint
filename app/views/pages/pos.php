<?php
ob_start(); // Buffer output agar header() bisa dipanggil kapan saja
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service', 'kasir');
$pageTitle = 'POS System';
transactionWorkflowSupportReady($conn);
transactionPaymentEnsureSupportTables($conn);
transactionOrderEnsureSupport($conn);

function posCustomerSelectSql(mysqli $conn, string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

    return $prefix . 'id, ' . $prefix . 'nama'
        . (schemaColumnExists($conn, 'pelanggan', 'telepon') ? ', ' . $prefix . 'telepon' : ", '' AS telepon")
        . (schemaColumnExists($conn, 'pelanggan', 'email') ? ', ' . $prefix . 'email' : ", '' AS email")
        . (schemaColumnExists($conn, 'pelanggan', 'alamat') ? ', ' . $prefix . 'alamat' : ", '' AS alamat")
        . (schemaColumnExists($conn, 'pelanggan', 'is_mitra') ? ', ' . $prefix . 'is_mitra' : ', 0 AS is_mitra');
}

function posFetchCustomers(mysqli $conn): array
{
    if (!schemaTableExists($conn, 'pelanggan')) {
        return [];
    }

    $result = $conn->query("SELECT " . posCustomerSelectSql($conn) . " FROM pelanggan ORDER BY nama");
    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function posFetchCustomerById(mysqli $conn, int $customerId): ?array
{
    if ($customerId <= 0 || !schemaTableExists($conn, 'pelanggan')) {
        return null;
    }

    $stmt = $conn->prepare("SELECT " . posCustomerSelectSql($conn) . " FROM pelanggan WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $customer;
}

if (($_GET['ajax'] ?? '') === 'pelanggan_catalog') {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'customers' => posFetchCustomers($conn),
    ]);
    exit;
}

// AJAX checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Tambah pelanggan baru dari POS
    if ($_POST['action'] === 'tambah_pelanggan') {
        ob_clean();
        header('Content-Type: application/json');
        $nama    = trim($_POST['nama'] ?? '');
        $telepon = trim($_POST['telepon'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $alamat  = trim($_POST['alamat'] ?? '');
        $mitra   = intval($_POST['is_mitra'] ?? 0);
        if (!$nama) { echo json_encode(['success'=>false,'msg'=>'Nama wajib diisi']); exit; }
        if (!customerSupportReady($conn)) {
            echo json_encode(['success' => false, 'msg' => 'Data pelanggan belum siap di database.']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO pelanggan (nama,telepon,email,alamat,is_mitra) VALUES (?,?,?,?,?)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'msg' => 'Form pelanggan belum bisa disimpan saat ini.']);
            exit;
        }
        $stmt->bind_param('ssssi', $nama,$telepon,$email,$alamat,$mitra);
        if ($stmt->execute()) {
            $customerId = (int) $conn->insert_id;
            $customer = posFetchCustomerById($conn, $customerId) ?? [
                'id' => $customerId,
                'nama' => $nama,
                'telepon' => $telepon,
                'email' => $email,
                'alamat' => $alamat,
                'is_mitra' => $mitra,
            ];

            echo json_encode([
                'success' => true,
                'id' => $customerId,
                'nama' => (string) ($customer['nama'] ?? $nama),
                'is_mitra' => (int) ($customer['is_mitra'] ?? $mitra),
                'customer' => $customer,
                'customers' => posFetchCustomers($conn),
            ]);
        } else {
            $dbMessage = trim((string) ($stmt->error ?: $conn->error));
            echo json_encode([
                'success' => false,
                'msg' => APP_DEBUG && $dbMessage !== ''
                    ? 'Gagal menyimpan pelanggan: ' . $dbMessage
                    : 'Gagal menyimpan pelanggan.',
            ]);
        }
        $stmt->close();
        exit;
    }

    if ($_POST['action'] === 'checkout') {
    ob_clean();
    header('Content-Type: application/json');

    set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    $transactionStarted = false;
    $paymentProofUpload = [
        'attempted' => false,
        'success' => false,
        'files' => [],
        'errors' => [],
        'message' => '',
    ];

    try {
        $itemsInput = json_decode((string) ($_POST['items'] ?? '[]'), true);
        $normalizedCart = transactionOrderNormalizeCartItems(is_array($itemsInput) ? $itemsInput : []);
        $items = $normalizedCart['items'];
        $workflowAction = trim((string) ($_POST['workflow_action'] ?? 'payment'));
        $isDraftSave = $workflowAction === 'draft';
        $appendToTransactionId = max(0, intval($_POST['append_to_transaction_id'] ?? 0));
        $isAppending = $appendToTransactionId > 0;
        $pelId = intval($_POST['pelanggan_id'] ?? 0) ?: null;
        $diskon = max(0, floatval($_POST['diskon'] ?? 0));
        $pajak = max(0, floatval($_POST['pajak'] ?? 0));
        $pajakAktif = intval($_POST['pajak_aktif'] ?? 0);
        $metodeBayar = strtolower(trim((string) ($_POST['metode_bayar'] ?? 'cash')));
        $bayar = max(0, floatval($_POST['bayar'] ?? 0));
        $dpAmount = max(0, floatval($_POST['dp_amount'] ?? 0));
        $tempoDays = intval($_POST['tempo_days'] ?? 30);
        $catatan = trim((string) ($_POST['catatan'] ?? ''));
        $invoiceNote = transactionOrderSanitizeInvoiceNote((string) ($_POST['invoice_note'] ?? ''));
        $paymentReference = trim((string) ($_POST['referensi_bayar'] ?? ''));
        if ($isDraftSave && !hasRole('superadmin', 'admin', 'service', 'kasir')) {
            throw new RuntimeException('Simpan draft hanya dapat diproses oleh admin, customer service, atau kasir.');
        }
        if (!$isDraftSave && !hasRole('superadmin', 'admin', 'kasir')) {
            throw new RuntimeException('Pembayaran POS hanya dapat diproses oleh admin atau kasir.');
        }

        $allowedMethods = $isAppending
            ? ['cash', 'transfer', 'qris']
            : ['cash', 'transfer', 'qris', 'downpayment', 'tempo'];
        if (!$isDraftSave && !in_array($metodeBayar, $allowedMethods, true)) {
            throw new RuntimeException('Metode pembayaran tidak valid.');
        }

        if ($pajakAktif === 0) {
            $pajak = 0;
        }

        $grandTotal = round((float) ($normalizedCart['subtotal'] ?? 0) - $diskon + $pajak, 2);
        if ($grandTotal <= 0) {
            throw new RuntimeException('Total transaksi harus lebih besar dari nol.');
        }

        if (!$isAppending && !$isDraftSave && $metodeBayar === 'tempo') {
            if (!$pelId) {
                throw new RuntimeException('Pembayaran tempo hanya untuk pelanggan terdaftar.');
            }

            $stmtMitra = $conn->prepare("SELECT is_mitra FROM pelanggan WHERE id = ? LIMIT 1");
            if (!$stmtMitra) {
                throw new RuntimeException('Pelanggan tidak dapat diverifikasi saat ini.');
            }

            $stmtMitra->bind_param('i', $pelId);
            $stmtMitra->execute();
            $mitra = $stmtMitra->get_result()->fetch_assoc();
            $stmtMitra->close();

            if (!$mitra || empty($mitra['is_mitra'])) {
                throw new RuntimeException('Pembayaran tempo hanya untuk pelanggan mitra.');
            }
            if ($tempoDays < 1 || $tempoDays > 90) {
                throw new RuntimeException('Maksimal tempo 3 bulan (1-90 hari).');
            }
        }

        $kembalian = 0;
        $sisaBayar = 0;
        $statusTrx = 'selesai';
        $tempoDt = null;
        $workflowStep = 'cashier';

        if ($isDraftSave) {
            $bayar = 0;
            $dpAmount = 0;
            $kembalian = 0;
            $sisaBayar = $grandTotal;
            $statusTrx = 'draft';
            $metodeBayar = 'draft';
            $workflowStep = 'draft';
        } elseif (in_array($metodeBayar, ['cash', 'transfer', 'qris'], true)) {
            if ($bayar + 0.000001 < $grandTotal) {
                throw new RuntimeException('Nominal pembayaran kurang dari total transaksi.');
            }
            $dpAmount = 0;
            $kembalian = round($bayar - $grandTotal, 2);
            $workflowStep = 'production';
        } elseif ($metodeBayar === 'downpayment') {
            if ($dpAmount <= 0) {
                throw new RuntimeException('Nominal down payment harus lebih besar dari nol.');
            }
            if ($dpAmount + 0.000001 >= $grandTotal) {
                throw new RuntimeException('Gunakan metode pembayaran lunas jika nominal DP sama atau melebihi total transaksi.');
            }
            $sisaBayar = round($grandTotal - $dpAmount, 2);
            $bayar = $dpAmount;
            $statusTrx = 'dp';
        } elseif ($metodeBayar === 'tempo') {
            $bayar = 0;
            $dpAmount = 0;
            $sisaBayar = $grandTotal;
            $statusTrx = 'tempo';
            $tempoDt = date('Y-m-d', strtotime("+{$tempoDays} days"));
        }

        $noTrx  = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $userId = intval($_SESSION['user_id']);

        $stmtUser = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        if (!$stmtUser) {
            throw new RuntimeException('Sesi tidak valid, silakan login ulang.');
        }

        $stmtUser->bind_param('i', $userId);
        $stmtUser->execute();
        $checkUser = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();

        if (!$checkUser) {
            throw new RuntimeException('Sesi tidak valid, silakan login ulang.');
        }

        if (!transactionPaymentEnsureSupportTables($conn)) {
            throw new RuntimeException('Fitur pembayaran transaksi belum siap di database.');
        }
        if (!transactionOrderEnsureSupport($conn)) {
            throw new RuntimeException('Fitur catatan invoice belum siap di database.');
        }

        $conn->begin_transaction();
        $transactionStarted = true;

        // Cek kolom yang tersedia di tabel transaksi
        $trxCols = transactionOrderTransaksiColumns($conn);
        $hasPajak    = in_array('pajak', $trxCols);
        $hasMetode   = in_array('metode_bayar', $trxCols);
        $hasDp       = in_array('dp_amount', $trxCols);
        $hasSisa     = in_array('sisa_bayar', $trxCols);
        $hasTempo    = in_array('tempo_tgl', $trxCols);
        $hasWorkflow = in_array('workflow_step', $trxCols);
        $hasInvoiceNote = in_array('catatan_invoice', $trxCols);

        $responseTotal = $grandTotal;
        $responseBayar = $bayar;
        $invoiceSummaryCustomerId = (int) ($pelId ?? 0);
        $responseMethod = $metodeBayar;

        if ($isAppending) {
            $appendFields = ['id', 'no_transaksi', 'pelanggan_id', 'total', 'diskon', 'bayar', 'kembalian', 'status', 'catatan'];
            if ($hasPajak) { $appendFields[] = 'pajak'; }
            if ($hasMetode) { $appendFields[] = 'metode_bayar'; }
            if ($hasDp) { $appendFields[] = 'dp_amount'; }
            if ($hasSisa) { $appendFields[] = 'sisa_bayar'; }
            if ($hasTempo) { $appendFields[] = 'tempo_tgl'; }
            if ($hasWorkflow) { $appendFields[] = 'workflow_step'; }
            if ($hasInvoiceNote) { $appendFields[] = 'catatan_invoice'; }

            $stmtAppend = $conn->prepare(
                "SELECT " . implode(',', $appendFields) . "
                 FROM transaksi
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$stmtAppend) {
                throw new RuntimeException('Invoice tujuan tidak dapat dibaca.');
            }

            $stmtAppend->bind_param('i', $appendToTransactionId);
            $stmtAppend->execute();
            $appendTrx = $stmtAppend->get_result()->fetch_assoc();
            $stmtAppend->close();

            if (!$appendTrx) {
                throw new RuntimeException('Invoice tujuan tidak ditemukan.');
            }
            if (strtolower(trim((string) ($appendTrx['status'] ?? ''))) === 'batal') {
                throw new RuntimeException('Invoice yang dibatalkan tidak dapat ditambah item lagi.');
            }
            if (!transactionOrderCanAmendTransaction($appendTrx)) {
                throw new RuntimeException('Amend invoice hanya tersedia untuk transaksi yang belum menerima pembayaran.');
            }

            $existingCustomerId = (int) ($appendTrx['pelanggan_id'] ?? 0);
            if ($existingCustomerId > 0 && $pelId && $existingCustomerId !== (int) $pelId) {
                throw new RuntimeException('Customer pada invoice lama berbeda. Pilih invoice dengan customer yang sama.');
            }
            if ($existingCustomerId > 0) {
                $pelId = $existingCustomerId;
            }

            transactionOrderInsertItems($conn, $appendToTransactionId, $items, $userId, false);

            $existingPaid = transactionPaymentResolvePaidTotal($appendTrx);
            $responseTotal = round((float) ($appendTrx['total'] ?? 0) + $grandTotal, 2);
            $newDiskon = round((float) ($appendTrx['diskon'] ?? 0) + $diskon, 2);
            $newPajak = $hasPajak ? round((float) ($appendTrx['pajak'] ?? 0) + $pajak, 2) : 0;
            $paymentNominal = 0.0;
            $remainingAfterAppend = round(max(0, $responseTotal - $existingPaid), 2);
            $metodeTransaksi = $isDraftSave ? 'draft' : (string) ($appendTrx['metode_bayar'] ?? '');
            $responseBayar = $existingPaid;
            $kembalian = $remainingAfterAppend > 0 ? 0 : (float) ($appendTrx['kembalian'] ?? 0);

            if (!$isDraftSave) {
                if ($bayar + 0.000001 < $remainingAfterAppend) {
                    throw new RuntimeException('Nominal pembayaran tambahan kurang dari sisa tagihan invoice.');
                }

                $paymentNominal = $remainingAfterAppend;
                if ($paymentNominal > 0.000001) {
                    transactionPaymentCreateRecord($conn, [
                        'transaksi_id' => $appendToTransactionId,
                        'tanggal' => date('Y-m-d'),
                        'nominal' => $paymentNominal,
                        'metode' => $metodeBayar,
                        'referensi' => $paymentReference,
                        'catatan' => $catatan !== '' ? $catatan : 'Pembayaran tambahan dari menu POS',
                        'created_by' => $userId,
                    ]);
                }

                $responseBayar = round($existingPaid + $paymentNominal, 2);
                $kembalian = round(max(0, $bayar - $paymentNominal), 2);
                $metodeTransaksi = $metodeBayar;
            }

            $sisaBayar = round(max(0, $responseTotal - $responseBayar), 2);
            $statusTrx = transactionOrderResolveStatusFromTotals(
                (string) ($appendTrx['status'] ?? ''),
                $metodeTransaksi,
                $responseBayar,
                $sisaBayar
            );
            $workflowStep = $statusTrx === 'draft'
                ? 'draft'
                : ($sisaBayar > 0.000001 ? 'cashier' : 'production');
            $tempoDt = $statusTrx === 'tempo' ? (($appendTrx['tempo_tgl'] ?? null) ?: null) : null;
            $responseMethod = $metodeTransaksi;

            $updateFields = [
                'pelanggan_id = NULLIF(?, 0)',
                'total = ?',
                'diskon = ?',
                'bayar = ?',
                'kembalian = ?',
                'status = ?'
            ];
            $updateValues = [(int) ($pelId ?? 0), $responseTotal, $newDiskon, $responseBayar, $kembalian, $statusTrx];
            $updateTypes = 'idddds';

            if ($hasPajak) { $updateFields[] = 'pajak = ?'; $updateValues[] = $newPajak; $updateTypes .= 'd'; }
            if ($hasMetode) { $updateFields[] = 'metode_bayar = ?'; $updateValues[] = $metodeTransaksi; $updateTypes .= 's'; }
            if ($hasDp) {
                $updateFields[] = 'dp_amount = ?';
                $updateValues[] = $sisaBayar > 0.000001 ? min($responseBayar, $responseTotal) : 0;
                $updateTypes .= 'd';
            }
            if ($hasSisa) { $updateFields[] = 'sisa_bayar = ?'; $updateValues[] = $sisaBayar; $updateTypes .= 'd'; }
            if ($hasTempo) { $updateFields[] = 'tempo_tgl = ?'; $updateValues[] = $tempoDt; $updateTypes .= 's'; }
            if ($hasWorkflow) { $updateFields[] = 'workflow_step = ?'; $updateValues[] = $workflowStep; $updateTypes .= 's'; }
            if ($hasInvoiceNote) { $updateFields[] = 'catatan_invoice = ?'; $updateValues[] = $invoiceNote; $updateTypes .= 's'; }

            $updateValues[] = $appendToTransactionId;
            $updateTypes .= 'i';
            $stmtUpdate = $conn->prepare(
                "UPDATE transaksi SET " . implode(', ', $updateFields) . " WHERE id = ?"
            );
            if (!$stmtUpdate) {
                throw new RuntimeException('Invoice lama tidak dapat diperbarui.');
            }

            $stmtUpdate->bind_param($updateTypes, ...$updateValues);
            if (!$stmtUpdate->execute()) {
                $stmtUpdate->close();
                throw new RuntimeException('Gagal menambahkan item ke invoice yang dipilih.');
            }
            $stmtUpdate->close();

            $trxId = $appendToTransactionId;
            $noTrx = (string) ($appendTrx['no_transaksi'] ?? '');
            $invoiceSummaryCustomerId = (int) ($pelId ?? 0);
        } else {
            $fields = ['no_transaksi','pelanggan_id','user_id','total','diskon','bayar','kembalian','status','catatan'];
            $vals   = [$noTrx, (int) ($pelId ?? 0), $userId, $grandTotal, $diskon, $bayar, $kembalian, $statusTrx, $catatan];
            $types  = 'siiddddss';
            $placeholders = ['?', 'NULLIF(?, 0)', '?', '?', '?', '?', '?', '?', '?'];

            if ($hasPajak)  { $fields[] = 'pajak';        $vals[] = $pajak;       $types .= 'd'; }
            if ($hasMetode) { $fields[] = 'metode_bayar'; $vals[] = $metodeBayar; $types .= 's'; }
            if ($hasDp)     { $fields[] = 'dp_amount';    $vals[] = $dpAmount;    $types .= 'd'; }
            if ($hasSisa)   { $fields[] = 'sisa_bayar';   $vals[] = $sisaBayar;   $types .= 'd'; }
            if ($hasTempo)  { $fields[] = 'tempo_tgl';    $vals[] = $tempoDt;     $types .= 's'; }
            if ($hasWorkflow) { $fields[] = 'workflow_step'; $vals[] = $workflowStep; $types .= 's'; }
            if ($hasInvoiceNote) { $fields[] = 'catatan_invoice'; $vals[] = $invoiceNote; $types .= 's'; }
            while (count($placeholders) < count($fields)) {
                $placeholders[] = '?';
            }

            $placeholderSql = implode(',', $placeholders);
            $sql = "INSERT INTO transaksi (" . implode(',', $fields) . ") VALUES ($placeholderSql)";
            $st  = $conn->prepare($sql);
            if (!$st) {
                throw new RuntimeException('Transaksi baru tidak dapat disimpan.');
            }
            $st->bind_param($types, ...$vals);
            if (!$st->execute()) {
                $st->close();
                throw new RuntimeException('Gagal menyimpan transaksi baru.');
            }
            $trxId = $conn->insert_id;
            $st->close();

            transactionOrderInsertItems($conn, $trxId, $items, $userId, false);

            $initialPaymentNominal = 0.0;
            if (!$isDraftSave) {
                if (in_array($metodeBayar, ['cash', 'transfer', 'qris'], true)) {
                    $initialPaymentNominal = $grandTotal;
                } elseif ($metodeBayar === 'downpayment') {
                    $initialPaymentNominal = $dpAmount;
                }
            }

            if ($initialPaymentNominal > 0.000001) {
                transactionPaymentCreateRecord($conn, [
                    'transaksi_id' => $trxId,
                    'tanggal' => date('Y-m-d'),
                    'nominal' => $initialPaymentNominal,
                    'metode' => $metodeBayar,
                    'referensi' => $paymentReference,
                    'catatan' => $catatan !== '' ? $catatan : ($metodeBayar === 'downpayment' ? 'Pembayaran awal / DP' : 'Pembayaran awal'),
                    'created_by' => $userId,
                ]);
            }

            $responseBayar = $bayar;
            $invoiceSummaryCustomerId = (int) ($pelId ?? 0);
        }

        transactionOrderSyncProductionJobsByPaymentState($conn, (int) $trxId, $userId);
        transactionWorkflowRepairStoredSteps($conn);
        $updatedTrx = transactionOrderFetchTransactionHeader($conn, (int) $trxId);
        if ($updatedTrx) {
            $responseTotal = (float) ($updatedTrx['total'] ?? $responseTotal);
            $responseBayar = transactionPaymentResolvePaidTotal($updatedTrx);
            $sisaBayar = transactionPaymentResolveRemaining($updatedTrx);
            $kembalian = (float) ($updatedTrx['kembalian'] ?? $kembalian);
            $statusTrx = (string) ($updatedTrx['status'] ?? $statusTrx);
            $responseMethod = (string) ($updatedTrx['metode_bayar'] ?? $responseMethod);
            $workflowStep = transactionWorkflowResolveStep($updatedTrx);
        }

        $conn->commit();
        $transactionStarted = false;

        if (!$isDraftSave && $metodeBayar !== 'tempo') {
            $paymentProofUpload = transactionPaymentStoreProofUpload(
                $conn,
                (int) $trxId,
                $userId,
                'bukti_pembayaran'
            );
        }

        if (!$isAppending && !$isDraftSave && $pelId) {
            $pel = $conn->query("SELECT nama, telepon FROM pelanggan WHERE id = " . (int) $pelId)->fetch_assoc();
            if ($pel && !empty($pel['telepon'])) {
                $whatsAppServicePath = dirname(__DIR__, 2) . '/services/whatsapp_service.php';
                if (is_file($whatsAppServicePath)) {
                    require_once $whatsAppServicePath;
                    if (function_exists('sendWaNotificationNewOrder')) {
                        sendWaNotificationNewOrder($pel['telepon'], $pel['nama'], $noTrx, $grandTotal, $statusTrx);
                    }
                }
            }
        }

        $paidForDisplay = min($responseTotal, max(0, $responseBayar));

        echo json_encode([
            'success' => true,
            'transaction_id' => $trxId,
            'no_transaksi' => $noTrx,
            'total' => $responseTotal,
            'bayar' => $paidForDisplay,
            'kembalian' => $kembalian,
            'sisa_bayar' => $sisaBayar,
            'metode' => $responseMethod,
            'workflow_step' => $workflowStep,
            'mode' => $isDraftSave ? 'draft' : 'payment',
            'transaction_mode' => $isAppending ? 'append' : 'create',
            'payment_proof' => $paymentProofUpload,
            'invoice_summary' => [
                'id' => $trxId,
                'no_transaksi' => $noTrx,
                'pelanggan_id' => $invoiceSummaryCustomerId,
                'total' => $responseTotal,
                'bayar' => $paidForDisplay,
                'sisa_bayar' => $sisaBayar,
                'status' => $statusTrx,
                'status_label' => transactionPaymentStatusLabel(['status' => $statusTrx, 'bayar' => $paidForDisplay]),
                'workflow_step' => $workflowStep,
                'workflow_label' => transactionWorkflowLabel($workflowStep),
                'catatan_invoice' => $invoiceNote,
            ],
        ]);
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : (APP_DEBUG
                ? $e->getMessage() . ' (line ' . $e->getLine() . ')'
                : 'Checkout gagal diproses. Silakan periksa input lalu coba lagi.');

        echo json_encode(['success'=>false,'msg'=>$message]);
    } finally {
        restore_error_handler();
    }
    exit;
} // end if action=checkout
} // end if POST && action

$produkAll    = $conn->query("SELECT p.*, k.nama as kat_nama, k.tipe as kat_tipe FROM produk p LEFT JOIN kategori k ON p.kategori_id=k.id ORDER BY k.tipe, p.nama")->fetch_all(MYSQLI_ASSOC);

// Fetch harga grosir for all products to prevent PHP notices
$grosirPrices = [];
$tblGrosirExists = schemaTableExists($conn, 'produk_harga_grosir');
if ($tblGrosirExists && !empty($produkAll)) {
    $allProdukIds = array_column($produkAll, 'id');
    $ids = implode(',', array_map('intval', $allProdukIds));
    $resGrosir = $conn->query("SELECT * FROM produk_harga_grosir WHERE produk_id IN ($ids) ORDER BY min_qty ASC");
    if ($resGrosir) {
        while ($row = $resGrosir->fetch_assoc()) {
            $grosirPrices[$row['produk_id']][] = $row;
        }
    }
}

// Inject grosir prices into produk array
foreach ($produkAll as &$p) {
    $p['grosir_tiers'] = $grosirPrices[$p['id']] ?? [];
}
unset($p);

$pelanggan    = posFetchCustomers($conn);
$appendTransactionId = max(0, (int) ($_GET['append_to'] ?? 0));
$recentInvoices = $appendTransactionId > 0 ? transactionOrderFetchRecentInvoices($conn, 160) : [];
$selectedAppendInvoice = null;
if ($appendTransactionId > 0) {
    foreach ($recentInvoices as $invoiceRow) {
        if ((int) ($invoiceRow['id'] ?? 0) !== $appendTransactionId) {
            continue;
        }

        if (!transactionOrderCanAmendTransaction($invoiceRow)) {
            break;
        }

        $selectedAppendInvoice = $invoiceRow;
        break;
    }

    if (!$selectedAppendInvoice) {
        $appendTransactionId = 0;
    }
}
$finPrinting  = ($r = $conn->query("SELECT * FROM finishing_printing ORDER BY nama")) ? $r->fetch_all(MYSQLI_ASSOC) : [];
$finApparel   = ($r = $conn->query("SELECT * FROM finishing_apparel ORDER BY nama"))  ? $r->fetch_all(MYSQLI_ASSOC) : [];
$bahanApparel = ($r = $conn->query("SELECT * FROM bahan_apparel ORDER BY nama"))      ? $r->fetch_all(MYSQLI_ASSOC) : [];

// Setting pajak, defensive jika kolom belum ada
$pajakAktif  = 0; $pajakPersen = 11; $pajakNama = 'PPN';
$resSetting  = $conn->query("SELECT * FROM setting WHERE id=1");
if ($resSetting) {
    $setting = $resSetting->fetch_assoc();
    if ($setting) {
        $pajakAktif  = !empty($setting['pajak_aktif']) ? 1 : 0;
        $pajakPersen = floatval($setting['pajak_persen'] ?? 11);
        $pajakNama   = htmlspecialchars($setting['pajak_nama'] ?? 'PPN');
    }
}

$produkPrintingCount = count(array_filter($produkAll, static fn($item) => ($item['kat_tipe'] ?? '') === 'printing'));
$produkApparelCount = count(array_filter($produkAll, static fn($item) => ($item['kat_tipe'] ?? '') === 'apparel'));
$produkLainnya = array_values(array_filter($produkAll, static fn($item) => !in_array($item['kat_tipe'] ?? '', ['printing', 'apparel'], true)));
$produkLainnyaCount = count($produkLainnya);

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/pos.css') . '">
<style>
.p-grosir-info { font-size: .7rem; color: var(--success); margin-top: 4px; line-height: 1.3; }
.size-section-title {
    margin-bottom: 8px !important;
}
.size-section-title span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.size-grid-note {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin-bottom: 14px;
    line-height: 1.5;
}
.size-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(68px, 1fr));
    gap: 10px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 2px 2px 10px;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent;
}
.size-grid::-webkit-scrollbar {
    height: 6px;
}
.size-grid::-webkit-scrollbar-track {
    background: transparent;
}
.size-grid::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
}
.invoice-context-box {
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: rgba(15, 23, 42, 0.04);
    font-size: 0.82rem;
    color: var(--text-muted);
    line-height: 1.55;
}
.invoice-context-box strong {
    color: #111827;
}
.size-header {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 68px;
    padding: 10px 6px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    font-size: .82rem;
    font-weight: 800;
    text-align: center;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.size-input-cell {
    min-width: 68px;
}
.size-qty-input {
    border: 1px solid #d1d5db !important;
    background: #ffffff !important;
    text-align: center;
    font-size: 1rem;
    font-weight: 700;
    padding: 10px 8px;
    width: 100%;
    height: 44px;
    -moz-appearance: textfield;
    color: #111827;
    border-radius: 12px;
    transition: var(--transition);
}
.size-qty-input::-webkit-outer-spin-button, .size-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.size-qty-input:focus {
    box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.08);
    outline: none;
    border-color: #111827 !important;
}
.size-qty-input::placeholder { color: #d1d5db; font-weight: 400; }
@media (max-width: 768px) {
    .size-grid {
        grid-template-columns: repeat(8, minmax(60px, 1fr));
        gap: 8px;
        padding-bottom: 12px;
    }
    .size-header,
    .size-input-cell {
        min-width: 60px;
    }
}

/* Override default button colors to match monochrome theme */
.btn-primary { background: #111827 !important; border-color: #111827 !important; color: #fff !important; }
.btn-primary:hover { background: #374151 !important; border-color: #374151 !important; }
.text-primary { color: #111827 !important; }
</style>';
$pageState = [
    'posState' => [
        'pajakPersen' => $pajakPersen,
        'endpoint' => pageUrl('pos.php'),
        'customerCatalogEndpoint' => pageUrl('pos.php?ajax=pelanggan_catalog'),
        'userRole' => (string) ($_SESSION['role'] ?? ''),
        'appendTransactionId' => $appendTransactionId,
    ],
];
$pageJs   = 'pos.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<div class="page-stack pos-page">
    <div class="pos-layout">
<div class="pos-products">
    <section class="toolbar-surface pos-catalog-shell">
        <div class="pos-catalog-head">
            <div class="pos-catalog-copy">
                <span class="pos-catalog-kicker"><i class="fas fa-grid-2"></i> Katalog Produk</span>
                <h2>Pilih produk</h2>
            </div>
            <button type="button" class="btn btn-sm btn-outline pos-catalog-custom-btn" onclick="openCustomProductModal()"><i class="fas fa-pen-ruler"></i> Custom</button>
        </div>

        <div class="pos-toolbar">
            <div class="pos-toolbar-controls">
                <input type="text" id="searchProduk" class="form-control" placeholder="Cari nama produk..." oninput="filterProduk()">
                <div class="pos-filter-group">
                    <button type="button" class="btn btn-sm btn-primary" id="btnAll" onclick="setTipeFilter('')">Semua</button>
                    <button type="button" class="btn btn-sm btn-outline" id="btnPrinting" onclick="setTipeFilter('printing')"><i class="fas fa-print"></i> Printing</button>
                    <button type="button" class="btn btn-sm btn-outline" id="btnApparel" onclick="setTipeFilter('apparel')"><i class="fas fa-shirt"></i> Apparel</button>
                    <?php if ($produkLainnyaCount > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline" id="btnLainnya" onclick="setTipeFilter('lainnya')"><i class="fas fa-box-open"></i> Lainnya</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="pos-catalog-grid">
            <div id="sectionPrinting" class="pos-section">
                <div class="pos-section-head">
                    <div>
                        <div class="pos-section-kicker">Kategori</div>
                        <h3><i class="fas fa-print"></i> Printing</h3>
                    </div>
                    <span class="badge badge-secondary"><?= number_format($produkPrintingCount) ?></span>
                </div>
                <div class="product-grid" id="gridPrinting">
                    <?php foreach ($produkAll as $p):
                        $tipe = $p['kat_tipe'] ?? '';
                        if ($tipe !== 'printing') continue; ?>
                    <div class="product-card" data-id="<?= $p['id'] ?>" data-nama="<?= htmlspecialchars($p['nama'],ENT_QUOTES) ?>"
                         data-search="<?= htmlspecialchars(strtolower(trim(($p['nama'] ?? '') . ' ' . ($p['kat_nama'] ?? '') . ' ' . ($p['satuan'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                         data-harga="<?= $p['harga_jual'] ?>" data-stok="<?= $p['stok'] ?>" data-tipe="printing" data-satuan="<?= $p['satuan'] ?>"
                         data-grosir-tiers='<?= htmlspecialchars(json_encode($p['grosir_tiers']), ENT_QUOTES, 'UTF-8') ?>'
                         onclick="pilihProduk(this)">
                        <div class="product-card-top">
                            <span class="product-icon product-icon-printing"><i class="fas fa-print"></i></span>
                            <span class="product-stock-chip <?= (float) $p['stok'] > 0 ? 'in-stock' : 'out-stock' ?>">
                                <?= (float) $p['stok'] > 0 ? 'Ready' : 'Kosong' ?>
                            </span>
                        </div>
                        <div class="p-name"><?= htmlspecialchars($p['nama']) ?></div>
                        <div class="p-price">Rp <?= number_format($p['harga_jual'],0,',','.') ?> / <?= htmlspecialchars($p['satuan']) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($produkPrintingCount === 0): ?>
                        <p class="text-muted small" style="padding:12px">Belum ada produk printing.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="sectionApparel" class="pos-section">
                <div class="pos-section-head pos-section-head-alt">
                    <div>
                        <div class="pos-section-kicker">Kategori</div>
                        <h3><i class="fas fa-shirt"></i> Apparel</h3>
                    </div>
                    <span class="badge badge-secondary"><?= number_format($produkApparelCount) ?></span>
                </div>
                <div class="product-grid" id="gridApparel">
                    <?php foreach ($produkAll as $p):
                        $tipe = $p['kat_tipe'] ?? '';
                        if ($tipe !== 'apparel') continue; ?>
                    <div class="product-card" data-id="<?= $p['id'] ?>" data-nama="<?= htmlspecialchars($p['nama'],ENT_QUOTES) ?>"
                         data-search="<?= htmlspecialchars(strtolower(trim(($p['nama'] ?? '') . ' ' . ($p['kat_nama'] ?? '') . ' apparel pcs')) , ENT_QUOTES, 'UTF-8') ?>"
                         data-harga="<?= $p['harga_jual'] ?>" data-stok="<?= $p['stok'] ?>" data-tipe="apparel" data-satuan="pcs"
                         data-grosir-tiers='<?= htmlspecialchars(json_encode($p['grosir_tiers']), ENT_QUOTES, 'UTF-8') ?>'
                         onclick="pilihProduk(this)">
                        <div class="product-card-top">
                            <span class="product-icon product-icon-apparel"><i class="fas fa-shirt"></i></span>
                            <span class="product-stock-chip <?= (float) $p['stok'] > 0 ? 'in-stock' : 'out-stock' ?>">
                                <?= (float) $p['stok'] > 0 ? 'Ready' : 'Kosong' ?>
                            </span>
                        </div>
                        <div class="p-name"><?= htmlspecialchars($p['nama']) ?></div>
                        <div class="p-price">Rp <?= number_format($p['harga_jual'],0,',','.') ?> / pcs</div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($produkApparelCount === 0): ?>
                        <p class="text-muted small" style="padding:12px">Belum ada produk apparel.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($produkLainnyaCount > 0): ?>
            <div id="sectionLainnya" class="pos-section pos-section-wide">
                <div class="pos-section-head pos-section-head-muted">
                    <div>
                        <div class="pos-section-kicker">Kategori</div>
                        <h3><i class="fas fa-box"></i> Lainnya</h3>
                    </div>
                    <span class="badge badge-secondary"><?= number_format($produkLainnyaCount) ?></span>
                </div>
                <div class="product-grid" id="gridLainnya">
                    <?php foreach ($produkLainnya as $p): ?>
                    <div class="product-card" data-id="<?= $p['id'] ?>" data-nama="<?= htmlspecialchars($p['nama'],ENT_QUOTES) ?>"
                         data-search="<?= htmlspecialchars(strtolower(trim(($p['nama'] ?? '') . ' ' . ($p['kat_nama'] ?? '') . ' ' . ($p['satuan'] ?: 'pcs'))), ENT_QUOTES, 'UTF-8') ?>"
                         data-harga="<?= $p['harga_jual'] ?>" data-stok="<?= $p['stok'] ?>" data-tipe="lainnya" data-satuan="<?= $p['satuan'] ?: 'pcs' ?>"
                         data-grosir-tiers='<?= htmlspecialchars(json_encode($p['grosir_tiers']), ENT_QUOTES, 'UTF-8') ?>'
                         onclick="pilihProdukLainnya(this)">
                        <div class="product-card-top">
                            <span class="product-icon product-icon-other"><i class="fas fa-box-open"></i></span>
                            <span class="product-stock-chip <?= (float) $p['stok'] > 0 ? 'in-stock' : 'out-stock' ?>">
                                <?= (float) $p['stok'] > 0 ? 'Ready' : 'Kosong' ?>
                            </span>
                        </div>
                        <div class="p-name"><?= htmlspecialchars($p['nama']) ?></div>
                        <div class="p-price">Rp <?= number_format($p['harga_jual'],0,',','.') ?> / <?= htmlspecialchars($p['satuan'] ?: 'pcs') ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="empty-state" id="posSearchEmpty" hidden>
            <i class="fas fa-search"></i>
            <div>Tidak ada produk yang cocok.</div>
        </div>
    </section>
</div>

<div class="pos-cart" id="posCart">
    <div class="cart-header">
        <div class="cart-heading">
            <div class="cart-title">Keranjang</div>
            <div class="cart-subtitle">Ringkasan transaksi aktif.</div>
        </div>
        <div class="cart-setup-grid<?= ($appendTransactionId > 0 && $selectedAppendInvoice) ? ' has-append-invoice' : '' ?>">
            <div class="cart-config-card compact-customer-card">
                <div class="cart-config-head">
                    <div class="cart-config-copy">
                        <span class="cart-config-kicker">Pelanggan</span>
                        <strong>Pilih pelanggan</strong>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm customer-picker-add-btn cart-config-add-btn" onclick="openModal('modalTambahPelanggan');preparePosCustomerModal()" title="Tambah Pelanggan Baru">
                        <i class="fas fa-user-plus"></i>
                        <span>Tambah</span>
                    </button>
                </div>
                <div class="customer-picker">
                    <div class="customer-picker-search-row">
                        <div class="customer-picker-field">
                            <span class="customer-picker-icon"><i class="fas fa-search"></i></span>
                            <input
                                type="text"
                                class="form-control"
                                id="pelangganSearch"
                                placeholder="Cari pelanggan..."
                                oninput="filterPelangganOptions()"
                                autocomplete="off"
                            >
                        </div>
                    </div>
                    <div class="customer-picker-select-row">
                        <div class="customer-picker-field">
                            <span class="customer-picker-icon"><i class="fas fa-user-circle"></i></span>
                            <select class="form-control" id="pelangganSelect">
                                <option value="" data-mitra="0" data-label="Pelanggan Umum" data-search="pelanggan umum">-- Pelanggan Umum --</option>
                                <?php foreach ($pelanggan as $pl): ?>
                                    <?php
                                    $customerLabel = trim((string) ($pl['nama'] ?? '')) . (!empty($pl['is_mitra']) ? ' *' : '');
                                    $customerSearch = strtolower(trim(
                                        (string) ($pl['nama'] ?? '') . ' ' .
                                        (string) ($pl['telepon'] ?? '') . ' ' .
                                        (string) ($pl['email'] ?? '') . ' ' .
                                        (string) ($pl['alamat'] ?? '')
                                    ));
                                    ?>
                                    <option
                                        value="<?= $pl['id'] ?>"
                                        data-mitra="<?= $pl['is_mitra'] ?>"
                                        data-label="<?= htmlspecialchars($customerLabel, ENT_QUOTES, 'UTF-8') ?>"
                                        data-search="<?= htmlspecialchars($customerSearch, ENT_QUOTES, 'UTF-8') ?>"
                                    ><?= htmlspecialchars($customerLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="customer-picker-result" id="pelangganSearchInfo">
                        <?= number_format(count($pelanggan)) ?> pelanggan siap dipilih.
                    </div>
                    <div class="cart-inline-note-stack">
                        <label class="form-label" for="invoiceNoteInput">Catatan Invoice</label>
                        <input
                            type="text"
                            id="invoiceNoteInput"
                            class="form-control"
                            maxlength="120"
                            value="<?= htmlspecialchars((string) ($selectedAppendInvoice['catatan_invoice'] ?? '')) ?>"
                            placeholder="Contoh: Deadline Senin pagi / pelanggan revisi cepat"
                        >
                        <div class="customer-picker-result">
                            Catatan ini tampil di daftar transaksi, riwayat pelanggan, dan invoice cetak agar pencarian invoice lebih cepat.
                        </div>
                    </div>
                </div>
            </div>

            <select class="form-control" id="invoiceModeSelect" onchange="onPosTransactionModeChange(this.value)" hidden>
                <option value="new" <?= $appendTransactionId > 0 ? '' : 'selected' ?>>Invoice Baru</option>
                <option value="append" <?= $appendTransactionId > 0 ? 'selected' : '' ?>>Tambah ke Invoice Lama</option>
            </select>

            <?php if ($appendTransactionId > 0 && $selectedAppendInvoice): ?>
                <?php
                $appendInvoiceLabel = trim((string) ($selectedAppendInvoice['no_transaksi'] ?? ''));
                $appendInvoiceCustomer = trim((string) ($selectedAppendInvoice['nama_pelanggan'] ?? 'Pelanggan Umum'));
                $appendInvoiceStatus = trim((string) ($selectedAppendInvoice['status_label'] ?? $selectedAppendInvoice['status'] ?? ''));
                $appendInvoiceWorkflow = trim((string) ($selectedAppendInvoice['workflow_step_label'] ?? ''));
                $appendInvoiceSearch = trim(implode(' ', array_filter([
                    $appendInvoiceLabel,
                    $appendInvoiceCustomer,
                    $appendInvoiceStatus,
                    $appendInvoiceWorkflow,
                    (string) ($selectedAppendInvoice['catatan_invoice'] ?? ''),
                ])));
                ?>
                <details class="cart-collapsible compact-pos-card">
                    <summary>
                        <span class="cart-collapsible-title"><i class="fas fa-file-circle-plus"></i> Amend Invoice</span>
                        <small><?= htmlspecialchars($appendInvoiceLabel . ' - ' . $appendInvoiceCustomer) ?></small>
                    </summary>
                    <div class="cart-collapsible-body">
                        <div class="cart-config-head">
                            <div class="cart-config-copy">
                                <span class="cart-config-kicker">Amend Invoice</span>
                                <strong>Invoice dipilih dari menu transaksi</strong>
                            </div>
                        </div>
                        <div class="invoice-context-box">
                            <strong><?= htmlspecialchars($appendInvoiceLabel . ' - ' . $appendInvoiceCustomer) ?></strong><br>
                            Status <?= htmlspecialchars($appendInvoiceStatus) ?>, tahap <?= htmlspecialchars($appendInvoiceWorkflow ?: '-') ?>, sisa tagihan Rp <?= number_format((float) ($selectedAppendInvoice['remaining_amount'] ?? 0), 0, ',', '.') ?>.
                        </div>
                        <div class="customer-picker-result" style="margin-top:10px">
                            Fitur amend invoice disimpan di daftar transaksi agar layar POS tetap ringkas.
                        </div>
                        <select class="form-control" id="existingInvoiceSelect" onchange="handleExistingInvoiceChange()" hidden>
                            <option
                                value="<?= (int) ($selectedAppendInvoice['id'] ?? 0) ?>"
                                selected
                                data-pelanggan-id="<?= (int) ($selectedAppendInvoice['pelanggan_id'] ?? 0) ?>"
                                data-pelanggan-label="<?= htmlspecialchars($appendInvoiceCustomer, ENT_QUOTES, 'UTF-8') ?>"
                                data-total="<?= htmlspecialchars((string) ((float) ($selectedAppendInvoice['total'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
                                data-bayar="<?= htmlspecialchars((string) ((float) ($selectedAppendInvoice['bayar'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
                                data-remaining="<?= htmlspecialchars((string) ((float) ($selectedAppendInvoice['remaining_amount'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
                                data-status="<?= htmlspecialchars($appendInvoiceStatus, ENT_QUOTES, 'UTF-8') ?>"
                                data-workflow="<?= htmlspecialchars($appendInvoiceWorkflow, ENT_QUOTES, 'UTF-8') ?>"
                                data-note="<?= htmlspecialchars((string) ($selectedAppendInvoice['catatan_invoice'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-search="<?= htmlspecialchars(strtolower($appendInvoiceSearch), ENT_QUOTES, 'UTF-8') ?>"
                            ><?= htmlspecialchars($appendInvoiceLabel . ' - ' . $appendInvoiceCustomer) ?></option>
                        </select>
                    </div>
                </details>
            <?php endif; ?>
        </div>
        <div class="cart-status-row">
            <div class="cart-status-pill">
                <span>Item aktif</span>
                <strong id="cartCount">0 item</strong>
            </div>
            <div class="cart-status-pill">
                <span>Total</span>
                <strong id="cartSummaryTotal">Rp 0</strong>
            </div>
        </div>
    </div>
    <div class="cart-items" id="cartItems">
        <div class="cart-empty-state">
            <i class="fas fa-shopping-cart"></i>
            <p>Keranjang masih kosong</p>
            <span>Pilih produk dari sisi kiri untuk mulai transaksi.</span>
        </div>
    </div>
    <div class="cart-footer">
        <div class="summary-grid">
            <div class="summary-label">Subtotal</div>
            <div class="summary-value" id="subtotalVal">Rp 0</div>

            <div class="summary-label">Diskon</div>
            <div class="summary-input">
                <input type="number" id="diskonInput" value="0" min="0" class="form-control" oninput="updateTotal()">
                <select id="diskonTipeSelect" class="form-control" onchange="updateTotal()">
                    <option value="nominal">Rp</option>
                    <option value="persen">%</option>
                </select>
            </div>

            <div class="summary-label-info" id="diskonInfo" style="display:none;"></div>
            <div class="summary-value-info" id="diskonNominalVal" style="display:none;">- Rp 0</div>

            <div class="summary-label">
                <label class="d-flex align-center gap-2" style="cursor:pointer; user-select:none;">
                    <input type="checkbox" id="pajakToggle" <?= $pajakAktif ? 'checked' : '' ?> onchange="updateTotal()">
                    <?= $pajakNama ?>
                </label>
            </div>
            <div class="summary-value" id="pajakVal">Rp 0</div>
        </div>
        <div class="grand-total">
            <div class="grand-total-label">TOTAL</div>
            <div class="grand-total-value" id="totalVal">Rp 0</div>
        </div>
        <div class="checkout-note">
            Simpan invoice sebagai draft dulu bila customer masih revisi. Setelah pelunasan selesai, invoice langsung masuk ke produksi tanpa tahap approval tambahan.
        </div>
        <div class="cart-footer-actions">
            <button type="button" class="btn btn-outline w-100" id="clearCartBtn" onclick="clearCart()"><i class="fas fa-trash"></i> Kosongkan</button>
            <?php if (hasRole('superadmin', 'admin', 'service', 'kasir')): ?>
                <button type="button" class="btn btn-secondary w-100" id="submitIntakeBtn" onclick="simpanDraftPos()"><i class="fas fa-floppy-disk"></i> Simpan ke Draft</button>
            <?php endif; ?>
            <?php if (hasRole('superadmin', 'admin', 'kasir')): ?>
                <button type="button" class="btn btn-primary w-100 btn-lg pos-checkout-btn" onclick="bukaBayar()"><i class="fas fa-cash-register"></i> Bayar</button>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
</div>

<div class="mobile-cart-bar hidden" id="mobileCartBar">
    <div class="mobile-cart-copy">
        <span id="mobileCartCount">0 item aktif</span>
        <strong id="mobileCartTotal">Rp 0</strong>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="focusCart()"><i class="fas fa-bag-shopping"></i> Keranjang</button>
</div>

<!-- Modal Printing -->
<div class="modal-overlay" id="modalPrinting">
    <div class="modal-box">
        <div class="modal-header"><h5><i class="fas fa-print"></i> Detail Printing</h5><button class="modal-close" onclick="closeModal('modalPrinting')">&times;</button></div>
        <div class="modal-body">
            <h4 id="printingProdukNama" style="font-weight:700;color:#111827;margin:0 0 16px 0;font-size:1.3rem;"></h4>
            
            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-ruler-combined"></i> Dimensi & Kuantitas</div>
                <div class="form-group">
                    <label class="form-label">Tipe Ukuran</label>
                    <select id="printingSatuan" class="form-control" onchange="toggleDimensi()" style="font-weight:500">
                        <option value="m2">Ukuran Custom (m2)</option>
                        <option value="pcs">Kuantitas Fix (pcs)</option>
                        <option value="lembar">Kuantitas Fix (lembar)</option>
                    </select>
                </div>
                <div id="dimensiGroup">
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Lebar (m)</label><input type="number" id="printingLebar" class="form-control" step="0.01" value="1" oninput="hitungLuas()"></div>
                        <div class="form-group"><label class="form-label">Tinggi (m)</label><input type="number" id="printingTinggi" class="form-control" step="0.01" value="1" oninput="hitungLuas()"></div>
                    </div>
                    <div class="form-group mb-0"><label class="form-label">Total Luas</label><input type="text" id="printingLuas" class="form-control" readonly style="background:var(--border); font-weight:600"></div>
                </div>
                <div id="qtyGroup" style="display:none">
                    <div class="form-group mb-0"><label class="form-label">Jumlah Order</label><input type="number" id="printingQty" class="form-control" value="1" min="1" oninput="hitungPrintingSubtotal()"></div>
                </div>
            </div>

            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-sliders-h"></i> Spesifikasi Tambahan</div>
                <div class="form-group">
                    <label class="form-label">Finishing</label>
                    <select id="printingFinishing" class="form-control" onchange="hitungPrintingSubtotal()">
                        <option value="">-- Tanpa Finishing --</option>
                        <?php foreach ($finPrinting as $f): ?>
                            <option value="<?= $f['id'] ?>" data-biaya="<?= $f['biaya'] ?>" data-nama="<?= htmlspecialchars($f['nama'],ENT_QUOTES) ?>">
                                <?= htmlspecialchars($f['nama']) ?> (+Rp <?= number_format($f['biaya'],0,',','.') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-0"><label class="form-label">Catatan</label><input type="text" id="printingCatatan" class="form-control" placeholder="Contoh: Cetak high-res, jangan dilipat..."></div>
            </div>

            <div class="pos-summary-box">
                <div class="summary-row"><span>Harga dasar:</span><span id="printingHargaSatuan">Rp 0</span></div>
                <div class="summary-row"><span>Biaya finishing:</span><span id="printingFinBiaya">Rp 0</span></div>
                <div class="summary-total"><span>Subtotal:</span><span id="printingSubtotal">Rp 0</span></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalPrinting')">Batal</button>
            <button class="btn btn-primary" onclick="addPrintingToCart()"><i class="fas fa-plus"></i> Tambah</button>
        </div>
    </div>
</div>

<!-- Modal Apparel -->
<div class="modal-overlay" id="modalApparel">
    <div class="modal-box modal-lg">
        <div class="modal-header"><h5><i class="fas fa-shirt"></i> Detail Apparel</h5><button class="modal-close" onclick="closeModal('modalApparel')">&times;</button></div>
        <div class="modal-body">
            <h4 id="apparelProdukNama" style="font-weight:700;color:#111827;margin:0 0 16px 0;font-size:1.3rem;"></h4>
            
            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-layer-group"></i> 1. Pilih Bahan & Finishing</div>
                <div class="form-row mb-0">
                    <div class="form-group mb-0">
                        <label class="form-label">Jenis Bahan</label>
                        <select id="apparelBahan" class="form-control">
                            <option value="">-- Pilih Bahan --</option>
                            <?php foreach ($bahanApparel as $b): ?>
                                <option value="<?= $b['id'] ?>" data-nama="<?= htmlspecialchars($b['nama'],ENT_QUOTES) ?>"><?= htmlspecialchars($b['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Metode Sablon/Bordir</label>
                        <select id="apparelFinishing" class="form-control" onchange="hitungApparelSubtotal()">
                            <option value="">-- Tanpa Finishing --</option>
                            <?php foreach ($finApparel as $f): ?>
                                <option value="<?= $f['id'] ?>" data-biaya="<?= $f['biaya'] ?>" data-nama="<?= htmlspecialchars($f['nama'],ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($f['nama']) ?> (+Rp <?= number_format($f['biaya'],0,',','.') ?>/pcs)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="pos-modal-section">
                <div class="pos-modal-section-title size-section-title">
                    <span><i class="fas fa-ruler-combined"></i> 2. List Ukuran & Kuantitas</span>
                </div>
                <div class="size-grid-note">Isi jumlah pada ukuran yang diinginkan (Contoh: S 4 pcs, M 5 pcs, L 6 pcs).</div>
                <?php $predefinedSizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL']; ?>
                <div id="sizeList" class="size-grid">
                    <?php foreach ($predefinedSizes as $size): ?>
                    <label for="size_<?= strtolower($size) ?>" class="size-header"><?= $size ?></label>
                    <?php endforeach; ?>
                    <?php foreach ($predefinedSizes as $size): ?>
                    <div class="size-input-cell">
                        <input type="number" id="size_<?= strtolower($size) ?>" class="form-control size-qty-input" data-size="<?= $size ?>" placeholder="0" min="0" inputmode="numeric" aria-label="Qty ukuran <?= $size ?>" oninput="hitungApparelSubtotal()">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-comment-dots"></i> 3. Catatan Tambahan (Opsional)</div>
                <div class="form-group mb-0"><input type="text" id="apparelCatatan" class="form-control" placeholder="Contoh: Lengan panjang untuk ukuran M, dsb."></div>
            </div>

            <div class="pos-summary-box">
                <div class="summary-row"><span>Total Jumlah (Qty):</span><span id="apparelTotalQty">0 pcs</span></div>
                <div class="summary-row"><span>Harga dasar per pcs:</span><span id="apparelHargaSatuan">Rp 0</span></div>
                <div class="summary-row"><span>Biaya finishing per pcs:</span><span id="apparelFinBiaya">Rp 0</span></div>
                <div class="summary-total"><span>Subtotal:</span><span id="apparelSubtotal">Rp 0</span></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalApparel')">Batal</button>
            <button class="btn btn-primary" onclick="addApparelToCart()"><i class="fas fa-plus"></i> Tambah</button>
        </div>
    </div>
</div>

<!-- Modal Tambah Pelanggan (dari POS) -->
<div class="modal-overlay" id="modalTambahPelanggan">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-user-plus"></i> Tambah Pelanggan Baru</h5>
            <button type="button" class="modal-close" onclick="closeModal('modalTambahPelanggan')">&times;</button>
        </div>
        <form id="tambahPelangganForm">
            <div class="modal-body">
                <div id="msgTambahPelanggan"></div>
                <div class="form-group">
                    <label class="form-label">Nama *</label>
                    <input type="text" id="newPelNama" class="form-control" placeholder="Nama pelanggan" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" id="newPelTelepon" class="form-control" placeholder="08xx...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" id="newPelEmail" class="form-control" placeholder="email@...">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <textarea id="newPelAlamat" class="form-control" rows="2" placeholder="Alamat..."></textarea>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.875rem">
                        <input type="checkbox" id="newPelMitra"> Pelanggan Mitra (dapat pembayaran tempo)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambahPelanggan')">Batal</button>
                <button type="submit" class="btn btn-primary" id="savePosCustomerBtn"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Lainnya -->
<div class="modal-overlay" id="modalLainnya">
    <div class="modal-box">
        <div class="modal-header"><h5><i class="fas fa-box"></i> Detail Produk</h5><button class="modal-close" onclick="closeModal('modalLainnya')">&times;</button></div>
        <div class="modal-body">
            <h4 id="lainnyaNama" style="font-weight:700;color:#111827;margin:0 0 16px 0;font-size:1.3rem;"></h4>
            
            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-cubes"></i> Kuantitas</div>
                <div class="form-group mb-0">
                    <label class="form-label">Jumlah Order (<span id="lainnyaSatuan"></span>)</label>
                    <input type="number" id="lainnyaQty" class="form-control" value="1" min="0.01" step="0.01" oninput="hitungLainnyaSubtotal()" style="font-size:1.2rem; font-weight:700; text-align:center;">
                </div>
            </div>

            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-comment-dots"></i> Catatan Tambahan</div>
                <div class="form-group mb-0"><input type="text" id="lainnyaCatatan" class="form-control" placeholder="Opsional..."></div>
            </div>

            <div class="pos-summary-box">
                <div class="summary-row"><span>Harga per satuan:</span><span id="lainnyaHarga">Rp 0</span></div>
                <div class="summary-total"><span>Subtotal:</span><span id="lainnyaSubtotal">Rp 0</span></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalLainnya')">Batal</button>
            <button class="btn btn-primary" onclick="addLainnyaToCart()"><i class="fas fa-plus"></i> Tambah</button>
        </div>
    </div>
</div>

<!-- Modal Produk Custom -->
<div class="modal-overlay" id="modalCustomProduct">
    <div class="modal-box">
        <div class="modal-header"><h5><i class="fas fa-pen-ruler"></i> Produk Custom</h5><button class="modal-close" onclick="closeModal('modalCustomProduct')">&times;</button></div>
        <div class="modal-body">
            <div class="alert alert-info" style="font-size:.85rem;margin-bottom:16px">
                Gunakan opsi ini untuk item yang belum ada di daftar produk. Item custom akan masuk invoice/transaksi, tetapi tidak mengurangi stok katalog produk.
            </div>

            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-box"></i> Identitas Produk</div>
                <div class="form-group">
                    <label class="form-label">Nama Produk</label>
                    <input type="text" id="customNama" class="form-control" placeholder="Contoh: Banner Event Khusus">
                </div>
                <div class="form-row mb-0">
                    <div class="form-group mb-0">
                        <label class="form-label">Harga Satuan</label>
                        <input type="number" id="customHarga" class="form-control" min="0" step="0.01" value="0" oninput="hitungCustomSubtotal()">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Satuan</label>
                        <input type="text" id="customSatuan" class="form-control" value="pcs" placeholder="pcs / set / box">
                    </div>
                </div>
            </div>

            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-cubes"></i> Kuantitas</div>
                <div class="form-group mb-0">
                    <label class="form-label">Jumlah Order</label>
                    <input type="number" id="customQty" class="form-control" value="1" min="0.01" step="0.01" oninput="hitungCustomSubtotal()" style="font-size:1.2rem; font-weight:700; text-align:center;">
                </div>
            </div>

            <div class="pos-modal-section">
                <div class="pos-modal-section-title"><i class="fas fa-comment-dots"></i> Catatan Tambahan</div>
                <div class="form-group mb-0">
                    <input type="text" id="customCatatan" class="form-control" placeholder="Opsional...">
                </div>
            </div>

            <div class="pos-summary-box">
                <div class="summary-row"><span>Harga per satuan:</span><span id="customHargaDisplay">Rp 0 / pcs</span></div>
                <div class="summary-row"><span>Jumlah:</span><span id="customQtyDisplay">1 pcs</span></div>
                <div class="summary-total"><span>Subtotal:</span><span id="customSubtotal">Rp 0</span></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalCustomProduct')">Batal</button>
            <button class="btn btn-primary" onclick="addCustomToCart()"><i class="fas fa-plus"></i> Tambah</button>
        </div>
    </div>
</div>

<!-- Modal Bayar -->
<div class="modal-overlay" id="modalBayar">
    <div class="modal-box">
        <div class="modal-header"><h5><i class="fas fa-cash-register"></i> Pembayaran</h5><button class="modal-close" onclick="closeModal('modalBayar')">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Total Tagihan</label>
                <input type="text" id="bayarTotal" class="form-control fw-bold" readonly style="font-size:1.2rem;color:#111827">
            </div>
            <div class="form-group">
                <label class="form-label">Metode Pembayaran</label>
                <div class="d-flex gap-2" style="flex-wrap:wrap">
                    <?php foreach (['cash'=>'Tunai','transfer'=>'Transfer','qris'=>'QRIS','downpayment'=>'DP','tempo'=>'Tempo'] as $val=>$lbl): ?>
                    <label style="cursor:pointer;flex:1;min-width:70px">
                        <input type="radio" name="metodeBayar" value="<?= $val ?>" <?= $val==='cash'?'checked':'' ?> onchange="onMetodeChange(this.value)" style="display:none">
                        <div class="metode-btn <?= $val==='cash'?'active':'' ?>" data-val="<?= $val ?>"><?= $lbl ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="groupCash">
                <div class="form-group"><label class="form-label">Uang Diterima</label><input type="number" id="bayarInput" class="form-control" placeholder="0" oninput="hitungKembalian()"></div>
                <div class="form-group"><label class="form-label">Kembalian</label><input type="text" id="kembalianDisplay" class="form-control" readonly style="color:var(--success)"></div>
            </div>
            <div id="groupDP" style="display:none">
                <div class="form-group"><label class="form-label">Jumlah DP (Rp)</label><input type="number" id="dpInput" class="form-control" placeholder="Masukkan jumlah DP" oninput="hitungSisa()"></div>
                <div class="form-group"><label class="form-label">Sisa Tagihan</label><input type="text" id="sisaDisplay" class="form-control" readonly style="color:var(--warning)"></div>
            </div>
            <div id="groupTempo" style="display:none">
                <div class="alert alert-warning" style="font-size:.85rem"><i class="fas fa-info-circle"></i> Hanya untuk pelanggan mitra (*). Maks. 3 bulan.</div>
                <div class="form-group"><label class="form-label">Jangka Waktu (hari, maks 90)</label><input type="number" id="tempoDays" class="form-control" value="30" min="1" max="90" oninput="updateTempoTgl()"></div>
                <div class="form-group"><label class="form-label">Jatuh Tempo</label><input type="text" id="tempoTglDisplay" class="form-control" readonly></div>
            </div>
            <div class="form-group"><label class="form-label">Referensi Pembayaran</label><input type="text" id="referensiBayarInput" class="form-control" placeholder="Nomor transfer / kode pembayaran / nomor bukti"></div>
            <div class="form-group">
                <label class="form-label">Upload Bukti Pembayaran</label>
                <input type="file" name="bukti_pembayaran" id="buktiPembayaranInput" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                <div id="buktiPembayaranHint" style="margin-top:6px;font-size:.78rem;color:var(--text-muted)">
                    Opsional. Format yang didukung: JPG, PNG, atau PDF. File akan tersimpan di detail transaksi sebagai bukti transfer.
                </div>
            </div>
            <div class="form-group"><label class="form-label">Catatan</label><input type="text" id="catatanBayar" class="form-control" placeholder="Opsional..."></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalBayar')">Batal</button>
            <button class="btn btn-success" id="submitCheckoutBtn" onclick="prosesCheckout()"><i class="fas fa-check"></i> Selesaikan</button>
        </div>
    </div>
</div>

<!-- Modal Struk -->
<div class="modal-overlay" id="modalStruk">
    <div class="modal-box">
        <div class="modal-header"><h5 id="strukHeading">Transaksi Berhasil</h5><button class="modal-close" onclick="closeModal('modalStruk')">&times;</button></div>
        <div class="modal-body text-center">
            <i class="fas fa-check-circle" style="font-size:3rem;color:var(--success)"></i>
            <h4 class="mt-2" id="strukNoTrx"></h4>
            <p id="strukTotal" class="fw-bold" style="font-size:1.1rem;color:#111827"></p>
            <p id="strukInfo" style="color:var(--text-muted);font-size:.9rem"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="closeModal('modalStruk');clearCart()">Transaksi Baru</button>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/layouts/footer.php';
?>
