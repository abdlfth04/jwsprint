<?php

function getUploadStorageRootPath(): string
{
    return PROJECT_ROOT . DIRECTORY_SEPARATOR . 'uploads';
}

function parseIniSizeToBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = is_numeric($unit) ? (float) $value : (float) substr($value, 0, -1);

    switch ($unit) {
        case 'g':
            $number *= 1024;
        case 'm':
            $number *= 1024;
        case 'k':
            $number *= 1024;
    }

    return max(0, (int) round($number));
}

function formatUploadByteSize(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    $precision = $size >= 100 || $unitIndex === 0 ? 0 : 1;
    return number_format($size, $precision, '.', '') . ' ' . $units[$unitIndex];
}

function getPhpIniSizeBytes(string $directive): int
{
    return parseIniSizeToBytes((string) ini_get($directive));
}

function getEffectiveUploadLimitBytes(): int
{
    $uploadMax = getPhpIniSizeBytes('upload_max_filesize');
    $postMax = getPhpIniSizeBytes('post_max_size');

    if ($uploadMax > 0 && $postMax > 0) {
        return min($uploadMax, $postMax);
    }

    return max($uploadMax, $postMax);
}

function isUploadRequestTooLarge(): bool
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = getPhpIniSizeBytes('post_max_size');

    return $contentLength > 0 && $postMax > 0 && $contentLength > $postMax;
}

function buildUploadLimitSummary(?int $appLimitBytes = null): string
{
    $summary = [];
    $serverLimit = getEffectiveUploadLimitBytes();

    if ($serverLimit > 0) {
        $summary[] = 'batas server/PHP ' . formatUploadByteSize($serverLimit);
    }

    if ($appLimitBytes !== null && $appLimitBytes > 0) {
        $summary[] = 'batas modul ' . formatUploadByteSize($appLimitBytes);
    }

    return empty($summary) ? '' : ' (' . implode(', ', $summary) . ')';
}

function buildUploadTooLargeMessage(?int $appLimitBytes = null): string
{
    return 'Ukuran upload melebihi batas yang diizinkan' . buildUploadLimitSummary($appLimitBytes) . '.';
}

function uploadErrorMessage(int $errorCode, string $filename = '', ?int $appLimitBytes = null): string
{
    $prefix = trim($filename) !== '' ? trim($filename) . ': ' : '';

    switch ($errorCode) {
        case UPLOAD_ERR_OK:
            return $prefix . 'Upload berhasil.';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return $prefix . buildUploadTooLargeMessage($appLimitBytes);
        case UPLOAD_ERR_PARTIAL:
            return $prefix . 'Upload terputus sebelum file selesai diterima server.';
        case UPLOAD_ERR_NO_FILE:
            return $prefix . 'Tidak ada file yang dipilih.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return $prefix . 'Folder temporary upload di server tidak tersedia.';
        case UPLOAD_ERR_CANT_WRITE:
            return $prefix . 'Server tidak bisa menulis file ke penyimpanan.';
        case UPLOAD_ERR_EXTENSION:
            return $prefix . 'Upload dihentikan oleh ekstensi PHP di server.';
        default:
            return $prefix . 'Upload gagal dengan kode error ' . $errorCode . '.';
    }
}

function normalizeUploadRelativePath(string $path): string
{
    $normalized = trim(str_replace('\\', '/', $path));
    $normalized = ltrim($normalized, '/');

    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return '';
    }

    return $normalized;
}

function resolveManagedUploadPath(string $relativePath): ?string
{
    $normalizedRelative = normalizeUploadRelativePath($relativePath);
    if ($normalizedRelative === '') {
        return null;
    }

    $rootPath = str_replace('\\', '/', getUploadStorageRootPath());
    $absolutePath = str_replace('\\', '/', PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedRelative));
    $normalizedRoot = rtrim($rootPath, '/');

    if (strpos($absolutePath, $normalizedRoot . '/') !== 0 && $absolutePath !== $normalizedRoot) {
        return null;
    }

    return str_replace('/', DIRECTORY_SEPARATOR, $absolutePath);
}

function detectFileMimeType(string $absolutePath): string
{
    if (!is_file($absolutePath)) {
        return 'application/octet-stream';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $absolutePath) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    return is_string($mimeType) && $mimeType !== '' ? strtolower($mimeType) : 'application/octet-stream';
}

