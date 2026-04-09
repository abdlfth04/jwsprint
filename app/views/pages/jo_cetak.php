<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();

function joStatusClass(string $status): string
{
    switch (strtolower(trim($status))) {
        case 'selesai':
            return 'success';
        case 'proses':
        case 'antrian':
        case 'pending':
            return 'warning';
        case 'batal':
            return 'danger';
        default:
            return 'secondary';
    }
}

function joFormatDimension(array $row): string
{
    $area = (float) ($row['luas'] ?? 0);
    $width = (float) ($row['lebar'] ?? 0);
    $height = (float) ($row['tinggi'] ?? 0);
    if ($area > 0 && $width > 0 && $height > 0) {
        return rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.') . ' x '
            . rtrim(rtrim(number_format($height, 2, '.', ''), '0'), '.') . ' m';
    }

    return number_format((float) ($row['qty'] ?? 0), 0, ',', '.') . ' ' . (string) ($row['satuan'] ?? '');
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (!canAccessProductionRecord($id)) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke dokumen JO ini.');
}

$stmtJo = $conn->prepare(
    "SELECT pr.*,
            t.no_transaksi, t.created_at AS tgl_invoice,
            p.nama AS nama_pelanggan, p.telepon AS tlp_pelanggan,
            u.nama AS nama_user,
            dt.nama_produk, dt.kategori_tipe, dt.satuan, dt.qty,
            dt.lebar, dt.tinggi, dt.luas,
            dt.harga, dt.finishing_nama, dt.bahan_nama,
            dt.size_detail, dt.subtotal, dt.catatan AS catatan_item
     FROM produksi pr
     LEFT JOIN transaksi t ON pr.transaksi_id = t.id
     LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
     LEFT JOIN users u ON pr.user_id = u.id
     LEFT JOIN detail_transaksi dt ON pr.detail_transaksi_id = dt.id
     WHERE pr.id = ? AND pr.tipe_dokumen = 'JO'
     LIMIT 1"
);
$jo = null;
if ($stmtJo) {
    $stmtJo->bind_param('i', $id);
    $stmtJo->execute();
    $jo = $stmtJo->get_result()->fetch_assoc();
    $stmtJo->close();
}

if (!$jo) {
    echo '<p style="font-family:sans-serif;padding:20px">Data JO tidak ditemukan.</p>';
    exit;
}

$setting = $conn->query("SELECT * FROM setting WHERE id = 1")->fetch_assoc() ?: [];
$tahapan = [];
if (schemaTableExists($conn, 'todo_list_tahapan')) {
    $stmtTahapan = $conn->prepare(
        "SELECT tl.*, u.nama AS nama_operator
         FROM todo_list_tahapan tl
         LEFT JOIN users u ON tl.user_id = u.id
         WHERE tl.produksi_id = ?
         ORDER BY tl.urutan"
    );
    if ($stmtTahapan) {
        $stmtTahapan->bind_param('i', $id);
        $stmtTahapan->execute();
        $tahapan = $stmtTahapan->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtTahapan->close();
    }
}

$files = [];
if (schemaTableExists($conn, 'file_transaksi') && !empty($jo['transaksi_id'])) {
    $transactionId = (int) $jo['transaksi_id'];
    $detailId = (int) ($jo['detail_transaksi_id'] ?? 0);
    $fileRows = fetchScopedTransactionFiles(
        $conn,
        [$transactionId],
        ['cetak'],
        'f.id, f.transaksi_id, f.detail_transaksi_id, f.nama_asli, f.created_at'
    );
    $groupedFiles = groupScopedTransactionFiles($fileRows);
    $files = resolveScopedTransactionFiles($conn, $groupedFiles, $transactionId, $detailId, ['cetak']);
}

