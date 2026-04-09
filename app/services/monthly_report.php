<?php
require_once __DIR__ . '/monthly_report_analytics.php';

function monthlyReportAvailableTabs(): array
{
    return [
        'ringkasan' => ['fa-chart-pie', 'Ringkasan'],
        'transaksi' => ['fa-receipt', 'Transaksi'],
        'operasional' => ['fa-wallet', 'Operasional'],
        'produksi' => ['fa-industry', 'Produksi'],
        'absensi' => ['fa-calendar-check', 'Absensi'],
        'karyawan' => ['fa-id-badge', 'Karyawan & Gaji'],
        'stok' => ['fa-boxes', 'Stok Produk'],
    ];
}

function monthlyReportNormalizeMonth(?string $month): string
{
    $month = is_string($month) ? trim($month) : '';
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return date('Y-m');
    }

    return $month;
}

function monthlyReportMonthLabel(string $month): string
{
    $month = monthlyReportNormalizeMonth($month);
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    $date = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: new DateTime();
    $monthNumber = (int) $date->format('n');

    return ($months[$monthNumber] ?? $date->format('F')) . ' ' . $date->format('Y');
}

function monthlyReportFormatSignedCurrency(float $amount): string
{
    $prefix = $amount < 0 ? '-Rp ' : 'Rp ';
    return $prefix . number_format(abs($amount), 0, ',', '.');
}

function monthlyReportWorkingDays(string $startDate, string $endDate): int
{
    $workingDays = 0;
    $cursor = new DateTime($startDate);
    $end = new DateTime($endDate);

    while ($cursor <= $end) {
        if ($cursor->format('N') !== '7') {
            $workingDays++;
        }
        $cursor->modify('+1 day');
    }

    return $workingDays;
}

function monthlyReportBuildDateAxis(string $startDate, string $endDate): array
{
    $keys = [];
    $labels = [];
    $cursor = new DateTime($startDate);
    $end = new DateTime($endDate);

    while ($cursor <= $end) {
        $keys[] = $cursor->format('Y-m-d');
        $labels[] = $cursor->format('d M');
        $cursor->modify('+1 day');
    }

    return [
        'keys' => $keys,
        'labels' => $labels,
    ];
}

function monthlyReportPercentage(float $numerator, float $denominator, int $precision = 1): float
{
    if ($denominator <= 0) {
        return 0.0;
    }

    return round(($numerator / $denominator) * 100, $precision);
}

function monthlyReportFormatPercentage(float $value, int $precision = 1): string
{
    return number_format($value, $precision, ',', '.') . '%';
}

function monthlyReportFormatVisualValue(float $value, string $format): string
{
    if ($format === 'currency') {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }

    if ($format === 'percentage') {
        return monthlyReportFormatPercentage($value);
    }

    return number_format($value, 0, ',', '.');
}

function monthlyReportAggregateItems(array $rows, callable $labelResolver, callable $valueResolver): array
{
    $aggregated = [];
    foreach ($rows as $row) {
        $label = trim((string) $labelResolver($row));
        if ($label === '') {
            $label = 'Tanpa Label';
        }

        if (!isset($aggregated[$label])) {
            $aggregated[$label] = 0.0;
        }

        $aggregated[$label] += (float) $valueResolver($row);
    }

    $items = [];
    foreach ($aggregated as $label => $value) {
        $items[] = [
            'label' => $label,
            'value' => (float) $value,
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return $right['value'] <=> $left['value'];
    });

    return $items;
}

function monthlyReportFilterValuedItems(array $items): array
{
    return array_values(array_filter($items, static function (array $item): bool {
        return (float) ($item['value'] ?? 0) > 0;
    }));
}

function monthlyReportSliceItems(array $items, int $limit = 0): array
{
    return $limit > 0 ? array_slice(array_values($items), 0, $limit) : array_values($items);
}

function monthlyReportSeriesFromRows(array $rows, string $dateField, array $dateKeys, callable $valueResolver): array
{
    $lookup = [];
    foreach ($rows as $row) {
        $dateKey = (string) ($row[$dateField] ?? '');
        if ($dateKey === '') {
            continue;
        }

        $lookup[$dateKey] = (float) $valueResolver($row);
    }

    return array_map(static function (string $dateKey) use ($lookup): float {
        return (float) ($lookup[$dateKey] ?? 0);
    }, $dateKeys);
}

