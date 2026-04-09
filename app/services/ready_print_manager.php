<?php

function readyPrintSchemaInfo(bool $refresh = false): array
{
    static $cache = null;
    if (!$refresh && is_array($cache)) {
        return $cache;
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        return $cache = [
            'table_exists' => false,
            'columns' => [],
            'versioning_ready' => false,
        ];
    }

    $columns = schemaTableColumns($conn, 'file_transaksi');
    $versionColumns = ['version_batch', 'version_no', 'is_current_version'];

    return $cache = [
        'table_exists' => !empty($columns),
        'columns' => $columns,
        'versioning_ready' => count(array_intersect($versionColumns, $columns)) === count($versionColumns),
    ];
}

function readyPrintVersioningReady(): bool
{
    $schema = readyPrintSchemaInfo();

    return !empty($schema['versioning_ready']);
}

function getReadyPrintScopeClause(mysqli $conn, int $transaksiId, ?int $detailId): string
{
    $detailId = normalizeTransactionDetailId($detailId);
    if ($detailId > 0) {
        if (canUseLegacyTransactionFilesForDetail($conn, $transaksiId, $detailId, ['siap_cetak'])) {
            return ' AND (detail_transaksi_id = ' . $detailId . ' OR detail_transaksi_id IS NULL OR detail_transaksi_id = 0)';
        }

        return ' AND detail_transaksi_id = ' . $detailId;
    }

    return ' AND (detail_transaksi_id IS NULL OR detail_transaksi_id = 0)';
}

function groupReadyPrintVersionRows(array $rows): array
{
    if (empty($rows)) {
        return [];
    }

    $versions = [];
    foreach ($rows as $row) {
        $batch = (string) ($row['version_batch'] ?? '');
        if ($batch === '') {
            $batch = 'legacy_' . (int) ($row['id'] ?? 0);
        }

        if (!isset($versions[$batch])) {
            $versions[$batch] = [
                'version_batch' => $batch,
                'version_group' => $row['version_group'] ?? null,
                'version_no' => (int) ($row['version_no'] ?? 1),
                'is_current_version' => !empty($row['is_current_version']),
                'created_at' => $row['created_at'] ?? null,
                'nama_uploader' => $row['nama_uploader'] ?? null,
                'files' => [],
            ];
        }

        $versions[$batch]['files'][] = $row;
    }

    uasort($versions, static function (array $left, array $right): int {
        if (($left['version_no'] ?? 0) === ($right['version_no'] ?? 0)) {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        }
        return ($right['version_no'] ?? 0) <=> ($left['version_no'] ?? 0);
    });

    return array_values($versions);
}

function getNextReadyPrintVersionNo(mysqli $conn, int $transaksiId, ?int $detailId = null): int
{
    $scopeClause = getReadyPrintScopeClause($conn, $transaksiId, $detailId);
    $stmt = $conn->prepare("SELECT COALESCE(MAX(version_no), 0) AS max_version FROM file_transaksi WHERE transaksi_id = ? AND tipe_file = 'siap_cetak'" . $scopeClause);
    if (!$stmt) {
        return 1;
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return max(1, ((int) ($row['max_version'] ?? 0)) + 1);
}

function prepareReadyPrintUploadContext(mysqli $conn, int $transaksiId, ?int $detailId = null): array
{
    $detailId = normalizeTransactionDetailId($detailId);

    return [
        'version_group' => $detailId > 0 ? 'siap_cetak_detail_' . $detailId : 'siap_cetak_trx_' . $transaksiId,
        'version_batch' => uniqid('scv_', true),
        'version_no' => getNextReadyPrintVersionNo($conn, $transaksiId, $detailId),
        'is_current_version' => 1,
    ];
}

function archiveCurrentReadyPrintVersion(mysqli $conn, int $transaksiId, ?int $detailId = null): void
{
    $scopeClause = getReadyPrintScopeClause($conn, $transaksiId, $detailId);
    $stmt = $conn->prepare("UPDATE file_transaksi SET is_current_version = 0 WHERE transaksi_id = ? AND tipe_file = 'siap_cetak' AND is_active = 1 AND is_current_version = 1" . $scopeClause);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $stmt->close();
}

function restoreCurrentReadyPrintVersionAfterDeletion(mysqli $conn, int $transaksiId, ?int $detailId = null): void
{
    $scopeClause = getReadyPrintScopeClause($conn, $transaksiId, $detailId);
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM file_transaksi WHERE transaksi_id = ? AND tipe_file = 'siap_cetak' AND is_active = 1 AND is_current_version = 1" . $scopeClause);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int) ($row['total'] ?? 0) > 0) {
        return;
    }

    $stmt = $conn->prepare("SELECT version_batch
        FROM file_transaksi
        WHERE transaksi_id = ? AND tipe_file = 'siap_cetak' AND is_active = 1
        " . $scopeClause . "
        ORDER BY version_no DESC, created_at DESC
        LIMIT 1");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $batchRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($batchRow['version_batch'])) {
        return;
    }

    $stmt = $conn->prepare("UPDATE file_transaksi SET is_current_version = 1 WHERE transaksi_id = ? AND tipe_file = 'siap_cetak' AND is_active = 1 AND version_batch = ?" . $scopeClause);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('is', $transaksiId, $batchRow['version_batch']);
    $stmt->execute();
    $stmt->close();
}

