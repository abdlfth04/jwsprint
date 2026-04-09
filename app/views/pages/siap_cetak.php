<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service', 'user');
$pageTitle = 'File Siap Cetak';
transactionWorkflowSupportReady($conn);

$allowedStatus = ['antrian', 'proses', 'selesai', 'batal'];
$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, $allowedStatus, true)) {
    $filterStatus = '';
}

$where = "WHERE pr.tipe_dokumen IN ('JO', 'SPK')";
if ($filterStatus !== '') {
    $where .= " AND pr.status = '" . $filterStatus . "'";
}

$jobs = [];
$readyVersionsByJob = [];
$supportFilesByJob = [];

$tableExists = schemaTableExists($conn, 'file_transaksi');
$tblProd = schemaTableExists($conn, 'produksi');
$hasWorkflowStep = schemaColumnExists($conn, 'transaksi', 'workflow_step');
$hasProduksiCreatedAt = $tblProd && schemaColumnExists($conn, 'produksi', 'created_at');
$hasProduksiTransaksiId = $tblProd && schemaColumnExists($conn, 'produksi', 'transaksi_id');
$hasProduksiDetailId = $tblProd && schemaColumnExists($conn, 'produksi', 'detail_transaksi_id');
$hasTransaksiPelangganId = schemaColumnExists($conn, 'transaksi', 'pelanggan_id');

