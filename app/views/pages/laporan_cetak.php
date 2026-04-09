<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/monthly_report.php';
requireRole('superadmin', 'admin');

function reportPrintStatusClass(string $status): string
{
    switch (strtolower(trim($status))) {
        case 'selesai':
        case 'sudah_dibayar':
        case 'hadir':
            return 'success';
        case 'dp':
        case 'tempo':
        case 'pending':
        case 'proses':
        case 'antrian':
        case 'terlambat':
            return 'warning';
        case 'batal':
        case 'void':
        case 'alpha':
            return 'danger';
        case 'izin':
        case 'sakit':
            return 'info';
        default:
            return 'secondary';
    }
}

function reportPrintToneClass(string $tone): string
{
    $tone = strtolower(trim($tone));
    return in_array($tone, ['success', 'warning', 'danger', 'primary', 'secondary'], true) ? 'tone-' . $tone : 'tone-primary';
}

function reportPrintSliceRows(array $rows, int $limit = 6): array
{
    return array_slice(array_values($rows), 0, $limit);
}

function reportPrintAnalyticsPack(array $analytics): array
{
    return [
        'summary' => (string) ($analytics['summary'] ?? ''),
        'kpis' => array_slice(array_values(is_array($analytics['kpis'] ?? null) ? $analytics['kpis'] : []), 0, 4),
        'insights' => array_slice(array_values(is_array($analytics['insights'] ?? null) ? $analytics['insights'] : []), 0, 3),
        'visuals' => array_slice(array_values(is_array($analytics['visuals'] ?? null) ? $analytics['visuals'] : []), 0, 2),
    ];
}

$bulan = monthlyReportNormalizeMonth((string) ($_GET['bulan'] ?? date('Y-m')));
$report = monthlyReportBuildContext($conn, $bulan, 'ringkasan', true);
extract($report, EXTR_SKIP);
$ringkasanAnalytics = reportPrintAnalyticsPack(is_array($tabAnalytics['ringkasan'] ?? null) ? $tabAnalytics['ringkasan'] : []);
$transaksiAnalytics = reportPrintAnalyticsPack(is_array($tabAnalytics['transaksi'] ?? null) ? $tabAnalytics['transaksi'] : []);
$operasionalAnalytics = reportPrintAnalyticsPack(is_array($tabAnalytics['operasional'] ?? null) ? $tabAnalytics['operasional'] : []);
$produksiAnalytics = reportPrintAnalyticsPack(is_array($tabAnalytics['produksi'] ?? null) ? $tabAnalytics['produksi'] : []);
$absensiAnalytics = reportPrintAnalyticsPack(is_array($tabAnalytics['absensi'] ?? null) ? $tabAnalytics['absensi'] : []);
$karyawanAnalytics = reportPrintAnalyticsPack(is_array($tabAnalytics['karyawan'] ?? null) ? $tabAnalytics['karyawan'] : []);
$stokAnalytics = reportPrintAnalyticsPack(is_array($tabAnalytics['stok'] ?? null) ? $tabAnalytics['stok'] : []);

$setting = $conn->query("SELECT * FROM setting WHERE id = 1")->fetch_assoc() ?: [];
$companyName = (string) ($setting['nama_toko'] ?? 'JWS Printing & Apparel');
$preparedBy = (string) ($_SESSION['nama'] ?? $_SESSION['username'] ?? 'Administrator');
$trxSelesaiTotal = array_sum(array_map(static function (array $row): float {
    return (($row['status'] ?? '') === 'selesai') ? (float) ($row['total'] ?? 0) : 0;
}, $trxData));
$opsTotalPrinted = array_sum(array_map(static fn(array $row): float => (float) ($row['jumlah'] ?? 0), $opsData));
$karyawanTotalGaji = array_sum(array_map(static fn(array $row): float => (float) ($row['gaji_bulan'] ?? 0), $karyawanData));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Bulanan <?= htmlspecialchars($label) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('css/print_professional.css') ?>">
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm;
        }

        .report-table {
            font-size: 8.35pt;
        }
    </style>