function getTransactionUploadMimeRulesByExtension(): array
{
    return [
        'jpg' => ['allow' => ['image/jpeg', 'image/pjpeg'], 'contains' => ['jpeg']],
        'jpeg' => ['allow' => ['image/jpeg', 'image/pjpeg'], 'contains' => ['jpeg']],
        'png' => ['allow' => ['image/png'], 'contains' => ['png']],
        'pdf' => ['allow' => ['application/pdf', 'application/x-pdf'], 'contains' => ['pdf']],
        'ai' => ['allow' => ['application/postscript', 'application/pdf', 'application/illustrator', 'application/vnd.adobe.illustrator', 'application/octet-stream'], 'contains' => ['postscript', 'illustrator']],
        'cdr' => ['allow' => ['application/octet-stream', 'application/cdr', 'application/vnd.corel-draw'], 'contains' => ['corel', 'cdr']],
        'eps' => ['allow' => ['application/postscript', 'application/eps', 'image/x-eps'], 'contains' => ['postscript', 'eps']],
        'xlsx' => ['allow' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'], 'contains' => ['spreadsheetml', 'zip']],
        'xls' => ['allow' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'], 'contains' => ['excel', 'ole']],
        'csv' => ['allow' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'], 'contains' => ['csv', 'plain']],
        'doc' => ['allow' => ['application/msword', 'application/vnd.ms-word', 'application/octet-stream'], 'contains' => ['msword', 'word']],
        'docx' => ['allow' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'], 'contains' => ['wordprocessingml', 'zip']],
        'ppt' => ['allow' => ['application/vnd.ms-powerpoint', 'application/mspowerpoint', 'application/octet-stream'], 'contains' => ['powerpoint', 'ppt']],
        'pptx' => ['allow' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip-compressed'], 'contains' => ['presentationml', 'zip']],
        'txt' => ['allow' => ['text/plain', 'application/octet-stream'], 'contains' => ['plain']],
        'tif' => ['allow' => ['image/tiff', 'image/x-tiff'], 'contains' => ['tiff']],
        'tiff' => ['allow' => ['image/tiff', 'image/x-tiff'], 'contains' => ['tiff']],
    ];
}

function isAllowedUploadMimeForExtension(string $extension, string $mimeType): bool
{
    $rules = getTransactionUploadMimeRulesByExtension();
    $extension = strtolower(trim($extension));
    $mimeType = strtolower(trim($mimeType));

    if ($extension === '' || $mimeType === '' || !isset($rules[$extension])) {
        return false;
    }

    foreach ($rules[$extension]['allow'] ?? [] as $allowedMime) {
        if ($mimeType === strtolower($allowedMime)) {
            return true;
        }
    }

    foreach ($rules[$extension]['contains'] ?? [] as $needle) {
        if (stripos($mimeType, (string) $needle) !== false) {
            return true;
        }
    }

    return false;
}

function sanitizeDownloadMimeType(string $mimeType, string $filename = ''): string
{
    $mimeType = strtolower(trim($mimeType));
    if ($mimeType !== '' && $mimeType !== 'application/octet-stream' && preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/', $mimeType)) {
        return $mimeType;
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $rules = getTransactionUploadMimeRulesByExtension();

    return $rules[$extension]['allow'][0] ?? ($mimeType !== '' ? $mimeType : 'application/octet-stream');
}

function buildDownloadFilenameHeader(string $filename): string
{
    $filename = basename(trim($filename));
    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = 'download';
    }

    $asciiFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
    $asciiFilename = $asciiFilename !== '' ? $asciiFilename : 'download';

    return 'filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

function canDisplayTransactionFileInline(string $mimeType): bool
{
    return in_array($mimeType, [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/tiff',
        'image/x-tiff',
        'application/pdf',
    ], true);
}

function getTransactionFileRules(string $tipeFile): ?array
{
    $rules = [
        'cetak' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'pdf', 'ai', 'cdr', 'eps'],
            'max_size' => 500 * 1024 * 1024,
        ],
        'mockup' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_size' => 500 * 1024 * 1024,
        ],
        'list_nama' => [
            'extensions' => ['xlsx', 'xls', 'csv'],
            'max_size' => 5 * 1024 * 1024,
        ],
        'bukti_transfer' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_size' => 25 * 1024 * 1024,
        ],
        'siap_cetak' => [
            'extensions' => ['tif', 'tiff', 'pdf'],
            'max_size' => 500 * 1024 * 1024,
            'mime_contains' => ['tiff', 'pdf'],
            'mime_allow' => ['image/tiff', 'image/x-tiff', 'application/pdf', 'application/x-pdf'],
        ],
    ];

    return $rules[$tipeFile] ?? null;
}

function normalizeTransactionUploadFiles(string $fieldName = 'file'): array
{
    if (!isset($_FILES[$fieldName])) {
        return [];
    }

    $file = $_FILES[$fieldName];
    if (!isset($file['name'])) {
        return [];
    }

    if (is_array($file['name'])) {
        $normalized = [];
        foreach ($file['name'] as $index => $name) {
            if ($name === '' && (($file['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'type' => $file['type'][$index] ?? '',
                'tmp_name' => $file['tmp_name'][$index] ?? '',
                'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    return [[
        'name' => $file['name'],
        'type' => $file['type'] ?? '',
        'tmp_name' => $file['tmp_name'] ?? '',
        'error' => $file['error'] ?? UPLOAD_ERR_NO_FILE,
        'size' => $file['size'] ?? 0,
    ]];
}

function ensureTransactionUploadDirectory(int $transaksiId): array
{
    $rootPath = dirname(__DIR__, 2);
    $relativePath = 'uploads/transaksi/' . $transaksiId . '/';
    $absolutePath = $rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (!is_dir($absolutePath)) {
        mkdir($absolutePath, 0755, true);
    }

    $htaccessPath = $absolutePath . '.htaccess';
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "deny from all\n");
    }

    return [$absolutePath, $relativePath];
}

function storeTransactionUploads(mysqli $conn, int $transaksiId, ?int $detailId, string $tipeFile, int $userId, string $fieldName = 'file'): array
{
    $rules = getTransactionFileRules($tipeFile);
    if ($rules === null) {
        return [
            'success' => false,
            'files' => [],
            'errors' => ['Tipe file tidak dikenal.'],
        ];
    }

    if (isUploadRequestTooLarge()) {
        if (function_exists('appLogRuntimeIssue')) {
            appLogRuntimeIssue('upload_request_too_large', [
                'tipe_file' => $tipeFile,
                'transaksi_id' => $transaksiId,
                'content_length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
                'server_limit' => getEffectiveUploadLimitBytes(),
                'module_limit' => (int) ($rules['max_size'] ?? 0),
            ]);
        }

        return [
            'success' => false,
            'files' => [],
            'errors' => [buildUploadTooLargeMessage((int) ($rules['max_size'] ?? 0))],
        ];
    }

    $files = normalizeTransactionUploadFiles($fieldName);
    if (empty($files)) {
        return [
            'success' => false,
            'files' => [],
            'errors' => ['Tidak ada file yang dipilih.'],
        ];
    }

    $detailId = normalizeTransactionDetailId($detailId);
    if ($tipeFile === 'bukti_transfer') {
        $detailId = 0;
    }

    $fileCategory = getTransactionFileCategoryForTypes([$tipeFile]);
    if ($fileCategory !== null) {
        $candidateDetailIds = getTransactionDetailIdsByCategory($conn, $transaksiId, $fileCategory);

        if ($detailId <= 0 && count($candidateDetailIds) === 1) {
            $detailId = (int) $candidateDetailIds[0];
        }

        if ($detailId <= 0 && count($candidateDetailIds) > 1) {
            return [
                'success' => false,
                'files' => [],
                'errors' => ['Pilih item produk terlebih dahulu agar file tidak tercampur dengan item lain dalam transaksi yang sama.'],
            ];
        }

        if ($detailId > 0 && !empty($candidateDetailIds) && !in_array($detailId, $candidateDetailIds, true)) {
            return [
                'success' => false,
                'files' => [],
                'errors' => ['Item produk yang dipilih tidak sesuai dengan tipe file yang diunggah.'],
            ];
        }
    }

    [$uploadDir, $relativeDir] = ensureTransactionUploadDirectory($transaksiId);
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        if (function_exists('appLogRuntimeIssue')) {
            appLogRuntimeIssue('upload_directory_unwritable', [
                'tipe_file' => $tipeFile,
                'transaksi_id' => $transaksiId,
                'upload_dir' => $uploadDir,
            ]);
        }

        return [
            'success' => false,
            'files' => [],
            'errors' => ['Folder upload server belum bisa ditulisi. Periksa permission direktori uploads/transaksi di hosting.'],
        ];
    }

    $uploaded = [];
    $errors = [];
    $readyPrintContext = null;
    $readyPrintArchived = false;

    if ($tipeFile === 'siap_cetak' && readyPrintVersioningReady()) {
        $readyPrintContext = prepareReadyPrintUploadContext($conn, $transaksiId, $detailId);
    }

    foreach ($files as $file) {
        $name = trim((string) ($file['name'] ?? ''));
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            $errors[] = uploadErrorMessage($errorCode, $name, (int) ($rules['max_size'] ?? 0));
            continue;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($name === '' || $ext === '') {
            $errors[] = 'Nama file tidak valid.';
            continue;
        }

        if (!is_uploaded_file($tmpName)) {
            $errors[] = $name . ': sumber upload tidak valid.';
            continue;
        }

        if (!in_array($ext, $rules['extensions'], true)) {
            $errors[] = $name . ': format tidak didukung. Hanya ' . implode(', ', $rules['extensions']) . '.';
            continue;
        }

        if ($size > (int) $rules['max_size']) {
            $errors[] = $name . ': ukuran melebihi batas ' . number_format($rules['max_size'] / 1024 / 1024, 0) . 'MB.';
            continue;
        }

        $mimeType = detectFileMimeType($tmpName);

        if (!isAllowedUploadMimeForExtension($ext, $mimeType)) {
            $errors[] = $name . ': tipe file terdeteksi sebagai ' . $mimeType . ' dan tidak sesuai dengan format .' . $ext . '.';
            continue;
        }

        if (!empty($rules['mime_contains']) || !empty($rules['mime_allow'])) {
            $mimeOk = false;

            foreach ($rules['mime_allow'] ?? [] as $allowedMime) {
                if ($mimeType === $allowedMime) {
                    $mimeOk = true;
                    break;
                }
            }

            if (!$mimeOk) {
                foreach ($rules['mime_contains'] ?? [] as $needle) {
                    if (stripos($mimeType, $needle) !== false) {
                        $mimeOk = true;
                        break;
                    }
                }
            }

            if (!$mimeOk) {
                $errors[] = $name . ': tipe file terdeteksi sebagai ' . $mimeType . ' dan tidak sesuai aturan upload.';
                continue;
            }
        }

        $storedName = uniqid($tipeFile . '_', true) . '.' . $ext;
        $absoluteTarget = resolveManagedUploadPath($relativeDir . $storedName);
        $relativeTarget = $relativeDir . $storedName;

        if ($absoluteTarget === null) {
            $errors[] = $name . ': path penyimpanan tidak valid.';
            continue;
        }

        if (!move_uploaded_file($tmpName, $absoluteTarget)) {
            if (function_exists('appLogRuntimeIssue')) {
                appLogRuntimeIssue('move_uploaded_file_failed', [
                    'tipe_file' => $tipeFile,
                    'transaksi_id' => $transaksiId,
                    'detail_transaksi_id' => $detailId,
                    'filename' => $name,
                    'target' => $absoluteTarget,
                ]);
            }

            $errors[] = $name . ': file gagal dipindahkan ke server.';
            continue;
        }

        $detailIdValue = $detailId ?: 0;

        if ($tipeFile === 'siap_cetak' && readyPrintVersioningReady() && $readyPrintContext !== null) {
            if (!$readyPrintArchived) {
                archiveCurrentReadyPrintVersion($conn, $transaksiId, $detailId);
                $readyPrintArchived = true;
            }

            $stmt = $conn->prepare("INSERT INTO file_transaksi (
                transaksi_id, detail_transaksi_id, nama_asli, nama_tersimpan, path_file, ukuran, mime_type, tipe_file, uploaded_by,
                version_group, version_batch, version_no, is_current_version
            ) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                @unlink($absoluteTarget);
                $errors[] = $name . ': gagal menyiapkan penyimpanan database.';
                continue;
            }

            $versionGroup = $readyPrintContext['version_group'];
            $versionBatch = $readyPrintContext['version_batch'];
            $versionNo = (int) $readyPrintContext['version_no'];
            $isCurrentVersion = (int) $readyPrintContext['is_current_version'];

            $stmt->bind_param(
                'iisssississii',
                $transaksiId,
                $detailIdValue,
                $name,
                $storedName,
                $relativeTarget,
                $size,
                $mimeType,
                $tipeFile,
                $userId,
                $versionGroup,
                $versionBatch,
                $versionNo,
                $isCurrentVersion
            );
        } else {
            $stmt = $conn->prepare("INSERT INTO file_transaksi (transaksi_id, detail_transaksi_id, nama_asli, nama_tersimpan, path_file, ukuran, mime_type, tipe_file, uploaded_by) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                @unlink($absoluteTarget);
                $errors[] = $name . ': gagal menyiapkan penyimpanan database.';
                continue;
            }

            $stmt->bind_param(
                'iisssissi',
                $transaksiId,
                $detailIdValue,
                $name,
                $storedName,
                $relativeTarget,
                $size,
                $mimeType,
                $tipeFile,
                $userId
            );
        }

        if ($stmt->execute()) {
            $uploaded[] = [
                'id' => $conn->insert_id,
                'nama_asli' => $name,
                'ukuran' => $size,
                'mime_type' => $mimeType,
                'tipe_file' => $tipeFile,
                'path_file' => $relativeTarget,
                'detail_transaksi_id' => $detailIdValue,
                'version_no' => $readyPrintContext['version_no'] ?? 1,
                'version_batch' => $readyPrintContext['version_batch'] ?? null,
            ];
        } else {
            @unlink($absoluteTarget);
            $errors[] = $name . ': gagal menyimpan ke database.';
        }

        $stmt->close();
    }

    if ($tipeFile === 'siap_cetak' && $readyPrintArchived && empty($uploaded) && readyPrintVersioningReady()) {
        restoreCurrentReadyPrintVersionAfterDeletion($conn, $transaksiId);
    }

    return [
        'success' => !empty($uploaded),
        'files' => $uploaded,
        'errors' => $errors,
    ];
}
