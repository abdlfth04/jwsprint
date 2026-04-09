<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();
transactionOrderEnsureSupport($conn);

function invoiceStatusClass(string $status): string
{
    switch (strtolower(trim($status))) {
        case 'selesai':
            return 'success';
        case 'dp':
        case 'tempo':
        case 'pending':
        case 'proses':
            return 'warning';
        case 'void':
        case 'batal':
            return 'danger';
        default:
            return 'secondary';
    }
}

function invoicePaymentLabel(string $method): string
{
    $normalized = strtolower(trim($method));
    switch ($normalized) {
        case 'cash':
            return 'Tunai';
        case 'transfer':
            return 'Transfer';
        case 'qris':
            return 'QRIS';
        case 'downpayment':
            return 'Down Payment';
        case 'tempo':
            return 'Tempo';
        default:
            return strtoupper(trim($method));
    }
}

function invoiceFormatNumber(float $value): string
{
    $formatted = number_format($value, 2, ',', '.');
    $formatted = rtrim($formatted, '0');

    return rtrim($formatted, ',');
}

function invoiceFormatQty(array $item): string
{
    $area = (float) ($item['luas'] ?? 0);
    $width = (float) ($item['lebar'] ?? 0);
    $height = (float) ($item['tinggi'] ?? 0);
    $qty = (float) ($item['qty'] ?? 0);
    $unit = trim((string) ($item['satuan'] ?? ''));
    if ($area > 0 && $width > 0 && $height > 0) {
        $dimensionLabel = invoiceFormatNumber($width) . ' x '
            . invoiceFormatNumber($height)
            . ' '
            . ($unit !== '' ? $unit : 'm');

        return $qty > 1 ? invoiceFormatNumber($qty) . ' x ' . $dimensionLabel : $dimensionLabel;
    }

    if ($qty > 0) {
        return trim(invoiceFormatNumber($qty) . ' ' . $unit);
    }

    return $unit !== '' ? $unit : '-';
}

