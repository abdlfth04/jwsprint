<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();

function spkStatusClass(string $status): string
{
    switch (strtolower(trim($status))) {
        case 'selesai':
            return 'success';
        case 'proses':
        case 'antrian':
        case 'pending':
            return 'warning';
        case 'approved':
            return 'info';
        case 'batal':
            return 'danger';
        default:
            return 'secondary';
    }
}

function spkFormatDimension(array $row): string
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

function getStoredFileAbsolutePath(string $relativePath): string
{
    return PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
}

function spreadsheetColumnIndexFromRef(string $cellRef): int
{
    if (!preg_match('/[A-Z]+/i', $cellRef, $matches)) {
        return 0;
    }

    $letters = strtoupper($matches[0]);
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }

    return max(0, $index - 1);
}

function detectCsvDelimiter(string $sample): string
{
    $delimiters = [',', ';', "\t", '|'];
    $bestDelimiter = ',';
    $bestCount = -1;

    foreach ($delimiters as $delimiter) {
        $count = substr_count($sample, $delimiter);
        if ($count > $bestCount) {
            $bestCount = $count;
            $bestDelimiter = $delimiter;
        }
    }

    return $bestDelimiter;
}

function loadCsvRows(string $filePath, int $maxRows = 60, int $maxCols = 16): array
{
    $handle = @fopen($filePath, 'rb');
    if (!$handle) {
        return [[], false];
    }

    $rows = [];
    $sample = fgets($handle) ?: '';
    rewind($handle);
    $delimiter = detectCsvDelimiter($sample);
    $truncated = false;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rows[] = array_slice(array_map(static fn($value): string => trim((string) $value), $data), 0, $maxCols);
        if (count($rows) >= $maxRows) {
            $truncated = !feof($handle);
            break;
        }
    }

    fclose($handle);
    return [$rows, $truncated];
}

function loadXlsxRows(string $filePath, int $maxRows = 60, int $maxCols = 16): array
{
    $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('File XLSX tidak dapat dibuka.');
    }

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml !== false) {
        $sharedXml = @simplexml_load_string($sharedStringsXml);
        if ($sharedXml !== false) {
            $sharedXml->registerXPathNamespace('main', $mainNs);
            foreach ($sharedXml->xpath('//main:si') ?: [] as $item) {
                $texts = [];
                $item->registerXPathNamespace('main', $mainNs);
                foreach ($item->xpath('.//main:t') ?: [] as $textNode) {
                    $texts[] = (string) $textNode;
                }
                $sharedStrings[] = implode('', $texts);
            }
        }
    }

    $worksheetPath = 'xl/worksheets/sheet1.xml';
    $workbookXmlRaw = $zip->getFromName('xl/workbook.xml');
    $workbookRelsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXmlRaw !== false && $workbookRelsRaw !== false) {
        $workbookXml = @simplexml_load_string($workbookXmlRaw);
        $workbookRels = @simplexml_load_string($workbookRelsRaw);

        if ($workbookXml !== false && $workbookRels !== false) {
            $workbookXml->registerXPathNamespace('main', $mainNs);
            $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $workbookRels->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

            $sheetNodes = $workbookXml->xpath('//main:sheets/main:sheet');
            $firstSheet = $sheetNodes[0] ?? null;
            if ($firstSheet) {
                $attributes = $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relId = (string) ($attributes['id'] ?? '');

                foreach ($workbookRels->xpath('//rel:Relationship') ?: [] as $relationship) {
                    if ((string) ($relationship['Id'] ?? '') !== $relId) {
                        continue;
                    }

                    $target = (string) ($relationship['Target'] ?? '');
                    if ($target !== '') {
                        $worksheetPath = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
                    }
                    break;
                }
            }
        }
    }

    $worksheetRaw = $zip->getFromName($worksheetPath);
    $zip->close();
    if ($worksheetRaw === false) {
        throw new RuntimeException('Worksheet XLSX tidak ditemukan.');
    }

    $worksheetXml = @simplexml_load_string($worksheetRaw);
    if ($worksheetXml === false) {
        throw new RuntimeException('Worksheet XLSX tidak dapat dibaca.');
    }

    $worksheetXml->registerXPathNamespace('main', $mainNs);
    $rowNodes = $worksheetXml->xpath('//main:sheetData/main:row') ?: [];

    $rows = [];
    $truncated = false;
    foreach ($rowNodes as $rowNode) {
        $row = [];
        $rowChildren = $rowNode->children($mainNs);
        foreach ($rowChildren->c as $cell) {
            $attributes = $cell->attributes();
            $ref = (string) ($attributes->r ?? '');
            $colIndex = spreadsheetColumnIndexFromRef($ref);
            if ($colIndex >= $maxCols) {
                continue;
            }

            $type = (string) ($attributes->t ?? '');
            $value = '';
            $cellChildren = $cell->children($mainNs);

            if ($type === 'inlineStr') {
                $parts = [];
                if (isset($cellChildren->is)) {
                    foreach ($cellChildren->is->children($mainNs)->t as $textNode) {
                        $parts[] = (string) $textNode;
                    }
                }
                $value = implode('', $parts);
            } else {
                $rawValue = (string) ($cellChildren->v ?? '');
                $value = $type === 's' ? ($sharedStrings[(int) $rawValue] ?? '') : $rawValue;
            }

            $row[$colIndex] = trim((string) $value);
        }

        if (!empty($row)) {
            ksort($row);
            $maxExistingCol = min($maxCols - 1, max(array_keys($row)));
            $normalized = [];
            for ($i = 0; $i <= $maxExistingCol; $i++) {
                $normalized[] = $row[$i] ?? '';
            }
            $rows[] = $normalized;
        }

        if (count($rows) >= $maxRows) {
            $truncated = count($rowNodes) > $maxRows;
            break;
        }
    }

    return [$rows, $truncated];
}