function monthlyReportMakeKpi(string $label, string $value, string $note, string $tone = 'primary'): array
{
    return [
        'label' => $label,
        'value' => $value,
        'note' => $note,
        'tone' => $tone,
    ];
}

function monthlyReportMakeInsight(string $tone, string $title, string $body): array
{
    return [
        'tone' => $tone,
        'title' => $title,
        'body' => $body,
    ];
}

function monthlyReportMakeChart(
    string $id,
    string $title,
    string $subtitle,
    string $type,
    string $format,
    array $labels,
    array $datasets,
    array $options = []
): array {
    $hasData = false;
    foreach ($datasets as $dataset) {
        foreach (($dataset['data'] ?? []) as $value) {
            if ((float) $value !== 0.0) {
                $hasData = true;
                break 2;
            }
        }
    }

    return [
        'id' => $id,
        'title' => $title,
        'subtitle' => $subtitle,
        'type' => $type,
        'format' => $format,
        'labels' => array_values($labels),
        'datasets' => array_values($datasets),
        'options' => $options,
        'hasData' => $hasData,
    ];
}

function monthlyReportMakeVisual(string $title, string $subtitle, string $format, array $items): array
{
    $items = monthlyReportFilterValuedItems($items);
    $sum = array_sum(array_map(static function (array $item): float {
        return (float) ($item['value'] ?? 0);
    }, $items));
    $max = 0.0;
    foreach ($items as $item) {
        $max = max($max, (float) ($item['value'] ?? 0));
    }

    $prepared = array_map(static function (array $item) use ($format, $sum, $max): array {
        $value = (float) ($item['value'] ?? 0);
        return [
            'label' => (string) ($item['label'] ?? 'Tanpa Label'),
            'value' => $value,
            'display' => monthlyReportFormatVisualValue($value, $format),
            'ratio' => $max > 0 ? round(($value / $max) * 100, 1) : 0.0,
            'share' => $sum > 0 ? round(($value / $sum) * 100, 1) : 0.0,
        ];
    }, $items);

    return [
        'title' => $title,
        'subtitle' => $subtitle,
        'format' => $format,
        'items' => $prepared,
        'hasData' => !empty($prepared),
    ];
}