</head>
<body class="print-document portrait">
    <div class="print-sheet">
        <div class="sheet-inner report-cover">
            <header class="document-banner">
                <div class="document-banner-main">
                    <?php if (!empty($setting['logo'])): ?>
                        <div class="document-logo">
                            <img src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars((string) $setting['logo']) ?>" alt="Logo perusahaan">
                        </div>
                    <?php endif; ?>
                    <div class="document-company">
                        <div class="document-eyebrow">Laporan Bulanan</div>
                        <div class="document-company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="document-company-meta">
                            <?php if (!empty($setting['alamat'])): ?><div><?= htmlspecialchars((string) $setting['alamat']) ?></div><?php endif; ?>
                            <?php if (!empty($setting['telepon'])): ?><div>Telp: <?= htmlspecialchars((string) $setting['telepon']) ?></div><?php endif; ?>
                            <?php if (!empty($setting['email'])): ?><div>Email: <?= htmlspecialchars((string) $setting['email']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <aside class="document-meta-card">
                    <div class="meta-title">Informasi Dokumen</div>
                    <div class="meta-stack">
                        <div class="meta-line">
                            <span class="meta-line-label">Periode</span>
                            <span class="meta-line-value"><?= htmlspecialchars($label) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">Disusun Oleh</span>
                            <span class="meta-line-value"><?= htmlspecialchars($preparedBy) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">Dicetak</span>
                            <span class="meta-line-value"><?= htmlspecialchars(date('d M Y H:i')) ?></span>
                        </div>
                    </div>
                </aside>
            </header>

            <section class="document-heading">
                <div>
                    <h1>Laporan <?= htmlspecialchars($label) ?></h1>
                    <p>Ringkasan performa penjualan, biaya, produksi, SDM, dan stok untuk kebutuhan monitoring dan presentasi internal.</p>
                </div>
                <div class="document-badges">
                    <span class="pill"><?= number_format($jmlTrx) ?> transaksi</span>
                    <span class="pill"><?= number_format($jmlKaryawan) ?> karyawan aktif</span>
                </div>
            </section>

            <section class="report-kpi-grid">
                <article class="kpi-card">
                    <div class="kpi-label">Omzet</div>
                    <div class="kpi-value">Rp <?= number_format($omzet, 0, ',', '.') ?></div>
                    <div class="kpi-note">Transaksi selesai pada periode ini.</div>
                </article>
                <article class="kpi-card">
                    <div class="kpi-label">Pengeluaran</div>
                    <div class="kpi-value">Rp <?= number_format($totalOps, 0, ',', '.') ?></div>
                    <div class="kpi-note">Akumulasi operasional printing, apparel, dan umum.</div>
                </article>
                <article class="kpi-card">
                    <div class="kpi-label">Laba Bersih</div>
                    <div class="kpi-value"><?= htmlspecialchars(monthlyReportFormatSignedCurrency($labaBersih)) ?></div>
                    <div class="kpi-note"><?= $labaBersih >= 0 ? 'Masih positif pada periode ini.' : 'Perlu evaluasi biaya dan omzet.' ?></div>
                </article>
                <article class="kpi-card">
                    <div class="kpi-label">Piutang Aktif</div>
                    <div class="kpi-value">Rp <?= number_format($sisaTempo, 0, ',', '.') ?></div>
                    <div class="kpi-note">Sisa pembayaran dari transaksi DP/tempo.</div>
                </article>
            </section>

            <section class="report-cover-intro">
                <article class="report-card">
                    <div class="report-card-head"><strong>Ikhtisar Keuangan</strong></div>
                    <div class="card-body">
                        <div class="totals-row"><span>Omzet Penjualan</span><strong>Rp <?= number_format($omzet, 0, ',', '.') ?></strong></div>
                        <div class="totals-row"><span>Total Pengeluaran</span><strong>Rp <?= number_format($totalOps, 0, ',', '.') ?></strong></div>
                        <div class="totals-row total"><span>Laba Bersih</span><strong><?= htmlspecialchars(monthlyReportFormatSignedCurrency($labaBersih)) ?></strong></div>
                        <div class="totals-row"><span>CEO 60%</span><strong><?= htmlspecialchars(monthlyReportFormatSignedCurrency($bagiHasilCeo)) ?></strong></div>
                        <div class="totals-row"><span>Head Office 40%</span><strong><?= htmlspecialchars(monthlyReportFormatSignedCurrency($bagiHasilHeadOffice)) ?></strong></div>
                    </div>
                </article>

                <article class="report-card">
                    <div class="report-card-head"><strong>Ringkasan Operasional</strong></div>
                    <div class="card-body">
                        <div class="kv-grid">
                            <div class="kv-item">
                                <span class="kv-label">Transaksi Selesai</span>
                                <span class="kv-value"><?= number_format($trxSelesai) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">JO Selesai</span>
                                <span class="kv-value"><?= number_format($joSelesai) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Total Hadir</span>
                                <span class="kv-value"><?= number_format($totalHadir) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Total Alpha</span>
                                <span class="kv-value"><?= number_format($totalAlpha) ?></span>
                            </div>
                        </div>
                        <div class="lead-note" style="margin-top: 10px;"><?= htmlspecialchars($bagiHasilNote) ?></div>
                    </div>
                </article>
            </section>

            <?php if (!empty($ringkasanAnalytics['summary']) || !empty($ringkasanAnalytics['insights']) || !empty($ringkasanAnalytics['visuals'])): ?>
                <section class="report-executive-block">
                    <?php if (!empty($ringkasanAnalytics['summary'])): ?>
                        <div class="executive-summary">
                            <strong>Executive Narrative</strong>
                            <p><?= htmlspecialchars($ringkasanAnalytics['summary']) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ringkasanAnalytics['insights'])): ?>
                        <div class="report-insight-grid">
                            <?php foreach ($ringkasanAnalytics['insights'] as $insight): ?>
                                <article class="report-insight-card <?= htmlspecialchars(reportPrintToneClass((string) ($insight['tone'] ?? 'primary'))) ?>">
                                    <strong><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></strong>
                                    <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ringkasanAnalytics['visuals'])): ?>
                        <div class="report-visual-grid">
                            <?php foreach ($ringkasanAnalytics['visuals'] as $visual): ?>
                                <article class="report-visual-card">
                                    <div class="report-card-head"><strong><?= htmlspecialchars((string) ($visual['title'] ?? 'Visual Statistik')) ?></strong></div>
                                    <div class="card-body">
                                        <div class="lead-note"><?= htmlspecialchars((string) ($visual['subtitle'] ?? '')) ?></div>
                                        <div class="visual-bar-stack">
                                            <?php foreach (array_slice(array_values((array) ($visual['items'] ?? [])), 0, 5) as $item): ?>
                                                <div class="visual-bar-row">
                                                    <div class="visual-bar-meta">
                                                        <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                        <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                    </div>
                                                    <div class="visual-bar-track"><span style="width: <?= htmlspecialchars((string) max(6, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="report-split">
                <article class="report-card">
                    <div class="report-card-head"><strong>Produk Terlaris</strong></div>
                    <div class="card-body">
                        <table class="data-table report-table">
                            <thead>
                                <tr>
                                    <th style="width: 8%;">No</th>
                                    <th>Produk</th>
                                    <th style="width: 18%;" class="text-right">Qty</th>
                                    <th style="width: 24%;" class="text-right">Omzet</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($produkLaris as $index => $produk): ?>
                                    <tr>
                                        <td class="text-center"><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars((string) ($produk['nama_produk'] ?? '-')) ?></td>
                                        <td class="text-right"><?= number_format((float) ($produk['total_qty'] ?? 0), 0, ',', '.') ?></td>
                                        <td class="text-right">Rp <?= number_format((float) ($produk['total_omzet'] ?? 0), 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($produkLaris)): ?>
                                    <tr><td colspan="4" class="text-center muted-text">Belum ada data produk terlaris pada periode ini.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="report-card">
                    <div class="report-card-head"><strong>Metode Pembayaran</strong></div>
                    <div class="card-body">
                        <table class="data-table report-table">
                            <thead>
                                <tr>
                                    <th>Metode</th>
                                    <th style="width: 18%;" class="text-center">Jumlah</th>
                                    <th style="width: 30%;" class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($metodeBayar as $metode): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($metode['metode_bayar'] ?? '-')) ?></td>
                                        <td class="text-center"><?= number_format((int) ($metode['jml'] ?? 0)) ?></td>
                                        <td class="text-right">Rp <?= number_format((float) ($metode['total'] ?? 0), 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($metodeBayar)): ?>
                                    <tr><td colspan="3" class="text-center muted-text">Belum ada data metode pembayaran.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <?php if (!empty($setting['footer_nota'])): ?>
                <div class="document-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="print-sheet report-section">
        <div class="sheet-inner">
            <section class="report-section-title">
                <div>
                    <h2>Transaksi</h2>
                    <p>Seluruh transaksi yang tercatat pada <?= htmlspecialchars($label) ?>.</p>
                </div>
            </section>
            <?php if (!empty($transaksiAnalytics['summary'])): ?>
                <div class="executive-summary compact">
                    <strong>Narasi Eksekutif</strong>
                    <p><?= htmlspecialchars($transaksiAnalytics['summary']) ?></p>
                </div>
            <?php endif; ?>
            <section class="report-mini-grid">
                <?php foreach (!empty($transaksiAnalytics['kpis']) ? $transaksiAnalytics['kpis'] : [
                    ['label' => 'Total Transaksi', 'value' => number_format(count($trxData)), 'note' => 'Semua status transaksi.'],
                    ['label' => 'Omzet Selesai', 'value' => 'Rp ' . number_format($trxSelesaiTotal, 0, ',', '.'), 'note' => 'Hanya status selesai.'],
                    ['label' => 'DP + Tempo', 'value' => number_format($trxDP + $trxTempo), 'note' => 'Masih ada sisa kewajiban bayar.'],
                ] as $kpi): ?>
                    <article class="mini-stat">
                        <div class="mini-stat-label"><?= htmlspecialchars((string) ($kpi['label'] ?? '-')) ?></div>
                        <div class="mini-stat-value"><?= htmlspecialchars((string) ($kpi['value'] ?? '-')) ?></div>
                        <div class="mini-stat-note"><?= htmlspecialchars((string) ($kpi['note'] ?? '')) ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php if (!empty($transaksiAnalytics['insights']) || !empty($transaksiAnalytics['visuals'])): ?>
                <section class="report-section-grid">
                    <?php if (!empty($transaksiAnalytics['insights'])): ?>
                        <div class="report-insight-grid">
                            <?php foreach ($transaksiAnalytics['insights'] as $insight): ?>
                                <article class="report-insight-card <?= htmlspecialchars(reportPrintToneClass((string) ($insight['tone'] ?? 'primary'))) ?>">
                                    <strong><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></strong>
                                    <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($transaksiAnalytics['visuals'])): ?>
                        <div class="report-visual-grid">
                            <?php foreach ($transaksiAnalytics['visuals'] as $visual): ?>
                                <article class="report-visual-card">
                                    <div class="report-card-head"><strong><?= htmlspecialchars((string) ($visual['title'] ?? 'Visual Statistik')) ?></strong></div>
                                    <div class="card-body">
                                        <div class="lead-note"><?= htmlspecialchars((string) ($visual['subtitle'] ?? '')) ?></div>
                                        <div class="visual-bar-stack">
                                            <?php foreach (array_slice(array_values((array) ($visual['items'] ?? [])), 0, 5) as $item): ?>
                                                <div class="visual-bar-row">
                                                    <div class="visual-bar-meta">
                                                        <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                        <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                    </div>
                                                    <div class="visual-bar-track"><span style="width: <?= htmlspecialchars((string) max(6, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <table class="data-table report-table">
                <thead>
                    <tr>
                        <th style="width: 7%;">No</th>
                        <th style="width: 18%;">No. Invoice</th>
                        <th>Pelanggan</th>
                        <th style="width: 14%;">Metode</th>
                        <th style="width: 12%;" class="text-center">Status</th>
                        <th style="width: 18%;" class="text-right">Total</th>
                        <th style="width: 18%;" class="text-right">Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (reportPrintSliceRows($trxData, 6) as $index => $row): ?>
                        <tr>
                            <td class="text-center"><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars((string) ($row['no_transaksi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['pelanggan'] ?? 'Umum')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['metode_bayar'] ?? '-')) ?></td>
                            <td class="text-center"><span class="status-chip <?= reportPrintStatusClass((string) ($row['status'] ?? 'pending')) ?>"><?= htmlspecialchars(strtoupper((string) ($row['status'] ?? 'PENDING'))) ?></span></td>
                            <td class="text-right">Rp <?= number_format((float) ($row['total'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-right"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($row['created_at'] ?? 'now')))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($trxData)): ?>
                        <tr><td colspan="7" class="text-center muted-text">Tidak ada data transaksi pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (count($trxData) > 6): ?>
                <div class="table-caption">Menampilkan 6 transaksi utama untuk kebutuhan presentasi. Sisa <?= number_format(count($trxData) - 6) ?> transaksi tetap tersedia di sistem.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-sheet report-section">
        <div class="sheet-inner">
            <section class="report-section-title">
                <div>
                    <h2>Operasional</h2>
                    <p>Rekap pengeluaran dan distribusi biaya per aktivitas.</p>
                </div>
            </section>
            <?php if (!empty($operasionalAnalytics['summary'])): ?>
                <div class="executive-summary compact">
                    <strong>Narasi Eksekutif</strong>
                    <p><?= htmlspecialchars($operasionalAnalytics['summary']) ?></p>
                </div>
            <?php endif; ?>
            <section class="report-mini-grid">
                <?php foreach (!empty($operasionalAnalytics['kpis']) ? $operasionalAnalytics['kpis'] : [
                    ['label' => 'Total Biaya', 'value' => 'Rp ' . number_format($opsTotalPrinted, 0, ',', '.'), 'note' => 'Akumulasi operasional bulan ini.'],
                    ['label' => 'Printing', 'value' => 'Rp ' . number_format($opsPrint, 0, ',', '.'), 'note' => 'Beban divisi printing.'],
                    ['label' => 'Apparel + Umum', 'value' => 'Rp ' . number_format($opsApp + $opsUmum, 0, ',', '.'), 'note' => 'Gabungan apparel dan umum.'],
                ] as $kpi): ?>
                    <article class="mini-stat">
                        <div class="mini-stat-label"><?= htmlspecialchars((string) ($kpi['label'] ?? '-')) ?></div>
                        <div class="mini-stat-value"><?= htmlspecialchars((string) ($kpi['value'] ?? '-')) ?></div>
                        <div class="mini-stat-note"><?= htmlspecialchars((string) ($kpi['note'] ?? '')) ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php if (!empty($operasionalAnalytics['insights']) || !empty($operasionalAnalytics['visuals'])): ?>
                <section class="report-section-grid">
                    <?php if (!empty($operasionalAnalytics['insights'])): ?>
                        <div class="report-insight-grid">
                            <?php foreach ($operasionalAnalytics['insights'] as $insight): ?>
                                <article class="report-insight-card <?= htmlspecialchars(reportPrintToneClass((string) ($insight['tone'] ?? 'primary'))) ?>">
                                    <strong><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></strong>
                                    <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($operasionalAnalytics['visuals'])): ?>
                        <div class="report-visual-grid">
                            <?php foreach ($operasionalAnalytics['visuals'] as $visual): ?>
                                <article class="report-visual-card">
                                    <div class="report-card-head"><strong><?= htmlspecialchars((string) ($visual['title'] ?? 'Visual Statistik')) ?></strong></div>
                                    <div class="card-body">
                                        <div class="lead-note"><?= htmlspecialchars((string) ($visual['subtitle'] ?? '')) ?></div>
                                        <div class="visual-bar-stack">
                                            <?php foreach (array_slice(array_values((array) ($visual['items'] ?? [])), 0, 5) as $item): ?>
                                                <div class="visual-bar-row">
                                                    <div class="visual-bar-meta">
                                                        <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                        <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                    </div>
                                                    <div class="visual-bar-track"><span style="width: <?= htmlspecialchars((string) max(6, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <table class="data-table report-table">
                <thead>
                    <tr>
                        <th style="width: 7%;">No</th>
                        <th style="width: 14%;">Tanggal</th>
                        <th style="width: 16%;">Divisi</th>
                        <th style="width: 20%;">Kategori</th>
                        <th>Keterangan</th>
                        <th style="width: 18%;" class="text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (reportPrintSliceRows($opsData, 6) as $index => $row): ?>
                        <tr>
                            <td class="text-center"><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime((string) ($row['tanggal'] ?? 'now')))) ?></td>
                            <td><?= htmlspecialchars((string) ($row['divisi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['kategori'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['keterangan'] ?? '-')) ?></td>
                            <td class="text-right">Rp <?= number_format((float) ($row['jumlah'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($opsData)): ?>
                        <tr><td colspan="6" class="text-center muted-text">Tidak ada data operasional pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (count($opsData) > 6): ?>
                <div class="table-caption">Menampilkan 6 pengeluaran representatif. Sisa <?= number_format(count($opsData) - 6) ?> baris tetap tersedia di sistem.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-sheet report-section">
        <div class="sheet-inner">
            <section class="report-section-title">
                <div>
                    <h2>Produksi</h2>
                    <p>Pergerakan job order dan surat kerja sepanjang periode laporan.</p>
                </div>
            </section>
            <?php if (!empty($produksiAnalytics['summary'])): ?>
                <div class="executive-summary compact">
                    <strong>Narasi Eksekutif</strong>
                    <p><?= htmlspecialchars($produksiAnalytics['summary']) ?></p>
                </div>
            <?php endif; ?>
            <section class="report-mini-grid">
                <?php foreach (!empty($produksiAnalytics['kpis']) ? $produksiAnalytics['kpis'] : [
                    ['label' => 'Total JO', 'value' => number_format($joPrinting), 'note' => 'Dokumen JO yang dibuat.'],
                    ['label' => 'JO Selesai', 'value' => number_format($joSelesai), 'note' => 'Order yang sudah rampung.'],
                    ['label' => 'JO Berjalan', 'value' => number_format(max(0, $joPrinting - $joSelesai)), 'note' => 'Masih aktif di produksi.'],
                ] as $kpi): ?>
                    <article class="mini-stat">
                        <div class="mini-stat-label"><?= htmlspecialchars((string) ($kpi['label'] ?? '-')) ?></div>
                        <div class="mini-stat-value"><?= htmlspecialchars((string) ($kpi['value'] ?? '-')) ?></div>
                        <div class="mini-stat-note"><?= htmlspecialchars((string) ($kpi['note'] ?? '')) ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php if (!empty($produksiAnalytics['insights']) || !empty($produksiAnalytics['visuals'])): ?>
                <section class="report-section-grid">
                    <?php if (!empty($produksiAnalytics['insights'])): ?>
                        <div class="report-insight-grid">
                            <?php foreach ($produksiAnalytics['insights'] as $insight): ?>
                                <article class="report-insight-card <?= htmlspecialchars(reportPrintToneClass((string) ($insight['tone'] ?? 'primary'))) ?>">
                                    <strong><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></strong>
                                    <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($produksiAnalytics['visuals'])): ?>
                        <div class="report-visual-grid">
                            <?php foreach ($produksiAnalytics['visuals'] as $visual): ?>
                                <article class="report-visual-card">
                                    <div class="report-card-head"><strong><?= htmlspecialchars((string) ($visual['title'] ?? 'Visual Statistik')) ?></strong></div>
                                    <div class="card-body">
                                        <div class="lead-note"><?= htmlspecialchars((string) ($visual['subtitle'] ?? '')) ?></div>
                                        <div class="visual-bar-stack">
                                            <?php foreach (array_slice(array_values((array) ($visual['items'] ?? [])), 0, 5) as $item): ?>
                                                <div class="visual-bar-row">
                                                    <div class="visual-bar-meta">
                                                        <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                        <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                    </div>
                                                    <div class="visual-bar-track"><span style="width: <?= htmlspecialchars((string) max(6, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <table class="data-table report-table">
                <thead>
                    <tr>
                        <th style="width: 7%;">No</th>
                        <th style="width: 16%;">Dokumen</th>
                        <th style="width: 10%;">Tipe</th>
                        <th>Pekerjaan</th>
                        <th style="width: 15%;">Deadline</th>
                        <th style="width: 16%;" class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (reportPrintSliceRows($produksiData, 6) as $index => $row): ?>
                        <tr>
                            <td class="text-center"><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars((string) ($row['no_dokumen'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['tipe_dokumen'] ?? '-')) ?></td>
                            <td>
                                <div class="strong-text"><?= htmlspecialchars((string) ($row['nama_pekerjaan'] ?? '-')) ?></div>
                                <div class="muted-text">Invoice: <?= htmlspecialchars((string) ($row['no_transaksi'] ?? '-')) ?></div>
                            </td>
                            <td><?= !empty($row['deadline']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $row['deadline']))) : '-' ?></td>
                            <td class="text-center"><span class="status-chip <?= reportPrintStatusClass((string) ($row['status'] ?? 'antrian')) ?>"><?= htmlspecialchars(strtoupper((string) ($row['status'] ?? 'ANTRIAN'))) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($produksiData)): ?>
                        <tr><td colspan="6" class="text-center muted-text">Tidak ada data produksi pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (count($produksiData) > 6): ?>
                <div class="table-caption">Menampilkan 6 job representatif. Sisa <?= number_format(count($produksiData) - 6) ?> job tetap tersimpan di sistem.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-sheet report-section">
        <div class="sheet-inner">
            <section class="report-section-title">
                <div>
                    <h2>Absensi</h2>
                    <p>Rekap kehadiran karyawan selama periode kerja berjalan.</p>
                </div>
            </section>
            <?php if (!empty($absensiAnalytics['summary'])): ?>
                <div class="executive-summary compact">
                    <strong>Narasi Eksekutif</strong>
                    <p><?= htmlspecialchars($absensiAnalytics['summary']) ?></p>
                </div>
            <?php endif; ?>
            <section class="report-mini-grid">
                <?php foreach (!empty($absensiAnalytics['kpis']) ? $absensiAnalytics['kpis'] : [
                    ['label' => 'Hari Kerja', 'value' => number_format($hariKerja), 'note' => 'Tanpa hari Minggu.'],
                    ['label' => 'Total Hadir', 'value' => number_format($totalHadir), 'note' => 'Hadir dan terlambat.'],
                    ['label' => 'Total Alpha', 'value' => number_format($totalAlpha), 'note' => 'Tanpa keterangan.'],
                ] as $kpi): ?>
                    <article class="mini-stat">
                        <div class="mini-stat-label"><?= htmlspecialchars((string) ($kpi['label'] ?? '-')) ?></div>
                        <div class="mini-stat-value"><?= htmlspecialchars((string) ($kpi['value'] ?? '-')) ?></div>
                        <div class="mini-stat-note"><?= htmlspecialchars((string) ($kpi['note'] ?? '')) ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php if (!empty($absensiAnalytics['insights']) || !empty($absensiAnalytics['visuals'])): ?>
                <section class="report-section-grid">
                    <?php if (!empty($absensiAnalytics['insights'])): ?>
                        <div class="report-insight-grid">
                            <?php foreach ($absensiAnalytics['insights'] as $insight): ?>
                                <article class="report-insight-card <?= htmlspecialchars(reportPrintToneClass((string) ($insight['tone'] ?? 'primary'))) ?>">
                                    <strong><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></strong>
                                    <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($absensiAnalytics['visuals'])): ?>
                        <div class="report-visual-grid">
                            <?php foreach ($absensiAnalytics['visuals'] as $visual): ?>
                                <article class="report-visual-card">
                                    <div class="report-card-head"><strong><?= htmlspecialchars((string) ($visual['title'] ?? 'Visual Statistik')) ?></strong></div>
                                    <div class="card-body">
                                        <div class="lead-note"><?= htmlspecialchars((string) ($visual['subtitle'] ?? '')) ?></div>
                                        <div class="visual-bar-stack">
                                            <?php foreach (array_slice(array_values((array) ($visual['items'] ?? [])), 0, 5) as $item): ?>
                                                <div class="visual-bar-row">
                                                    <div class="visual-bar-meta">
                                                        <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                        <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                    </div>
                                                    <div class="visual-bar-track"><span style="width: <?= htmlspecialchars((string) max(6, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <table class="data-table report-table">
                <thead>
                    <tr>
                        <th style="width: 7%;">No</th>
                        <th>Nama</th>
                        <th style="width: 16%;">Jabatan</th>
                        <th style="width: 8%;" class="text-center">H</th>
                        <th style="width: 8%;" class="text-center">T</th>
                        <th style="width: 8%;" class="text-center">I</th>
                        <th style="width: 8%;" class="text-center">S</th>
                        <th style="width: 8%;" class="text-center">A</th>
                        <th style="width: 12%;" class="text-center">% Hadir</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (reportPrintSliceRows($absensiData, 6) as $index => $row): ?>
                        <?php $pct = $hariKerja > 0 ? round(((int) ($row['hadir'] ?? 0) / $hariKerja) * 100) : 0; ?>
                        <tr>
                            <td class="text-center"><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars((string) ($row['nama'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['jabatan'] ?? '-')) ?></td>
                            <td class="text-center"><?= number_format((int) ($row['hadir'] ?? 0)) ?></td>
                            <td class="text-center"><?= number_format((int) ($row['terlambat'] ?? 0)) ?></td>
                            <td class="text-center"><?= number_format((int) ($row['izin'] ?? 0)) ?></td>
                            <td class="text-center"><?= number_format((int) ($row['sakit'] ?? 0)) ?></td>
                            <td class="text-center"><?= number_format((int) ($row['alpha'] ?? 0)) ?></td>
                            <td class="text-center"><span class="status-chip <?= $pct >= 80 ? 'success' : ($pct >= 60 ? 'warning' : 'danger') ?>"><?= $pct ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($absensiData)): ?>
                        <tr><td colspan="9" class="text-center muted-text">Tidak ada data absensi pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (count($absensiData) > 6): ?>
                <div class="table-caption">Menampilkan 6 karyawan prioritas untuk review absensi. Sisa <?= number_format(count($absensiData) - 6) ?> karyawan tetap tersedia di sistem.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-sheet report-section">
        <div class="sheet-inner">
            <section class="report-section-title">
                <div>
                    <h2>Karyawan & Gaji</h2>
                    <p>Daftar karyawan aktif dan status slip gaji pada periode laporan.</p>
                </div>
            </section>
            <?php if (!empty($karyawanAnalytics['summary'])): ?>
                <div class="executive-summary compact">
                    <strong>Narasi Eksekutif</strong>
                    <p><?= htmlspecialchars($karyawanAnalytics['summary']) ?></p>
                </div>
            <?php endif; ?>
            <section class="report-mini-grid">
                <?php foreach (!empty($karyawanAnalytics['kpis']) ? $karyawanAnalytics['kpis'] : [
                    ['label' => 'Karyawan Aktif', 'value' => number_format($jmlKaryawan), 'note' => 'Personel aktif.'],
                    ['label' => 'Total Gaji', 'value' => 'Rp ' . number_format($karyawanTotalGaji, 0, ',', '.'), 'note' => 'Slip yang sudah tergenerate.'],
                    ['label' => 'Belum Generate', 'value' => number_format(count(array_filter($karyawanData, static fn(array $row): bool => (float) ($row['gaji_bulan'] ?? 0) <= 0))), 'note' => 'Masih perlu proses payroll.'],
                ] as $kpi): ?>
                    <article class="mini-stat">
                        <div class="mini-stat-label"><?= htmlspecialchars((string) ($kpi['label'] ?? '-')) ?></div>
                        <div class="mini-stat-value"><?= htmlspecialchars((string) ($kpi['value'] ?? '-')) ?></div>
                        <div class="mini-stat-note"><?= htmlspecialchars((string) ($kpi['note'] ?? '')) ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php if (!empty($karyawanAnalytics['insights']) || !empty($karyawanAnalytics['visuals'])): ?>
                <section class="report-section-grid">
                    <?php if (!empty($karyawanAnalytics['insights'])): ?>
                        <div class="report-insight-grid">
                            <?php foreach ($karyawanAnalytics['insights'] as $insight): ?>
                                <article class="report-insight-card <?= htmlspecialchars(reportPrintToneClass((string) ($insight['tone'] ?? 'primary'))) ?>">
                                    <strong><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></strong>
                                    <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($karyawanAnalytics['visuals'])): ?>
                        <div class="report-visual-grid">
                            <?php foreach ($karyawanAnalytics['visuals'] as $visual): ?>
                                <article class="report-visual-card">
                                    <div class="report-card-head"><strong><?= htmlspecialchars((string) ($visual['title'] ?? 'Visual Statistik')) ?></strong></div>
                                    <div class="card-body">
                                        <div class="lead-note"><?= htmlspecialchars((string) ($visual['subtitle'] ?? '')) ?></div>
                                        <div class="visual-bar-stack">
                                            <?php foreach (array_slice(array_values((array) ($visual['items'] ?? [])), 0, 5) as $item): ?>
                                                <div class="visual-bar-row">
                                                    <div class="visual-bar-meta">
                                                        <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                        <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                    </div>
                                                    <div class="visual-bar-track"><span style="width: <?= htmlspecialchars((string) max(6, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <table class="data-table report-table">
                <thead>
                    <tr>
                        <th style="width: 7%;">No</th>
                        <th>Nama</th>
                        <th style="width: 16%;">Jabatan</th>
                        <th style="width: 14%;">Divisi</th>
                        <th style="width: 14%;">Metode</th>
                        <th style="width: 18%;" class="text-right">Gaji</th>
                        <th style="width: 17%;" class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (reportPrintSliceRows($karyawanData, 6) as $index => $row): ?>
                        <?php $hasSalary = (float) ($row['gaji_bulan'] ?? 0) > 0; ?>
                        <tr>
                            <td class="text-center"><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars((string) ($row['nama'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['jabatan'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['divisi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['metode_gaji'] ?? '-')) ?></td>
                            <td class="text-right">Rp <?= number_format((float) ($row['gaji_bulan'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-center">
                                <?php if ($hasSalary): ?>
                                    <span class="status-chip <?= reportPrintStatusClass((string) ($row['status_bayar'] ?? 'belum_dibayar')) ?>"><?= htmlspecialchars(strtoupper((string) ($row['status_bayar'] ?? 'BELUM_DIBAYAR'))) ?></span>
                                <?php else: ?>
                                    <span class="status-chip secondary">BELUM GENERATE</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($karyawanData)): ?>
                        <tr><td colspan="7" class="text-center muted-text">Tidak ada data karyawan untuk ditampilkan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (count($karyawanData) > 6): ?>
                <div class="table-caption">Menampilkan 6 data payroll utama. Sisa <?= number_format(count($karyawanData) - 6) ?> karyawan tetap tersedia di sistem.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-sheet report-section">
        <div class="sheet-inner">
            <section class="report-section-title">
                <div>
                    <h2>Stok Produk</h2>
                    <p>Posisi stok dan estimasi nilai stok pada saat laporan dicetak.</p>
                </div>
            </section>
            <?php if (!empty($stokAnalytics['summary'])): ?>
                <div class="executive-summary compact">
                    <strong>Narasi Eksekutif</strong>
                    <p><?= htmlspecialchars($stokAnalytics['summary']) ?></p>
                </div>
            <?php endif; ?>
            <section class="report-mini-grid">
                <?php foreach (!empty($stokAnalytics['kpis']) ? $stokAnalytics['kpis'] : [
                    ['label' => 'Jumlah SKU', 'value' => number_format(count($stokData)), 'note' => 'Seluruh produk dalam katalog.'],
                    ['label' => 'Nilai Stok', 'value' => 'Rp ' . number_format($nilaiStok, 0, ',', '.'), 'note' => 'Estimasi berdasarkan harga jual.'],
                    ['label' => 'Stok Kritis', 'value' => number_format(count(array_filter($stokData, static fn(array $row): bool => (float) ($row['stok'] ?? 0) <= 5))), 'note' => 'Perlu restock atau monitoring.'],
                ] as $kpi): ?>
                    <article class="mini-stat">
                        <div class="mini-stat-label"><?= htmlspecialchars((string) ($kpi['label'] ?? '-')) ?></div>
                        <div class="mini-stat-value"><?= htmlspecialchars((string) ($kpi['value'] ?? '-')) ?></div>
                        <div class="mini-stat-note"><?= htmlspecialchars((string) ($kpi['note'] ?? '')) ?></div>
                    </article>
                <?php endforeach; ?>
            </section>
            <?php if (!empty($stokAnalytics['insights']) || !empty($stokAnalytics['visuals'])): ?>
                <section class="report-section-grid">
                    <?php if (!empty($stokAnalytics['insights'])): ?>
                        <div class="report-insight-grid">
                            <?php foreach ($stokAnalytics['insights'] as $insight): ?>
                                <article class="report-insight-card <?= htmlspecialchars(reportPrintToneClass((string) ($insight['tone'] ?? 'primary'))) ?>">
                                    <strong><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></strong>
                                    <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($stokAnalytics['visuals'])): ?>
                        <div class="report-visual-grid">
                            <?php foreach ($stokAnalytics['visuals'] as $visual): ?>
                                <article class="report-visual-card">
                                    <div class="report-card-head"><strong><?= htmlspecialchars((string) ($visual['title'] ?? 'Visual Statistik')) ?></strong></div>
                                    <div class="card-body">
                                        <div class="lead-note"><?= htmlspecialchars((string) ($visual['subtitle'] ?? '')) ?></div>
                                        <div class="visual-bar-stack">
                                            <?php foreach (array_slice(array_values((array) ($visual['items'] ?? [])), 0, 5) as $item): ?>
                                                <div class="visual-bar-row">
                                                    <div class="visual-bar-meta">
                                                        <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                        <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                    </div>
                                                    <div class="visual-bar-track"><span style="width: <?= htmlspecialchars((string) max(6, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <table class="data-table report-table">
                <thead>
                    <tr>
                        <th style="width: 7%;">No</th>
                        <th style="width: 14%;">Kode</th>
                        <th>Produk</th>
                        <th style="width: 15%;">Kategori</th>
                        <th style="width: 10%;" class="text-center">Stok</th>
                        <th style="width: 10%;" class="text-center">Sat.</th>
                        <th style="width: 16%;" class="text-right">Harga</th>
                        <th style="width: 18%;" class="text-right">Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (reportPrintSliceRows($stokData, 6) as $index => $row): ?>
                        <tr>
                            <td class="text-center"><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars((string) ($row['kode'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['nama'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['kategori'] ?? '-')) ?></td>
                            <td class="text-center"><span class="status-chip <?= ((float) ($row['stok'] ?? 0) <= 5) ? 'danger' : 'success' ?>"><?= number_format((float) ($row['stok'] ?? 0), 0, ',', '.') ?></span></td>
                            <td class="text-center"><?= htmlspecialchars((string) ($row['satuan'] ?? '-')) ?></td>
                            <td class="text-right">Rp <?= number_format((float) ($row['harga_jual'] ?? 0), 0, ',', '.') ?></td>
                            <td class="text-right">Rp <?= number_format((float) ($row['nilai_stok'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stokData)): ?>
                        <tr><td colspan="8" class="text-center muted-text">Tidak ada data stok produk.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (count($stokData) > 6): ?>
                <div class="table-caption">Menampilkan 6 SKU representatif. Sisa <?= number_format(count($stokData) - 6) ?> SKU tetap tersedia di sistem.</div>
            <?php endif; ?>

            <?php if (!empty($setting['footer_nota'])): ?>
                <div class="document-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <script>window.onload = function () { window.print(); };</script>
</body>
</html>
