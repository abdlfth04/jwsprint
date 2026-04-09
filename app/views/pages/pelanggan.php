<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service', 'kasir');
$pageTitle = 'Pelanggan';
transactionWorkflowSupportReady($conn);
transactionPaymentEnsureSupportTables($conn);
transactionOrderEnsureSupport($conn);

function pelangganDeletionDependencies(mysqli $conn, int $customerId): array
{
    return schemaBuildDependencyList($conn, $customerId, [
        'transaksi' => ['table' => 'transaksi', 'column' => 'pelanggan_id'],
        'penawaran' => ['table' => 'penawaran', 'column' => 'pelanggan_id'],
    ]);
}

function pelangganPageUrlWithFilters(string $tab = 'semua', string $search = ''): string
{
    $params = [];
    if ($tab !== '' && $tab !== 'semua') {
        $params['tab'] = $tab;
    }
    if ($search !== '') {
        $params['q'] = $search;
    }

    $query = http_build_query($params);

    return pageUrl('pelanggan.php' . ($query !== '' ? '?' . $query : ''));
}

$msg = '';
if (isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'tambah') {
        $nama    = trim($_POST['nama']);
        $telepon = trim($_POST['telepon']);
        $email   = trim($_POST['email']);
        $alamat  = trim($_POST['alamat']);
        $mitra   = isset($_POST['is_mitra']) ? 1 : 0;
        $stmt = $conn->prepare("INSERT INTO pelanggan (nama,telepon,email,alamat,is_mitra) VALUES (?,?,?,?,?)");
        $stmt->bind_param('ssssi', $nama,$telepon,$email,$alamat,$mitra);
        $stmt->execute() ? $msg = 'success|Pelanggan berhasil ditambahkan.' : $msg = 'danger|Gagal menambahkan.';
    } elseif ($act === 'edit') {
        $id    = intval($_POST['id']);
        $nama  = trim($_POST['nama']); $telepon = trim($_POST['telepon']);
        $email = trim($_POST['email']); $alamat = trim($_POST['alamat']);
        $mitra = isset($_POST['is_mitra']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE pelanggan SET nama=?,telepon=?,email=?,alamat=?,is_mitra=? WHERE id=?");
        $stmt->bind_param('ssssii', $nama,$telepon,$email,$alamat,$mitra,$id);
        $stmt->execute() ? $msg = 'success|Data berhasil diperbarui.' : $msg = 'danger|Gagal memperbarui.';
    } elseif ($act === 'hapus') {
        $id = intval($_POST['id']);
        $dependencies = pelangganDeletionDependencies($conn, $id);
        if (!empty($dependencies)) {
            $msg = 'danger|Pelanggan tidak dapat dihapus karena masih dipakai di modul ' . implode(', ', $dependencies) . '.';
        } else {
            $stmt = $conn->prepare("DELETE FROM pelanggan WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute() ? $msg = 'success|Data dihapus.' : $msg = 'danger|Gagal menghapus pelanggan.';
                $stmt->close();
            } else {
                $msg = 'danger|Gagal menyiapkan proses hapus pelanggan.';
            }
        }
    }
}