function monthlyReportBuildContext(mysqli $conn, ?string $month = null, string $tab = 'ringkasan', bool $includeAllDetails = false): array
{
    $bulan = monthlyReportNormalizeMonth($month);
    $tabs = monthlyReportAvailableTabs();
    if (!isset($tabs[$tab])) {
        $tab = 'ringkasan';
    }

    $tgl1 = $bulan . '-01';
    $tgl2 = date('Y-m-t', strtotime($tgl1));
    $tglNext = date('Y-m-d', strtotime($tgl1 . ' +1 month'));
    $label = monthlyReportMonthLabel($bulan);
    $hariKerja = monthlyReportWorkingDays($tgl1, $tgl2);
    $dateAxis = monthlyReportBuildDateAxis($tgl1, $tgl2);

    $omzet = (float) schemaFetchScalar(
        $conn,
        "SELECT COALESCE(SUM(total),0) FROM transaksi WHERE status='selesai' AND created_at >= ? AND created_at < ?",
        'ss',
        $tgl1,
        $tglNext
    );
    $jmlTrx = (int) schemaFetchScalar(
        $conn,
        "SELECT COUNT(*) FROM transaksi WHERE created_at >= ? AND created_at < ?",
        'ss',
        $tgl1,
        $tglNext
    );
    $trxSelesai = (int) schemaFetchScalar(
        $conn,
        "SELECT COUNT(*) FROM transaksi WHERE status='selesai' AND created_at >= ? AND created_at < ?",
        'ss',
        $tgl1,
        $tglNext
    );
    $trxDP = (int) schemaFetchScalar(
        $conn,
        "SELECT COUNT(*) FROM transaksi WHERE status='dp' AND created_at >= ? AND created_at < ?",
        'ss',
        $tgl1,
        $tglNext
    );
    $trxTempo = (int) schemaFetchScalar(
        $conn,
        "SELECT COUNT(*) FROM transaksi WHERE status='tempo' AND created_at >= ? AND created_at < ?",
        'ss',
        $tgl1,
        $tglNext
    );
    $sisaTempo = (float) schemaFetchScalar(
        $conn,
        "SELECT COALESCE(SUM(sisa_bayar),0) FROM transaksi WHERE status IN ('dp','tempo') AND created_at >= ? AND created_at < ?",
        'ss',
        $tgl1,
        $tglNext
    );

    $totalOps = 0.0;
    $opsPrint = 0.0;
    $opsApp = 0.0;
    $opsUmum = 0.0;
    if (schemaTableExists($conn, 'operasional')) {
        $hasDivisi = schemaColumnExists($conn, 'operasional', 'divisi');
        $totalOps = (float) schemaFetchScalar(
            $conn,
            "SELECT COALESCE(SUM(jumlah),0) FROM operasional WHERE tanggal >= ? AND tanggal < ?",
            'ss',
            $tgl1,
            $tglNext
        );
        if ($hasDivisi) {
            $opsPrint = (float) schemaFetchScalar(
                $conn,
                "SELECT COALESCE(SUM(jumlah),0) FROM operasional WHERE divisi='printing' AND tanggal >= ? AND tanggal < ?",
                'ss',
                $tgl1,
                $tglNext
            );
            $opsApp = (float) schemaFetchScalar(
                $conn,
                "SELECT COALESCE(SUM(jumlah),0) FROM operasional WHERE divisi='apparel' AND tanggal >= ? AND tanggal < ?",
                'ss',
                $tgl1,
                $tglNext
            );
            $opsUmum = (float) schemaFetchScalar(
                $conn,
                "SELECT COALESCE(SUM(jumlah),0) FROM operasional WHERE divisi='umum' AND tanggal >= ? AND tanggal < ?",
                'ss',
                $tgl1,
                $tglNext
            );
        }
    }

    $labaBersih = $omzet - $totalOps;
    $basisBagiHasil = $labaBersih;
    $bagiHasilCeo = $basisBagiHasil * 0.6;
    $bagiHasilHeadOffice = $basisBagiHasil * 0.4;
    $bagiHasilNote = $basisBagiHasil >= 0
        ? 'Pembagian dihitung dari laba bersih bulan berjalan setelah omzet dikurangi seluruh pengeluaran.'
        : 'Hasil bersih periode ini masih negatif, sehingga porsi bagi hasil ikut tercatat negatif sebagai indikator evaluasi.';

    $joPrinting = 0;
    $joSelesai = 0;
    if (schemaTableExists($conn, 'produksi')) {
        $joPrinting = (int) schemaFetchScalar(
            $conn,
            "SELECT COUNT(*) FROM produksi WHERE tipe_dokumen='JO' AND tanggal >= ? AND tanggal < ?",
            'ss',
            $tgl1,
            $tglNext
        );
        $joSelesai = (int) schemaFetchScalar(
            $conn,
            "SELECT COUNT(*) FROM produksi WHERE tipe_dokumen='JO' AND status='selesai' AND tanggal >= ? AND tanggal < ?",
            'ss',
            $tgl1,
            $tglNext
        );
    }

    $jmlKaryawan = (int) (($conn->query("SELECT COUNT(*) FROM karyawan WHERE status='aktif'")->fetch_row()[0]) ?? 0);
    $totalHadir = 0;
    $totalAlpha = 0;
    if (schemaTableExists($conn, 'absensi')) {
        $totalHadir = (int) schemaFetchScalar(
            $conn,
            "SELECT COUNT(*) FROM absensi WHERE status IN ('hadir','terlambat') AND tanggal >= ? AND tanggal < ?",
            'ss',
            $tgl1,
            $tglNext
        );
        $totalAlpha = (int) schemaFetchScalar(
            $conn,
            "SELECT COUNT(*) FROM absensi WHERE status='alpha' AND tanggal >= ? AND tanggal < ?",
            'ss',
            $tgl1,
            $tglNext
        );
    }

    $produkLaris = schemaFetchAllAssoc(
        $conn,
        "SELECT dt.nama_produk, SUM(dt.qty) AS total_qty, SUM(dt.subtotal) AS total_omzet
         FROM detail_transaksi dt
         JOIN transaksi t ON dt.transaksi_id = t.id
         WHERE t.status='selesai' AND t.created_at >= ? AND t.created_at < ?
         GROUP BY dt.nama_produk
         ORDER BY total_qty DESC
         LIMIT 5",
        'ss',
        $tgl1,
        $tglNext
    );
    $metodeBayar = schemaFetchAllAssoc(
        $conn,
        "SELECT metode_bayar, COUNT(*) AS jml, SUM(total) AS total
         FROM transaksi
         WHERE created_at >= ? AND created_at < ?
         GROUP BY metode_bayar",
        'ss',
        $tgl1,
        $tglNext
    );
    $financeDailyRows = schemaFetchAllAssoc(
        $conn,
        "SELECT DATE(created_at) AS tanggal,
                COUNT(*) AS total_transaksi,
                SUM(CASE WHEN status='selesai' THEN total ELSE 0 END) AS omzet_selesai,
                SUM(CASE WHEN status IN ('dp','tempo') THEN sisa_bayar ELSE 0 END) AS piutang_aktif
         FROM transaksi
         WHERE created_at >= ? AND created_at < ?
         GROUP BY DATE(created_at)
         ORDER BY tanggal ASC",
        'ss',
        $tgl1,
        $tglNext
    );
    $opsDailyRows = (schemaTableExists($conn, 'operasional'))
        ? schemaFetchAllAssoc(
            $conn,
            "SELECT DATE(tanggal) AS tanggal, SUM(jumlah) AS total_biaya
             FROM operasional
             WHERE tanggal >= ? AND tanggal < ?
             GROUP BY DATE(tanggal)
             ORDER BY tanggal ASC",
            'ss',
            $tgl1,
            $tglNext
        )
        : [];

    $needAllDetails = $includeAllDetails;
    $shouldLoad = static function (string $key) use ($needAllDetails, $tab): bool {
        return $needAllDetails || $tab === $key;
    };

    $trxData = $shouldLoad('transaksi')
        ? schemaFetchAllAssoc(
            $conn,
            "SELECT t.no_transaksi, p.nama AS pelanggan, u.nama AS kasir,
                    t.total, t.diskon, t.bayar, t.kembalian, t.metode_bayar, t.status, t.created_at
             FROM transaksi t
             LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE t.created_at >= ? AND t.created_at < ?
             ORDER BY t.created_at DESC",
            'ss',
            $tgl1,
            $tglNext
        )
        : [];

    $opsData = ($shouldLoad('operasional') && schemaTableExists($conn, 'operasional'))
        ? schemaFetchAllAssoc(
            $conn,
            "SELECT o.*, u.nama AS nama_user
             FROM operasional o
             LEFT JOIN users u ON o.user_id = u.id
             WHERE o.tanggal >= ? AND o.tanggal < ?
             ORDER BY o.tanggal DESC",
            'ss',
            $tgl1,
            $tglNext
        )
        : [];

    $produksiData = ($shouldLoad('produksi') && schemaTableExists($conn, 'produksi'))
        ? schemaFetchAllAssoc(
            $conn,
            "SELECT pr.no_dokumen, pr.tipe_dokumen, pr.nama_pekerjaan, pr.tanggal, pr.deadline, pr.status,
                    t.no_transaksi, k.nama AS karyawan
             FROM produksi pr
             LEFT JOIN transaksi t ON pr.transaksi_id = t.id
             LEFT JOIN karyawan k ON pr.karyawan_id = k.id
             WHERE pr.tanggal >= ? AND pr.tanggal < ?
             ORDER BY pr.tanggal DESC",
            'ss',
            $tgl1,
            $tglNext
        )
        : [];

    $absensiData = ($shouldLoad('absensi') && schemaTableExists($conn, 'absensi'))
        ? schemaFetchAllAssoc(
            $conn,
            "SELECT k.nama, k.jabatan,
                    SUM(a.status IN ('hadir','terlambat')) AS hadir,
                    SUM(a.status='terlambat') AS terlambat,
                    SUM(a.status='izin') AS izin,
                    SUM(a.status='sakit') AS sakit,
                    SUM(a.status='alpha') AS alpha
             FROM absensi a
             JOIN karyawan k ON a.karyawan_id = k.id
             WHERE a.tanggal >= ? AND a.tanggal < ?
             GROUP BY k.id
             ORDER BY k.nama",
            'ss',
            $tgl1,
            $tglNext
        )
        : [];

    $karyawanData = $shouldLoad('karyawan')
        ? schemaFetchAllAssoc(
            $conn,
            "SELECT k.nama, k.jabatan, k.divisi, k.metode_gaji,
                    COALESCE(sg.total_gaji,0) AS gaji_bulan, sg.status_bayar
             FROM karyawan k
             LEFT JOIN slip_gaji sg ON sg.karyawan_id = k.id AND sg.periode_mulai >= ? AND sg.periode_mulai < ?
             WHERE k.status='aktif'
             ORDER BY k.nama",
            'ss',
            $tgl1,
            $tglNext
        )
        : [];

    $stokData = [];
    $nilaiStok = 0.0;
    if ($shouldLoad('stok')) {
        $stokData = schemaFetchAllAssoc(
            $conn,
            "SELECT p.kode, p.nama, k.nama AS kategori, p.stok, p.satuan,
                    p.harga_jual, (p.stok * p.harga_jual) AS nilai_stok
             FROM produk p
             LEFT JOIN kategori k ON p.kategori_id = k.id
             ORDER BY k.tipe, p.nama"
        );
        $nilaiStok = (float) array_sum(array_column($stokData, 'nilai_stok'));
    }
    $tabAnalytics = monthlyReportBuildAnalytics([
        'label' => $label,
        'tgl2' => $tgl2,
        'hariKerja' => $hariKerja,
        'omzet' => $omzet,
        'jmlTrx' => $jmlTrx,
        'trxSelesai' => $trxSelesai,
        'trxDP' => $trxDP,
        'trxTempo' => $trxTempo,
        'sisaTempo' => $sisaTempo,
        'totalOps' => $totalOps,
        'opsPrint' => $opsPrint,
        'opsApp' => $opsApp,
        'opsUmum' => $opsUmum,
        'labaBersih' => $labaBersih,
        'joPrinting' => $joPrinting,
        'joSelesai' => $joSelesai,
        'jmlKaryawan' => $jmlKaryawan,
        'totalHadir' => $totalHadir,
        'totalAlpha' => $totalAlpha,
        'nilaiStok' => $nilaiStok,
        'produkLaris' => $produkLaris,
        'metodeBayar' => $metodeBayar,
        'trxData' => $trxData,
        'opsData' => $opsData,
        'produksiData' => $produksiData,
        'absensiData' => $absensiData,
        'karyawanData' => $karyawanData,
        'stokData' => $stokData,
        'dateAxis' => $dateAxis,
        'financeDailyRows' => $financeDailyRows,
        'opsDailyRows' => $opsDailyRows,
    ]);

    $selectedCount = 0;
    switch ($tab) {
        case 'ringkasan':
            $selectedCount = count($produkLaris);
            break;
        case 'transaksi':
            $selectedCount = count($trxData);
            break;
        case 'operasional':
            $selectedCount = count($opsData);
            break;
        case 'produksi':
            $selectedCount = count($produksiData);
            break;
        case 'absensi':
            $selectedCount = count($absensiData);
            break;
        case 'karyawan':
            $selectedCount = count($karyawanData);
            break;
        case 'stok':
            $selectedCount = count($stokData);
            break;
    }

    return [
        'bulan' => $bulan,
        'tab' => $tab,
        'tabs' => $tabs,
        'tgl1' => $tgl1,
        'tgl2' => $tgl2,
        'tglNext' => $tglNext,
        'label' => $label,
        'selectedCount' => $selectedCount,
        'tabLabel' => $tabs[$tab][1] ?? 'Ringkasan',
        'hariKerja' => $hariKerja,
        'omzet' => $omzet,
        'jmlTrx' => $jmlTrx,
        'trxSelesai' => $trxSelesai,
        'trxDP' => $trxDP,
        'trxTempo' => $trxTempo,
        'sisaTempo' => $sisaTempo,
        'totalOps' => $totalOps,
        'opsPrint' => $opsPrint,
        'opsApp' => $opsApp,
        'opsUmum' => $opsUmum,
        'labaBersih' => $labaBersih,
        'basisBagiHasil' => $basisBagiHasil,
        'bagiHasilCeo' => $bagiHasilCeo,
        'bagiHasilHeadOffice' => $bagiHasilHeadOffice,
        'bagiHasilNote' => $bagiHasilNote,
        'joPrinting' => $joPrinting,
        'joSelesai' => $joSelesai,
        'jmlKaryawan' => $jmlKaryawan,
        'totalHadir' => $totalHadir,
        'totalAlpha' => $totalAlpha,
        'produkLaris' => $produkLaris,
        'metodeBayar' => $metodeBayar,
        'trxData' => $trxData,
        'opsData' => $opsData,
        'produksiData' => $produksiData,
        'absensiData' => $absensiData,
        'karyawanData' => $karyawanData,
        'stokData' => $stokData,
        'nilaiStok' => $nilaiStok,
        'tabAnalytics' => $tabAnalytics,
    ];
}