if ($tblProd) {
    $workflowSelect = $hasWorkflowStep ? 't.workflow_step,' : "'production' AS workflow_step,";
    $detailSelect = $hasProduksiDetailId ? 'pr.detail_transaksi_id,' : '0 AS detail_transaksi_id,';
    $transaksiJoin = $hasProduksiTransaksiId ? 'LEFT JOIN transaksi t ON pr.transaksi_id = t.id' : '';
    $transaksiSelect = $hasProduksiTransaksiId ? 't.id AS transaksi_id, t.no_transaksi,' : '0 AS transaksi_id, NULL AS no_transaksi,';
    $pelangganJoin = ($hasProduksiTransaksiId && $hasTransaksiPelangganId) ? 'LEFT JOIN pelanggan p ON t.pelanggan_id = p.id' : '';
    $pelangganSelect = ($hasProduksiTransaksiId && $hasTransaksiPelangganId) ? 'p.nama AS nama_pelanggan,' : 'NULL AS nama_pelanggan,';
    $detailJoin = $hasProduksiDetailId ? 'LEFT JOIN detail_transaksi dt ON pr.detail_transaksi_id = dt.id' : '';
    $orderBy = $hasProduksiCreatedAt ? 'pr.created_at DESC' : 'pr.id DESC';

    $result = $conn->query("SELECT
        pr.id,
        pr.no_dokumen,
        pr.tipe_dokumen,
        {$detailSelect}
        pr.nama_pekerjaan,
        pr.status,
        pr.tanggal,
        pr.deadline,
        {$transaksiSelect}
        {$workflowSelect}
        {$pelangganSelect}
        dt.nama_produk
        FROM produksi pr
        {$transaksiJoin}
        {$pelangganJoin}
        {$detailJoin}
        $where
        ORDER BY {$orderBy}");
    if ($result) {
        $jobs = $result->fetch_all(MYSQLI_ASSOC);
    }
}

if (hasRole('user') && !empty($jobs)) {
    $jobs = array_values(array_filter($jobs, static function (array $job): bool {
        return canAccessProductionRecord((int) ($job['id'] ?? 0));
    }));
}

if ($tableExists && !empty($jobs)) {
    $trxIds = array_values(array_unique(array_filter(array_map('intval', array_column($jobs, 'transaksi_id')))));

    if (!empty($trxIds)) {
        if (readyPrintVersioningReady()) {
            $readyVersionsByJob = fetchReadyPrintVersionsForJobs($conn, $jobs);
        }

        $supportRows = fetchScopedTransactionFiles(
            $conn,
            $trxIds,
            ['cetak', 'mockup', 'list_nama'],
            'f.*, u.nama AS nama_uploader, dt.nama_produk AS nama_produk_detail'
        );
        $groupedSupportFiles = groupScopedTransactionFiles($supportRows);

        foreach ($jobs as $job) {
            $jobId = (int) ($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $transaksiId = (int) ($job['transaksi_id'] ?? 0);
            $detailId = (int) ($job['detail_transaksi_id'] ?? 0);
            $supportFilesByJob[$jobId] = [
                'cetak' => resolveScopedTransactionFiles($conn, $groupedSupportFiles, $transaksiId, $detailId, ['cetak']),
                'mockup' => resolveScopedTransactionFiles($conn, $groupedSupportFiles, $transaksiId, $detailId, ['mockup']),
                'list_nama' => resolveScopedTransactionFiles($conn, $groupedSupportFiles, $transaksiId, $detailId, ['list_nama']),
            ];
        }
    }
}

$currentVersionCount = 0;
$jobsWithHistory = 0;
$readyPrintEligibleJobs = 0;
foreach ($jobs as $job) {
    if (strtoupper(trim((string) ($job['tipe_dokumen'] ?? ''))) !== 'JO') {
        continue;
    }

    $readyPrintEligibleJobs++;
    $jobId = (int) ($job['id'] ?? 0);
    $versions = $readyVersionsByJob[$jobId] ?? [];
    if (!empty($versions)) {
        $currentVersionCount++;
        if (count($versions) > 1) {
            $jobsWithHistory++;
        }
    }
}
$jobsWithoutReadyPrint = max(0, $readyPrintEligibleJobs - $currentVersionCount);
$supportFileCount = 0;
$jobsWithSupportFiles = 0;
foreach ($supportFilesByJob as $fileGroups) {
    $jobFileCount = 0;
    foreach ($fileGroups as $rows) {
        $jobFileCount += count($rows);
    }
    $supportFileCount += $jobFileCount;
    if ($jobFileCount > 0) {
        $jobsWithSupportFiles++;
    }
}

$extraCss = '<link rel="stylesheet" href="' . assetUrl('css/siap_cetak.css') . '">';
$extraCss .= '<style>
@media (max-width: 768px) {
    .siap-cetak-page .filter-pills { display: flex; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 8px; -webkit-overflow-scrolling: touch; gap: 8px; }
    .siap-cetak-page .filter-pill { white-space: nowrap; flex-shrink: 0; }
    .siap-cetak-page .sc-head-top { flex-direction: column; align-items: flex-start; gap: 8px; }
    .siap-cetak-page .sc-job-meta { flex-wrap: wrap; gap: 6px 12px; }
    .siap-cetak-page .reference-view-bar { flex-direction: column; align-items: flex-start; }
    .siap-cetak-page .reference-view-toggle { width: 100%; }
    .siap-cetak-page .reference-view-btn { flex: 1; justify-content: center; }
}
</style>';
$pageJs = 'siap_cetak.js';

require_once dirname(__DIR__) . '/layouts/header.php';

function fileIcon(string $mime, string $ext): string
{
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'fa-file-image';
    if ($ext === 'pdf') return 'fa-file-pdf';
    if (in_array($ext, ['tif', 'tiff'], true)) return 'fa-file-image';
    if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) return 'fa-file-excel';
    if (in_array($ext, ['ai', 'eps', 'cdr'], true)) return 'fa-bezier-curve';
    return 'fa-file';
}

function isPreviewable(string $ext): bool
{
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function formatStoredFileSize(?int $size): string
{
    $size = max(0, (int) $size);
    if ($size >= 1024 * 1024) {
        return number_format($size / 1024 / 1024, 1) . ' MB';
    }

    if ($size >= 1024) {
        return number_format($size / 1024, 0) . ' KB';
    }

    return number_format($size) . ' B';
}

function renderSupportFileCards(array $files, array $job, bool $canDownloadJobFiles, bool $canManageJobFiles, string $transparentPixel): void
{
    $productFallback = (string) (($job['nama_produk'] ?? '') !== '' ? $job['nama_produk'] : ($job['nama_pekerjaan'] ?? ''));

    foreach ($files as $file) {
        $fileId = (int) ($file['id'] ?? 0);
        $fileName = (string) ($file['nama_asli'] ?? 'File lampiran');
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $previewSrc = pageUrl('file_download.php?id=' . $fileId . '&inline=1');
        ?>
        <div class="reference-file-card">
            <?php if (isPreviewable($ext)): ?>
                <div class="thumb-wrap">
                    <div class="file-thumb preview-thumb is-loading" onclick="showLightbox('<?= htmlspecialchars($previewSrc, ENT_QUOTES) ?>', '<?= htmlspecialchars($fileName, ENT_QUOTES) ?>')" title="Preview: <?= htmlspecialchars($fileName) ?>">
                        <span class="preview-thumb-placeholder" aria-hidden="true">
                            <i class="fas fa-image thumb-icon"></i>
                            <span class="thumb-label"><?= htmlspecialchars($ext !== '' ? $ext : 'file') ?></span>
                        </span>
                        <img
                            src="<?= $transparentPixel ?>"
                            data-preview-src="<?= htmlspecialchars($previewSrc, ENT_QUOTES) ?>"
                            alt="<?= htmlspecialchars($fileName) ?>"
                            loading="lazy"
                            decoding="async"
                            width="64"
                            height="64"
                        >
                    </div>
                    <?php if ($canDownloadJobFiles): ?>
                        <a href="<?= pageUrl('file_download.php?id=' . $fileId) ?>" target="_blank" rel="noopener" download class="thumb-download" onclick="event.stopPropagation();" title="Download <?= htmlspecialchars($fileName) ?>">
                            <i class="fas fa-download"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="thumb-wrap">
                    <?php if ($canDownloadJobFiles): ?>
                        <a href="<?= pageUrl('file_download.php?id=' . $fileId) ?>" target="_blank" rel="noopener" download class="file-thumb" onclick="event.stopPropagation();" title="Download <?= htmlspecialchars($fileName) ?>">
                            <i class="fas <?= fileIcon((string) ($file['mime_type'] ?? ''), $ext) ?> thumb-icon"></i>
                            <span class="thumb-label"><?= htmlspecialchars($ext !== '' ? $ext : 'file') ?></span>
                        </a>
                        <a href="<?= pageUrl('file_download.php?id=' . $fileId) ?>" target="_blank" rel="noopener" download class="thumb-download" onclick="event.stopPropagation();" title="Download <?= htmlspecialchars($fileName) ?>">
                            <i class="fas fa-download"></i>
                        </a>
                    <?php else: ?>
                        <div class="file-thumb" title="<?= htmlspecialchars($fileName) ?>">
                            <i class="fas <?= fileIcon((string) ($file['mime_type'] ?? ''), $ext) ?> thumb-icon"></i>
                            <span class="thumb-label"><?= htmlspecialchars($ext !== '' ? $ext : 'file') ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="reference-file-copy">
                <div class="reference-file-name" title="<?= htmlspecialchars($fileName) ?>"><?= htmlspecialchars($fileName) ?></div>
                <div class="reference-file-meta">
                    <span><i class="fas fa-box"></i> <?= htmlspecialchars((string) (($file['nama_produk_detail'] ?? '') !== '' ? $file['nama_produk_detail'] : $productFallback)) ?></span>
                    <span><i class="fas fa-user"></i> <?= htmlspecialchars((string) (($file['nama_uploader'] ?? '') !== '' ? $file['nama_uploader'] : 'Uploader tidak diketahui')) ?></span>
                    <span><i class="fas fa-clock"></i> <?= !empty($file['created_at']) ? date('d/m/Y H:i', strtotime((string) $file['created_at'])) : '-' ?></span>
                    <span><i class="fas fa-weight-hanging"></i> <?= htmlspecialchars(formatStoredFileSize(isset($file['ukuran']) ? (int) $file['ukuran'] : 0)) ?></span>
                </div>
                <div class="reference-file-actions">
                    <?php if ($canDownloadJobFiles): ?>
                        <a href="<?= pageUrl('file_download.php?id=' . $fileId) ?>" target="_blank" rel="noopener" download class="btn btn-secondary btn-sm" onclick="event.stopPropagation();">
                            <i class="fas fa-download"></i> Download
                        </a>
                    <?php else: ?>
                        <span class="badge badge-secondary">Unduh dibatasi untuk operator yang ditugaskan</span>
                    <?php endif; ?>
                    <?php if ($canManageJobFiles): ?>
                        <button type="button" class="btn btn-danger btn-sm" onclick="hapusFileTransaksi(<?= $fileId ?>, this, 'file ini')">
                            <i class="fas fa-trash"></i> Hapus
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}

function renderSupportSection(array $section, array $job, int $jobId, int $transaksiId, int $detailTransaksiId, bool $canDownloadJobFiles, bool $canManageJobFiles, string $transparentPixel): void
{
    $type = (string) ($section['type'] ?? 'lampiran');
    $label = (string) ($section['label'] ?? 'Lampiran Produksi');
    $icon = (string) ($section['icon'] ?? 'fa-file');
    $files = array_values(is_array($section['files'] ?? null) ? $section['files'] : []);
    $accept = (string) ($section['accept'] ?? '');
    $emptyMessage = (string) ($section['empty'] ?? 'Belum ada file pada bagian ini.');
    $uploadTitle = (string) ($section['upload_title'] ?? ('Upload ' . $label));
    $uploadNote = (string) ($section['upload_note'] ?? 'Simpan file langsung dari halaman ini.');
    $sectionNote = (string) ($section['note'] ?? 'File pada blok ini tersimpan per job transaksi dan bisa diunduh kembali dari sini.');
    $formatLabel = (string) ($section['format'] ?? '');
    $summaryText = !empty($files) ? number_format(count($files)) . ' file tersedia' : 'Belum ada file';
    $safeType = preg_replace('/[^a-z0-9_-]+/i', '-', $type) ?: 'lampiran';
    $inputId = 'supportFileInput-' . $jobId . '-' . $safeType;
    $statusTargetId = 'uploadStatus-' . $jobId . '-' . $safeType;
    ?>
    <details class="sc-collapsible sc-support-toggle" data-reference-collapsible="1"<?= empty($files) ? ' open' : '' ?>>
        <summary>
            <span class="sc-collapsible-label"><i class="fas <?= htmlspecialchars($icon) ?>"></i> <?= htmlspecialchars($label) ?></span>
            <span class="sc-ready-summary">
                <span class="sc-collapsible-note"><?= htmlspecialchars($summaryText) ?></span>
                <?php if ($formatLabel !== ''): ?>
                    <span class="sc-chip"><?= htmlspecialchars($formatLabel) ?></span>
                <?php endif; ?>
            </span>
        </summary>
        <div class="sc-collapsible-body">
            <div class="sc-reference-note"><?= htmlspecialchars($sectionNote) ?></div>

            <?php if (!empty($files)): ?>
                <div class="file-gallery">
                    <?php renderSupportFileCards($files, $job, $canDownloadJobFiles, $canManageJobFiles, $transparentPixel); ?>
                </div>
            <?php else: ?>
                <div class="ready-version-empty sc-inline-empty">
                    <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                    <div><?= htmlspecialchars($emptyMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($canManageJobFiles): ?>
                <div class="sc-inline-upload">
                    <div class="upload-zone"
                        onclick="document.getElementById('<?= htmlspecialchars($inputId, ENT_QUOTES) ?>').click()"
                        ondragover="event.preventDefault();this.classList.add('drag-over');"
                        ondragleave="this.classList.remove('drag-over');"
                        ondrop="handleSupportDrop(event, <?= $jobId ?>, <?= $transaksiId ?>, <?= $detailTransaksiId ?>, '<?= htmlspecialchars($type, ENT_QUOTES) ?>', '<?= htmlspecialchars($statusTargetId, ENT_QUOTES) ?>', '<?= htmlspecialchars($label, ENT_QUOTES) ?>');this.classList.remove('drag-over');">
                        <input type="file" id="<?= htmlspecialchars($inputId, ENT_QUOTES) ?>" accept="<?= htmlspecialchars($accept, ENT_QUOTES) ?>" multiple onchange="uploadSupportFiles(this, <?= $jobId ?>, <?= $transaksiId ?>, <?= $detailTransaksiId ?>, '<?= htmlspecialchars($type, ENT_QUOTES) ?>', '<?= htmlspecialchars($statusTargetId, ENT_QUOTES) ?>', '<?= htmlspecialchars($label, ENT_QUOTES) ?>')">
                        <div class="upload-zone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <p><?= htmlspecialchars($uploadTitle) ?></p>
                        <span><?= htmlspecialchars($uploadNote) ?></span>
                    </div>
                    <div id="<?= htmlspecialchars($statusTargetId, ENT_QUOTES) ?>" class="upload-status"></div>
                </div>
            <?php endif; ?>
        </div>
    </details>
    <?php
}

$transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
?>

<?php if ($msg): $msgParts = explode('|', $msg, 2); $type = $msgParts[0]; $text = isset($msgParts[1]) ? $msgParts[1] : ''; ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?>" data-dismiss="1"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-stack siap-cetak-page">
    <section class="page-hero">
        <div class="page-hero-content">
            <div>
                <div class="page-eyebrow"><i class="fas fa-print"></i> File Siap Cetak</div>
                <h1 class="page-title">Pusat lampiran produksi untuk JO dan SPK</h1>
                <p class="page-description">
                    Semua lampiran produksi sekarang dipusatkan di sini, mulai dari referensi customer, mockup, list ukuran atau nama punggung, sampai file final siap cetak. Tim bisa upload dan menelusuri file tanpa bolak-balik ke detail transaksi.
                </p>
                <div class="page-meta">
                    <span class="page-meta-item"><i class="fas fa-list-check"></i> <?= number_format(count($jobs)) ?> job tampil</span>
                    <span class="page-meta-item"><i class="fas fa-layer-group"></i> <?= number_format($currentVersionCount) ?> versi aktif</span>
                    <span class="page-meta-item"><i class="fas fa-filter"></i> <?= $filterStatus !== '' ? ucfirst($filterStatus) : 'Semua status' ?></span>
                </div>
            </div>
            <div class="page-actions">
                <a href="<?= pageUrl('dashboard.php') ?>" class="btn btn-secondary"><i class="fas fa-home"></i> Dashboard</a>
                <a href="<?= pageUrl('notifikasi.php') ?>" class="btn btn-outline"><i class="fas fa-bell"></i> Notification Center</a>
            </div>
        </div>
    </section>

    <div class="metric-strip compact-metric-strip">
        <div class="metric-card">
            <span class="metric-label">Lampiran Referensi</span>
            <span class="metric-value"><?= number_format($supportFileCount) ?></span>
            <span class="metric-note">Total file referensi customer, mockup, dan list ukuran atau nama punggung yang aktif.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Versi Final Aktif</span>
            <span class="metric-value"><?= number_format($currentVersionCount) ?></span>
            <span class="metric-note">Job JO yang sudah punya file siap cetak aktif saat ini.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Job Dengan Histori</span>
            <span class="metric-value"><?= number_format($jobsWithHistory) ?></span>
            <span class="metric-note">Job JO yang sudah punya lebih dari satu jejak versi file final.</span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Belum Ada File Final</span>
            <span class="metric-value"><?= number_format($jobsWithoutReadyPrint) ?></span>
            <span class="metric-note">Total JO yang masih menunggu file final siap cetak aktif.</span>
        </div>
    </div>

    <section class="toolbar-surface ready-print-search-panel">
        <div class="ready-print-search-panel-copy">
            <div class="ready-print-search-title"><i class="fas fa-search"></i> Cari job siap cetak</div>
            <p class="ready-print-search-description">
                Telusuri nomor JO/SPK, invoice, pelanggan, produk, status kerja, atau nama file lampiran dari satu kolom pencarian.
            </p>
        </div>
        <div class="ready-print-search-row">
            <div class="ready-print-search-field">
                <span class="ready-print-search-icon" aria-hidden="true"><i class="fas fa-search"></i></span>
                <input
                    type="text"
                    id="siapCetakSearch"
                    class="form-control"
                    placeholder="Cari nomor JO/SPK, invoice, pelanggan, produk, atau file..."
                    oninput="filterSiapCetakView()"
                    autocomplete="off"
                >
                <button type="button" class="btn btn-secondary btn-sm ready-print-search-clear" id="siapCetakSearchClear" hidden>
                    <i class="fas fa-times"></i> Bersihkan
                </button>
            </div>
            <div class="ready-print-search-hint"><kbd>/</kbd> fokus cepat</div>
        </div>
        <div class="ready-print-search-meta" id="siapCetakSearchInfo">
            <?= number_format(count($jobs)) ?> job siap ditelusuri.
        </div>
    </section>

    <details class="toolbar-surface mobile-collapse-panel compact-toolbar-panel"<?= $filterStatus !== '' ? ' open' : '' ?>>
        <summary>
            <span class="mobile-collapse-label">
                <strong>Filter Job Produksi</strong>
                <span><?= number_format(count($jobs)) ?> job - <?= htmlspecialchars($filterStatus !== '' ? ucfirst($filterStatus) : 'Semua status') ?></span>
            </span>
        </summary>
        <div class="mobile-collapse-body">
            <div class="section-heading" style="margin-bottom: 14px;">
                <div>
                    <h2>Filter Job Produksi</h2>
                    <p>Saring daftar berdasarkan status produksi sambil tetap melihat semua lampiran kerja yang kini dipusatkan di satu halaman.</p>
                </div>
            </div>
            <div class="filter-pills">
                <a href="?status=" class="filter-pill <?= $filterStatus === '' ? 'active' : '' ?>"><span>Semua</span></a>
                <a href="?status=antrian" class="filter-pill <?= $filterStatus === 'antrian' ? 'active' : '' ?>"><span>Antrian</span></a>
                <a href="?status=proses" class="filter-pill <?= $filterStatus === 'proses' ? 'active' : '' ?>"><span>Proses</span></a>
                <a href="?status=selesai" class="filter-pill <?= $filterStatus === 'selesai' ? 'active' : '' ?>"><span>Selesai</span></a>
                <a href="?status=batal" class="filter-pill <?= $filterStatus === 'batal' ? 'active' : '' ?>"><span>Batal</span></a>
            </div>
            <div class="ready-print-filter-note">
                Pencarian cepat selalu tersedia di atas daftar job, jadi filter di sini bisa fokus untuk status produksi dan tampilan referensi.
            </div>
            <div class="reference-view-bar">
                <div class="reference-view-copy">
                    <div class="reference-view-title"><i class="fas fa-images"></i> Tampilan Referensi</div>
                    <div class="reference-view-note">Mode ringkas tidak memuat preview gambar sampai Anda mengaktifkan thumbnail.</div>
                </div>
                <div class="reference-view-toggle" data-reference-preview-toggle>
                    <button type="button" class="reference-view-btn" data-preview-mode="compact">Ringkas</button>
                    <button type="button" class="reference-view-btn" data-preview-mode="thumbnail">Thumbnail</button>
                </div>
            </div>
        </div>
    </details>

    <?php if (empty($jobs)): ?>
        <div class="card">
            <div class="empty-state">
                <i class="fas fa-print"></i>
                <div>Belum ada job produksi yang cocok dengan filter saat ini.</div>
            </div>
        </div>
    <?php else: ?>
        <div class="sc-grid">
            <?php foreach ($jobs as $job): ?>
                <?php
                $jobId = (int) ($job['id'] ?? 0);
                $transaksiId = (int) ($job['transaksi_id'] ?? 0);
                $detailTransaksiId = (int) ($job['detail_transaksi_id'] ?? 0);
                $jobType = strtoupper(trim((string) ($job['tipe_dokumen'] ?? 'JO')));
                $isReadyPrintJob = $jobType === 'JO';
                $supportFiles = $supportFilesByJob[$jobId] ?? ['cetak' => [], 'mockup' => [], 'list_nama' => []];
                $jobSupportSections = $jobType === 'SPK'
                    ? [
                        [
                            'type' => 'mockup',
                            'label' => 'Mockup',
                            'icon' => 'fa-shirt',
                            'files' => $supportFiles['mockup'] ?? [],
                            'accept' => '.jpg,.jpeg,.png,.pdf',
                            'format' => 'JPG / PDF',
                            'empty' => 'Belum ada file mockup untuk SPK ini.',
                            'upload_title' => 'Upload mockup',
                            'upload_note' => 'Simpan mockup apparel langsung dari halaman ini.',
                            'note' => 'Gunakan bagian ini untuk mockup apparel yang akan jadi acuan jahit, bordir, atau finishing tim produksi.',
                        ],
                        [
                            'type' => 'list_nama',
                            'label' => 'List Ukuran / Nama Punggung',
                            'icon' => 'fa-list-ol',
                            'files' => $supportFiles['list_nama'] ?? [],
                            'accept' => '.xlsx,.xls,.csv',
                            'format' => 'XLSX / CSV',
                            'empty' => 'Belum ada file list ukuran atau nama punggung untuk SPK ini.',
                            'upload_title' => 'Upload list ukuran / nama punggung',
                            'upload_note' => 'Simpan spreadsheet ukuran, nomor, atau nama punggung dari halaman ini.',
                            'note' => 'Bagian ini dipakai untuk spreadsheet personalisasi seperti ukuran jersey, nomor, dan nama punggung customer.',
                        ],
                    ]
                    : [
                        [
                            'type' => 'cetak',
                            'label' => 'Referensi Customer',
                            'icon' => 'fa-images',
                            'files' => $supportFiles['cetak'] ?? [],
                            'accept' => '.jpg,.jpeg,.png,.pdf,.ai,.cdr,.eps',
                            'format' => 'JPG / PDF / AI',
                            'empty' => 'Belum ada file referensi customer untuk JO ini.',
                            'upload_title' => 'Upload referensi customer',
                            'upload_note' => 'Simpan brief, desain referensi, atau file dari customer langsung dari halaman ini.',
                            'note' => 'Referensi customer bisa berupa brief desain, contoh visual, PDF lama, atau artwork mentah yang masih dipakai sebagai acuan JO ini.',
                        ],
                    ];
                $versions = $readyVersionsByJob[$jobId] ?? [];
                $activeVersion = $versions[0] ?? null;
                $historyVersions = $activeVersion ? array_slice($versions, 1) : [];
                $statusBadgeMap = ['antrian' => 'badge-secondary', 'proses' => 'badge-warning', 'selesai' => 'badge-success', 'batal' => 'badge-danger'];
                $workflowStep = transactionWorkflowResolveStep($job);
                $canDownloadJobFiles = canAccessFileDownload($transaksiId, $detailTransaksiId);
                $canManageJobFiles = canManageTransactionFiles($transaksiId, $detailTransaksiId);
                $activeVersionSummary = $activeVersion
                    ? 'V' . (int) ($activeVersion['version_no'] ?? 1)
                        . ''
                        . ' • ' . number_format(count($activeVersion['files'] ?? [])) . ' file'
                    : 'Belum ada versi aktif';
                $activeVersionSummary = $activeVersion
                    ? 'V' . (int) ($activeVersion['version_no'] ?? 1)
                        . ' | ' . number_format(count($activeVersion['files'] ?? [])) . ' file'
                    : 'Belum ada versi aktif';
                $jobSearchParts = [
                    (string) ($job['no_dokumen'] ?? ''),
                    (string) ($job['tipe_dokumen'] ?? ''),
                    (string) ($job['nama_pelanggan'] ?? 'Umum'),
                    (string) ($job['no_transaksi'] ?? ''),
                    (string) ($job['nama_produk'] ?: $job['nama_pekerjaan']),
                    (string) ($job['status'] ?? ''),
                    (string) transactionWorkflowLabel($workflowStep),
                ];
                if (!empty($job['tanggal'])) {
                    $jobSearchParts[] = date('d/m/Y', strtotime((string) $job['tanggal']));
                }
                if (!empty($job['deadline'])) {
                    $jobSearchParts[] = date('d/m/Y', strtotime((string) $job['deadline']));
                }
                foreach ($jobSupportSections as $supportSection) {
                    foreach ((array) ($supportSection['files'] ?? []) as $supportFile) {
                        $jobSearchParts[] = (string) ($supportFile['nama_asli'] ?? '');
                    }
                }
                foreach ($versions as $versionRow) {
                    foreach ((array) ($versionRow['files'] ?? []) as $versionFile) {
                        $jobSearchParts[] = (string) ($versionFile['nama_asli'] ?? '');
                    }
                }
                $jobSearchText = strtolower(trim(implode(' ', array_filter($jobSearchParts, static function ($value): bool {
                    return trim((string) $value) !== '';
                }))));
                ?>
                <div class="sc-card" data-ready-search="<?= htmlspecialchars($jobSearchText, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="sc-card-header">
                        <div class="sc-head-top">
                            <div>
                                <div class="sc-job-code"><?= htmlspecialchars($job['no_dokumen']) ?></div>
                                <div class="sc-job-meta">
                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($job['nama_pelanggan'] ?? 'Umum') ?></span>
                                    <span><i class="fas fa-receipt"></i> <?= htmlspecialchars($job['no_transaksi'] ?? '-') ?></span>
                                </div>
                            </div>
                            <div class="sc-head-status">
                                <span class="badge <?= $statusBadgeMap[$job['status']] ?? 'badge-secondary' ?>"><?= strtoupper(htmlspecialchars($job['status'])) ?></span>
                                <span class="badge badge-<?= htmlspecialchars(transactionWorkflowBadgeClass($workflowStep)) ?>"><?= htmlspecialchars(transactionWorkflowLabel($workflowStep)) ?></span>
                                <?php if (!empty($job['deadline'])): ?>
                                    <div class="sc-deadline"><i class="fas fa-clock"></i> <?= date('d M Y', strtotime($job['deadline'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="sc-job-title"><?= htmlspecialchars($job['nama_produk'] ?: $job['nama_pekerjaan']) ?></div>
                    </div>

                    <div class="sc-card-body">
                        <?php foreach ($jobSupportSections as $supportSection): ?>
                            <?php renderSupportSection($supportSection, $job, $jobId, $transaksiId, $detailTransaksiId, $canDownloadJobFiles, $canManageJobFiles, $transparentPixel); ?>
                        <?php endforeach; ?>

                        <?php if ($isReadyPrintJob): ?>
                            <details class="sc-collapsible sc-ready-toggle">
                                <summary>
                                    <span class="sc-collapsible-label"><i class="fas fa-print"></i> File Siap Cetak</span>
                                    <span class="sc-ready-summary">
                                        <span class="sc-collapsible-note"><?= htmlspecialchars($activeVersionSummary) ?></span>
                                        <span class="sc-chip">TIF / PDF</span>
                                    </span>
                                </summary>
                                <div class="sc-collapsible-body">
                                    <?php if ($activeVersion): ?>
                                        <div class="ready-version-card current">
                                            <div class="ready-version-top">
                                                <div>
                                                    <div class="ready-version-title">
                                                        <span>Versi V<?= (int) $activeVersion['version_no'] ?></span>
                                                    </div>
                                                    <div class="ready-version-meta">
                                                        <span><i class="fas fa-upload"></i> <?= htmlspecialchars($activeVersion['nama_uploader'] ?? 'Sistem') ?></span>
                                                        <span><i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($activeVersion['created_at'])) ?></span>
                                                    </div>
                                                </div>
                                                <span class="version-current-pill">Aktif</span>
                                            </div>

                                            <div class="ready-file-list">
                                                <?php foreach ($activeVersion['files'] as $file): ?>
                                                    <?php $fileExt = strtolower(pathinfo((string) ($file['nama_asli'] ?? ''), PATHINFO_EXTENSION)); ?>
                                                    <div class="ready-file-item">
                                                        <div class="ready-file-icon">
                                                            <i class="fas <?= fileIcon((string) ($file['mime_type'] ?? ''), $fileExt) ?>"></i>
                                                        </div>
                                                        <div class="ready-file-copy">
                                                            <div class="ready-file-name" title="<?= htmlspecialchars($file['nama_asli']) ?>"><?= htmlspecialchars($file['nama_asli']) ?></div>
                                                            <div class="ready-file-meta"><?= htmlspecialchars(formatStoredFileSize(isset($file['ukuran']) ? (int) $file['ukuran'] : 0)) ?></div>
                                                        </div>
                                                        <div class="ready-file-actions">
                                                            <a href="<?= pageUrl('file_download.php?id=' . (int) $file['id']) ?>" target="_blank" rel="noopener" download class="btn btn-primary btn-sm" title="Download">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                            <?php if ($canManageJobFiles): ?>
                                                                <button type="button" class="btn btn-danger btn-sm" onclick="hapusFileTransaksi(<?= (int) $file['id'] ?>, this, 'file siap cetak ini')" title="Hapus">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <?php if ($workflowStep === 'cashier'): ?>
                                                <div class="review-note-box" style="margin-top:12px">
                                                    <strong>Transisi Workflow</strong>
                                                    <div style="margin-top:6px">Order ini masih menunggu pelunasan. Setelah invoice lunas, file siap cetak langsung bisa dipakai di produksi.</div>
                                                </div>
                                            <?php elseif (in_array($workflowStep, ['production', 'done'], true)): ?>
                                                <div class="review-note-box" style="margin-top:12px">
                                                    <strong>Transisi Workflow</strong>
                                                    <div style="margin-top:6px">Pembayaran sudah diproses. File siap cetak aktif ini sudah berada di alur produksi.</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="ready-version-empty">
                                            <i class="fas fa-file-image"></i>
                                            <div>Belum ada file siap cetak aktif untuk JO ini.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </details>

                            <?php if ($canManageJobFiles): ?>
                                <details class="sc-collapsible sc-upload-toggle"<?= $activeVersion ? '' : ' open' ?>>
                                    <summary>
                                        <span class="sc-collapsible-label"><i class="fas fa-cloud-upload-alt"></i> Upload File Siap Cetak</span>
                                        <span class="sc-collapsible-note"><?= $activeVersion ? 'Buka saat ingin membuat versi file baru.' : 'Belum ada versi aktif, unggah file pertama dari sini.' ?></span>
                                    </summary>
                                    <div class="sc-collapsible-body">
                                        <div class="upload-zone" id="zone-<?= (int) $job['id'] ?>"
                                            onclick="document.getElementById('fileInput-<?= $jobId ?>').click()"
                                            ondragover="event.preventDefault();this.classList.add('drag-over');"
                                            ondragleave="this.classList.remove('drag-over');"
                                            ondrop="handleDrop(event, <?= $jobId ?>, <?= $transaksiId ?>, <?= $detailTransaksiId ?>);this.classList.remove('drag-over');">
                                            <input type="file" id="fileInput-<?= $jobId ?>" accept=".tif,.tiff,.pdf" multiple onchange="uploadSiapCetak(this, <?= $jobId ?>, <?= $transaksiId ?>, <?= $detailTransaksiId ?>)">
                                            <div class="upload-zone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                            <p>Upload file siap cetak</p>
                                            <span>Buat versi file final berikutnya dalam format TIF atau PDF.</span>
                                        </div>
                                        <div id="uploadStatus-<?= $jobId ?>" class="upload-status"></div>
                                    </div>
                                </details>
                            <?php endif; ?>

                            <?php if (!empty($historyVersions)): ?>
                                <details class="version-history">
                                    <summary>Riwayat Versi (<?= number_format(count($historyVersions)) ?>)</summary>
                                    <div class="version-history-list">
                                        <?php foreach ($historyVersions as $history): ?>
                                            <div class="ready-version-card history">
                                                <div class="ready-version-top">
                                                    <div>
                                                        <div class="ready-version-title">
                                                            <span>Versi V<?= (int) $history['version_no'] ?></span>
                                                        </div>
                                                        <div class="ready-version-meta">
                                                            <span><i class="fas fa-upload"></i> <?= htmlspecialchars($history['nama_uploader'] ?? 'Sistem') ?></span>
                                                            <span><i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($history['created_at'])) ?></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="ready-file-list compact">
                                                    <?php foreach ($history['files'] as $file): ?>
                                                        <?php $historyFileExt = strtolower(pathinfo((string) ($file['nama_asli'] ?? ''), PATHINFO_EXTENSION)); ?>
                                                        <div class="ready-file-item">
                                                            <div class="ready-file-icon">
                                                                <i class="fas <?= fileIcon((string) ($file['mime_type'] ?? ''), $historyFileExt) ?>"></i>
                                                            </div>
                                                            <div class="ready-file-copy">
                                                                <div class="ready-file-name" title="<?= htmlspecialchars($file['nama_asli']) ?>"><?= htmlspecialchars($file['nama_asli']) ?></div>
                                                                <div class="ready-file-meta"><?= htmlspecialchars(formatStoredFileSize(isset($file['ukuran']) ? (int) $file['ukuran'] : 0)) ?></div>
                                                            </div>
                                                            <div class="ready-file-actions">
                                                                <a href="<?= pageUrl('file_download.php?id=' . (int) $file['id']) ?>" target="_blank" rel="noopener" download class="btn btn-secondary btn-sm" title="Download">
                                                                    <i class="fas fa-download"></i>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card ready-print-search-empty" id="siapCetakSearchEmpty" hidden>
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <div>Tidak ada job siap cetak yang cocok.</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="lightbox" onclick="closeLightbox()">
    <span class="lb-close" onclick="closeLightbox()">&times;</span>
    <img id="lbImg" src="" alt="">
</div>

<?php
require_once dirname(__DIR__) . '/layouts/footer.php';
?>
