<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/quotation_manager.php';
requireRole('superadmin', 'admin', 'service', 'kasir');

$quotationId = (int) ($_GET['id'] ?? 0);
if ($quotationId <= 0 || !quotationEnsureSupportTables($conn)) {
    echo '<p style="font-family:sans-serif;padding:20px">Penawaran tidak valid.</p>';
    exit;
}

$quote = quotationLoadHeader($conn, $quotationId);
$items = quotationLoadItems($conn, $quotationId);
if (!$quote || empty($items)) {
    echo '<p style="font-family:sans-serif;padding:20px">Penawaran tidak ditemukan.</p>';
    exit;
}

$setting = $conn->query("SELECT * FROM setting WHERE id = 1 LIMIT 1")->fetch_assoc() ?: [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Penawaran - <?= htmlspecialchars((string) ($quote['no_penawaran'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('css/print_a4.css') ?>">
    <style>
        .quote-table td:last-child, .quote-table th:last-child { text-align: right; }
        .quote-table td:nth-child(3), .quote-table th:nth-child(3) { text-align: center; }
        .quote-summary { margin-top: 16px; margin-left: auto; width: 280px; }
        .quote-summary-row { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px dashed #d4d4d8; }
        .quote-summary-row.total { font-weight: 700; border-bottom: 2px solid #111827; }
        .quote-note { margin-top: 16px; font-size: 10pt; color: #374151; line-height: 1.6; }
    </style>
</head>
<body>
<div class="doc-header">
    <div class="header-left">
        <?php if (!empty($setting['logo'])): ?>
            <div class="header-logo"><img src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars((string) $setting['logo']) ?>" alt="Logo"></div>
        <?php endif; ?>
        <div class="header-company">
            <div class="company-name"><?= htmlspecialchars((string) ($setting['nama_toko'] ?? 'JWS Printing & Apparel')) ?></div>
            <?php if (!empty($setting['alamat'])): ?><div class="company-detail"><?= htmlspecialchars((string) $setting['alamat']) ?></div><?php endif; ?>
            <?php if (!empty($setting['telepon'])): ?><div class="company-detail">Telp: <?= htmlspecialchars((string) $setting['telepon']) ?></div><?php endif; ?>
            <?php if (!empty($setting['email'])): ?><div class="company-detail">Email: <?= htmlspecialchars((string) $setting['email']) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="header-right">
        <div style="font-size:9pt;color:#555">No. Penawaran: <?= htmlspecialchars((string) ($quote['no_penawaran'] ?? '')) ?></div>
        <div style="font-size:9pt;color:#555">Tanggal: <?= htmlspecialchars((string) ($quote['tanggal'] ?? '-')) ?></div>
        <div style="font-size:9pt;color:#555">Berlaku s/d: <?= htmlspecialchars((string) ($quote['berlaku_sampai'] ?? '-')) ?></div>
    </div>
</div>

<div class="doc-title">PENAWARAN HARGA</div>
<div class="doc-subtitle">Status: <?= htmlspecialchars(quotationStatusLabel((string) ($quote['status'] ?? 'draft'))) ?></div>

<div class="doc-info-grid">
    <div class="info-row">
        <span class="info-label">Pelanggan</span>
        <span class="info-value"><?= htmlspecialchars((string) ($quote['nama_pelanggan'] ?? 'Umum')) ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">Dibuat Oleh</span>
        <span class="info-value"><?= htmlspecialchars((string) ($quote['nama_pembuat'] ?? '-')) ?></span>
    </div>
    <?php if (!empty($quote['no_transaksi_konversi'])): ?>
        <div class="info-row">
            <span class="info-label">Transaksi</span>
            <span class="info-value"><?= htmlspecialchars((string) $quote['no_transaksi_konversi']) ?></span>
        </div>
    <?php endif; ?>
</div>

<div class="doc-section">
    <div class="doc-section-title">Rincian Item</div>
    <table class="quote-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Kategori</th>
                <th>Qty</th>
                <th>Harga</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) ($item['nama_item'] ?? '')) ?></strong>
                        <?php if (!empty($item['finishing_nama'])): ?><div style="font-size:9pt;color:#6b7280">Finishing: <?= htmlspecialchars((string) $item['finishing_nama']) ?></div><?php endif; ?>
                        <?php if (!empty($item['catatan'])): ?><div style="font-size:9pt;color:#6b7280"><?= nl2br(htmlspecialchars((string) $item['catatan'])) ?></div><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(ucfirst((string) ($item['kategori_tipe'] ?? 'lainnya'))) ?></td>
                    <td><?= number_format((float) ($item['qty'] ?? 0), 2, ',', '.') ?> <?= htmlspecialchars((string) ($item['satuan'] ?? '')) ?></td>
                    <td>Rp <?= number_format((float) ($item['harga'] ?? 0), 0, ',', '.') ?></td>
                    <td>Rp <?= number_format((float) ($item['subtotal'] ?? 0), 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="quote-summary">
        <div class="quote-summary-row"><span>Subtotal</span><strong>Rp <?= number_format((float) ($quote['subtotal'] ?? 0), 0, ',', '.') ?></strong></div>
        <div class="quote-summary-row"><span>Diskon</span><strong>Rp <?= number_format((float) ($quote['diskon'] ?? 0), 0, ',', '.') ?></strong></div>
        <div class="quote-summary-row"><span>Pajak</span><strong>Rp <?= number_format((float) ($quote['pajak'] ?? 0), 0, ',', '.') ?></strong></div>
        <div class="quote-summary-row total"><span>Total</span><strong>Rp <?= number_format((float) ($quote['total'] ?? 0), 0, ',', '.') ?></strong></div>
    </div>
</div>

<?php if (!empty($quote['catatan'])): ?>
    <div class="quote-note">
        <strong>Catatan:</strong><br>
        <?= nl2br(htmlspecialchars((string) $quote['catatan'])) ?>
    </div>
<?php endif; ?>

<?php if (!empty($setting['footer_nota'])): ?>
    <div class="doc-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
<?php endif; ?>

<script>window.onload = function () { window.print(); };</script>
</body>
</html>