function loadSpreadsheetRowsForPrint(string $filePath): array
{
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($ext === 'csv') {
        [$rows, $truncated] = loadCsvRows($filePath);
        return ['rows' => $rows, 'truncated' => $truncated, 'error' => null];
    }

    if ($ext === 'xlsx') {
        try {
            [$rows, $truncated] = loadXlsxRows($filePath);
            return ['rows' => $rows, 'truncated' => $truncated, 'error' => null];
        } catch (Throwable $e) {
            return ['rows' => [], 'truncated' => false, 'error' => $e->getMessage()];
        }
    }

    if ($ext === 'xls') {
        return ['rows' => [], 'truncated' => false, 'error' => 'Format XLS lama belum didukung untuk preview otomatis. Simpan sebagai XLSX atau CSV agar tabel bisa tampil.'];
    }

    return ['rows' => [], 'truncated' => false, 'error' => 'Format file tidak didukung untuk preview tabel.'];
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (!canAccessProductionRecord($id)) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke dokumen SPK ini.');
}

$stmtSpk = $conn->prepare(
    "SELECT pr.*,
            t.no_transaksi,
            p.nama AS nama_pelanggan, p.telepon AS tlp_pelanggan,
            u.nama AS nama_user,
            dt.nama_produk, dt.kategori_tipe, dt.satuan, dt.qty,
            dt.lebar, dt.tinggi, dt.luas,
            dt.finishing_nama, dt.bahan_nama,
            dt.size_detail, dt.catatan AS catatan_item
     FROM produksi pr
     LEFT JOIN transaksi t ON pr.transaksi_id = t.id
     LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
     LEFT JOIN users u ON pr.user_id = u.id
     LEFT JOIN detail_transaksi dt ON pr.detail_transaksi_id = dt.id
     WHERE pr.id = ? AND pr.tipe_dokumen = 'SPK'
     LIMIT 1"
);
$spk = null;
if ($stmtSpk) {
    $stmtSpk->bind_param('i', $id);
    $stmtSpk->execute();
    $spk = $stmtSpk->get_result()->fetch_assoc();
    $stmtSpk->close();
}

if (!$spk) {
    echo '<p style="font-family:sans-serif;padding:20px">Data SPK tidak ditemukan.</p>';
    exit;
}

$setting = $conn->query("SELECT * FROM setting WHERE id = 1")->fetch_assoc() ?: [];
$lampiranFiles = [];
if (!empty($spk['transaksi_id']) && schemaTableExists($conn, 'file_transaksi')) {
    $transactionId = (int) $spk['transaksi_id'];
    $detailId = (int) ($spk['detail_transaksi_id'] ?? 0);
    $fileRows = fetchScopedTransactionFiles(
        $conn,
        [$transactionId],
        ['mockup', 'list_nama'],
        'f.*, dt.nama_produk AS nama_produk_detail'
    );
    $groupedFiles = groupScopedTransactionFiles($fileRows);
    $lampiranFiles = resolveScopedTransactionFiles($conn, $groupedFiles, $transactionId, $detailId, ['mockup', 'list_nama']);
}

$jo = null;
if (!empty($spk['jo_id'])) {
    $stmtJo = $conn->prepare("SELECT no_dokumen FROM produksi WHERE id = ? LIMIT 1");
    if ($stmtJo) {
        $joId = (int) $spk['jo_id'];
        $stmtJo->bind_param('i', $joId);
        $stmtJo->execute();
        $jo = $stmtJo->get_result()->fetch_assoc();
        $stmtJo->close();
    }
}

