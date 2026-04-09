<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
$pageTitle = 'HPP & Margin';

$bulan = (string) ($_GET['bulan'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}

$departemen = (string) ($_GET['departemen'] ?? 'printing');
if (!in_array($departemen, ['printing', 'apparel'], true)) {
    $departemen = 'printing';
}

function hppEnsureCostingTable(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (schemaTableExists($conn, 'job_costing_item')) {
        return $ready = true;
    }

    if (!appSchemaAutoMigrateEnabled()) {
        return $ready = false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS job_costing_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        detail_transaksi_id INT NOT NULL,
        departemen ENUM('printing','apparel') NOT NULL,
        bahan_baku_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        finishing_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        tenaga_kerja_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        overhead_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        subkon_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        pengiriman_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        lain_lain_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        catatan TEXT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_job_costing_detail (detail_transaksi_id),
        KEY idx_job_costing_departemen (departemen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    return $ready = (bool) $conn->query($sql);
}

function hppEnsureMaterialUsageTable(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (schemaTableExists($conn, 'job_material_usage')) {
        return $ready = true;
    }

    if (!appSchemaAutoMigrateEnabled()) {
        return $ready = false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS job_material_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        detail_transaksi_id INT NOT NULL,
        departemen ENUM('printing','apparel') NOT NULL,
        stok_bahan_id INT NULL,
        nama_bahan VARCHAR(190) NOT NULL,
        satuan VARCHAR(50) NULL,
        qty DECIMAL(15,3) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_job_material_detail (detail_transaksi_id),
        KEY idx_job_material_departemen (departemen),
        KEY idx_job_material_stok (stok_bahan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    return $ready = (bool) $conn->query($sql);
}

function hppFormatMoney(float $amount, bool $signed = false): string
{
    if ($signed) {
        return ($amount < 0 ? '-Rp ' : 'Rp ') . number_format(abs($amount), 0, ',', '.');
    }

    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function hppDepartmentLabel(string $departemen): string
{
    return $departemen === 'apparel' ? 'Apparel' : 'Printing';
}

function hppDepartmentIcon(string $departemen): string
{
    return $departemen === 'apparel' ? 'fa-shirt' : 'fa-print';
}

function hppStatusBadgeClass(string $status): string
{
    return [
        'selesai' => 'badge-success',
        'pending' => 'badge-warning',
        'dp' => 'badge-warning',
        'tempo' => 'badge-info',
        'batal' => 'badge-danger',
    ][$status] ?? 'badge-secondary';
}

function hppSuggestedMaterialCost(array $row): float
{
    return max(0, (float) ($row['produk_harga_beli'] ?? 0)) * max(0, (float) ($row['qty'] ?? 0));
}

function hppSuggestedFinishingCost(array $row): float
{
    $base = max(0, (float) ($row['finishing_biaya'] ?? 0));
    if ($base <= 0) {
        return 0;
    }

    if (($row['kategori_tipe'] ?? '') === 'apparel') {
        return $base * max(0, (float) ($row['qty'] ?? 0));
    }

    return $base;
}

function hppNetSales(array $row, array $grossByTransaction): float
{
    $transaksiId = (int) ($row['transaksi_id'] ?? 0);
    $gross = (float) ($grossByTransaction[$transaksiId] ?? 0);
    $subtotal = (float) ($row['subtotal'] ?? 0);
    $diskon = max(0, (float) ($row['transaksi_diskon'] ?? 0));
    $alokasiDiskon = $gross > 0 ? ($diskon * ($subtotal / $gross)) : 0;

    return max(0, $subtotal - $alokasiDiskon);
}

function hppBuildSummary(string $departemen): array
{
    return [
        'departemen' => $departemen,
        'count' => 0,
        'omzet' => 0,
        'hpp' => 0,
        'laba' => 0,
        'missing_actual' => 0,
        'margin_pct' => 0,
    ];
}

function hppLoadMaterialCatalog(mysqli $conn): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'printing' => [],
        'apparel' => [],
    ];

    if (!schemaTableExists($conn, 'stok_bahan')) {
        return $catalog;
    }

    $result = $conn->query(
        "SELECT id, kode, nama, kategori, satuan, harga_beli, stok
         FROM stok_bahan
         WHERE kategori IN ('printing','apparel')
         ORDER BY kategori, nama"
    );

    if (!$result) {
        return $catalog;
    }

    while ($row = $result->fetch_assoc()) {
        $deptKey = (string) ($row['kategori'] ?? '');
        if (!isset($catalog[$deptKey])) {
            continue;
        }

        $kode = trim((string) ($row['kode'] ?? ''));
        $nama = trim((string) ($row['nama'] ?? ''));
        $catalog[$deptKey][] = [
            'id' => (int) ($row['id'] ?? 0),
            'kode' => $kode,
            'nama' => $nama,
            'label' => $kode !== '' ? $kode . ' - ' . $nama : $nama,
            'kategori' => $deptKey,
            'satuan' => trim((string) ($row['satuan'] ?? '')),
            'harga_beli' => (float) ($row['harga_beli'] ?? 0),
            'stok' => (float) ($row['stok'] ?? 0),
        ];
    }

    return $catalog;
}

function hppLoadMaterialCatalogById(mysqli $conn): array
{
    static $catalogById = null;
    if ($catalogById !== null) {
        return $catalogById;
    }

    $catalogById = [];
    foreach (hppLoadMaterialCatalog($conn) as $items) {
        foreach ($items as $item) {
            $catalogById[(int) ($item['id'] ?? 0)] = $item;
        }
    }

    return $catalogById;
}

function hppSanitizeMaterialRows(mysqli $conn, $rawInput, string $departemen): array
{
    $decoded = is_string($rawInput) ? json_decode($rawInput, true) : $rawInput;
    if (!is_array($decoded)) {
        return [];
    }

    $catalogById = hppLoadMaterialCatalogById($conn);
    $cleanRows = [];

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $stokBahanId = max(0, (int) ($row['stok_bahan_id'] ?? 0));
        $qty = max(0, (float) ($row['qty'] ?? 0));
        $unitCost = max(0, (float) ($row['unit_cost'] ?? 0));
        $namaBahan = trim((string) ($row['nama_bahan'] ?? ''));
        $satuan = trim((string) ($row['satuan'] ?? ''));

        $catalogItem = $stokBahanId > 0 ? ($catalogById[$stokBahanId] ?? null) : null;
        if ($catalogItem && (string) ($catalogItem['kategori'] ?? '') !== $departemen) {
            $catalogItem = null;
            $stokBahanId = 0;
        }

        if ($catalogItem) {
            $namaBahan = trim((string) ($catalogItem['nama'] ?? $namaBahan));
            if ($satuan === '') {
                $satuan = trim((string) ($catalogItem['satuan'] ?? ''));
            }
            if ($unitCost <= 0) {
                $unitCost = max(0, (float) ($catalogItem['harga_beli'] ?? 0));
            }
        }

        if ($namaBahan === '' || $qty <= 0) {
            continue;
        }

        $cleanRows[] = [
            'stok_bahan_id' => $stokBahanId > 0 ? $stokBahanId : null,
            'nama_bahan' => $namaBahan,
            'satuan' => $satuan,
            'qty' => round($qty, 3),
            'unit_cost' => round($unitCost, 2),
            'total_cost' => round($qty * $unitCost, 2),
        ];
    }

    return $cleanRows;
}

function hppMaterialUsageTotal(array $rows): float
{
    $total = 0;
    foreach ($rows as $row) {
        $total += (float) ($row['total_cost'] ?? 0);
    }

    return $total;
}

function hppLoadMaterialUsageRows(mysqli $conn, int $detailId): array
{
    if ($detailId <= 0 || !schemaTableExists($conn, 'job_material_usage')) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT stok_bahan_id, nama_bahan, satuan, qty, unit_cost, total_cost
         FROM job_material_usage
         WHERE detail_transaksi_id = ?
         ORDER BY id ASC"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $detailId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $row): array {
        return [
            'stok_bahan_id' => isset($row['stok_bahan_id']) ? (int) $row['stok_bahan_id'] : null,
            'nama_bahan' => (string) ($row['nama_bahan'] ?? ''),
            'satuan' => (string) ($row['satuan'] ?? ''),
            'qty' => (float) ($row['qty'] ?? 0),
            'unit_cost' => (float) ($row['unit_cost'] ?? 0),
            'total_cost' => (float) ($row['total_cost'] ?? 0),
        ];
    }, $rows);
}

$tableReady = hppEnsureCostingTable($conn) && hppEnsureMaterialUsageTable($conn);
$msg = '';

if ($tableReady && isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? '');
    $detailId = (int) ($_POST['detail_transaksi_id'] ?? 0);

    if ($action === 'save_costing' && $detailId > 0) {
        $stmtDetail = $conn->prepare(
            "SELECT dt.id, dt.kategori_tipe
             FROM detail_transaksi dt
             JOIN transaksi t ON t.id = dt.transaksi_id
             WHERE dt.id = ? AND dt.kategori_tipe IN ('printing','apparel') AND t.status <> 'batal'
             LIMIT 1"
        );
        if ($stmtDetail) {
            $stmtDetail->bind_param('i', $detailId);
            $stmtDetail->execute();
            $detail = $stmtDetail->get_result()->fetch_assoc();
            $stmtDetail->close();

            if ($detail) {
                $deptValue = (string) ($detail['kategori_tipe'] ?? 'printing');
                $previousMaterialRows = hppLoadMaterialUsageRows($conn, $detailId);
                $materialRows = hppSanitizeMaterialRows($conn, $_POST['material_usage_json'] ?? '[]', $deptValue);
                $materialTotal = hppMaterialUsageTotal($materialRows);
                $bahanCost = !empty($materialRows)
                    ? $materialTotal
                    : max(0, (float) ($_POST['bahan_baku_cost'] ?? 0));
                $finishingCost = max(0, (float) ($_POST['finishing_cost'] ?? 0));
                $tenagaKerja = max(0, (float) ($_POST['tenaga_kerja_cost'] ?? 0));
                $overhead = max(0, (float) ($_POST['overhead_cost'] ?? 0));
                $subkon = max(0, (float) ($_POST['subkon_cost'] ?? 0));
                $pengiriman = max(0, (float) ($_POST['pengiriman_cost'] ?? 0));
                $lainLain = max(0, (float) ($_POST['lain_lain_cost'] ?? 0));
                $catatan = trim((string) ($_POST['catatan'] ?? ''));
                $updatedBy = (int) ($_SESSION['user_id'] ?? 0);

                $stmtSave = $conn->prepare(
                    "INSERT INTO job_costing_item (
                        detail_transaksi_id,
                        departemen,
                        bahan_baku_cost,
                        finishing_cost,
                        tenaga_kerja_cost,
                        overhead_cost,
                        subkon_cost,
                        pengiriman_cost,
                        lain_lain_cost,
                        catatan,
                        updated_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        departemen = VALUES(departemen),
                        bahan_baku_cost = VALUES(bahan_baku_cost),
                        finishing_cost = VALUES(finishing_cost),
                        tenaga_kerja_cost = VALUES(tenaga_kerja_cost),
                        overhead_cost = VALUES(overhead_cost),
                        subkon_cost = VALUES(subkon_cost),
                        pengiriman_cost = VALUES(pengiriman_cost),
                        lain_lain_cost = VALUES(lain_lain_cost),
                        catatan = VALUES(catatan),
                        updated_by = VALUES(updated_by)"
                );

                if ($stmtSave) {
                    $stmtDeleteMaterial = $conn->prepare("DELETE FROM job_material_usage WHERE detail_transaksi_id = ?");
                    $stmtInsertMaterial = $conn->prepare(
                        "INSERT INTO job_material_usage (
                            detail_transaksi_id,
                            departemen,
                            stok_bahan_id,
                            nama_bahan,
                            satuan,
                            qty,
                            unit_cost,
                            total_cost
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    if ($stmtDeleteMaterial && $stmtInsertMaterial) {
                        $conn->begin_transaction();
                        try {
                            $stmtSave->bind_param(
                                'isdddddddsi',
                                $detailId,
                                $deptValue,
                                $bahanCost,
                                $finishingCost,
                                $tenagaKerja,
                                $overhead,
                                $subkon,
                                $pengiriman,
                                $lainLain,
                                $catatan,
                                $updatedBy
                            );

                            if (!$stmtSave->execute()) {
                                throw new RuntimeException('Gagal menyimpan biaya inti HPP.');
                            }

                            $stmtDeleteMaterial->bind_param('i', $detailId);
                            if (!$stmtDeleteMaterial->execute()) {
                                throw new RuntimeException('Gagal memperbarui detail bahan.');
                            }

                            materialInventorySyncJobUsageStock(
                                $conn,
                                $detailId,
                                $deptValue,
                                $previousMaterialRows,
                                $materialRows,
                                $updatedBy
                            );

                            foreach ($materialRows as $materialRow) {
                                $stokBahanId = !empty($materialRow['stok_bahan_id']) ? (int) $materialRow['stok_bahan_id'] : 0;
                                $namaBahan = (string) ($materialRow['nama_bahan'] ?? '');
                                $satuanBahan = (string) ($materialRow['satuan'] ?? '');
                                $qtyBahan = (float) ($materialRow['qty'] ?? 0);
                                $unitCostBahan = (float) ($materialRow['unit_cost'] ?? 0);
                                $totalCostBahan = (float) ($materialRow['total_cost'] ?? 0);

                                $stmtInsertMaterial->bind_param(
                                    'isissddd',
                                    $detailId,
                                    $deptValue,
                                    $stokBahanId,
                                    $namaBahan,
                                    $satuanBahan,
                                    $qtyBahan,
                                    $unitCostBahan,
                                    $totalCostBahan
                                );

                                if (!$stmtInsertMaterial->execute()) {
                                    throw new RuntimeException('Gagal menyimpan baris pemakaian bahan.');
                                }
                            }

                            $conn->commit();
                            $msg = 'success|Biaya HPP dan pemakaian bahan berhasil disimpan.';
                        } catch (Throwable $e) {
                            $conn->rollback();
                            $msg = 'danger|' . $e->getMessage();
                        }
                    } else {
                        $msg = 'danger|Form HPP tidak dapat menyiapkan detail bahan.';
                    }

                    $stmtSave->close();
                    if ($stmtDeleteMaterial) {
                        $stmtDeleteMaterial->close();
                    }
                    if ($stmtInsertMaterial) {
                        $stmtInsertMaterial->close();
                    }
                } else {
                    $msg = 'danger|Form HPP tidak dapat diproses saat ini.';
                }
            } else {
                $msg = 'danger|Item job tidak ditemukan atau tidak termasuk departemen terpisah.';
            }
        }
    } elseif ($action === 'reset_costing' && $detailId > 0) {
        $stmtDetail = $conn->prepare("SELECT kategori_tipe FROM detail_transaksi WHERE id = ? LIMIT 1");
        $stmtResetCosting = $conn->prepare("DELETE FROM job_costing_item WHERE detail_transaksi_id = ?");
        $stmtResetMaterial = $conn->prepare("DELETE FROM job_material_usage WHERE detail_transaksi_id = ?");
        if ($stmtDetail && $stmtResetCosting && $stmtResetMaterial) {
            $conn->begin_transaction();
            try {
                $stmtDetail->bind_param('i', $detailId);
                $stmtDetail->execute();
                $detail = $stmtDetail->get_result()->fetch_assoc();
                if (!$detail) {
                    throw new RuntimeException('Item job tidak ditemukan.');
                }

                $existingMaterialRows = hppLoadMaterialUsageRows($conn, $detailId);
                materialInventorySyncJobUsageStock(
                    $conn,
                    $detailId,
                    (string) ($detail['kategori_tipe'] ?? 'printing'),
                    $existingMaterialRows,
                    [],
                    (int) ($_SESSION['user_id'] ?? 0)
                );

                $stmtResetMaterial->bind_param('i', $detailId);
                if (!$stmtResetMaterial->execute()) {
                    throw new RuntimeException('Gagal mereset detail bahan.');
                }

                $stmtResetCosting->bind_param('i', $detailId);
                if (!$stmtResetCosting->execute()) {
                    throw new RuntimeException('Gagal mereset biaya aktual.');
                }

                $conn->commit();
                $msg = 'success|Biaya aktual dan detail bahan direset ke estimasi otomatis.';
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = 'danger|' . $e->getMessage();
            }

            $stmtDetail->close();
            $stmtResetCosting->close();
            $stmtResetMaterial->close();
        }
    }
}

$monthLabel = date('F Y', strtotime($bulan . '-01'));
$rawRows = [];
$materialUsageByDetail = [];
$materialCatalog = hppLoadMaterialCatalog($conn);
$departmentSummary = [
    'printing' => hppBuildSummary('printing'),
    'apparel' => hppBuildSummary('apparel'),
];

if ($tableReady) {
    $stmtRows = $conn->prepare(
        "SELECT
            dt.id AS detail_transaksi_id,
            dt.transaksi_id,
            dt.nama_produk,
            dt.kategori_tipe,
            dt.satuan,
            dt.qty,
            dt.lebar,
            dt.tinggi,
            dt.luas,
            dt.harga,
            dt.subtotal,
            dt.finishing_nama,
            dt.finishing_biaya,
            dt.bahan_nama,
            dt.size_detail,
            dt.catatan AS catatan_item,
            t.no_transaksi,
            t.status AS transaksi_status,
            t.diskon AS transaksi_diskon,
            t.created_at,
            pel.nama AS nama_pelanggan,
            p.harga_beli AS produk_harga_beli,
            pr.no_dokumen,
            pr.tipe_dokumen,
            pr.status AS produksi_status,
            k.nama AS nama_pic,
            jc.id AS costing_id,
            jc.bahan_baku_cost,
            jc.finishing_cost,
            jc.tenaga_kerja_cost,
            jc.overhead_cost,
            jc.subkon_cost,
            jc.pengiriman_cost,
            jc.lain_lain_cost,
            jc.catatan AS catatan_costing,
            jc.updated_at AS costing_updated_at
         FROM detail_transaksi dt
         JOIN transaksi t ON t.id = dt.transaksi_id
         LEFT JOIN pelanggan pel ON pel.id = t.pelanggan_id
         LEFT JOIN produk p ON p.id = dt.produk_id
         LEFT JOIN (
            SELECT p1.detail_transaksi_id, p1.no_dokumen, p1.tipe_dokumen, p1.status, p1.karyawan_id
            FROM produksi p1
            INNER JOIN (
                SELECT detail_transaksi_id, MAX(id) AS latest_id
                FROM produksi
                WHERE detail_transaksi_id IS NOT NULL
                GROUP BY detail_transaksi_id
            ) latest ON latest.latest_id = p1.id
         ) pr ON pr.detail_transaksi_id = dt.id
         LEFT JOIN karyawan k ON k.id = pr.karyawan_id
         LEFT JOIN job_costing_item jc ON jc.detail_transaksi_id = dt.id
         WHERE dt.kategori_tipe IN ('printing','apparel')
           AND t.status <> 'batal'
           AND DATE_FORMAT(t.created_at, '%Y-%m') = ?
         ORDER BY FIELD(dt.kategori_tipe, 'printing', 'apparel'), t.created_at DESC, dt.id DESC"
    );

    if ($stmtRows) {
        $stmtRows->bind_param('s', $bulan);
        $stmtRows->execute();
        $rawRows = $stmtRows->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtRows->close();
    }
}

if (!empty($rawRows)) {
    $detailIds = array_values(array_unique(array_map(static function (array $row): int {
        return (int) ($row['detail_transaksi_id'] ?? 0);
    }, $rawRows)));
    $detailIds = array_values(array_filter($detailIds, static fn (int $id): bool => $id > 0));

    if (!empty($detailIds)) {
        $detailIdSql = implode(',', $detailIds);
        $resultMaterial = $conn->query(
            "SELECT
                detail_transaksi_id,
                stok_bahan_id,
                nama_bahan,
                satuan,
                qty,
                unit_cost,
                total_cost
             FROM job_material_usage
             WHERE detail_transaksi_id IN ({$detailIdSql})
             ORDER BY detail_transaksi_id ASC, id ASC"
        );

        if ($resultMaterial) {
            while ($materialRow = $resultMaterial->fetch_assoc()) {
                $detailId = (int) ($materialRow['detail_transaksi_id'] ?? 0);
                if ($detailId <= 0) {
                    continue;
                }

                $materialUsageByDetail[$detailId][] = [
                    'stok_bahan_id' => isset($materialRow['stok_bahan_id']) ? (int) $materialRow['stok_bahan_id'] : null,
                    'nama_bahan' => (string) ($materialRow['nama_bahan'] ?? ''),
                    'satuan' => (string) ($materialRow['satuan'] ?? ''),
                    'qty' => (float) ($materialRow['qty'] ?? 0),
                    'unit_cost' => (float) ($materialRow['unit_cost'] ?? 0),
                    'total_cost' => (float) ($materialRow['total_cost'] ?? 0),
                ];
            }
        }
    }
}

$grossByTransaction = [];
foreach ($rawRows as $row) {
    $transactionId = (int) ($row['transaksi_id'] ?? 0);
    if (!isset($grossByTransaction[$transactionId])) {
        $grossByTransaction[$transactionId] = 0;
    }
    $grossByTransaction[$transactionId] += (float) ($row['subtotal'] ?? 0);
}

$rows = [];
foreach ($rawRows as $row) {
    $deptKey = (string) ($row['kategori_tipe'] ?? 'printing');
    $detailId = (int) ($row['detail_transaksi_id'] ?? 0);
    $hasCosting = !empty($row['costing_id']);
    $materialRows = $materialUsageByDetail[$detailId] ?? [];
    $hasMaterialUsage = !empty($materialRows);
    $materialUsageTotal = hppMaterialUsageTotal($materialRows);
    $suggestedMaterial = hppSuggestedMaterialCost($row);
    $suggestedFinishing = hppSuggestedFinishingCost($row);
    $effectiveMaterial = $hasMaterialUsage
        ? $materialUsageTotal
        : ($hasCosting ? (float) ($row['bahan_baku_cost'] ?? 0) : $suggestedMaterial);
    $effectiveFinishing = $hasCosting ? (float) ($row['finishing_cost'] ?? 0) : $suggestedFinishing;
    $tenagaKerja = $hasCosting ? (float) ($row['tenaga_kerja_cost'] ?? 0) : 0;
    $overhead = $hasCosting ? (float) ($row['overhead_cost'] ?? 0) : 0;
    $subkon = $hasCosting ? (float) ($row['subkon_cost'] ?? 0) : 0;
    $pengiriman = $hasCosting ? (float) ($row['pengiriman_cost'] ?? 0) : 0;
    $lainLain = $hasCosting ? (float) ($row['lain_lain_cost'] ?? 0) : 0;
    $omzetBersih = hppNetSales($row, $grossByTransaction);
    $totalHpp = $effectiveMaterial + $effectiveFinishing + $tenagaKerja + $overhead + $subkon + $pengiriman + $lainLain;
    $laba = $omzetBersih - $totalHpp;
    $marginPct = $omzetBersih > 0 ? round(($laba / $omzetBersih) * 100, 1) : 0;

    $row['has_costing'] = $hasCosting;
    $row['has_material_usage'] = $hasMaterialUsage;
    $row['material_usage_rows'] = $materialRows;
    $row['material_usage_total'] = $materialUsageTotal;
    $row['suggested_material_cost'] = $suggestedMaterial;
    $row['suggested_finishing_cost'] = $suggestedFinishing;
    $row['effective_material_cost'] = $effectiveMaterial;
    $row['effective_finishing_cost'] = $effectiveFinishing;
    $row['tenaga_kerja_cost'] = $tenagaKerja;
    $row['overhead_cost'] = $overhead;
    $row['subkon_cost'] = $subkon;
    $row['pengiriman_cost'] = $pengiriman;
    $row['lain_lain_cost'] = $lainLain;
    $row['omzet_bersih'] = $omzetBersih;
    $row['total_hpp'] = $totalHpp;
    $row['laba'] = $laba;
    $row['margin_pct'] = $marginPct;
    $rows[] = $row;

    if (!isset($departmentSummary[$deptKey])) {
        $departmentSummary[$deptKey] = hppBuildSummary($deptKey);
    }

    $departmentSummary[$deptKey]['count']++;
    $departmentSummary[$deptKey]['omzet'] += $omzetBersih;
    $departmentSummary[$deptKey]['hpp'] += $totalHpp;
    $departmentSummary[$deptKey]['laba'] += $laba;
    if (!$hasCosting) {
        $departmentSummary[$deptKey]['missing_actual']++;
    }
}

foreach ($departmentSummary as $key => $summary) {
    $departmentSummary[$key]['margin_pct'] = $summary['omzet'] > 0
        ? round(($summary['laba'] / $summary['omzet']) * 100, 1)
        : 0;
}

$visibleRows = array_values(array_filter($rows, static function (array $row) use ($departemen): bool {
    return ($row['kategori_tipe'] ?? '') === $departemen;
}));
$activeSummary = $departmentSummary[$departemen] ?? hppBuildSummary($departemen);
$selectedCount = count($visibleRows);

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">';
$pageState = [
    'hppState' => [
        'materialsByDepartment' => $materialCatalog,
    ],
];
$pageJs = 'hpp.js';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>
<?php if (!$tableReady): ?>
    <div class="alert alert-danger">Tabel pendukung HPP belum siap sehingga rincian biaya per job belum bisa dipakai penuh.</div>
<?php endif; ?>

<div class="page-stack admin-panel hpp-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-calculator"></i> HPP & Margin</div>
                <h1 class="page-title">Perhitungan HPP dipisah per departemen supaya printing dan apparel tidak tercampur</h1>
                <p class="page-description">
                    Modul ini membaca order per item/job lalu mengelompokkan biaya aktual dan margin berdasarkan departemen. Produk, bahan baku, finishing, dan laba dibaca terpisah agar analisis lebih terstruktur.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas <?= hppDepartmentIcon($departemen) ?>"></i> Fokus: <?= htmlspecialchars(hppDepartmentLabel($departemen)) ?></span>
                    <span class="page-meta-item"><i class="fas fa-calendar"></i> <?= htmlspecialchars($monthLabel) ?></span>
                    <span class="page-meta-item"><i class="fas fa-list-ol"></i> <?= number_format($selectedCount) ?> job tampil</span>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('laporan.php') ?>" class="btn btn-primary"><i class="fas fa-chart-bar"></i> Laporan</a>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <?php foreach (['printing', 'apparel'] as $deptKey): ?>
            <?php $summary = $departmentSummary[$deptKey] ?? hppBuildSummary($deptKey); ?>
            <div class="metric-card">
                <span class="metric-label"><?= htmlspecialchars(hppDepartmentLabel($deptKey)) ?></span>
                <span class="metric-value"><?= hppFormatMoney((float) $summary['omzet']) ?></span>
                <span class="metric-note">
                    <?= number_format((int) $summary['count']) ?> job, laba <?= hppFormatMoney((float) $summary['laba'], true) ?>, margin <?= number_format((float) $summary['margin_pct'], 1, ',', '.') ?>%.
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="toolbar-surface admin-filter-grid">
        <div class="section-heading">
            <div>
                <h2>Filter Departemen</h2>
                <p>Gunakan tab untuk membaca HPP printing dan apparel secara terpisah, lalu ganti bulan untuk menilai performa periodenya.</p>
            </div>
        </div>
        <div class="admin-toolbar">
            <form method="GET" class="admin-inline-actions" style="flex:1 1 420px;">
                <input type="hidden" name="departemen" value="<?= htmlspecialchars($departemen) ?>">
                <input type="month" name="bulan" value="<?= htmlspecialchars($bulan) ?>" class="form-control" style="min-width:180px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
            </form>
            <div class="report-meta-chip">
                <i class="fas fa-layer-group"></i>
                <?= number_format((int) ($activeSummary['missing_actual'] ?? 0)) ?> job belum punya biaya aktual
            </div>
        </div>
        <div class="filter-pills">
            <?php foreach (['printing', 'apparel'] as $deptKey): ?>
                <?php $summary = $departmentSummary[$deptKey] ?? hppBuildSummary($deptKey); ?>
                <a href="?departemen=<?= urlencode($deptKey) ?>&bulan=<?= urlencode($bulan) ?>" class="filter-pill <?= $departemen === $deptKey ? 'active' : '' ?>">
                    <span><i class="fas <?= hppDepartmentIcon($deptKey) ?>"></i> <?= htmlspecialchars(hppDepartmentLabel($deptKey)) ?></span>
                    <span class="filter-pill-count"><?= number_format((int) $summary['count']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Omzet Bersih</span>
            <span class="metric-value"><?= hppFormatMoney((float) ($activeSummary['omzet'] ?? 0)) ?></span>
            <span class="metric-note">Subtotal item dikurangi alokasi diskon transaksi, pajak tidak dihitung sebagai basis margin.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Total HPP</span>
            <span class="metric-value"><?= hppFormatMoney((float) ($activeSummary['hpp'] ?? 0)) ?></span>
            <span class="metric-note">Termasuk bahan baku, finishing, tenaga kerja, overhead, subkon, pengiriman, dan biaya lain.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Estimasi Laba</span>
            <span class="metric-value"><?= hppFormatMoney((float) ($activeSummary['laba'] ?? 0), true) ?></span>
            <span class="metric-note">Nilai positif berarti job departemen ini masih menyisakan margin setelah HPP.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Margin</span>
            <span class="metric-value"><?= number_format((float) ($activeSummary['margin_pct'] ?? 0), 1, ',', '.') ?>%</span>
            <span class="metric-note">Job tanpa biaya aktual masih memakai estimasi dasar dari master produk dan finishing.</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas <?= hppDepartmentIcon($departemen) ?>"></i> Job Costing <?= htmlspecialchars(hppDepartmentLabel($departemen)) ?></span>
                <div class="card-subtitle">Setiap baris mewakili satu item/job agar biaya departemen tidak tercampur di level transaksi.</div>
            </div>
        </div>

        <div class="admin-toolbar" style="margin-bottom:18px;">
            <div class="search-bar">
                <input type="text" id="srchHpp" class="form-control" placeholder="Cari invoice, nama pelanggan, item, PIC, atau nomor dokumen..." oninput="filterHppView()">
            </div>
        </div>

        <?php if (!empty($visibleRows)): ?>
            <div class="table-responsive table-desktop">
                <table id="tblHpp">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Invoice / Job</th>
                            <th>Pelanggan</th>
                            <th>Item</th>
                            <th>Omzet Bersih</th>
                            <th>Bahan</th>
                            <th>Finishing</th>
                            <th>Total HPP</th>
                            <th>Laba</th>
                            <th>Margin</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visibleRows as $index => $row): ?>
                        <?php
                        $materialCount = count($row['material_usage_rows'] ?? []);
                        $payload = [
                            'detail_id' => (int) $row['detail_transaksi_id'],
                            'departemen' => (string) $row['kategori_tipe'],
                            'invoice' => (string) ($row['no_transaksi'] ?? '-'),
                            'job' => (string) ($row['no_dokumen'] ?? '-'),
                            'pelanggan' => (string) ($row['nama_pelanggan'] ?? 'Umum'),
                            'item' => (string) ($row['nama_produk'] ?? ''),
                            'qty' => (float) ($row['qty'] ?? 0),
                            'satuan' => (string) ($row['satuan'] ?? ''),
                            'omzet' => (float) ($row['omzet_bersih'] ?? 0),
                            'suggested_bahan' => (float) ($row['suggested_material_cost'] ?? 0),
                            'suggested_finishing' => (float) ($row['suggested_finishing_cost'] ?? 0),
                            'bahan_baku_cost' => (float) ($row['effective_material_cost'] ?? 0),
                            'finishing_cost' => (float) ($row['effective_finishing_cost'] ?? 0),
                            'tenaga_kerja_cost' => (float) ($row['tenaga_kerja_cost'] ?? 0),
                            'overhead_cost' => (float) ($row['overhead_cost'] ?? 0),
                            'subkon_cost' => (float) ($row['subkon_cost'] ?? 0),
                            'pengiriman_cost' => (float) ($row['pengiriman_cost'] ?? 0),
                            'lain_lain_cost' => (float) ($row['lain_lain_cost'] ?? 0),
                            'catatan' => (string) ($row['catatan_costing'] ?? ''),
                            'total_hpp' => (float) ($row['total_hpp'] ?? 0),
                            'material_rows' => array_values($row['material_usage_rows'] ?? []),
                            'material_usage_total' => (float) ($row['material_usage_total'] ?? 0),
                        ];
                        ?>
                        <tr class="hpp-row">
                            <td><?= $index + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['no_transaksi'] ?? '-') ?></strong>
                                <div class="text-muted small"><?= htmlspecialchars(($row['tipe_dokumen'] ?? '-') . ' ' . ($row['no_dokumen'] ?? '-')) ?></div>
                            </td>
                            <td><?= htmlspecialchars($row['nama_pelanggan'] ?? 'Umum') ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['nama_produk'] ?? '-') ?></strong>
                                <div class="text-muted small">
                                    Qty <?= number_format((float) ($row['qty'] ?? 0), 2, ',', '.') ?> <?= htmlspecialchars($row['satuan'] ?? '') ?>
                                    <?php if (!empty($row['finishing_nama'])): ?> | <?= htmlspecialchars($row['finishing_nama']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td><?= hppFormatMoney((float) ($row['omzet_bersih'] ?? 0)) ?></td>
                            <td>
                                <?= hppFormatMoney((float) ($row['effective_material_cost'] ?? 0)) ?>
                                <div class="text-muted small">
                                    <?= $materialCount > 0
                                        ? number_format($materialCount) . ' bahan aktual'
                                        : (!empty($row['has_costing']) ? 'manual tanpa rincian' : 'estimasi dasar') ?>
                                </div>
                            </td>
                            <td><?= hppFormatMoney((float) ($row['effective_finishing_cost'] ?? 0)) ?></td>
                            <td><?= hppFormatMoney((float) ($row['total_hpp'] ?? 0)) ?></td>
                            <td style="color:<?= (float) ($row['laba'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:700;"><?= hppFormatMoney((float) ($row['laba'] ?? 0), true) ?></td>
                            <td><?= number_format((float) ($row['margin_pct'] ?? 0), 1, ',', '.') ?>%</td>
                            <td>
                                <span class="badge <?= !empty($row['has_costing']) ? 'badge-success' : 'badge-warning' ?>">
                                    <?= !empty($row['has_costing']) ? 'Aktual' : 'Estimasi' ?>
                                </span>
                                <div class="text-muted small" style="margin-top:6px;">
                                    <span class="badge <?= hppStatusBadgeClass((string) ($row['transaksi_status'] ?? '')) ?>"><?= htmlspecialchars((string) ($row['transaksi_status'] ?? '-')) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-primary btn-sm" onclick='openHppModal(<?= json_encode($payload, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen-to-square"></i></button>
                                    <form method="POST" onsubmit="return resetHppCosting(this, <?= json_encode((string) ($row['nama_produk'] ?? 'item ini'), JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="reset_costing">
                                        <input type="hidden" name="detail_transaksi_id" value="<?= (int) $row['detail_transaksi_id'] ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-data-list" id="mobileHppList">
                    <?php foreach ($visibleRows as $row): ?>
                        <?php
                        $materialCount = count($row['material_usage_rows'] ?? []);
                        $payload = [
                            'detail_id' => (int) $row['detail_transaksi_id'],
                            'departemen' => (string) $row['kategori_tipe'],
                        'invoice' => (string) ($row['no_transaksi'] ?? '-'),
                        'job' => (string) ($row['no_dokumen'] ?? '-'),
                        'pelanggan' => (string) ($row['nama_pelanggan'] ?? 'Umum'),
                        'item' => (string) ($row['nama_produk'] ?? ''),
                        'qty' => (float) ($row['qty'] ?? 0),
                        'satuan' => (string) ($row['satuan'] ?? ''),
                        'omzet' => (float) ($row['omzet_bersih'] ?? 0),
                        'suggested_bahan' => (float) ($row['suggested_material_cost'] ?? 0),
                        'suggested_finishing' => (float) ($row['suggested_finishing_cost'] ?? 0),
                        'bahan_baku_cost' => (float) ($row['effective_material_cost'] ?? 0),
                        'finishing_cost' => (float) ($row['effective_finishing_cost'] ?? 0),
                        'tenaga_kerja_cost' => (float) ($row['tenaga_kerja_cost'] ?? 0),
                        'overhead_cost' => (float) ($row['overhead_cost'] ?? 0),
                        'subkon_cost' => (float) ($row['subkon_cost'] ?? 0),
                        'pengiriman_cost' => (float) ($row['pengiriman_cost'] ?? 0),
                        'lain_lain_cost' => (float) ($row['lain_lain_cost'] ?? 0),
                            'catatan' => (string) ($row['catatan_costing'] ?? ''),
                            'total_hpp' => (float) ($row['total_hpp'] ?? 0),
                            'material_rows' => array_values($row['material_usage_rows'] ?? []),
                            'material_usage_total' => (float) ($row['material_usage_total'] ?? 0),
                        ];
                        ?>
                    <div class="mobile-data-card hpp-mobile-card">
                        <div class="mobile-data-top">
                            <div>
                                <div class="mobile-data-title"><?= htmlspecialchars($row['nama_produk'] ?? '-') ?></div>
                                <div class="mobile-data-subtitle"><?= htmlspecialchars($row['no_transaksi'] ?? '-') ?> | <?= htmlspecialchars($row['no_dokumen'] ?? '-') ?></div>
                            </div>
                            <span class="badge <?= !empty($row['has_costing']) ? 'badge-success' : 'badge-warning' ?>"><?= !empty($row['has_costing']) ? 'Aktual' : 'Estimasi' ?></span>
                        </div>
                        <div class="mobile-data-grid">
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Pelanggan</span>
                                <span class="mobile-data-value"><?= htmlspecialchars($row['nama_pelanggan'] ?? 'Umum') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Qty</span>
                                <span class="mobile-data-value"><?= number_format((float) ($row['qty'] ?? 0), 2, ',', '.') ?> <?= htmlspecialchars($row['satuan'] ?? '') ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Omzet Bersih</span>
                                <span class="mobile-data-value"><?= hppFormatMoney((float) ($row['omzet_bersih'] ?? 0)) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Total HPP</span>
                                <span class="mobile-data-value"><?= hppFormatMoney((float) ($row['total_hpp'] ?? 0)) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Laba</span>
                                <span class="mobile-data-value" style="color:<?= (float) ($row['laba'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:700;"><?= hppFormatMoney((float) ($row['laba'] ?? 0), true) ?></span>
                            </div>
                            <div class="mobile-data-field">
                                <span class="mobile-data-label">Margin</span>
                                <span class="mobile-data-value"><?= number_format((float) ($row['margin_pct'] ?? 0), 1, ',', '.') ?>%</span>
                            </div>
                        </div>
                        <div class="mobile-note">
                            <?= !empty($row['finishing_nama']) ? 'Finishing: ' . htmlspecialchars((string) $row['finishing_nama']) . '. ' : '' ?>
                            <?= $materialCount > 0 ? number_format($materialCount) . ' bahan aktual tercatat. ' : 'Belum ada rincian bahan aktual. ' ?>
                            Status transaksi: <?= htmlspecialchars((string) ($row['transaksi_status'] ?? '-')) ?>.
                        </div>
                        <div class="mobile-data-actions">
                            <button type="button" class="btn btn-primary btn-sm" onclick='openHppModal(<?= json_encode($payload, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen-to-square"></i> Atur Biaya</button>
                            <form method="POST" onsubmit="return resetHppCosting(this, <?= json_encode((string) ($row['nama_produk'] ?? 'item ini'), JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="reset_costing">
                                <input type="hidden" name="detail_transaksi_id" value="<?= (int) $row['detail_transaksi_id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> Reset</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas <?= hppDepartmentIcon($departemen) ?>"></i>
                <div>Belum ada job <?= strtolower(hppDepartmentLabel($departemen)) ?> pada periode ini.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="modalHppCosting">
    <div class="modal-box modal-lg">
        <div class="modal-header">
            <h5>Atur Biaya HPP</h5>
            <button class="modal-close" onclick="closeModal('modalHppCosting')">&times;</button>
        </div>
        <form method="POST" id="hppCostingForm">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="save_costing">
            <input type="hidden" name="detail_transaksi_id" id="hppDetailId">
            <input type="hidden" name="material_usage_json" id="hppMaterialUsageJson" value="[]">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Departemen</label>
                        <input type="text" id="hppDepartemen" class="form-control" readonly style="background:var(--bg)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice / Job</label>
                        <input type="text" id="hppInvoiceJob" class="form-control" readonly style="background:var(--bg)">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Item</label>
                    <input type="text" id="hppItemName" class="form-control" readonly style="background:var(--bg)">
                </div>
                <div class="metric-strip" style="margin-bottom:16px;">
                    <div class="metric-card">
                        <span class="metric-label">Omzet Bersih</span>
                        <span class="metric-value" id="hppOmzetPreview">Rp 0</span>
                        <span class="metric-note">Omzet item setelah alokasi diskon transaksi.</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Total HPP</span>
                        <span class="metric-value" id="hppTotalPreview">Rp 0</span>
                        <span class="metric-note">Akan dihitung otomatis saat nilai biaya diubah.</span>
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Bahan Baku</label>
                        <input type="number" step="0.01" min="0" name="bahan_baku_cost" id="hppBahanCost" class="form-control" oninput="updateHppPreview()">
                        <small class="text-muted">Saran otomatis: <span id="hppSuggestedBahan">Rp 0</span></small>
                        <small class="text-muted d-block" id="hppBahanModeNote">Kosongkan rincian bahan jika ingin isi biaya bahan secara manual.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Finishing</label>
                        <input type="number" step="0.01" min="0" name="finishing_cost" id="hppFinishingCost" class="form-control" oninput="updateHppPreview()">
                        <small class="text-muted">Saran otomatis: <span id="hppSuggestedFinishing">Rp 0</span></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tenaga Kerja</label>
                        <input type="number" step="0.01" min="0" name="tenaga_kerja_cost" id="hppTenagaKerjaCost" class="form-control" oninput="updateHppPreview()">
                    </div>
                </div>
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-header">
                        <div>
                            <span class="card-title"><i class="fas fa-layer-group"></i> Pemakaian Bahan Per Job</span>
                            <div class="card-subtitle">Bahan yang ditampilkan hanya dari stok bahan departemen aktif agar printing dan apparel tetap terpisah.</div>
                        </div>
                    </div>
                    <div style="padding:0 18px 18px;">
                        <div class="admin-toolbar" style="margin-bottom:12px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addHppMaterialRow()">
                                <i class="fas fa-plus"></i> Tambah Bahan
                            </button>
                            <div class="report-meta-chip">
                                <i class="fas fa-boxes-stacked"></i>
                                <span id="hppMaterialCount">0 bahan</span> | total <span id="hppMaterialTotal">Rp 0</span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="hppMaterialTable">
                                <thead>
                                    <tr>
                                        <th>Bahan Baku</th>
                                        <th>Qty Pakai</th>
                                        <th>Satuan</th>
                                        <th>Harga Beli</th>
                                        <th>Total</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="hppMaterialRows"></tbody>
                            </table>
                        </div>
                        <div class="empty-state" id="hppMaterialEmpty" style="display:none; margin-top:12px;">
                            <i class="fas fa-box-open"></i>
                            <div>Belum ada rincian bahan aktual. Anda masih bisa simpan biaya bahan manual jika stok bahannya belum didaftarkan.</div>
                        </div>
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Overhead</label>
                        <input type="number" step="0.01" min="0" name="overhead_cost" id="hppOverheadCost" class="form-control" oninput="updateHppPreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subkon / Vendor</label>
                        <input type="number" step="0.01" min="0" name="subkon_cost" id="hppSubkonCost" class="form-control" oninput="updateHppPreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pengiriman</label>
                        <input type="number" step="0.01" min="0" name="pengiriman_cost" id="hppPengirimanCost" class="form-control" oninput="updateHppPreview()">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Biaya Lain-lain</label>
                        <input type="number" step="0.01" min="0" name="lain_lain_cost" id="hppLainLainCost" class="form-control" oninput="updateHppPreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estimasi Laba</label>
                        <input type="text" id="hppProfitPreview" class="form-control" readonly style="background:var(--bg)">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan Biaya</label>
                    <textarea name="catatan" id="hppCatatan" class="form-control" rows="3" placeholder="Contoh: tambahan lembur, biaya vendor bordir, packing khusus, atau catatan margin rendah."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalHppCosting')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Biaya</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