function fetchReadyPrintVersionSummary(mysqli $conn, int $transaksiId, string $versionBatch, ?int $detailId = null): ?array
{
    $scopeClause = getReadyPrintScopeClause($conn, $transaksiId, $detailId);
    $stmt = $conn->prepare("SELECT
        version_batch,
        version_no,
        is_current_version,
        COUNT(*) AS total_files
        FROM file_transaksi
        WHERE transaksi_id = ? AND tipe_file = 'siap_cetak' AND is_active = 1 AND version_batch = ?" . $scopeClause . "
        GROUP BY version_batch, version_no, is_current_version
        LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('is', $transaksiId, $versionBatch);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $summary;
}

function fetchReadyPrintVersionsForJobs(mysqli $conn, array $jobs): array
{
    $transaksiIds = array_values(array_unique(array_map(static function (array $job): int {
        return (int) ($job['transaksi_id'] ?? 0);
    }, $jobs)));
    if (empty($transaksiIds)) {
        return [];
    }

    $sql = "SELECT
        f.id, f.transaksi_id, f.detail_transaksi_id, f.nama_asli, f.nama_tersimpan, f.path_file, f.ukuran, f.mime_type, f.created_at, f.tipe_file,
        f.version_group, f.version_batch, f.version_no, f.is_current_version,
        uploader.nama AS nama_uploader
        FROM file_transaksi f
        LEFT JOIN users uploader ON f.uploaded_by = uploader.id
        WHERE f.transaksi_id IN (" . implode(',', $transaksiIds) . ")
            AND f.tipe_file = 'siap_cetak'
            AND f.is_active = 1
        ORDER BY f.transaksi_id ASC, f.version_no DESC, f.created_at DESC";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $groupedRows = groupScopedTransactionFiles($result->fetch_all(MYSQLI_ASSOC));
    $versions = [];
    foreach ($jobs as $job) {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            continue;
        }

        $transaksiId = (int) ($job['transaksi_id'] ?? 0);
        $detailId = (int) ($job['detail_transaksi_id'] ?? 0);
        $rows = resolveScopedTransactionFiles($conn, $groupedRows, $transaksiId, $detailId, ['siap_cetak']);
        $versions[$jobId] = groupReadyPrintVersionRows($rows);
    }

    return $versions;
}

function fetchReadyPrintVersionsForTransactions(mysqli $conn, array $transaksiIds): array
{
    $jobs = array_map(static function (int $transaksiId): array {
        return [
            'id' => $transaksiId,
            'transaksi_id' => $transaksiId,
            'detail_transaksi_id' => 0,
        ];
    }, array_values(array_unique(array_map('intval', array_filter($transaksiIds)))));

    return fetchReadyPrintVersionsForJobs($conn, $jobs);
}