if (($_GET['ajax'] ?? '') === 'riwayat_transaksi') {
    header('Content-Type: application/json');

    $customerId = (int) ($_GET['id'] ?? 0);
    if ($customerId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Pelanggan tidak valid.']);
        exit;
    }

    if (!schemaTableExists($conn, 'transaksi') || !schemaColumnExists($conn, 'transaksi', 'pelanggan_id')) {
        echo json_encode([
            'success' => true,
            'summary' => [
                'total_transaksi' => 0,
                'nilai_transaksi' => 0,
                'outstanding_total' => 0,
                'active_processes' => 0,
            ],
            'transactions' => [],
        ]);
        exit;
    }

    $trxFields = ['id', 'no_transaksi', 'total', 'bayar', 'status'];
    foreach (['sisa_bayar', 'workflow_step', 'created_at', 'metode_bayar', 'catatan_invoice'] as $column) {
        if (schemaColumnExists($conn, 'transaksi', $column)) {
            $trxFields[] = $column;
        }
    }

    $stmt = $conn->prepare(
        "SELECT " . implode(', ', $trxFields) . "
         FROM transaksi
         WHERE pelanggan_id = ?
         ORDER BY " . (schemaColumnExists($conn, 'transaksi', 'created_at') ? 'created_at DESC' : 'id DESC') . "
         LIMIT 100"
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Riwayat transaksi tidak dapat dimuat.']);
        exit;
    }

    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $summary = [
        'total_transaksi' => count($rows),
        'nilai_transaksi' => 0.0,
        'outstanding_total' => 0.0,
        'active_processes' => 0,
    ];

    foreach ($rows as $index => $row) {
        $remaining = transactionPaymentResolveRemaining($row);
        $workflowStep = transactionWorkflowResolveStep($row);
        $statusText = strtolower(trim((string) ($row['status'] ?? '')));

        $rows[$index]['remaining_amount'] = $remaining;
        $rows[$index]['status_label'] = transactionPaymentStatusLabel($row);
        $rows[$index]['workflow_label'] = transactionWorkflowLabel($workflowStep);
        $rows[$index]['invoice_url'] = pageUrl('invoice_cetak.php?id=' . (int) ($row['id'] ?? 0));
        $rows[$index]['append_url'] = pageUrl('pos.php?append_to=' . (int) ($row['id'] ?? 0));

        if ($statusText !== 'batal') {
            $summary['nilai_transaksi'] += (float) ($row['total'] ?? 0);
            $summary['outstanding_total'] += $remaining;
            if ($remaining > 0.000001 || in_array($workflowStep, ['cashier', 'production'], true)) {
                $summary['active_processes']++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'transactions' => $rows,
    ]);
    exit;
}

$tab     = $_GET['tab'] ?? 'semua';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$hasPelangganTable = schemaTableExists($conn, 'pelanggan');
$hasPelangganTelepon = $hasPelangganTable && schemaColumnExists($conn, 'pelanggan', 'telepon');
$hasPelangganEmail = $hasPelangganTable && schemaColumnExists($conn, 'pelanggan', 'email');
$hasPelangganAlamat = $hasPelangganTable && schemaColumnExists($conn, 'pelanggan', 'alamat');
$hasPelangganMitra = $hasPelangganTable && schemaColumnExists($conn, 'pelanggan', 'is_mitra');
$whereConditions = [];
$whereTypes = '';
$whereParams = [];
switch ($tab) {
    case 'reguler':
        if ($hasPelangganMitra) {
            $whereConditions[] = 'p.is_mitra = ?';
            $whereTypes .= 'i';
            $whereParams[] = 0;
        }
        break;
    case 'mitra':
        if ($hasPelangganMitra) {
            $whereConditions[] = 'p.is_mitra = ?';
            $whereTypes .= 'i';
            $whereParams[] = 1;
        }
        break;
}
if ($searchQuery !== '') {
    $searchColumns = ["COALESCE(p.nama, '')"];
    if ($hasPelangganTelepon) {
        $searchColumns[] = "COALESCE(p.telepon, '')";
    }
    if ($hasPelangganEmail) {
        $searchColumns[] = "COALESCE(p.email, '')";
    }
    if ($hasPelangganAlamat) {
        $searchColumns[] = "COALESCE(p.alamat, '')";
    }

    $searchParts = [];
    foreach ($searchColumns as $column) {
        $searchParts[] = $column . ' LIKE ?';
        $whereTypes .= 's';
        $whereParams[] = '%' . $searchQuery . '%';
    }
    $whereConditions[] = '(' . implode(' OR ', $searchParts) . ')';
}
$wherePelanggan = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
$pelangganSelect = 'id, nama'
    . ($hasPelangganTelepon ? ', telepon' : ", '' AS telepon")
    . ($hasPelangganEmail ? ', email' : ", '' AS email")
    . ($hasPelangganAlamat ? ', alamat' : ", '' AS alamat")
    . ($hasPelangganMitra ? ', is_mitra' : ', 0 AS is_mitra');
$groupByColumns = ['p.id', 'p.nama'];
if ($hasPelangganTelepon) { $groupByColumns[] = 'p.telepon'; }
if ($hasPelangganEmail) { $groupByColumns[] = 'p.email'; }
if ($hasPelangganAlamat) { $groupByColumns[] = 'p.alamat'; }
if ($hasPelangganMitra) { $groupByColumns[] = 'p.is_mitra'; }
$hasCustomerTransactions = schemaTableExists($conn, 'transaksi')
    && schemaColumnExists($conn, 'transaksi', 'pelanggan_id');
$remainingExpr = $hasCustomerTransactions ? transactionWorkflowRemainingSql($conn, 't') : '0';
$customerTransactionJoin = $hasCustomerTransactions ? 'LEFT JOIN transaksi t ON t.pelanggan_id = p.id' : '';
$customerTransactionSelect = $hasCustomerTransactions
    ? "COUNT(t.id) AS transaksi_total,
       COALESCE(SUM(CASE WHEN t.status <> 'batal' THEN t.total ELSE 0 END), 0) AS nilai_transaksi,
       COALESCE(SUM(CASE WHEN t.status <> 'batal' THEN {$remainingExpr} ELSE 0 END), 0) AS outstanding_total,
       MAX(" . (schemaColumnExists($conn, 'transaksi', 'created_at') ? 't.created_at' : 'NULL') . ") AS last_transaction_at"
    : "0 AS transaksi_total,
       0 AS nilai_transaksi,
       0 AS outstanding_total,
       NULL AS last_transaction_at";
$data = $hasPelangganTable
    ? schemaFetchAllAssoc(
        $conn,
        "SELECT p.{$pelangganSelect},
                {$customerTransactionSelect}
         FROM pelanggan p
         {$customerTransactionJoin}
         {$wherePelanggan}
         GROUP BY " . implode(', ', $groupByColumns) . "
         ORDER BY p.nama"
        ,
        $whereTypes,
        ...$whereParams
    )
    : [];
$cntAll = $hasPelangganTable ? schemaFetchCount($conn, 'SELECT COUNT(*) FROM pelanggan') : 0;
$cntReg = $hasPelangganTable && $hasPelangganMitra
    ? schemaFetchCount($conn, 'SELECT COUNT(*) FROM pelanggan WHERE is_mitra=0')
    : $cntAll;
$cntMit = $hasPelangganTable && $hasPelangganMitra
    ? schemaFetchCount($conn, 'SELECT COUNT(*) FROM pelanggan WHERE is_mitra=1')
    : 0;
if ($hasPelangganTelepon && $hasPelangganEmail) {
    $cntReachable = schemaFetchCount($conn, "SELECT COUNT(*) FROM pelanggan WHERE COALESCE(NULLIF(TRIM(telepon), ''), NULLIF(TRIM(email), '')) IS NOT NULL");
} elseif ($hasPelangganTelepon) {
    $cntReachable = schemaFetchCount($conn, "SELECT COUNT(*) FROM pelanggan WHERE NULLIF(TRIM(telepon), '') IS NOT NULL");
} elseif ($hasPelangganEmail) {
    $cntReachable = schemaFetchCount($conn, "SELECT COUNT(*) FROM pelanggan WHERE NULLIF(TRIM(email), '') IS NOT NULL");
} else {
    $cntReachable = 0;
}
$selectedCount = count($data);
$customerDetailsMap = [];
foreach ($data as $customerRow) {
    $customerId = (int) ($customerRow['id'] ?? 0);
    if ($customerId <= 0) {
        continue;
    }

    $customerDetailsMap[(string) $customerId] = [
        'id' => $customerId,
        'nama' => (string) ($customerRow['nama'] ?? ''),
        'telepon' => (string) ($customerRow['telepon'] ?? ''),
        'email' => (string) ($customerRow['email'] ?? ''),
        'alamat' => (string) ($customerRow['alamat'] ?? ''),
        'is_mitra' => (int) ($customerRow['is_mitra'] ?? 0),
        'transaksi_total' => (int) ($customerRow['transaksi_total'] ?? 0),
        'nilai_transaksi' => (float) ($customerRow['nilai_transaksi'] ?? 0),
        'outstanding_total' => (float) ($customerRow['outstanding_total'] ?? 0),
        'last_transaction_at' => (string) ($customerRow['last_transaction_at'] ?? ''),
    ];
}
$tabLabels = [
    'semua' => 'Semua pelanggan',
    'reguler' => 'Pelanggan reguler',
    'mitra' => 'Pelanggan mitra',
];
$missingPelangganColumns = [];
foreach (['telepon' => $hasPelangganTelepon, 'email' => $hasPelangganEmail, 'alamat' => $hasPelangganAlamat, 'is_mitra' => $hasPelangganMitra] as $column => $exists) {
    if (!$exists) {
        $missingPelangganColumns[] = $column;
    }
}

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">';
$extraCss .= '<style>
.pelanggan-page .record-link-button {
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
.pelanggan-page .record-link-button:hover {
    text-decoration: none;
    border-color: rgba(15, 118, 110, 0.24);
    background: rgba(255, 255, 255, 0.74);
    transform: translateY(-1px);
}
.customer-search-panel {
    display: grid;
    gap: 14px;
}
.customer-search-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.customer-search-title {
    margin: 0;
    font-size: .94rem;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -0.02em;
}
.customer-search-copy {
    margin: 6px 0 0;
    max-width: 68ch;
    color: var(--text-muted);
    font-size: .8rem;
    line-height: 1.6;
}
.customer-search-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 10px;
    align-items: center;
}
.customer-search-field {
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
}
.customer-search-field i {
    position: absolute;
    left: 14px;
    color: var(--text-muted);
    pointer-events: none;
}
.customer-search-field .form-control {
    min-width: 0;
    padding-left: 40px;
    padding-right: 42px;
}
.customer-search-clear {
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
.customer-search-clear:hover {
    color: var(--text);
    background: rgba(255, 255, 255, 0.6);
    border-color: rgba(15, 118, 110, 0.16);
}
.customer-search-meta {
    color: var(--text-muted);
    font-size: .78rem;
    line-height: 1.5;
}
.customer-search-meta strong {
    color: var(--text);
}
.customer-search-panel .info-banner.note,
.customer-search-panel .info-banner.primary {
    display: block;
}
.pelanggan-page .table-responsive {
    overflow-x: auto;
}
.pelanggan-page table {
    min-width: 1040px;
}
.pelanggan-page thead th,
.pelanggan-page tbody td {
    line-height: 1.5;
}
.pelanggan-page .customer-inline-meta {
    flex-wrap: wrap;
    gap: 8px 12px;
}
.pelanggan-page .customer-inline-meta span,
.pelanggan-page .mobile-data-value,
.pelanggan-page .mobile-data-title {
    overflow-wrap: anywhere;
}
.pelanggan-page td.rp,
.pelanggan-page .mobile-data-value.rp {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.pelanggan-page .badge {
    min-height: 30px;
    max-width: 100%;
    padding: 6px 12px;
    line-height: 1.25;
    white-space: normal;
    text-align: center;
}
.customer-search-empty[hidden] {
    display: none !important;
}
.customer-search-empty {
    margin-top: 16px;
}
@media (max-width: 768px) {
    .customer-search-panel {
        padding: 14px;
    }
    .customer-search-copy {
        font-size: .78rem;
    }
    .customer-search-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .customer-search-field {
        grid-column: 1 / -1;
    }
    .customer-search-form .btn {
        width: 100%;
    }
}
@media (max-width: 520px) {
    .customer-search-form {
        grid-template-columns: 1fr;
    }
    .customer-search-form .btn,
    .customer-search-form .btn-outline {
        width: 100%;
    }
    .pelanggan-page .badge {
        font-size: .68rem;
        padding: 5px 10px;
    }
}
</style>';
$pageState = [
    'pelangganHistoryEndpoint' => pageUrl('pelanggan.php?ajax=riwayat_transaksi'),
    'pelangganDetails' => $customerDetailsMap,
];
$pageJs = 'pelanggan.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= $type ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack admin-panel pelanggan-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-users"></i> Pelanggan</div>
                <h1 class="page-title">Daftar pelanggan yang lebih ringkas dan mudah dipindai</h1>
                <p class="page-description">
                    Halaman pelanggan dirapikan supaya tim service dan kasir bisa cepat membaca tipe pelanggan, kontak yang tersedia, dan status mitra tanpa harus bergantung pada tabel padat.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-filter"></i> Filter aktif: <?= htmlspecialchars($tabLabels[$tab] ?? 'Semua pelanggan') ?></span>
                    <span class="page-meta-item"><i class="fas fa-address-book"></i> <?= number_format($selectedCount) ?> pelanggan ditampilkan</span>
                    <span class="page-meta-item"><i class="fas fa-handshake"></i> <?= number_format($cntMit) ?> pelanggan mitra</span>
                    <?php if ($searchQuery !== ''): ?>
                        <span class="page-meta-item"><i class="fas fa-magnifying-glass"></i> Kata kunci: <?= htmlspecialchars($searchQuery) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('modalTambah')"><i class="fas fa-user-plus"></i> Tambah Pelanggan</button>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <section class="toolbar-surface admin-filter-grid customer-search-panel">
        <div class="customer-search-header">
            <div>
                <h2 class="customer-search-title">Cari dan kelompokkan pelanggan lebih cepat</h2>
                <p class="customer-search-copy">Gunakan segmen pelanggan dan kata kunci untuk menemukan nama, telepon, email, atau alamat tanpa area kosong di halaman.</p>
            </div>
            <div class="customer-search-meta" id="customerSearchSummary">
                <strong><?= number_format($selectedCount) ?></strong> pelanggan pada halaman ini
            </div>
        </div>
        <div class="page-inline-summary compact-summary-row">
            <div class="page-inline-pill">
                <span>Total</span>
                <strong><?= number_format($cntAll) ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Reguler</span>
                <strong><?= number_format($cntReg) ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Mitra</span>
                <strong><?= number_format($cntMit) ?></strong>
            </div>
            <div class="page-inline-pill">
                <span>Kontak</span>
                <strong><?= number_format($cntReachable) ?></strong>
            </div>
        </div>
        <?php if (!$hasPelangganTable): ?>
            <div class="info-banner warning">
                <strong>Tabel <code>pelanggan</code> belum tersedia.</strong> Halaman tetap dibuka, tetapi data pelanggan baru akan tampil setelah schema database dilengkapi.
            </div>
        <?php elseif (!empty($missingPelangganColumns)): ?>
            <div class="info-banner note">
                <strong>Schema pelanggan di hosting belum lengkap.</strong> Kolom yang belum tersedia: <?= htmlspecialchars(implode(', ', $missingPelangganColumns)) ?>.
            </div>
        <?php endif; ?>
        <div class="filter-pills">
            <a href="<?= htmlspecialchars(pelangganPageUrlWithFilters('semua', $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === 'semua' ? 'active' : '' ?>">
                <span>Semua</span>
                <span class="filter-pill-count"><?= number_format($cntAll) ?></span>
            </a>
            <a href="<?= htmlspecialchars(pelangganPageUrlWithFilters('reguler', $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === 'reguler' ? 'active' : '' ?>">
                <span>Reguler</span>
                <span class="filter-pill-count"><?= number_format($cntReg) ?></span>
            </a>
            <a href="<?= htmlspecialchars(pelangganPageUrlWithFilters('mitra', $searchQuery), ENT_QUOTES, 'UTF-8') ?>" class="filter-pill <?= $tab === 'mitra' ? 'active' : '' ?>">
                <span>Mitra</span>
                <span class="filter-pill-count"><?= number_format($cntMit) ?></span>
            </a>
        </div>
        <?php if ($tab === 'mitra'): ?>
            <div class="info-banner note">
                <strong>Pelanggan mitra</strong> dapat menggunakan alur pembayaran tempo. Pastikan kontak dan alamat selalu terisi agar follow-up invoice lebih mudah.
            </div>
        <?php endif; ?>
        <form method="GET" class="customer-search-form">
            <?php if ($tab !== 'semua'): ?>
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <label class="sr-only" for="customerSearchInput">Cari pelanggan</label>
            <div class="customer-search-field">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input
                    type="search"
                    class="form-control"
                    name="q"
                    id="customerSearchInput"
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Cari nama, telepon, email, atau alamat..."
                    data-customer-search-input
                >
                <button
                    type="button"
                    class="customer-search-clear"
                    data-customer-search-clear
                    aria-label="Kosongkan pencarian"
                    <?= $searchQuery === '' ? 'hidden' : '' ?>
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
            <a href="<?= htmlspecialchars(pelangganPageUrlWithFilters($tab), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline">Reset</a>
        </form>
        <div class="customer-search-meta">
            Pencarian langsung menyaring daftar yang terlihat di layar. Tombol <strong>Terapkan</strong> akan menyimpan kata kunci ke URL supaya hasil tetap aktif saat halaman dibuka ulang.
        </div>
    </section>

    <details class="card mobile-collapse-panel mobile-record-panel customer-record-panel" open>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Daftar Pelanggan</strong>
                <span><?= number_format($selectedCount) ?> pelanggan siap dipindai</span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas fa-address-card"></i> Daftar Pelanggan</span>
                <div class="card-subtitle">Desktop memakai tabel lengkap, mobile memakai kartu ringkas dengan aksi yang sama.</div>
            </div>
        </div>

        <?php if (!empty($data)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblPelanggan">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Kontak</th>
                            <th>Transaksi</th>
                            <th>Sisa Tagihan</th>
                            <th>Terakhir</th>
                            <?php if ($tab !== 'mitra' && $tab !== 'reguler'): ?>
                                <th>Tipe</th>
                            <?php endif; ?>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($data as $i => $d): ?>
                        <?php
                        $customerSearchIndex = strtolower(trim(implode(' ', array_filter([
                            (string) ($d['nama'] ?? ''),
                            (string) ($d['telepon'] ?? ''),
                            (string) ($d['email'] ?? ''),
                            (string) ($d['alamat'] ?? ''),
                            (string) ($d['transaksi_total'] ?? 0),
                            (string) ($d['outstanding_total'] ?? 0),
                            !empty($d['is_mitra']) ? 'mitra' : 'reguler',
                        ]))));
                        ?>
                        <tr data-search="<?= htmlspecialchars($customerSearchIndex, ENT_QUOTES, 'UTF-8') ?>">
                            <td><?= $i + 1 ?></td>
                            <td>
                                <button type="button" class="record-link-button" onclick="openCustomerDetailModal(<?= (int) $d['id'] ?>)">
                                    <?= htmlspecialchars($d['nama']) ?>
                                </button>
                            </td>
                            <td class="customer-contact-cell">
                                <div class="customer-inline-meta">
                                    <span><i class="fas fa-phone"></i> <?= htmlspecialchars($d['telepon'] ?: '-') ?></span>
                                    <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($d['email'] ?: '-') ?></span>
                                </div>
                            </td>
                            <td><?= number_format((int) ($d['transaksi_total'] ?? 0)) ?></td>
                            <td class="rp"><?= number_format((float) ($d['outstanding_total'] ?? 0), 0, ',', '.') ?></td>
                            <td><?= !empty($d['last_transaction_at']) ? date('d/m/Y H:i', strtotime((string) $d['last_transaction_at'])) : '-' ?></td>
                            <?php if ($tab !== 'mitra' && $tab !== 'reguler'): ?>
                                <td>
                                    <span class="badge <?= !empty($d['is_mitra']) ? 'badge-mitra' : 'badge-reguler' ?>">
                                        <i class="fas <?= !empty($d['is_mitra']) ? 'fa-handshake' : 'fa-user' ?>"></i>
                                        <?= !empty($d['is_mitra']) ? 'Mitra' : 'Reguler' ?>
                                    </span>
                                </td>
                            <?php endif; ?>
                            <td>
                                <button type="button" class="btn btn-info btn-sm" onclick="openCustomerDetailModal(<?= (int) $d['id'] ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobilePelangganList">
                <?php foreach ($data as $d): ?>
                    <?php
                    $customerSearchIndex = strtolower(trim(implode(' ', array_filter([
                        (string) ($d['nama'] ?? ''),
                        (string) ($d['telepon'] ?? ''),
                        (string) ($d['email'] ?? ''),
                        (string) ($d['alamat'] ?? ''),
                        (string) ($d['transaksi_total'] ?? 0),
                        (string) ($d['outstanding_total'] ?? 0),
                        !empty($d['is_mitra']) ? 'mitra' : 'reguler',
                    ]))));
                    ?>
                    <div class="mobile-data-card pelanggan-card" data-search="<?= htmlspecialchars($customerSearchIndex, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title">
                                    <button type="button" class="record-link-button" onclick="openCustomerDetailModal(<?= (int) $d['id'] ?>)">
                                        <?= htmlspecialchars($d['nama']) ?>
                                    </button>
                                </div>
                            </div>
                            <span class="badge <?= !empty($d['is_mitra']) ? 'badge-mitra' : 'badge-reguler' ?>">
                                <?= !empty($d['is_mitra']) ? 'Mitra' : 'Reguler' ?>
                            </span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Telepon</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($d['telepon'] ?: '-') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Email</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($d['email'] ?: '-') ?></span>
                            </div>
                            <div class="mobile-data-field" style="grid-column: 1 / -1;">
                                <span class="mobile-data-label">Alamat</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($d['alamat'] ?: '-') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Transaksi</span>
                                <span class="mobile-data-value"><?= number_format((int) ($d['transaksi_total'] ?? 0)) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Sisa Tagihan</span>
                                <span class="mobile-data-value rp"><?= number_format((float) ($d['outstanding_total'] ?? 0), 0, ',', '.') ?></span>
                            </div>
                            <div class="mobile-data-field" style="grid-column: 1 / -1;">
                                <span class="mobile-data-label">Transaksi Terakhir</span>
                                <span class="mobile-data-value"><?= !empty($d['last_transaction_at']) ? date('d/m/Y H:i', strtotime((string) $d['last_transaction_at'])) : '-' ?></span>
                            </div>
                        </div>
                        <div class="mobile-note">
                            <?= !empty($d['is_mitra']) ? 'Pelanggan ini dapat dipakai untuk pembayaran tempo.' : 'Pelanggan reguler untuk transaksi umum.' ?>
                        </div>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-info btn-sm" onclick="openCustomerDetailModal(<?= (int) $d['id'] ?>)"><i class="fas fa-eye"></i> Detail</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty-state customer-search-empty" id="customerSearchEmpty" hidden>
                <i class="fas fa-magnifying-glass"></i>
                <div>Tidak ada pelanggan yang cocok dengan kata kunci yang sedang dipakai.</div>
                <p>Coba ubah kata kunci, ganti segmen pelanggan, atau tekan reset untuk menampilkan daftar lagi.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <div><?= $tab === 'mitra' ? 'Belum ada pelanggan mitra.' : 'Belum ada pelanggan pada filter ini.' ?></div>
            </div>
        <?php endif; ?>
        </div>
    </details>
</div>

<!-- Modal Tambah -->
<div class="modal-overlay" id="modalTambah">
    <div class="modal-box">
        <div class="modal-header">
            <h5>Tambah Pelanggan</h5>
            <button class="modal-close" onclick="closeModal('modalTambah')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="tambah">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Telepon</label><input type="text" name="telepon" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                </div>
                <div class="form-group"><label class="form-label">Alamat</label><textarea name="alamat" class="form-control" rows="2"></textarea></div>
                <div class="form-group" style="background:var(--bg);border-radius:8px;padding:12px">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0">
                        <div class="toggle-switch">
                            <input type="checkbox" name="is_mitra" id="addMitra" value="1"
                                   <?= $tab==='mitra' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <div>
                            <div style="font-weight:600;font-size:.875rem">Pelanggan Mitra</div>
                            <div style="font-size:.8rem;color:var(--text-muted)">Dapat akses pembayaran tempo hingga 3 bulan</div>
                        </div>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambah')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-box">
        <div class="modal-header">
            <h5>Edit Pelanggan</h5>
            <button class="modal-close" onclick="closeModal('modalEdit')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Nama *</label><input type="text" name="nama" id="editNama" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Telepon</label><input type="text" name="telepon" id="editTelepon" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="editEmail" class="form-control"></div>
                </div>
                <div class="form-group"><label class="form-label">Alamat</label><textarea name="alamat" id="editAlamat" class="form-control" rows="2"></textarea></div>
                <div class="form-group" style="background:var(--bg);border-radius:8px;padding:12px">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0">
                        <div class="toggle-switch">
                            <input type="checkbox" name="is_mitra" id="editMitra" value="1">
                            <span class="toggle-slider"></span>
                        </div>
                        <div>
                            <div style="font-weight:600;font-size:.875rem">Pelanggan Mitra</div>
                            <div style="font-size:.8rem;color:var(--text-muted)">Dapat akses pembayaran tempo hingga 3 bulan</div>
                        </div>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEdit')">Batal</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modalDetailPelanggan">
    <div class="modal-box modal-lg" style="max-width: 980px;">
        <div class="modal-header">
            <h5 id="modalDetailPelangganTitle">Detail Pelanggan</h5>
            <button class="modal-close" onclick="closeModal('modalDetailPelanggan')">&times;</button>
        </div>
        <div class="modal-body" id="detailPelangganBody">
            <div class="text-center text-muted" style="padding:32px 0">Pilih pelanggan untuk melihat detail dan aksinya.</div>
        </div>
        <div class="modal-footer customer-detail-footer">
            <div class="customer-detail-actions">
                <button type="button" class="btn btn-info" id="detailPelangganHistoryBtn">
                    <i class="fas fa-receipt"></i> Riwayat Transaksi
                </button>
                <button type="button" class="btn btn-warning" id="detailPelangganEditBtn">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
            <div class="customer-detail-actions">
                <form method="POST" id="detailPelangganDeleteForm">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="hapus">
                    <input type="hidden" name="id" id="detailPelangganDeleteId" value="0">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </form>
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalDetailPelanggan')">Tutup</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalRiwayatPelanggan">
    <div class="modal-box modal-lg" style="max-width: 1080px;">
        <div class="modal-header">
            <h5 id="riwayatPelangganTitle">Riwayat Transaksi Pelanggan</h5>
            <button class="modal-close" onclick="closeModal('modalRiwayatPelanggan')">&times;</button>
        </div>
        <div class="modal-body" id="riwayatPelangganBody">
            <div class="text-center text-muted" style="padding:32px 0">Pilih pelanggan untuk melihat riwayat transaksinya.</div>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/layouts/footer.php';
?>