function invoiceCompactText(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function invoiceDecodeUnicodeEscapes(string $value): string
{
    return preg_replace_callback(
        '/\\\\u([0-9a-fA-F]{4})/',
        static fn(array $match): string => json_decode('"\\u' . $match[1] . '"') ?: $match[0],
        $value
    ) ?? $value;
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (!canAccessTransactionDetail($id)) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke invoice ini.');
}

$stmtTrx = $conn->prepare(
    "SELECT t.*, p.nama AS nama_pelanggan, p.telepon AS tlp_pelanggan, p.email AS email_pelanggan,
            u.nama AS nama_kasir
     FROM transaksi t
     LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
     LEFT JOIN users u ON t.user_id = u.id
     WHERE t.id = ?
     LIMIT 1"
);
$trx = null;
if ($stmtTrx) {
    $stmtTrx->bind_param('i', $id);
    $stmtTrx->execute();
    $trx = $stmtTrx->get_result()->fetch_assoc();
    $stmtTrx->close();
}

if (!$trx) {
    echo '<p style="font-family:sans-serif;padding:20px">Data invoice tidak ditemukan.</p>';
    exit;
}

$items = [];
$stmtItems = $conn->prepare("SELECT * FROM detail_transaksi WHERE transaksi_id = ? ORDER BY id");
if ($stmtItems) {
    $stmtItems->bind_param('i', $id);
    $stmtItems->execute();
    $items = $stmtItems->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtItems->close();
}

$setting = $conn->query("SELECT * FROM setting WHERE id = 1")->fetch_assoc() ?: [];
$subtotalSum = (float) array_sum(array_map(static fn(array $item): float => (float) ($item['subtotal'] ?? 0), $items));
$discountAmount = (float) ($trx['diskon'] ?? 0);
$taxAmount = isset($trx['pajak']) ? (float) $trx['pajak'] : max(0, ((float) ($trx['total'] ?? 0)) - max(0, $subtotalSum - $discountAmount));
$amountPaid = (float) ($trx['bayar'] ?? 0);
$changeAmount = (float) ($trx['kembalian'] ?? 0);
$remainingAmount = (float) ($trx['sisa_bayar'] ?? 0);
$status = (string) ($trx['status'] ?? 'pending');
$paymentMethod = (string) ($trx['metode_bayar'] ?? '-');
$isOutstanding = in_array(strtolower($paymentMethod), ['downpayment', 'tempo'], true) && $remainingAmount > 0;
$statusLabel = strtoupper($status);
$companyName = invoiceDecodeUnicodeEscapes((string) ($setting['nama_toko'] ?? 'JWS Printing & Apparel'));
$companyLogo = !empty($setting['logo']) ? uploadUrl((string) $setting['logo']) : companyLogoUrl();
$customerName = trim((string) ($trx['nama_pelanggan'] ?? 'Pelanggan umum'));
$customerPhone = trim((string) ($trx['tlp_pelanggan'] ?? ''));
$customerEmail = trim((string) ($trx['email_pelanggan'] ?? ''));
$cashierName = trim((string) ($trx['nama_kasir'] ?? ''));
$transactionNote = trim((string) ($trx['catatan'] ?? ''));
$invoiceNote = trim((string) ($trx['catatan_invoice'] ?? ''));
$paymentStatusLabel = $isOutstanding ? 'Belum lunas' : 'Lunas';
$balanceLabel = $isOutstanding ? 'Sisa Bayar' : 'Kembalian';
$balanceAmount = $isOutstanding ? $remainingAmount : $changeAmount;
$itemCount = count($items);
$createdAtLabel = date('d M Y H:i', strtotime((string) ($trx['created_at'] ?? 'now')));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= htmlspecialchars((string) ($trx['no_transaksi'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('css/print_professional.css') ?>">
    <style>
        @page {
            size: A5 portrait;
            margin: 5mm;
        }

        body.print-document.invoice-print {
            --sheet-width: 148mm;
            font-size: 8.5pt;
            line-height: 1.28;
        }

        .invoice-print .sheet-inner {
            padding: 4.5mm 4.5mm 4mm;
        }

        .invoice-print .print-sheet {
            border-radius: 12px;
        }

        .invoice-shell {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .invoice-topbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 154px;
            gap: 6px;
            align-items: stretch;
        }

        .invoice-brand {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            padding: 7px 8px;
            border: 1px solid var(--line-soft);
            border-radius: 11px;
            background: linear-gradient(135deg, rgba(14, 116, 144, 0.09), rgba(255, 255, 255, 0.98));
        }

        .invoice-logo {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            padding: 5px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: #ffffff;
        }

        .invoice-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .invoice-brand-text {
            min-width: 0;
            flex: 1 1 auto;
        }

        .invoice-kicker {
            display: inline-flex;
            align-items: center;
            padding: 2px 6px;
            border-radius: 999px;
            background: rgba(15, 118, 110, 0.1);
            color: var(--brand-dark);
            font-size: 6.3pt;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .invoice-company-name {
            margin-top: 4px;
            font-size: 12.2pt;
            font-weight: 800;
            line-height: 1.08;
            overflow-wrap: anywhere;
        }

        .invoice-company-meta {
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 6.9pt;
            line-height: 1.28;
        }

        .invoice-reference {
            border: 1px solid var(--line-soft);
            border-radius: 11px;
            padding: 7px 8px;
            background: #ffffff;
        }

        .invoice-ref-title {
            margin-bottom: 5px;
            font-size: 6.2pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
        }

        .invoice-ref-row {
            display: flex;
            flex-direction: column;
            gap: 1px;
            padding: 3px 0;
            border-bottom: 1px dashed var(--line-soft);
        }

        .invoice-ref-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .invoice-ref-label {
            font-size: 6.1pt;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .invoice-ref-value {
            font-size: 7.7pt;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .invoice-titlebar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 6px;
        }

        .invoice-titlebar h1 {
            margin: 0;
            font-size: 13.2pt;
            line-height: 1;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .invoice-titlebar p {
            margin: 2px 0 0;
            color: var(--text-muted);
            font-size: 6.8pt;
        }

        .invoice-pill-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 4px;
            max-width: 55%;
        }

        .invoice-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 7px;
            border-radius: 999px;
            border: 1px solid var(--line-soft);
            background: var(--surface-soft);
            color: #334155;
            font-size: 6.5pt;
            font-weight: 700;
        }

        .invoice-print .status-chip {
            min-width: auto;
            padding: 3px 7px;
            font-size: 6.4pt;
        }

        .invoice-overview {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 5px;
        }

        .overview-item {
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            padding: 5px 6px;
            background: #ffffff;
        }

        .overview-label {
            font-size: 6pt;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .overview-value {
            margin-top: 2px;
            font-size: 7.3pt;
            font-weight: 700;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .overview-value.emphasis {
            font-size: 8.4pt;
            color: var(--brand-dark);
        }

        .invoice-block-grid,
        .invoice-footer-grid {
            display: grid;
            gap: 6px;
        }

        .invoice-block-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .invoice-footer-grid {
            grid-template-columns: minmax(0, 1fr) 150px;
            align-items: start;
        }

        .invoice-card {
            border: 1px solid var(--line-soft);
            border-radius: 11px;
            background: #ffffff;
            overflow: hidden;
        }

        .invoice-card-head {
            padding: 5px 7px;
            background: var(--surface-soft);
            border-bottom: 1px solid var(--line-soft);
        }

        .invoice-card-head strong {
            font-size: 6.7pt;
            color: var(--brand-dark);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .invoice-card-body {
            padding: 6px 7px;
        }

        .invoice-detail-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 5px 7px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }

        .detail-label {
            font-size: 6pt;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .detail-value {
            font-size: 7.5pt;
            font-weight: 700;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .compact-note {
            color: var(--text-muted);
            font-size: 6.9pt;
            line-height: 1.25;
        }

        .invoice-table-card .invoice-card-body {
            padding: 0;
        }

        .invoice-print .data-table {
            font-size: 6.6pt;
        }

        .invoice-print .data-table thead th {
            font-size: 5.8pt;
        }

        .invoice-print .data-table th,
        .invoice-print .data-table td {
            padding: 4px 4px;
        }

        .item-name {
            font-size: 7pt;
            font-weight: 700;
            line-height: 1.16;
            overflow-wrap: anywhere;
        }

        .item-meta {
            margin-top: 1px;
            color: var(--text-muted);
            font-size: 5.9pt;
            line-height: 1.18;
        }

        .note-stack {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .invoice-total-card .invoice-card-body {
            padding-top: 5px;
            padding-bottom: 5px;
        }

        .invoice-total-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px dashed var(--line-soft);
            font-size: 6.8pt;
        }

        .invoice-total-row:last-child {
            border-bottom: 0;
        }

        .invoice-total-row strong {
            text-align: right;
        }

        .invoice-total-row.emphasis {
            font-size: 8pt;
            font-weight: 800;
            color: var(--brand-dark);
        }

        .invoice-total-row.negative strong {
            color: #b91c1c;
        }

        .invoice-signatures {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 5px;
        }

        .signature-box {
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            padding: 6px;
            min-height: 54px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .signature-box .signature-role {
            font-size: 6pt;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .signature-box .signature-name {
            margin-top: 14px;
            padding-top: 4px;
            border-top: 1px solid var(--line-strong);
            font-size: 6.8pt;
            font-weight: 700;
            text-align: center;
            overflow-wrap: anywhere;
        }

        .invoice-print .document-footer {
            margin-top: 0;
            padding-top: 4px;
            font-size: 6.1pt;
            line-height: 1.22;
        }

        @media screen and (max-width: 900px) {
            .invoice-topbar,
            .invoice-block-grid,
            .invoice-footer-grid,
            .invoice-overview,
            .invoice-signatures {
                grid-template-columns: 1fr;
            }

            .invoice-titlebar {
                flex-direction: column;
            }

            .invoice-pill-list {
                justify-content: flex-start;
                max-width: none;
            }

            .invoice-detail-list {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="print-document portrait invoice-print">
    <div class="print-sheet">
        <div class="sheet-inner">
            <div class="invoice-shell">
                <header class="invoice-topbar">
                    <div class="invoice-brand">
                        <div class="invoice-logo">
                            <img src="<?= htmlspecialchars($companyLogo) ?>" alt="Logo perusahaan">
                        </div>
                        <div class="invoice-brand-text">
                            <div class="invoice-kicker">Dokumen Transaksi</div>
                            <div class="invoice-company-name"><?= htmlspecialchars($companyName) ?></div>
                            <div class="invoice-company-meta">
                                <?php if (!empty($setting['alamat'])): ?><div><?= htmlspecialchars((string) $setting['alamat']) ?></div><?php endif; ?>
                                <?php if (!empty($setting['telepon'])): ?><div>Telp: <?= htmlspecialchars((string) $setting['telepon']) ?></div><?php endif; ?>
                                <?php if (!empty($setting['email'])): ?><div>Email: <?= htmlspecialchars((string) $setting['email']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <aside class="invoice-reference">
                        <div class="invoice-ref-title">Info Invoice</div>
                        <div class="invoice-ref-row">
                            <span class="invoice-ref-label">No. Invoice</span>
                            <span class="invoice-ref-value"><?= htmlspecialchars((string) ($trx['no_transaksi'] ?? '-')) ?></span>
                        </div>
                        <div class="invoice-ref-row">
                            <span class="invoice-ref-label">Tanggal</span>
                            <span class="invoice-ref-value"><?= htmlspecialchars($createdAtLabel) ?></span>
                        </div>
                        <div class="invoice-ref-row">
                            <span class="invoice-ref-label">Kasir</span>
                            <span class="invoice-ref-value"><?= htmlspecialchars($cashierName !== '' ? $cashierName : '-') ?></span>
                        </div>
                    </aside>
                </header>

                <section class="invoice-titlebar">
                    <div>
                        <h1>Invoice</h1>
                        <p>Rincian transaksi yang dibuat ringkas agar tetap nyaman dibaca di kertas A5.</p>
                    </div>
                    <div class="invoice-pill-list">
                        <span class="invoice-pill">Metode: <?= htmlspecialchars(invoicePaymentLabel($paymentMethod)) ?></span>
                        <span class="invoice-pill">Pembayaran: <?= htmlspecialchars($paymentStatusLabel) ?></span>
                        <span class="status-chip <?= invoiceStatusClass($status) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    </div>
                </section>

                <section class="invoice-overview avoid-break">
                    <article class="overview-item">
                        <div class="overview-label">Pelanggan</div>
                        <div class="overview-value"><?= htmlspecialchars($customerName !== '' ? $customerName : 'Pelanggan umum') ?></div>
                    </article>
                    <article class="overview-item">
                        <div class="overview-label">Jumlah Item</div>
                        <div class="overview-value"><?= number_format($itemCount, 0, ',', '.') ?> item</div>
                    </article>
                    <article class="overview-item">
                        <div class="overview-label">Total Invoice</div>
                        <div class="overview-value emphasis">Rp <?= number_format((float) ($trx['total'] ?? 0), 0, ',', '.') ?></div>
                    </article>
                    <article class="overview-item">
                        <div class="overview-label"><?= htmlspecialchars($balanceLabel) ?></div>
                        <div class="overview-value emphasis">Rp <?= number_format($balanceAmount, 0, ',', '.') ?></div>
                    </article>
                </section>

                <section class="invoice-block-grid">
                    <article class="invoice-card avoid-break">
                        <div class="invoice-card-head"><strong>Data Pelanggan</strong></div>
                        <div class="invoice-card-body">
                            <div class="invoice-detail-list">
                                <div class="detail-item">
                                    <span class="detail-label">Nama</span>
                                    <span class="detail-value"><?= htmlspecialchars($customerName !== '' ? $customerName : 'Pelanggan umum') ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">No. Telepon</span>
                                    <span class="detail-value"><?= htmlspecialchars($customerPhone !== '' ? $customerPhone : '-') ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Email</span>
                                    <span class="detail-value"><?= htmlspecialchars($customerEmail !== '' ? $customerEmail : '-') ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Kasir</span>
                                    <span class="detail-value"><?= htmlspecialchars($cashierName !== '' ? $cashierName : '-') ?></span>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article class="invoice-card avoid-break">
                        <div class="invoice-card-head"><strong>Pembayaran</strong></div>
                        <div class="invoice-card-body">
                            <div class="invoice-detail-list">
                                <div class="detail-item">
                                    <span class="detail-label">Status Bayar</span>
                                    <span class="detail-value"><?= htmlspecialchars($paymentStatusLabel) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Metode</span>
                                    <span class="detail-value"><?= htmlspecialchars(invoicePaymentLabel($paymentMethod)) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Dibayar</span>
                                    <span class="detail-value">Rp <?= number_format($amountPaid, 0, ',', '.') ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><?= htmlspecialchars($balanceLabel) ?></span>
                                    <span class="detail-value">Rp <?= number_format($balanceAmount, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>

                <section class="invoice-card invoice-table-card">
                    <div class="invoice-card-head"><strong>Rincian Item</strong></div>
                    <div class="invoice-card-body">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 6%;">No</th>
                                    <th>Item</th>
                                    <th style="width: 20%;" class="text-center">Qty / Ukuran</th>
                                    <th style="width: 18%;" class="text-right">Harga</th>
                                    <th style="width: 20%;" class="text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $index => $item): ?>
                                    <?php
                                    $itemMetaParts = [];
                                    if (!empty($item['bahan_nama'])) {
                                        $itemMetaParts[] = 'Bahan: ' . (string) $item['bahan_nama'];
                                    }
                                    if (!empty($item['finishing_nama'])) {
                                        $itemMetaParts[] = 'Finishing: ' . (string) $item['finishing_nama'];
                                    }
                                    if (!empty($item['catatan'])) {
                                        $itemMetaParts[] = invoiceCompactText((string) $item['catatan']);
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $index + 1 ?></td>
                                        <td>
                                            <div class="item-name"><?= htmlspecialchars((string) ($item['nama_produk'] ?? '-')) ?></div>
                                            <?php if (!empty($itemMetaParts)): ?>
                                                <div class="item-meta"><?= htmlspecialchars(implode(' | ', $itemMetaParts)) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= htmlspecialchars(invoiceFormatQty($item)) ?></td>
                                        <td class="text-right">Rp <?= number_format((float) ($item['harga'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right strong-text">Rp <?= number_format((float) ($item['subtotal'] ?? 0), 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="invoice-footer-grid">
                    <div class="note-stack">
                        <?php if ($invoiceNote !== '' || $transactionNote !== ''): ?>
                            <article class="invoice-card avoid-break">
                                <div class="invoice-card-head"><strong>Catatan</strong></div>
                                <div class="invoice-card-body compact-note">
                                    <?php if ($invoiceNote !== ''): ?>
                                        <div><strong>Invoice:</strong> <?= nl2br(htmlspecialchars($invoiceNote)) ?></div>
                                    <?php endif; ?>
                                    <?php if ($transactionNote !== ''): ?>
                                        <div><strong>Transaksi:</strong> <?= nl2br(htmlspecialchars($transactionNote)) ?></div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endif; ?>

                        <section class="invoice-signatures avoid-break">
                            <div class="signature-box">
                                <div class="signature-role">Dibuat Oleh</div>
                                <div class="signature-name"><?= htmlspecialchars($cashierName !== '' ? $cashierName : '-') ?></div>
                            </div>
                            <div class="signature-box">
                                <div class="signature-role">Diterima Pelanggan</div>
                                <div class="signature-name"><?= htmlspecialchars($customerName !== '' ? $customerName : 'Pelanggan') ?></div>
                            </div>
                        </section>
                    </div>

                    <article class="invoice-card invoice-total-card avoid-break">
                        <div class="invoice-card-head"><strong>Ringkasan Nilai</strong></div>
                        <div class="invoice-card-body">
                            <div class="invoice-total-row"><span>Subtotal</span><strong>Rp <?= number_format($subtotalSum, 0, ',', '.') ?></strong></div>
                            <?php if ($discountAmount > 0): ?>
                                <div class="invoice-total-row negative"><span>Diskon</span><strong>- Rp <?= number_format($discountAmount, 0, ',', '.') ?></strong></div>
                            <?php endif; ?>
                            <?php if ($taxAmount > 0): ?>
                                <div class="invoice-total-row"><span>Pajak</span><strong>Rp <?= number_format($taxAmount, 0, ',', '.') ?></strong></div>
                            <?php endif; ?>
                            <div class="invoice-total-row emphasis"><span>Total Invoice</span><strong>Rp <?= number_format((float) ($trx['total'] ?? 0), 0, ',', '.') ?></strong></div>
                            <div class="invoice-total-row"><span>Sudah Dibayar</span><strong>Rp <?= number_format($amountPaid, 0, ',', '.') ?></strong></div>
                            <div class="invoice-total-row <?= $isOutstanding ? 'negative' : '' ?>">
                                <span><?= htmlspecialchars($balanceLabel) ?></span>
                                <strong>Rp <?= number_format($balanceAmount, 0, ',', '.') ?></strong>
                            </div>
                        </div>
                    </article>
                </section>

                <?php if (!empty($setting['footer_nota'])): ?>
                    <div class="document-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>window.onload = function () { window.print(); };</script>
</body>
</html>