$companyName = (string) ($setting['nama_toko'] ?? 'JWS Printing & Apparel');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JO <?= htmlspecialchars((string) ($jo['no_dokumen'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('css/print_professional.css') ?>">
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm;
        }
    </style>
</head>
<body class="print-document landscape">
    <div class="print-sheet">
        <div class="sheet-inner">
            <header class="document-banner">
                <div class="document-banner-main">
                    <?php if (!empty($setting['logo'])): ?>
                        <div class="document-logo">
                            <img src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars((string) $setting['logo']) ?>" alt="Logo perusahaan">
                        </div>
                    <?php endif; ?>
                    <div class="document-company">
                        <div class="document-eyebrow">Produksi</div>
                        <div class="document-company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="document-company-meta">
                            <?php if (!empty($setting['alamat'])): ?><div><?= htmlspecialchars((string) $setting['alamat']) ?></div><?php endif; ?>
                            <?php if (!empty($setting['telepon'])): ?><div>Telp: <?= htmlspecialchars((string) $setting['telepon']) ?></div><?php endif; ?>
                            <?php if (!empty($setting['email'])): ?><div>Email: <?= htmlspecialchars((string) $setting['email']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <aside class="document-meta-card">
                    <div class="meta-title">Referensi JO</div>
                    <div class="meta-stack">
                        <div class="meta-line">
                            <span class="meta-line-label">No. JO</span>
                            <span class="meta-line-value"><?= htmlspecialchars((string) ($jo['no_dokumen'] ?? '-')) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">Invoice</span>
                            <span class="meta-line-value"><?= htmlspecialchars((string) ($jo['no_transaksi'] ?? '-')) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">Tanggal</span>
                            <span class="meta-line-value"><?= htmlspecialchars(date('d M Y', strtotime((string) ($jo['tanggal'] ?? 'now')))) ?></span>
                        </div>
                    </div>
                </aside>
            </header>

            <section class="document-heading">
                <div>
                    <h1>Job Order</h1>
                    <p>Dokumen operasional untuk meneruskan detail order ke tim produksi.</p>
                </div>
                <div class="document-badges">
                    <?php if (!empty($jo['deadline'])): ?>
                        <span class="pill">Deadline: <?= htmlspecialchars(date('d M Y', strtotime((string) $jo['deadline']))) ?></span>
                    <?php endif; ?>
                    <span class="status-chip <?= joStatusClass((string) ($jo['status'] ?? 'antrian')) ?>"><?= htmlspecialchars(strtoupper((string) ($jo['status'] ?? 'ANTRIAN'))) ?></span>
                </div>
            </section>

            <section class="info-panel-grid two-col">
                <article class="info-card avoid-break">
                    <div class="info-card-head"><strong>Identitas Order</strong></div>
                    <div class="card-body">
                        <div class="kv-grid">
                            <div class="kv-item">
                                <span class="kv-label">Pelanggan</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($jo['nama_pelanggan'] ?? 'Umum')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">No. Telepon</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($jo['tlp_pelanggan'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Nama Pekerjaan</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($jo['nama_pekerjaan'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Dibuat Oleh</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($jo['nama_user'] ?? '-')) ?></span>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="info-card avoid-break">
                    <div class="info-card-head"><strong>Spesifikasi Singkat</strong></div>
                    <div class="card-body">
                        <div class="kv-grid">
                            <div class="kv-item">
                                <span class="kv-label">Produk</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($jo['nama_produk'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Qty / Ukuran</span>
                                <span class="kv-value"><?= htmlspecialchars(joFormatDimension($jo)) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Bahan</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($jo['bahan_nama'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Finishing</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($jo['finishing_nama'] ?? '-')) ?></span>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <section class="summary-panel-grid two-col">
                <article class="summary-card avoid-break">
                    <div class="summary-card-head"><strong>Instruksi Pengerjaan</strong></div>
                    <div class="card-body">
                        <table class="data-table">
                            <tbody>
                                <tr>
                                    <td style="width: 24%;">Kategori</td>
                                    <td><?= htmlspecialchars((string) ($jo['kategori_tipe'] ?? '-')) ?></td>
                                </tr>
                                <tr>
                                    <td>Ukuran Detail</td>
                                    <td><?= htmlspecialchars((string) ($jo['size_detail'] ?? '-')) ?></td>
                                </tr>
                                <tr>
                                    <td>Catatan Item</td>
                                    <td><?= !empty($jo['catatan_item']) ? nl2br(htmlspecialchars((string) $jo['catatan_item'])) : '-' ?></td>
                                </tr>
                                <tr>
                                    <td>Instruksi Tambahan</td>
                                    <td><?= !empty($jo['keterangan']) ? nl2br(htmlspecialchars((string) $jo['keterangan'])) : '-' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="summary-card avoid-break">
                    <div class="summary-card-head"><strong>File Referensi</strong></div>
                    <div class="card-body">
                        <?php if (!empty($files)): ?>
                            <ul class="file-list">
                                <?php foreach ($files as $file): ?>
                                    <li><?= htmlspecialchars((string) ($file['nama_asli'] ?? 'File referensi')) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="lead-note">Belum ada file referensi yang ditautkan ke item produksi ini.</div>
                        <?php endif; ?>
                    </div>
                </article>
            </section>

            <section class="summary-card">
                <div class="summary-card-head"><strong>Checklist Tahapan Produksi</strong></div>
                <div class="card-body">
                    <?php if (!empty($tahapan)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 6%;">No</th>
                                    <th>Nama Tahapan</th>
                                    <th style="width: 28%;">Operator</th>
                                    <th style="width: 16%;" class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tahapan as $index => $tahap): ?>
                                    <tr>
                                        <td class="text-center"><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars((string) ($tahap['nama_tahapan'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string) ($tahap['nama_operator'] ?? '-')) ?></td>
                                        <td class="text-center">
                                            <span class="status-chip <?= joStatusClass((string) ($tahap['status'] ?? 'belum')) ?>"><?= htmlspecialchars(strtoupper((string) ($tahap['status'] ?? 'BELUM'))) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="lead-note">Belum ada tahapan produksi yang tersusun untuk JO ini.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="signature-grid">
                <div class="signature-card">
                    <div class="signature-role">Disiapkan Oleh</div>
                    <div class="signature-name"><?= htmlspecialchars((string) ($jo['nama_user'] ?? '-')) ?></div>
                </div>
                <div class="signature-card">
                    <div class="signature-role">Persetujuan Produksi</div>
                    <div class="signature-name">____________________________</div>
                </div>
            </section>

            <?php if (!empty($setting['footer_nota'])): ?>
                <div class="document-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <script>window.onload = function () { window.print(); };</script>
</body>
</html>
