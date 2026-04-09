<?php

function monthlyReportBuildAnalytics(array $context): array
{
    $label = (string) ($context['label'] ?? '');
    $tgl2 = (string) ($context['tgl2'] ?? date('Y-m-d'));
    $hariKerja = (int) ($context['hariKerja'] ?? 0);
    $omzet = (float) ($context['omzet'] ?? 0);
    $jmlTrx = (int) ($context['jmlTrx'] ?? 0);
    $trxSelesai = (int) ($context['trxSelesai'] ?? 0);
    $trxDP = (int) ($context['trxDP'] ?? 0);
    $trxTempo = (int) ($context['trxTempo'] ?? 0);
    $sisaTempo = (float) ($context['sisaTempo'] ?? 0);
    $totalOps = (float) ($context['totalOps'] ?? 0);
    $opsPrint = (float) ($context['opsPrint'] ?? 0);
    $opsApp = (float) ($context['opsApp'] ?? 0);
    $opsUmum = (float) ($context['opsUmum'] ?? 0);
    $labaBersih = (float) ($context['labaBersih'] ?? 0);
    $joPrinting = (int) ($context['joPrinting'] ?? 0);
    $joSelesai = (int) ($context['joSelesai'] ?? 0);
    $jmlKaryawan = (int) ($context['jmlKaryawan'] ?? 0);
    $totalHadir = (int) ($context['totalHadir'] ?? 0);
    $totalAlpha = (int) ($context['totalAlpha'] ?? 0);
    $nilaiStok = (float) ($context['nilaiStok'] ?? 0);
    $produkLaris = is_array($context['produkLaris'] ?? null) ? $context['produkLaris'] : [];
    $metodeBayar = is_array($context['metodeBayar'] ?? null) ? $context['metodeBayar'] : [];
    $trxData = is_array($context['trxData'] ?? null) ? $context['trxData'] : [];
    $opsData = is_array($context['opsData'] ?? null) ? $context['opsData'] : [];
    $produksiData = is_array($context['produksiData'] ?? null) ? $context['produksiData'] : [];
    $absensiData = is_array($context['absensiData'] ?? null) ? $context['absensiData'] : [];
    $karyawanData = is_array($context['karyawanData'] ?? null) ? $context['karyawanData'] : [];
    $stokData = is_array($context['stokData'] ?? null) ? $context['stokData'] : [];
    $dateAxis = is_array($context['dateAxis'] ?? null) ? $context['dateAxis'] : ['keys' => [], 'labels' => []];
    $financeDailyRows = is_array($context['financeDailyRows'] ?? null) ? $context['financeDailyRows'] : [];
    $opsDailyRows = is_array($context['opsDailyRows'] ?? null) ? $context['opsDailyRows'] : [];

    $dailyOmzet = monthlyReportSeriesFromRows($financeDailyRows, 'tanggal', $dateAxis['keys'], static fn(array $row): float => (float) ($row['omzet_selesai'] ?? 0));
    $dailyTrx = monthlyReportSeriesFromRows($financeDailyRows, 'tanggal', $dateAxis['keys'], static fn(array $row): float => (float) ($row['total_transaksi'] ?? 0));
    $dailyOps = monthlyReportSeriesFromRows($opsDailyRows, 'tanggal', $dateAxis['keys'], static fn(array $row): float => (float) ($row['total_biaya'] ?? 0));

    $expenseRatio = monthlyReportPercentage($totalOps, $omzet);
    $closingRate = monthlyReportPercentage((float) $trxSelesai, (float) $jmlTrx);
    $averageTicket = $trxSelesai > 0 ? ($omzet / $trxSelesai) : 0.0;
    $attendanceCapacity = $jmlKaryawan > 0 ? ($jmlKaryawan * $hariKerja) : 0;
    $attendanceRate = monthlyReportPercentage((float) $totalHadir, (float) $attendanceCapacity);
    $joCompletionRate = monthlyReportPercentage((float) $joSelesai, (float) $joPrinting);

    $peakOmzet = !empty($dailyOmzet) ? max($dailyOmzet) : 0.0;
    $peakOmzetIndex = $peakOmzet > 0 ? array_search($peakOmzet, $dailyOmzet, true) : false;
    $peakOmzetLabel = $peakOmzetIndex !== false ? ($dateAxis['labels'][$peakOmzetIndex] ?? '-') : '-';
    $peakOps = !empty($dailyOps) ? max($dailyOps) : 0.0;
    $peakOpsIndex = $peakOps > 0 ? array_search($peakOps, $dailyOps, true) : false;
    $peakOpsLabel = $peakOpsIndex !== false ? ($dateAxis['labels'][$peakOpsIndex] ?? '-') : '-';

    $opsDivisionItems = monthlyReportFilterValuedItems([
        ['label' => 'Printing', 'value' => $opsPrint],
        ['label' => 'Apparel', 'value' => $opsApp],
        ['label' => 'Umum', 'value' => $opsUmum],
    ]);
    $trxStatusSummary = monthlyReportFilterValuedItems([
        ['label' => 'Selesai', 'value' => $trxSelesai],
        ['label' => 'DP', 'value' => $trxDP],
        ['label' => 'Tempo', 'value' => $trxTempo],
        ['label' => 'Lainnya', 'value' => max(0, $jmlTrx - ($trxSelesai + $trxDP + $trxTempo))],
    ]);
    $trxStatusDetailed = !empty($trxData)
        ? monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($trxData, static fn(array $row): string => ucfirst((string) ($row['status'] ?? 'lainnya')), static fn(): float => 1.0)), 6)
        : $trxStatusSummary;
    $paymentItems = monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($metodeBayar, static fn(array $row): string => (string) ($row['metode_bayar'] ?? 'Lainnya'), static fn(array $row): float => (float) ($row['jml'] ?? 0))), 6);
    $opsCategoryItems = monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($opsData, static fn(array $row): string => (string) ($row['kategori'] ?? 'Tanpa Kategori'), static fn(array $row): float => (float) ($row['jumlah'] ?? 0))), 6);
    $produksiStatusItems = monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($produksiData, static fn(array $row): string => ucfirst((string) ($row['status'] ?? 'Tanpa Status')), static fn(): float => 1.0)), 6);
    $produksiPicItems = monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($produksiData, static fn(array $row): string => trim((string) ($row['karyawan'] ?? '')) !== '' ? (string) $row['karyawan'] : 'Belum Ada PIC', static fn(): float => 1.0)), 6);
    $salaryByDivisionItems = monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($karyawanData, static fn(array $row): string => (string) ($row['divisi'] ?? 'Tanpa Divisi'), static fn(array $row): float => (float) ($row['gaji_bulan'] ?? 0))), 6);
    $stokCategoryItems = monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($stokData, static fn(array $row): string => (string) ($row['kategori'] ?? 'Tanpa Kategori'), static fn(array $row): float => (float) ($row['nilai_stok'] ?? 0))), 6);

    $produksiActive = count(array_filter($produksiData, static function (array $row): bool {
        return !in_array(strtolower((string) ($row['status'] ?? '')), ['selesai', 'batal'], true);
    }));
    $produksiOverdue = count(array_filter($produksiData, static function (array $row) use ($tgl2): bool {
        $deadline = (string) ($row['deadline'] ?? '');
        $status = strtolower((string) ($row['status'] ?? ''));
        return $deadline !== '' && $deadline < $tgl2 && !in_array($status, ['selesai', 'batal'], true);
    }));

    $attendanceStatusItems = monthlyReportFilterValuedItems([
        ['label' => 'Hadir', 'value' => array_sum(array_map(static fn(array $row): float => (float) ($row['hadir'] ?? 0), $absensiData))],
        ['label' => 'Terlambat', 'value' => array_sum(array_map(static fn(array $row): float => (float) ($row['terlambat'] ?? 0), $absensiData))],
        ['label' => 'Izin', 'value' => array_sum(array_map(static fn(array $row): float => (float) ($row['izin'] ?? 0), $absensiData))],
        ['label' => 'Sakit', 'value' => array_sum(array_map(static fn(array $row): float => (float) ($row['sakit'] ?? 0), $absensiData))],
        ['label' => 'Alpha', 'value' => array_sum(array_map(static fn(array $row): float => (float) ($row['alpha'] ?? 0), $absensiData))],
    ]);
    $attendanceRiskRows = [];
    foreach ($absensiData as $row) {
        $attendanceRiskRows[] = [
            'label' => (string) ($row['nama'] ?? 'Tanpa Nama'),
            'value' => $hariKerja > 0 ? monthlyReportPercentage((float) ($row['hadir'] ?? 0), (float) $hariKerja) : 0.0,
            'alpha' => (float) ($row['alpha'] ?? 0),
        ];
    }
    usort($attendanceRiskRows, static function (array $left, array $right): int {
        return $left['value'] === $right['value'] ? ($right['alpha'] <=> $left['alpha']) : ($left['value'] <=> $right['value']);
    });
    $attendanceRiskItems = monthlyReportSliceItems(array_map(static fn(array $row): array => ['label' => $row['label'], 'value' => $row['value']], $attendanceRiskRows), 6);
    $attendanceRiskCount = count(array_filter($attendanceRiskRows, static fn(array $row): bool => (float) ($row['value'] ?? 0) < 80));

    $payrollStatusItemsRaw = [];
    foreach ($karyawanData as $row) {
        $salary = (float) ($row['gaji_bulan'] ?? 0);
        $payrollStatusItemsRaw[] = ['label' => $salary > 0 ? ((($row['status_bayar'] ?? '') === 'sudah_dibayar') ? 'Sudah Dibayar' : 'Belum Dibayar') : 'Belum Generate', 'value' => 1];
    }
    $payrollStatusItems = monthlyReportSliceItems(monthlyReportFilterValuedItems(monthlyReportAggregateItems($payrollStatusItemsRaw, static fn(array $row): string => (string) ($row['label'] ?? 'Lainnya'), static fn(array $row): float => (float) ($row['value'] ?? 0))), 4);
    $generatedPayroll = count(array_filter($karyawanData, static fn(array $row): bool => (float) ($row['gaji_bulan'] ?? 0) > 0));
    $unpaidPayroll = count(array_filter($karyawanData, static fn(array $row): bool => (float) ($row['gaji_bulan'] ?? 0) > 0 && (($row['status_bayar'] ?? '') !== 'sudah_dibayar')));
    $notGeneratedPayroll = count(array_filter($karyawanData, static fn(array $row): bool => (float) ($row['gaji_bulan'] ?? 0) <= 0));
    $totalPayroll = (float) array_sum(array_column($karyawanData, 'gaji_bulan'));
    $averagePayroll = $generatedPayroll > 0 ? ($totalPayroll / $generatedPayroll) : 0.0;

    $stokConditionItems = monthlyReportFilterValuedItems([
        ['label' => 'Kritis <= 5', 'value' => count(array_filter($stokData, static fn(array $row): bool => (float) ($row['stok'] ?? 0) <= 5))],
        ['label' => 'Waspada 6-10', 'value' => count(array_filter($stokData, static function (array $row): bool { $stok = (float) ($row['stok'] ?? 0); return $stok > 5 && $stok <= 10; }))],
        ['label' => 'Aman > 10', 'value' => count(array_filter($stokData, static fn(array $row): bool => (float) ($row['stok'] ?? 0) > 10))],
    ]);
    $stokCritical = (int) (($stokConditionItems[0]['value'] ?? 0));
    $stokSafe = 0;
    foreach ($stokConditionItems as $item) {
        if (($item['label'] ?? '') === 'Aman > 10') {
            $stokSafe = (int) ($item['value'] ?? 0);
        }
    }

    $topProductVisual = monthlyReportMakeVisual('Produk Penggerak Omzet', 'Produk dengan omzet tertinggi pada periode ini.', 'currency', monthlyReportSliceItems(array_map(static fn(array $item): array => ['label' => (string) ($item['nama_produk'] ?? 'Tanpa Nama'), 'value' => (float) ($item['total_omzet'] ?? 0)], $produkLaris), 5));
    $paymentVisual = monthlyReportMakeVisual('Metode Pembayaran Dominan', 'Sebaran jumlah transaksi per metode bayar.', 'number', $paymentItems);
    $opsDivisionVisual = monthlyReportMakeVisual('Distribusi Beban Operasional', 'Komposisi biaya menurut divisi.', 'currency', $opsDivisionItems);
    $opsCategoryVisual = monthlyReportMakeVisual('Kategori Biaya Terbesar', 'Aktivitas yang paling banyak menyerap biaya.', 'currency', $opsCategoryItems);
    $produksiStatusVisual = monthlyReportMakeVisual('Status Job Produksi', 'Sebaran status dokumen produksi bulan ini.', 'number', $produksiStatusItems);
    $produksiPicVisual = monthlyReportMakeVisual('Beban Kerja per PIC', 'PIC dengan job terbanyak pada periode ini.', 'number', $produksiPicItems);
    $absensiStatusVisual = monthlyReportMakeVisual('Distribusi Kehadiran', 'Status kehadiran selama periode berjalan.', 'number', $attendanceStatusItems);
    $absensiRiskVisual = monthlyReportMakeVisual('Karyawan Risiko Kehadiran', 'Persentase hadir terendah yang perlu dibina.', 'percentage', $attendanceRiskItems);
    $payrollDivisionVisual = monthlyReportMakeVisual('Distribusi Gaji per Divisi', 'Akumulasi payroll dari slip yang sudah tergenerate.', 'currency', $salaryByDivisionItems);
    $payrollStatusVisual = monthlyReportMakeVisual('Status Payroll', 'Slip dibayar, belum dibayar, dan belum tergenerate.', 'number', $payrollStatusItems);
    $stokCategoryVisual = monthlyReportMakeVisual('Nilai Stok per Kategori', 'Kategori yang paling besar menahan modal persediaan.', 'currency', $stokCategoryItems);
    $stokConditionVisual = monthlyReportMakeVisual('Kondisi Kesehatan Stok', 'Jumlah SKU aman, waspada, dan kritis.', 'number', $stokConditionItems);

    return [
        'ringkasan' => [
            'summary' => 'Ringkasan ini merangkum arus omzet, tekanan biaya, eksekusi produksi, dan kesehatan SDM untuk bulan ' . $label . '.',
            'kpis' => [
                monthlyReportMakeKpi('Closing Rate', monthlyReportFormatPercentage($closingRate), 'Transaksi selesai terhadap total transaksi.', $closingRate >= 70 ? 'success' : 'warning'),
                monthlyReportMakeKpi('Rasio Biaya', monthlyReportFormatPercentage($expenseRatio), 'Biaya dibanding omzet selesai.', $expenseRatio <= 65 ? 'success' : 'danger'),
                monthlyReportMakeKpi('Tingkat Hadir', monthlyReportFormatPercentage($attendanceRate), 'Kapasitas kehadiran tim pada bulan ini.', $attendanceRate >= 85 ? 'success' : 'warning'),
                monthlyReportMakeKpi('JO Selesai', monthlyReportFormatPercentage($joCompletionRate), 'Penyelesaian JO pada periode berjalan.', $joCompletionRate >= 80 ? 'success' : 'warning'),
            ],
            'insights' => [
                monthlyReportMakeInsight($labaBersih >= 0 ? 'success' : 'danger', $labaBersih >= 0 ? 'Margin bulan ini masih positif' : 'Margin bulan ini masih tertekan', 'Laba bersih tercatat ' . monthlyReportFormatSignedCurrency($labaBersih) . ' dengan rasio biaya ' . monthlyReportFormatPercentage($expenseRatio) . '.'),
                monthlyReportMakeInsight($sisaTempo > 0 ? 'warning' : 'success', $sisaTempo > 0 ? 'Piutang aktif perlu dikawal' : 'Arus kas penjualan relatif aman', $sisaTempo > 0 ? 'Masih ada sisa pembayaran ' . monthlyReportFormatSignedCurrency($sisaTempo) . ' dari transaksi DP dan tempo.' : 'Tidak ada piutang aktif dari transaksi DP atau tempo pada periode ini.'),
                monthlyReportMakeInsight($peakOmzet >= $peakOps ? 'primary' : 'warning', 'Hari paling aktif ada di ' . $peakOmzetLabel, 'Puncak omzet harian mencapai ' . monthlyReportFormatVisualValue($peakOmzet, 'currency') . ', sedangkan puncak biaya berada di ' . $peakOpsLabel . ' sebesar ' . monthlyReportFormatVisualValue($peakOps, 'currency') . '.'),
            ],
            'charts' => [
                monthlyReportMakeChart('ringkasan-cashflow', 'Tren Omzet vs Biaya Harian', 'Membaca momentum pemasukan dan tekanan biaya sepanjang bulan.', 'line', 'currency', $dateAxis['labels'], [['label' => 'Omzet Selesai', 'data' => $dailyOmzet, 'borderColor' => '#0f766e', 'backgroundColor' => 'rgba(15, 118, 110, 0.12)', 'fill' => true, 'tension' => 0.35], ['label' => 'Biaya Operasional', 'data' => $dailyOps, 'borderColor' => '#dc2626', 'backgroundColor' => 'rgba(220, 38, 38, 0.08)', 'fill' => true, 'tension' => 0.35]], ['legend' => true]),
                monthlyReportMakeChart('ringkasan-status', 'Komposisi Status Transaksi', 'Kualitas konversi penjualan selama bulan berjalan.', 'doughnut', 'number', array_column($trxStatusSummary, 'label'), [['label' => 'Status', 'data' => array_column($trxStatusSummary, 'value'), 'backgroundColor' => ['#0f766e', '#f59e0b', '#3b82f6', '#94a3b8']]]),
            ],
            'visuals' => [$topProductVisual, $opsDivisionVisual],
        ],
        'transaksi' => [
            'summary' => 'Tab transaksi memusatkan kualitas penjualan, metode pembayaran, piutang aktif, dan ritme transaksi harian agar cepat dibaca manajemen.',
            'kpis' => [
                monthlyReportMakeKpi('Omzet Selesai', 'Rp ' . number_format($omzet, 0, ',', '.'), 'Pendapatan transaksi selesai.', 'success'),
                monthlyReportMakeKpi('Rata-rata Invoice', 'Rp ' . number_format($averageTicket, 0, ',', '.'), 'Nilai rata-rata transaksi selesai.', 'primary'),
                monthlyReportMakeKpi('Piutang Aktif', 'Rp ' . number_format($sisaTempo, 0, ',', '.'), 'Sisa bayar transaksi DP dan tempo.', $sisaTempo > 0 ? 'warning' : 'success'),
                monthlyReportMakeKpi('Closing Rate', monthlyReportFormatPercentage($closingRate), 'Rasio transaksi selesai.', $closingRate >= 70 ? 'success' : 'warning'),
            ],
            'insights' => [
                monthlyReportMakeInsight('primary', 'Metode pembayaran dominan: ' . ($paymentItems[0]['label'] ?? 'Belum ada data'), number_format((float) ($paymentItems[0]['value'] ?? 0), 0, ',', '.') . ' transaksi menggunakan metode ini.'),
                monthlyReportMakeInsight($sisaTempo > 0 ? 'warning' : 'success', $sisaTempo > 0 ? 'Masih ada transaksi yang belum lunas' : 'Tagihan transaksi relatif terkendali', $sisaTempo > 0 ? number_format($trxDP + $trxTempo, 0, ',', '.') . ' transaksi masih menyisakan kewajiban bayar sebesar ' . monthlyReportFormatVisualValue($sisaTempo, 'currency') . '.' : 'Tidak ada kewajiban piutang aktif yang menonjol di periode ini.'),
                monthlyReportMakeInsight($peakOmzet > 0 ? 'success' : 'secondary', 'Puncak omzet transaksi terjadi pada ' . $peakOmzetLabel, 'Nilai omzet selesai tertinggi harian tercatat sebesar ' . monthlyReportFormatVisualValue($peakOmzet, 'currency') . '.'),
            ],
            'charts' => [
                monthlyReportMakeChart('transaksi-harian', 'Aktivitas Transaksi Harian', 'Volume transaksi dan omzet selesai dalam satu pandangan.', 'bar', 'number', $dateAxis['labels'], [['label' => 'Jumlah Transaksi', 'data' => $dailyTrx, 'backgroundColor' => 'rgba(59, 130, 246, 0.22)', 'borderColor' => '#3b82f6', 'borderWidth' => 1], ['type' => 'line', 'label' => 'Omzet Selesai', 'data' => $dailyOmzet, 'borderColor' => '#0f766e', 'backgroundColor' => 'rgba(15, 118, 110, 0.10)', 'yAxisID' => 'yCurrency', 'tension' => 0.35, 'fill' => false]], ['legend' => true, 'secondaryAxis' => 'currency']),
                monthlyReportMakeChart('transaksi-komposisi', 'Status & Kualitas Penjualan', 'Sebaran status transaksi pada periode ini.', 'doughnut', 'number', array_column($trxStatusDetailed, 'label'), [['label' => 'Status', 'data' => array_column($trxStatusDetailed, 'value'), 'backgroundColor' => ['#0f766e', '#f59e0b', '#3b82f6', '#ef4444', '#94a3b8', '#8b5cf6']]]),
            ],
            'visuals' => [$paymentVisual, $topProductVisual],
        ],
        'operasional' => [
            'summary' => 'Tab operasional menyorot arah pembelanjaan, divisi paling boros, dan ritme biaya harian untuk keputusan efisiensi yang lebih cepat.',
            'kpis' => [
                monthlyReportMakeKpi('Total Biaya', 'Rp ' . number_format($totalOps, 0, ',', '.'), 'Akumulasi seluruh pengeluaran operasional.', 'danger'),
                monthlyReportMakeKpi('Biaya per Hari Kerja', 'Rp ' . number_format($hariKerja > 0 ? ($totalOps / $hariKerja) : 0, 0, ',', '.'), 'Rata-rata biaya per hari kerja.', 'primary'),
                monthlyReportMakeKpi('Rasio ke Omzet', monthlyReportFormatPercentage($expenseRatio), 'Pengeluaran dibanding omzet selesai.', $expenseRatio <= 65 ? 'success' : 'warning'),
                monthlyReportMakeKpi('Divisi Terbesar', (string) ($opsDivisionItems[0]['label'] ?? 'Belum ada biaya'), 'Kontribusi biaya tertinggi saat ini.', !empty($opsDivisionItems) ? 'warning' : 'secondary'),
            ],
            'insights' => [
                monthlyReportMakeInsight(!empty($opsDivisionItems) ? 'warning' : 'secondary', 'Divisi biaya terbesar: ' . ($opsDivisionItems[0]['label'] ?? 'Belum ada biaya'), 'Nilainya mencapai ' . monthlyReportFormatVisualValue((float) ($opsDivisionItems[0]['value'] ?? 0), 'currency') . '.'),
                monthlyReportMakeInsight(!empty($opsCategoryItems) ? 'primary' : 'secondary', 'Kategori biaya dominan: ' . ($opsCategoryItems[0]['label'] ?? 'Belum ada kategori'), 'Kategori ini menyerap ' . monthlyReportFormatVisualValue((float) ($opsCategoryItems[0]['value'] ?? 0), 'currency') . ' dan layak dievaluasi.'),
                monthlyReportMakeInsight($peakOps > 0 ? 'warning' : 'secondary', 'Lonjakan biaya tertinggi ada di ' . $peakOpsLabel, 'Nilainya mencapai ' . monthlyReportFormatVisualValue($peakOps, 'currency') . '.'),
            ],
            'charts' => [
                monthlyReportMakeChart('operasional-tren', 'Tren Biaya Harian', 'Pola pengeluaran harian sepanjang bulan berjalan.', 'line', 'currency', $dateAxis['labels'], [['label' => 'Biaya Operasional', 'data' => $dailyOps, 'borderColor' => '#dc2626', 'backgroundColor' => 'rgba(220, 38, 38, 0.10)', 'fill' => true, 'tension' => 0.35]]),
                monthlyReportMakeChart('operasional-divisi', 'Komposisi Biaya per Divisi', 'Sumber tekanan biaya dari sisi divisi.', 'doughnut', 'currency', array_column($opsDivisionItems, 'label'), [['label' => 'Biaya', 'data' => array_column($opsDivisionItems, 'value'), 'backgroundColor' => ['#dc2626', '#f59e0b', '#3b82f6']]]),
            ],
            'visuals' => [$opsDivisionVisual, $opsCategoryVisual],
        ],
        'produksi' => [
            'summary' => 'Tab produksi memusatkan status job, backlog aktif, keterlambatan deadline, dan distribusi beban PIC untuk menjaga SLA tetap sehat.',
            'kpis' => [
                monthlyReportMakeKpi('Dokumen Produksi', number_format(count($produksiData), 0, ',', '.'), 'JO dan SPK yang tercatat.', 'primary'),
                monthlyReportMakeKpi('Penyelesaian JO', monthlyReportFormatPercentage($joCompletionRate), 'JO selesai dibanding total JO.', $joCompletionRate >= 80 ? 'success' : 'warning'),
                monthlyReportMakeKpi('Job Aktif', number_format($produksiActive, 0, ',', '.'), 'Dokumen yang masih berjalan.', $produksiActive > 0 ? 'warning' : 'success'),
                monthlyReportMakeKpi('Melewati Deadline', number_format($produksiOverdue, 0, ',', '.'), 'Job aktif yang perlu eskalasi.', $produksiOverdue > 0 ? 'danger' : 'success'),
            ],
            'insights' => [
                monthlyReportMakeInsight($produksiActive > 0 ? 'warning' : 'success', 'Backlog aktif saat ini ' . number_format($produksiActive, 0, ',', '.'), 'Jumlah ini merepresentasikan dokumen yang belum selesai atau belum dibatalkan.'),
                monthlyReportMakeInsight($produksiOverdue > 0 ? 'danger' : 'success', $produksiOverdue > 0 ? 'Ada job yang melampaui deadline' : 'Deadline produksi relatif terkendali', $produksiOverdue > 0 ? number_format($produksiOverdue, 0, ',', '.') . ' dokumen aktif melewati deadline.' : 'Tidak ada dokumen aktif yang melewati deadline pada periode ini.'),
                monthlyReportMakeInsight(!empty($produksiPicItems) ? 'primary' : 'secondary', 'PIC dengan beban tertinggi: ' . ($produksiPicItems[0]['label'] ?? 'Belum ada PIC'), 'Total job yang melekat sebanyak ' . number_format((float) ($produksiPicItems[0]['value'] ?? 0), 0, ',', '.') . ' dokumen.'),
            ],
            'charts' => [
                monthlyReportMakeChart('produksi-status', 'Komposisi Status Produksi', 'Posisi antrian, proses, selesai, dan batal.', 'doughnut', 'number', array_column($produksiStatusItems, 'label'), [['label' => 'Status Produksi', 'data' => array_column($produksiStatusItems, 'value'), 'backgroundColor' => ['#64748b', '#f59e0b', '#0f766e', '#ef4444', '#3b82f6', '#8b5cf6']]]),
                monthlyReportMakeChart('produksi-pic', 'Distribusi Job per PIC', 'Konsentrasi beban kerja produksi.', 'bar', 'number', array_column($produksiPicItems, 'label'), [['label' => 'Jumlah Job', 'data' => array_column($produksiPicItems, 'value'), 'backgroundColor' => 'rgba(15, 118, 110, 0.18)', 'borderColor' => '#0f766e', 'borderWidth' => 1]], ['indexAxis' => 'y']),
            ],
            'visuals' => [$produksiStatusVisual, $produksiPicVisual],
        ],
        'absensi' => [
            'summary' => 'Tab absensi memusatkan disiplin kehadiran, tingkat alpha, keterlambatan, dan daftar karyawan yang perlu pembinaan paling cepat.',
            'kpis' => [
                monthlyReportMakeKpi('Hari Kerja', number_format($hariKerja, 0, ',', '.'), 'Perhitungan tanpa hari Minggu.', 'primary'),
                monthlyReportMakeKpi('Tingkat Hadir', monthlyReportFormatPercentage($attendanceRate), 'Kapasitas kehadiran tim.', $attendanceRate >= 85 ? 'success' : 'warning'),
                monthlyReportMakeKpi('Total Alpha', number_format($totalAlpha, 0, ',', '.'), 'Absensi tanpa keterangan.', $totalAlpha > 0 ? 'danger' : 'success'),
                monthlyReportMakeKpi('Karyawan Risiko', number_format($attendanceRiskCount, 0, ',', '.'), 'Persentase hadir di bawah 80%.', $attendanceRiskCount > 0 ? 'warning' : 'success'),
            ],
            'insights' => [
                monthlyReportMakeInsight($attendanceRate >= 85 ? 'success' : 'warning', 'Kehadiran tim berada di ' . monthlyReportFormatPercentage($attendanceRate), 'Semakin tinggi nilai ini, semakin stabil kapasitas operasional tim.'),
                monthlyReportMakeInsight($totalAlpha > 0 ? 'danger' : 'success', $totalAlpha > 0 ? 'Masih ada absensi tanpa keterangan' : 'Tidak ada alpha pada periode ini', $totalAlpha > 0 ? 'Jumlah alpha mencapai ' . number_format($totalAlpha, 0, ',', '.') . ' kejadian.' : 'Tim mencatat disiplin absensi yang lebih rapi.'),
                monthlyReportMakeInsight($attendanceRiskCount > 0 ? 'warning' : 'success', $attendanceRiskCount > 0 ? 'Ada karyawan yang perlu coaching kehadiran' : 'Tidak ada karyawan pada zona risiko kehadiran', $attendanceRiskCount > 0 ? number_format($attendanceRiskCount, 0, ',', '.') . ' karyawan memiliki persentase hadir di bawah 80%.' : 'Persentase hadir seluruh karyawan berada pada level yang aman.'),
            ],
            'charts' => [
                monthlyReportMakeChart('absensi-status', 'Distribusi Status Absensi', 'Sebaran hadir, terlambat, izin, sakit, dan alpha.', 'doughnut', 'number', array_column($attendanceStatusItems, 'label'), [['label' => 'Status Absensi', 'data' => array_column($attendanceStatusItems, 'value'), 'backgroundColor' => ['#0f766e', '#f59e0b', '#3b82f6', '#8b5cf6', '#ef4444']]]),
                monthlyReportMakeChart('absensi-risiko', 'Persentase Hadir Karyawan Risiko', 'Fokus pada karyawan dengan tingkat hadir terendah.', 'bar', 'percentage', array_column($attendanceRiskItems, 'label'), [['label' => '% Hadir', 'data' => array_column($attendanceRiskItems, 'value'), 'backgroundColor' => 'rgba(245, 158, 11, 0.20)', 'borderColor' => '#f59e0b', 'borderWidth' => 1]], ['indexAxis' => 'y']),
            ],
            'visuals' => [$absensiStatusVisual, $absensiRiskVisual],
        ],
        'karyawan' => [
            'summary' => 'Tab karyawan dan gaji menyorot kesiapan payroll, beban gaji per divisi, dan slip yang masih perlu diselesaikan sebelum tutup buku.',
            'kpis' => [
                monthlyReportMakeKpi('Karyawan Aktif', number_format($jmlKaryawan, 0, ',', '.'), 'Jumlah personel aktif.', 'primary'),
                monthlyReportMakeKpi('Total Payroll', 'Rp ' . number_format($totalPayroll, 0, ',', '.'), 'Slip gaji yang sudah tergenerate.', 'success'),
                monthlyReportMakeKpi('Rata-rata Gaji', 'Rp ' . number_format($averagePayroll, 0, ',', '.'), 'Rata-rata slip gaji yang ada.', 'primary'),
                monthlyReportMakeKpi('Belum Dibayar/Generate', number_format($unpaidPayroll + $notGeneratedPayroll, 0, ',', '.'), 'Slip yang masih perlu tindakan HR.', ($unpaidPayroll + $notGeneratedPayroll) > 0 ? 'warning' : 'success'),
            ],
            'insights' => [
                monthlyReportMakeInsight(!empty($salaryByDivisionItems) ? 'primary' : 'secondary', 'Divisi payroll terbesar: ' . ($salaryByDivisionItems[0]['label'] ?? 'Belum ada divisi'), 'Akumulasi slip gaji pada divisi ini mencapai ' . monthlyReportFormatVisualValue((float) ($salaryByDivisionItems[0]['value'] ?? 0), 'currency') . '.'),
                monthlyReportMakeInsight($unpaidPayroll > 0 ? 'warning' : 'success', $unpaidPayroll > 0 ? 'Masih ada slip yang belum dibayarkan' : 'Seluruh slip tergenerate sudah dibayar', $unpaidPayroll > 0 ? number_format($unpaidPayroll, 0, ',', '.') . ' slip masih berstatus belum dibayar.' : 'Tidak ada slip tergenerate yang tertahan di status belum dibayar.'),
                monthlyReportMakeInsight($notGeneratedPayroll > 0 ? 'warning' : 'success', $notGeneratedPayroll > 0 ? 'Masih ada karyawan tanpa slip bulan ini' : 'Seluruh karyawan aktif sudah memiliki slip', $notGeneratedPayroll > 0 ? number_format($notGeneratedPayroll, 0, ',', '.') . ' karyawan aktif belum memiliki slip gaji.' : 'Proses generate payroll sudah mencakup seluruh karyawan aktif.'),
            ],
            'charts' => [
                monthlyReportMakeChart('karyawan-divisi', 'Total Payroll per Divisi', 'Beban gaji terbesar pada organisasi.', 'bar', 'currency', array_column($salaryByDivisionItems, 'label'), [['label' => 'Total Gaji', 'data' => array_column($salaryByDivisionItems, 'value'), 'backgroundColor' => 'rgba(59, 130, 246, 0.18)', 'borderColor' => '#3b82f6', 'borderWidth' => 1]], ['indexAxis' => 'y']),
                monthlyReportMakeChart('karyawan-status', 'Kesiapan Payroll', 'Sebaran slip dibayar, belum dibayar, dan belum tergenerate.', 'doughnut', 'number', array_column($payrollStatusItems, 'label'), [['label' => 'Status Payroll', 'data' => array_column($payrollStatusItems, 'value'), 'backgroundColor' => ['#0f766e', '#f59e0b', '#94a3b8', '#ef4444']]]),
            ],
            'visuals' => [$payrollDivisionVisual, $payrollStatusVisual],
        ],
        'stok' => [
            'summary' => 'Tab stok memusatkan nilai persediaan, kategori dengan modal tertahan terbesar, dan SKU kritis yang berisiko menghambat penjualan.',
            'kpis' => [
                monthlyReportMakeKpi('Jumlah SKU', number_format(count($stokData), 0, ',', '.'), 'Seluruh item produk yang tercatat.', 'primary'),
                monthlyReportMakeKpi('Nilai Stok', 'Rp ' . number_format($nilaiStok, 0, ',', '.'), 'Estimasi nilai persediaan saat laporan dibuat.', 'success'),
                monthlyReportMakeKpi('Stok Kritis', number_format($stokCritical, 0, ',', '.'), 'SKU dengan stok 5 unit atau kurang.', $stokCritical > 0 ? 'danger' : 'success'),
                monthlyReportMakeKpi('SKU Aman', number_format($stokSafe, 0, ',', '.'), 'Produk dengan stok lebih dari 10 unit.', 'primary'),
            ],
            'insights' => [
                monthlyReportMakeInsight(!empty($stokCategoryItems) ? 'primary' : 'secondary', 'Kategori bernilai terbesar: ' . ($stokCategoryItems[0]['label'] ?? 'Belum ada kategori'), 'Kategori ini menahan nilai stok sebesar ' . monthlyReportFormatVisualValue((float) ($stokCategoryItems[0]['value'] ?? 0), 'currency') . '.'),
                monthlyReportMakeInsight($stokCritical > 0 ? 'danger' : 'success', $stokCritical > 0 ? 'Ada produk yang perlu restock cepat' : 'Tidak ada SKU pada level kritis', $stokCritical > 0 ? number_format($stokCritical, 0, ',', '.') . ' SKU berada di batas kritis dan berpotensi mengganggu penjualan.' : 'Level stok kritis masih aman pada seluruh SKU yang tercatat.'),
                monthlyReportMakeInsight(count($stokCategoryItems) > 0 ? 'primary' : 'secondary', 'Komposisi nilai stok membantu prioritas pembelian', 'Gunakan distribusi kategori untuk menentukan item restock versus item slow moving.'),
            ],
            'charts' => [
                monthlyReportMakeChart('stok-kategori', 'Nilai Stok per Kategori', 'Kategori yang paling besar menahan modal persediaan.', 'bar', 'currency', array_column($stokCategoryItems, 'label'), [['label' => 'Nilai Stok', 'data' => array_column($stokCategoryItems, 'value'), 'backgroundColor' => 'rgba(15, 118, 110, 0.18)', 'borderColor' => '#0f766e', 'borderWidth' => 1]], ['indexAxis' => 'y']),
                monthlyReportMakeChart('stok-kondisi', 'Kesehatan Stok', 'Sebaran SKU aman, waspada, dan kritis.', 'doughnut', 'number', array_column($stokConditionItems, 'label'), [['label' => 'Kondisi Stok', 'data' => array_column($stokConditionItems, 'value'), 'backgroundColor' => ['#ef4444', '#f59e0b', '#0f766e']]]),
            ],
            'visuals' => [$stokCategoryVisual, $stokConditionVisual],
        ],
    ];
}
