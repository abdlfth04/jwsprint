<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once dirname(__DIR__, 2) . '/services/monthly_report.php';
requireRole('superadmin', 'admin');
$pageTitle = 'Laporan';

$bulan = monthlyReportNormalizeMonth((string) ($_GET['bulan'] ?? date('Y-m')));
$tab = (string) ($_GET['tab'] ?? 'ringkasan');
$report = monthlyReportBuildContext($conn, $bulan, $tab, false);
extract($report, EXTR_SKIP);
$activeAnalytics = is_array($tabAnalytics[$tab] ?? null) ? $tabAnalytics[$tab] : [];
$activeCharts = array_values(array_filter(
    is_array($activeAnalytics['charts'] ?? null) ? $activeAnalytics['charts'] : [],
    static function (array $chart): bool {
        return !empty($chart['id']);
    }
));
$pageState = ['reportCharts' => []];
foreach ($activeCharts as $chart) {
    $pageState['reportCharts'][(string) $chart['id']] = $chart;
}
$pageScriptUrls = !empty($activeCharts) ? ['https://cdn.jsdelivr.net/npm/chart.js'] : [];
$pageJs = !empty($activeCharts) ? 'laporan.js' : null;
$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/admin.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/workforce.css') . '">'
    . '<link rel="stylesheet" href="' . assetUrl('css/laporan.css') . '">';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<div class="page-stack admin-panel laporan-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-chart-bar"></i> Laporan</div>
                <h1 class="page-title">Dashboard laporan bulanan yang lebih bersih untuk membaca penjualan, operasional, produksi, dan SDM</h1>
                <p class="page-description">
                    Modul laporan dirapikan supaya ringkasan bisnis dan rekap per tab tetap nyaman dianalisis dari browser tanpa perlu berpindah ke export data terlebih dahulu.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-calendar"></i> <?= htmlspecialchars($label) ?></span>
                    <span class="page-meta-item"><i class="fas fa-layer-group"></i> Tab aktif: <?= htmlspecialchars($tabLabel) ?></span>
                    <span class="page-meta-item"><i class="fas fa-list-ol"></i> <?= number_format($selectedCount) ?> baris data</span>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('laporan_cetak.php?bulan=' . urlencode($bulan)) ?>" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i> Cetak Laporan</a>
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </div>
    </section>

    <div class="metric-strip">
        <div class="metric-card">
            <span class="metric-label">Omzet</span>
            <span class="metric-value">Rp <?= number_format($omzet, 0, ',', '.') ?></span>
            <span class="metric-note">Penjualan selesai pada periode laporan.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Pengeluaran</span>
            <span class="metric-value">Rp <?= number_format($totalOps, 0, ',', '.') ?></span>
            <span class="metric-note">Akumulasi operasional printing, apparel, dan umum.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Laba Bersih</span>
            <span class="metric-value"><?= ($labaBersih >= 0 ? 'Rp ' : '-Rp ') . number_format(abs($labaBersih), 0, ',', '.') ?></span>
            <span class="metric-note"><?= $labaBersih >= 0 ? 'Margin masih positif untuk periode ini.' : 'Perlu evaluasi karena pengeluaran melebihi omzet.' ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Total Transaksi</span>
            <span class="metric-value"><?= number_format($jmlTrx) ?></span>
            <span class="metric-note"><?= number_format($trxSelesai) ?> selesai, <?= number_format($trxDP + $trxTempo) ?> masih punya kewajiban bayar.</span>
        </div>
    </div>

    <div class="card" style="padding:18px 20px;">
        <div class="card-header" style="padding:0 0 14px;border-bottom:1px solid var(--border);margin-bottom:14px;">
            <div>
                <span class="card-title"><i class="fas fa-scale-balanced"></i> Bagi Hasil Bulanan</span>
                <div class="card-subtitle">Dasar pembagian diambil dari omzet selesai dikurangi seluruh pengeluaran pada periode <?= htmlspecialchars($label) ?>.</div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
            <div class="stat-card">
                <div class="stat-icon <?= $basisBagiHasil >= 0 ? 'bg-primary' : 'bg-danger' ?>"><i class="fas fa-calculator"></i></div>
                    <div class="stat-info">
                    <div class="stat-value" style="font-size:1.1rem"><?= htmlspecialchars(monthlyReportFormatSignedCurrency($basisBagiHasil)) ?></div>
                    <div class="stat-label">Dasar Bagi Hasil</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?= $bagiHasilCeo >= 0 ? 'bg-success' : 'bg-danger' ?>"><i class="fas fa-user-tie"></i></div>
                <div class="stat-info">
                    <div class="stat-value" style="font-size:1.1rem"><?= htmlspecialchars(monthlyReportFormatSignedCurrency($bagiHasilCeo)) ?></div>
                    <div class="stat-label">CEO 60%</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?= $bagiHasilHeadOffice >= 0 ? 'bg-warning' : 'bg-danger' ?>"><i class="fas fa-building"></i></div>
                <div class="stat-info">
                    <div class="stat-value" style="font-size:1.1rem"><?= htmlspecialchars(monthlyReportFormatSignedCurrency($bagiHasilHeadOffice)) ?></div>
                    <div class="stat-label">Head Office 40%</div>
                </div>
            </div>
        </div>
        <div style="margin-top:14px;font-size:.82rem;color:var(--text-muted);line-height:1.6;">
            Formula: <?= htmlspecialchars(monthlyReportFormatSignedCurrency($omzet)) ?> - <?= htmlspecialchars(monthlyReportFormatSignedCurrency($totalOps)) ?> = <?= htmlspecialchars(monthlyReportFormatSignedCurrency($basisBagiHasil)) ?>.
            <?= htmlspecialchars($bagiHasilNote) ?>
        </div>
    </div>

    <div class="toolbar-surface admin-filter-grid">
        <div class="section-heading">
            <div>
                <h2>Periode & Tab Laporan</h2>
                <p>Pindah antar jenis laporan tanpa kehilangan konteks periode bulanan yang sedang Anda analisis.</p>
            </div>
        </div>
        <div class="admin-toolbar">
            <form method="GET" class="admin-inline-actions" style="flex:1 1 420px;">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <input type="month" name="bulan" value="<?= htmlspecialchars($bulan) ?>" class="form-control" style="min-width:180px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
            </form>
            <?php if ($sisaTempo > 0): ?>
                <div class="report-meta-chip"><i class="fas fa-clock"></i> Piutang aktif Rp <?= number_format($sisaTempo, 0, ',', '.') ?></div>
            <?php endif; ?>
        </div>
        <div class="filter-pills">
            <?php foreach ($tabs as $key => [$icon, $lbl]): ?>
                <a href="?tab=<?= $key ?>&bulan=<?= urlencode($bulan) ?>" class="filter-pill <?= $tab === $key ? 'active' : '' ?>">
                    <span><i class="fas <?= $icon ?>"></i> <?= $lbl ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <span class="card-title"><i class="fas <?= $tabs[$tab][0] ?>"></i> <?= htmlspecialchars($tabLabel) ?></span>
                <div class="card-subtitle">Laporan periode <?= htmlspecialchars($label) ?> ditata ulang agar lebih mudah dibaca dan dipresentasikan.</div>
            </div>
        </div>
        <?php if (!empty($activeAnalytics)): ?>
            <div class="laporan-analytics">
                <?php if (!empty($activeAnalytics['summary'])): ?>
                    <section class="laporan-summary-panel">
                        <div class="laporan-summary-eyebrow">Executive Summary</div>
                        <p><?= htmlspecialchars((string) $activeAnalytics['summary']) ?></p>
                    </section>
                <?php endif; ?>

                <?php if (!empty($activeAnalytics['kpis']) && is_array($activeAnalytics['kpis'])): ?>
                    <section class="laporan-kpi-grid">
                        <?php foreach ($activeAnalytics['kpis'] as $kpi): ?>
                            <article class="laporan-kpi-card laporan-tone-<?= htmlspecialchars((string) ($kpi['tone'] ?? 'primary')) ?>">
                                <div class="laporan-kpi-label"><?= htmlspecialchars((string) ($kpi['label'] ?? '-')) ?></div>
                                <div class="laporan-kpi-value"><?= htmlspecialchars((string) ($kpi['value'] ?? '-')) ?></div>
                                <div class="laporan-kpi-note"><?= htmlspecialchars((string) ($kpi['note'] ?? '')) ?></div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <?php if (!empty($activeAnalytics['insights']) && is_array($activeAnalytics['insights'])): ?>
                    <section class="laporan-insight-grid">
                        <?php foreach ($activeAnalytics['insights'] as $insight): ?>
                            <article class="laporan-insight-card laporan-tone-<?= htmlspecialchars((string) ($insight['tone'] ?? 'primary')) ?>">
                                <div class="laporan-insight-title"><?= htmlspecialchars((string) ($insight['title'] ?? '-')) ?></div>
                                <p><?= htmlspecialchars((string) ($insight['body'] ?? '')) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <?php if (!empty($activeAnalytics['charts']) && is_array($activeAnalytics['charts'])): ?>
                    <section class="laporan-chart-grid">
                        <?php foreach ($activeAnalytics['charts'] as $chart): ?>
                            <article class="laporan-chart-card">
                                <div class="laporan-chart-head">
                                    <div class="laporan-chart-title"><?= htmlspecialchars((string) ($chart['title'] ?? 'Grafik')) ?></div>
                                    <?php if (!empty($chart['subtitle'])): ?>
                                        <div class="laporan-chart-subtitle"><?= htmlspecialchars((string) $chart['subtitle']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($chart['hasData'])): ?>
                                    <div class="laporan-chart-body">
                                        <canvas class="report-chart-canvas" data-chart-id="<?= htmlspecialchars((string) ($chart['id'] ?? '')) ?>"></canvas>
                                    </div>
                                <?php else: ?>
                                    <div class="laporan-chart-empty">Belum ada data yang cukup untuk membentuk grafik pada periode ini.</div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <?php if (!empty($activeAnalytics['visuals']) && is_array($activeAnalytics['visuals'])): ?>
                    <section class="laporan-visual-grid">
                        <?php foreach ($activeAnalytics['visuals'] as $visual): ?>
                            <article class="laporan-visual-card">
                                <div class="laporan-chart-head">
                                    <div class="laporan-chart-title"><?= htmlspecialchars((string) ($visual['title'] ?? 'Sorotan Data')) ?></div>
                                    <?php if (!empty($visual['subtitle'])): ?>
                                        <div class="laporan-chart-subtitle"><?= htmlspecialchars((string) $visual['subtitle']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($visual['hasData']) && !empty($visual['items']) && is_array($visual['items'])): ?>
                                    <div class="laporan-visual-list">
                                        <?php foreach ($visual['items'] as $item): ?>
                                            <div class="laporan-visual-row">
                                                <div class="laporan-visual-meta">
                                                    <span><?= htmlspecialchars((string) ($item['label'] ?? '-')) ?></span>
                                                    <strong><?= htmlspecialchars((string) ($item['display'] ?? '-')) ?></strong>
                                                </div>
                                                <div class="laporan-visual-track">
                                                    <span style="width: <?= htmlspecialchars((string) max(5, min(100, (float) ($item['ratio'] ?? 0)))) ?>%;"></span>
                                                </div>
                                                <div class="laporan-visual-share"><?= number_format((float) ($item['share'] ?? 0), 1, ',', '.') ?>%</div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="laporan-chart-empty">Belum ada data pendukung untuk sorotan statistik ini.</div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php if ($tab === 'ringkasan'): ?>
    <!-- ??????????????????????????????????????????????????????????????????????
         RINGKASAN
    ?????????????????????????????????????????????????????????????????????? -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px">
        <?php
        $cards = [
            ['Omzet',          'Rp '.number_format($omzet,0,',','.'),       'bg-success', 'fa-money-bill-wave'],
            ['Pengeluaran',    'Rp '.number_format($totalOps,0,',','.'),    'bg-danger',  'fa-wallet'],
            ['Laba Bersih',    'Rp '.number_format($labaBersih,0,',','.'),  $labaBersih>=0?'bg-primary':'bg-danger', 'fa-chart-line'],
            ['Total Transaksi',$jmlTrx.' trx',                              'bg-secondary','fa-receipt'],
        ];
        foreach ($cards as [$lbl,$val,$bg,$ico]):
        ?>
        <div class="stat-card">
            <div class="stat-icon <?=$bg?>"><i class="fas <?=$ico?>"></i></div>
            <div class="stat-info">
                <div class="stat-value" style="font-size:1.1rem"><?=$val?></div>
                <div class="stat-label"><?=$lbl?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="form-row">
        <!-- Keuangan -->
        <div>
            <div class="card">
                <div class="card-header"><span class="card-title"><i class="fas fa-chart-pie"></i> Ringkasan Keuangan</span></div>
                <table>
                    <tbody>
                    <tr><td style="padding:8px 14px;color:var(--text-muted)">Omzet Penjualan</td><td style="padding:8px 14px;text-align:right;font-weight:600;color:var(--success)">Rp <?=number_format($omzet,0,',','.')?></td></tr>
                    <tr style="background:var(--bg)"><td colspan="2" style="padding:6px 14px;font-size:.8rem;font-weight:600;color:var(--text-muted)">Pengeluaran per Divisi</td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)"><i class="fas fa-print" style="width:14px"></i> Printing</td><td style="padding:6px 14px;text-align:right">Rp <?=number_format($opsPrint,0,',','.')?></td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)"><i class="fas fa-shirt" style="width:14px"></i> Apparel</td><td style="padding:6px 14px;text-align:right">Rp <?=number_format($opsApp,0,',','.')?></td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)"><i class="fas fa-building" style="width:14px"></i> Umum</td><td style="padding:6px 14px;text-align:right">Rp <?=number_format($opsUmum,0,',','.')?></td></tr>
                    <tr style="border-top:2px solid var(--border)"><td style="padding:8px 14px;font-weight:700">Total Pengeluaran</td><td style="padding:8px 14px;text-align:right;font-weight:700;color:var(--danger)">Rp <?=number_format($totalOps,0,',','.')?></td></tr>
                    <tr style="background:<?=$labaBersih>=0?'#d1fae5':'#fee2e2'?>"><td style="padding:10px 14px;font-weight:700">Laba Bersih</td><td style="padding:10px 14px;text-align:right;font-weight:700;color:<?=$labaBersih>=0?'var(--success)':'var(--danger)'?>"><?=htmlspecialchars(monthlyReportFormatSignedCurrency($labaBersih))?></td></tr>
                    <tr style="background:var(--bg)"><td colspan="2" style="padding:6px 14px;font-size:.8rem;font-weight:600;color:var(--text-muted)">Bagi Hasil</td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)"><i class="fas fa-user-tie" style="width:14px"></i> CEO 60%</td><td style="padding:6px 14px;text-align:right;font-weight:600;color:<?=$bagiHasilCeo>=0?'var(--success)':'var(--danger)'?>"><?=htmlspecialchars(monthlyReportFormatSignedCurrency($bagiHasilCeo))?></td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)"><i class="fas fa-building" style="width:14px"></i> Head Office 40%</td><td style="padding:6px 14px;text-align:right;font-weight:600;color:<?=$bagiHasilHeadOffice>=0?'var(--primary)':'var(--danger)'?>"><?=htmlspecialchars(monthlyReportFormatSignedCurrency($bagiHasilHeadOffice))?></td></tr>
                    <?php if ($sisaTempo > 0): ?>
                    <tr><td style="padding:8px 14px;color:var(--warning)"><i class="fas fa-clock"></i> Piutang (DP/Tempo)</td><td style="padding:8px 14px;text-align:right;color:var(--warning)">Rp <?=number_format($sisaTempo,0,',','.')?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Transaksi & Produksi -->
        <div>
            <div class="card" style="margin-bottom:16px">
                <div class="card-header"><span class="card-title"><i class="fas fa-receipt"></i> Status Transaksi</span></div>
                <table>
                    <tbody>
                    <tr><td style="padding:8px 14px;color:var(--text-muted)">Total Transaksi</td><td style="padding:8px 14px;text-align:right;font-weight:600"><?=$jmlTrx?></td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)">Selesai</td><td style="padding:6px 14px;text-align:right"><span class="badge badge-success"><?=$trxSelesai?></span></td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)">DP</td><td style="padding:6px 14px;text-align:right"><span class="badge badge-warning"><?=$trxDP?></span></td></tr>
                    <tr><td style="padding:6px 14px 6px 24px;color:var(--text-muted)">Tempo</td><td style="padding:6px 14px;text-align:right"><span class="badge badge-info"><?=$trxTempo?></span></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <div class="card-header"><span class="card-title"><i class="fas fa-fire"></i> Produk Terlaris</span></div>
                <table>
                    <thead><tr><th>#</th><th>Produk</th><th style="text-align:right">Qty</th><th style="text-align:right">Omzet</th></tr></thead>
                    <tbody>
                    <?php foreach ($produkLaris as $i => $p): ?>
                    <tr>
                        <td><?=$i+1?></td>
                        <td><?=htmlspecialchars($p['nama_produk'])?></td>
                        <td style="text-align:right"><?=number_format($p['total_qty'],0,',','.')?></td>
                        <td style="text-align:right">Rp <?=number_format($p['total_omzet'],0,',','.')?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($produkLaris)): ?><tr><td colspan="4" class="text-center text-muted">Tidak ada data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Produksi & Absensi ringkasan -->
    <div class="form-row" style="margin-top:0">
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="fas fa-industry"></i> Produksi</span></div>
            <table>
                <tbody>
                <tr><td style="padding:8px 14px;color:var(--text-muted)">Total JO</td><td style="padding:8px 14px;text-align:right;font-weight:600"><?=$joPrinting?></td></tr>
                <tr><td style="padding:8px 14px;color:var(--text-muted)">JO Selesai</td><td style="padding:8px 14px;text-align:right"><span class="badge badge-success"><?=$joSelesai?></span></td></tr>
                <tr><td style="padding:8px 14px;color:var(--text-muted)">JO Pending</td><td style="padding:8px 14px;text-align:right"><span class="badge badge-warning"><?=$joPrinting-$joSelesai?></span></td></tr>
                </tbody>
            </table>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="fas fa-calendar-check"></i> Kehadiran</span></div>
            <table>
                <tbody>
                <tr><td style="padding:8px 14px;color:var(--text-muted)">Karyawan Aktif</td><td style="padding:8px 14px;text-align:right;font-weight:600"><?=$jmlKaryawan?></td></tr>
                <tr><td style="padding:8px 14px;color:var(--text-muted)">Total Hadir</td><td style="padding:8px 14px;text-align:right"><span class="badge badge-success"><?=$totalHadir?></span></td></tr>
                <tr><td style="padding:8px 14px;color:var(--text-muted)">Total Alpha</td><td style="padding:8px 14px;text-align:right"><span class="badge badge-danger"><?=$totalAlpha?></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tab === 'transaksi'): ?>
    <!-- ?? TRANSAKSI ?? -->
    <div class="d-flex justify-between align-center mb-2">
        <span class="text-muted small"><?=count($trxData)?> transaksi ? Total: <strong>Rp <?=number_format(array_sum(array_column(array_filter($trxData,fn($r)=>$r['status']==='selesai'),'total')),0,',','.')?></strong></span>
    </div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>#</th><th>No. Transaksi</th><th>Pelanggan</th><th>Total</th><th>Status</th><th>Tanggal</th></tr></thead>
            <tbody>
            <?php foreach ($trxData as $i => $d): ?>
            <tr>
                <td><?=$i+1?></td>
                <td><?=htmlspecialchars($d['no_transaksi'])?></td>
                <td><?=htmlspecialchars($d['pelanggan']??'Umum')?></td>
                <td class="rp"><?=number_format($d['total'],0,',','.')?></td>
                <td><span class="badge badge-<?=['selesai'=>'success','pending'=>'warning','batal'=>'danger','dp'=>'warning','tempo'=>'info'][$d['status']]??'secondary' ?>"><?=$d['status']?></span></td>
                <td><?=date('d/m/Y H:i',strtotime($d['created_at']))?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($trxData)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:20px">Tidak ada data</td></tr><?php endif; ?>
            </tbody>
            <?php if (!empty($trxData)): ?>
            <tfoot><tr style="background:var(--bg);font-weight:700">
                <td colspan="3" style="padding:8px 14px;text-align:right">Total Omzet (selesai)</td>
                <td style="padding:8px 14px" class="rp"><?=number_format(array_sum(array_column(array_filter($trxData,fn($r)=>$r['status']==='selesai'),'total')),0,',','.')?></td>
                <td colspan="2"></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php elseif ($tab === 'operasional'): ?>
    <!-- ?? OPERASIONAL ?? -->
    <div class="d-flex justify-between align-center mb-2">
        <span class="text-muted small"><?=count($opsData)?> pengeluaran ? Total: <strong>Rp <?=number_format(array_sum(array_column($opsData,'jumlah')),0,',','.')?></strong></span>
    </div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>#</th><th>Tanggal</th><th>Divisi</th><th>Kategori</th><th>Keterangan</th><th>Jumlah</th></tr></thead>
            <tbody>
            <?php foreach ($opsData as $i => $d): ?>
            <tr>
                <td><?=$i+1?></td>
                <td><?=date('d/m/Y',strtotime($d['tanggal']))?></td>
                <td><?=htmlspecialchars($d['divisi']??'-')?></td>
                <td><?=htmlspecialchars($d['kategori'])?></td>
                <td><?=htmlspecialchars($d['keterangan'])?></td>
                <td class="rp"><?=number_format($d['jumlah'],0,',','.')?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($opsData)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:20px">Tidak ada data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($tab === 'produksi'): ?>
    <!-- ?? PRODUKSI ?? -->
    <div class="table-responsive">
        <table>
            <thead><tr><th>#</th><th>No. Dokumen</th><th>Tipe</th><th>Pekerjaan</th><th>Invoice</th><th>Tanggal</th><th>Deadline</th><th>Karyawan</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($produksiData as $i => $d): ?>
            <tr>
                <td><?=$i+1?></td>
                <td><?=htmlspecialchars($d['no_dokumen'])?></td>
                <td><span class="badge <?=$d['tipe_dokumen']==='JO'?'badge-info':'badge-warning' ?>"><?=$d['tipe_dokumen']?></span></td>
                <td><?=htmlspecialchars($d['nama_pekerjaan'])?></td>
                <td><?=htmlspecialchars($d['no_transaksi']??'-')?></td>
                <td><?=date('d/m/Y',strtotime($d['tanggal']))?></td>
                <td><?=$d['deadline']?date('d/m/Y',strtotime($d['deadline'])):'-'?></td>
                <td><?=htmlspecialchars($d['karyawan']??'-')?></td>
                <td><span class="badge <?=['antrian'=>'badge-secondary','proses'=>'badge-warning','selesai'=>'badge-success','batal'=>'badge-danger'][$d['status']]??'badge-secondary' ?>"><?=$d['status']?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($produksiData)): ?><tr><td colspan="9" class="text-center text-muted" style="padding:20px">Tidak ada data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($tab === 'absensi'): ?>
    <!-- ?? ABSENSI ?? -->
    <div class="table-responsive">
        <table>
            <thead><tr><th>#</th><th>Nama</th><th>Jabatan</th><th style="text-align:center">Hadir</th><th style="text-align:center">Terlambat</th><th style="text-align:center">Izin</th><th style="text-align:center">Sakit</th><th style="text-align:center">Alpha</th><th style="text-align:center">% Hadir</th></tr></thead>
            <tbody>
            <?php
            // Hitung hari kerja bulan ini
            $hariKerja = 0;
            $d = new DateTime($tgl1); $end = new DateTime($tgl2);
            while ($d <= $end) { if ($d->format('N') != 7) $hariKerja++; $d->modify('+1 day'); }
            foreach ($absensiData as $i => $d):
                $pct = $hariKerja > 0 ? round($d['hadir']/$hariKerja*100) : 0;
                $color = $pct >= 80 ? 'var(--success)' : ($pct >= 60 ? 'var(--warning)' : 'var(--danger)');
            ?>
            <tr>
                <td><?=$i+1?></td>
                <td><?=htmlspecialchars($d['nama'])?></td>
                <td><?=htmlspecialchars($d['jabatan']??'-')?></td>
                <td style="text-align:center"><span class="badge badge-success"><?=$d['hadir']?></span></td>
                <td style="text-align:center"><span class="badge badge-warning"><?=$d['terlambat']?></span></td>
                <td style="text-align:center"><span class="badge badge-info"><?=$d['izin']?></span></td>
                <td style="text-align:center"><span class="badge badge-info"><?=$d['sakit']?></span></td>
                <td style="text-align:center"><span class="badge badge-danger"><?=$d['alpha']?></span></td>
                <td style="text-align:center"><span style="color:<?=$color?>;font-weight:700"><?=$pct?>%</span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($absensiData)): ?><tr><td colspan="9" class="text-center text-muted" style="padding:20px">Tidak ada data absensi</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($tab === 'karyawan'): ?>
    <!-- ?? KARYAWAN & GAJI ?? -->
    <div class="table-responsive">
        <table>
            <thead><tr><th>#</th><th>Nama</th><th>Jabatan</th><th>Divisi</th><th>Gaji Bulan Ini</th><th>Status Bayar</th></tr></thead>
            <tbody>
            <?php foreach ($karyawanData as $i => $d): ?>
            <tr>
                <td><?=$i+1?></td>
                <td><?=htmlspecialchars($d['nama'])?></td>
                <td><?=htmlspecialchars($d['jabatan']??'-')?></td>
                <td><?=htmlspecialchars($d['divisi']??'-')?></td>
                <td class="rp"><?=number_format($d['gaji_bulan'],0,',','.')?></td>
                <td>
                    <?php if ($d['gaji_bulan'] > 0): ?>
                        <span class="badge <?=$d['status_bayar']==='sudah_dibayar'?'badge-success':'badge-warning' ?>"><?=$d['status_bayar']??'belum_dibayar'?></span>
                    <?php else: ?>
                        <span class="text-muted small">Belum di-generate</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($karyawanData)): ?><tr><td colspan="6" class="text-center text-muted" style="padding:20px">Tidak ada data</td></tr><?php endif; ?>
            </tbody>
            <?php if (!empty($karyawanData)): ?>
            <tfoot><tr style="background:var(--bg);font-weight:700">
                <td colspan="4" style="padding:8px 14px;text-align:right">Total Gaji</td>
                <td style="padding:8px 14px" class="rp"><?=number_format(array_sum(array_column($karyawanData,'gaji_bulan')),0,',','.')?></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php elseif ($tab === 'stok'): ?>
    <!-- ?? STOK PRODUK ?? -->
    <div class="d-flex justify-between align-center mb-2">
        <span class="text-muted small">Nilai total stok: <strong>Rp <?=number_format($nilaiStok,0,',','.')?></strong></span>
    </div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>#</th><th>Kode</th><th>Nama</th><th>Kategori</th><th>Stok</th><th>Satuan</th><th>Harga Jual</th><th>Nilai Stok</th></tr></thead>
            <tbody>
            <?php foreach ($stokData as $i => $d): ?>
            <tr>
                <td><?=$i+1?></td>
                <td><?=htmlspecialchars($d['kode'])?></td>
                <td><?=htmlspecialchars($d['nama'])?></td>
                <td><?=htmlspecialchars($d['kategori']??'-')?></td>
                <td><span class="badge <?=$d['stok']<=5?'badge-danger':'badge-success' ?>"><?=$d['stok']?></span></td>
                <td><?=htmlspecialchars($d['satuan'])?></td>
                <td class="rp"><?=number_format($d['harga_jual'],0,',','.')?></td>
                <td class="rp"><?=number_format($d['nilai_stok'],0,',','.')?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($stokData)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:20px">Tidak ada data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    </div><!-- end card body -->
</div>
</div>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>