$targetId = !empty($spk['jo_id']) ? (int) $spk['jo_id'] : $id;
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
        $stmtTahapan->bind_param('i', $targetId);
        $stmtTahapan->execute();
        $tahapan = $stmtTahapan->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtTahapan->close();
    }
}

$companyName = (string) ($setting['nama_toko'] ?? 'JWS Printing & Apparel');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPK <?= htmlspecialchars((string) ($spk['no_dokumen'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('css/print_professional.css') ?>">
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm;
        }
    </style>
</head>
<body class="print-document portrait">
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
                    <div class="meta-title">Referensi SPK</div>
                    <div class="meta-stack">
                        <div class="meta-line">
                            <span class="meta-line-label">No. SPK</span>
                            <span class="meta-line-value"><?= htmlspecialchars((string) ($spk['no_dokumen'] ?? '-')) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">No. JO</span>
                            <span class="meta-line-value"><?= htmlspecialchars((string) ($jo['no_dokumen'] ?? '-')) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">Tanggal</span>
                            <span class="meta-line-value"><?= htmlspecialchars(date('d M Y', strtotime((string) ($spk['tanggal'] ?? 'now')))) ?></span>
                        </div>
                    </div>
                </aside>
            </header>

            <section class="document-heading">
                <div>
                    <h1>SPK</h1>
                    <p>Surat perintah kerja untuk panduan teknis dan eksekusi produksi.</p>
                </div>
                <div class="document-badges">
                    <?php if (!empty($spk['deadline'])): ?>
                        <span class="pill">Deadline: <?= htmlspecialchars(date('d M Y', strtotime((string) $spk['deadline']))) ?></span>
                    <?php endif; ?>
                    <span class="status-chip <?= spkStatusClass((string) ($spk['status'] ?? 'antrian')) ?>"><?= htmlspecialchars(strtoupper((string) ($spk['status'] ?? 'ANTRIAN'))) ?></span>
                </div>
            </section>

            <section class="info-panel-grid two-col">
                <article class="info-card avoid-break">
                    <div class="info-card-head"><strong>Informasi Order</strong></div>
                    <div class="card-body">
                        <div class="kv-grid">
                            <div class="kv-item">
                                <span class="kv-label">Pelanggan</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['nama_pelanggan'] ?? 'Umum')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">No. Telepon</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['tlp_pelanggan'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">No. Transaksi</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['no_transaksi'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Supervisor</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['nama_user'] ?? '-')) ?></span>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="info-card avoid-break">
                    <div class="info-card-head"><strong>Spesifikasi Teknis</strong></div>
                    <div class="card-body">
                        <div class="kv-grid">
                            <div class="kv-item">
                                <span class="kv-label">Nama Pekerjaan</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['nama_pekerjaan'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Produk</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['nama_produk'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Qty / Ukuran</span>
                                <span class="kv-value"><?= htmlspecialchars(spkFormatDimension($spk)) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Bahan</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['bahan_nama'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Finishing</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['finishing_nama'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Size Detail</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($spk['size_detail'] ?? '-')) ?></span>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <section class="summary-card avoid-break">
                <div class="summary-card-head"><strong>Instruksi Produksi</strong></div>
                <div class="card-body">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <td style="width: 28%;">Kategori</td>
                                <td><?= htmlspecialchars((string) ($spk['kategori_tipe'] ?? '-')) ?></td>
                            </tr>
                            <tr>
                                <td>Catatan Item</td>
                                <td><?= !empty($spk['catatan_item']) ? nl2br(htmlspecialchars((string) $spk['catatan_item'])) : '-' ?></td>
                            </tr>
                            <tr>
                                <td>Instruksi Tambahan</td>
                                <td><?= !empty($spk['keterangan']) ? nl2br(htmlspecialchars((string) $spk['keterangan'])) : '-' ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="summary-card">
                <div class="summary-card-head"><strong>Tahapan Pengerjaan</strong></div>
                <div class="card-body">
                    <?php if (!empty($tahapan)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 7%;">No</th>
                                    <th>Nama Tahapan</th>
                                    <th style="width: 28%;">Operator</th>
                                    <th style="width: 18%;" class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tahapan as $index => $tahap): ?>
                                    <tr>
                                        <td class="text-center"><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars((string) ($tahap['nama_tahapan'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string) ($tahap['nama_operator'] ?? '-')) ?></td>
                                        <td class="text-center"><span class="status-chip <?= spkStatusClass((string) ($tahap['status'] ?? 'belum')) ?>"><?= htmlspecialchars(strtoupper((string) ($tahap['status'] ?? 'BELUM'))) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="lead-note">Tahapan pengerjaan belum disusun untuk SPK ini.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="signature-grid">
                <div class="signature-card">
                    <div class="signature-role">Operator</div>
                    <div class="signature-name">____________________________</div>
                </div>
                <div class="signature-card">
                    <div class="signature-role">Supervisor</div>
                    <div class="signature-name"><?= htmlspecialchars((string) ($spk['nama_user'] ?? '-')) ?></div>
                </div>
            </section>

            <?php if (!empty($setting['footer_nota'])): ?>
                <div class="document-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($lampiranFiles)): ?>
        <div class="print-sheet">
            <div class="sheet-inner">
                <section class="document-heading">
                    <div>
                        <h1>Lampiran SPK</h1>
                        <p>Mockup desain, daftar nama, atau spreadsheet referensi yang menyertai pekerjaan ini.</p>
                    </div>
                    <div class="document-badges">
                        <span class="pill"><?= number_format(count($lampiranFiles)) ?> file terlampir</span>
                    </div>
                </section>

                <?php
                $mockupFiles = array_values(array_filter($lampiranFiles, static fn(array $file): bool => ($file['tipe_file'] ?? '') === 'mockup'));
                $listNamaFiles = array_values(array_filter($lampiranFiles, static fn(array $file): bool => ($file['tipe_file'] ?? '') === 'list_nama'));
                ?>

                <?php if (!empty($mockupFiles)): ?>
                    <section class="report-card avoid-break" style="margin-bottom: 14px;">
                        <div class="report-card-head"><strong>Mockup Desain</strong></div>
                        <div class="card-body">
                            <div class="image-preview-grid">
                                <?php foreach ($mockupFiles as $file): ?>
                                    <?php
                                    $filePath = getStoredFileAbsolutePath((string) ($file['path_file'] ?? ''));
                                    $extension = strtolower(pathinfo((string) ($file['nama_asli'] ?? ''), PATHINFO_EXTENSION));
                                    $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
                                    ?>
                                    <article class="image-preview avoid-break">
                                        <div class="image-preview-head"><?= htmlspecialchars((string) ($file['nama_asli'] ?? 'Mockup')) ?></div>
                                        <div class="image-preview-body">
                                            <?php if ($isImage && file_exists($filePath)): ?>
                                                <img src="<?= pageUrl('file_download.php?id=' . (int) ($file['id'] ?? 0) . '&inline=1') ?>" alt="Mockup desain">
                                            <?php else: ?>
                                                <div class="lead-note">Preview gambar tidak tersedia. File tetap tersimpan sebagai lampiran produksi.</div>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php foreach ($listNamaFiles as $file): ?>
                    <section class="report-card avoid-break" style="margin-bottom: 14px;">
                        <div class="report-card-head"><strong>Daftar Nama / Spreadsheet</strong></div>
                        <div class="card-body">
                            <div class="table-caption">Sumber file: <?= htmlspecialchars((string) ($file['nama_asli'] ?? '-')) ?></div>
                            <?php
                            $filePath = getStoredFileAbsolutePath((string) ($file['path_file'] ?? ''));
                            if (!file_exists($filePath)) {
                                echo '<div class="lead-note">File fisik tidak ditemukan di server.</div>';
                            } else {
                                $preview = loadSpreadsheetRowsForPrint($filePath);
                                $rows = $preview['rows'] ?? [];

                                if (!empty($preview['error'])) {
                                    echo '<div class="lead-note">' . htmlspecialchars((string) $preview['error']) . '</div>';
                                } elseif (empty($rows)) {
                                    echo '<div class="lead-note">Spreadsheet kosong atau belum dapat dibaca.</div>';
                                } else {
                                    $headerRow = $rows[0];
                                    $bodyRows = array_slice($rows, 1);
                                    ?>
                                    <div class="spreadsheet-preview">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <?php foreach ($headerRow as $header): ?>
                                                        <th><?= htmlspecialchars((string) $header) ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($bodyRows as $row): ?>
                                                    <tr>
                                                        <?php foreach ($headerRow as $columnIndex => $unused): ?>
                                                            <td><?= htmlspecialchars((string) ($row[$columnIndex] ?? '')) ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (!empty($preview['truncated'])): ?>
                                        <div class="lead-note" style="margin-top: 8px;">Preview tabel dipotong agar tetap nyaman dicetak. Data lengkap tetap ada pada file aslinya.</div>
                                    <?php endif; ?>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php if (!empty($setting['footer_nota'])): ?>
                    <div class="document-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <script>window.onload = function () { window.print(); };</script>
</body>
</html>
