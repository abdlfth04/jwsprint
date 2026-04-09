<?php

function normalizeTransactionDetailId($detailId): int
{
    $detailId = (int) $detailId;
    return $detailId > 0 ? $detailId : 0;
}

function getTransactionFileCategoryForTypes(array $types): ?string
{
    $map = [
        'cetak' => 'printing',
        'siap_cetak' => 'printing',
        'mockup' => 'apparel',
        'list_nama' => 'apparel',
    ];

    $categories = [];
    foreach ($types as $type) {
        $type = trim((string) $type);
        if ($type === '' || !isset($map[$type])) {
            return null;
        }
        $categories[$map[$type]] = true;
    }

    if (count($categories) !== 1) {
        return null;
    }

    return array_key_first($categories);
}

function getTransactionDetailIdsByCategory(mysqli $conn, int $transaksiId, string $category): array
{
    static $cache = [];

    $transaksiId = (int) $transaksiId;
    $category = trim($category);
    $cacheKey = $transaksiId . '|' . $category;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($transaksiId <= 0 || $category === '') {
        return $cache[$cacheKey] = [];
    }

    $stmt = $conn->prepare("SELECT id FROM detail_transaksi WHERE transaksi_id = ? AND kategori_tipe = ? ORDER BY id ASC");
    if (!$stmt) {
        return $cache[$cacheKey] = [];
    }

    $stmt->bind_param('is', $transaksiId, $category);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $cache[$cacheKey] = array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $rows);
}

function canUseLegacyTransactionFilesForDetail(mysqli $conn, int $transaksiId, ?int $detailId, array $types): bool
{
    $detailId = normalizeTransactionDetailId($detailId);
    if ($transaksiId <= 0 || $detailId <= 0) {
        return false;
    }

    $category = getTransactionFileCategoryForTypes($types);
    if ($category === null) {
        return false;
    }

    $candidateIds = getTransactionDetailIdsByCategory($conn, $transaksiId, $category);
    return count($candidateIds) === 1 && (int) $candidateIds[0] === $detailId;
}

function fetchScopedTransactionFiles(mysqli $conn, array $transaksiIds, array $types, string $fields = 'f.*'): array
{
    $transaksiIds = array_values(array_unique(array_map('intval', array_filter($transaksiIds))));
    $types = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $types))));

    if (empty($transaksiIds) || empty($types)) {
        return [];
    }

    $escapedTypes = array_map([$conn, 'real_escape_string'], $types);
    $sql = "SELECT {$fields}
        FROM file_transaksi f
        LEFT JOIN users u ON u.id = f.uploaded_by
        LEFT JOIN detail_transaksi dt ON dt.id = f.detail_transaksi_id
        WHERE f.transaksi_id IN (" . implode(',', $transaksiIds) . ")
            AND f.tipe_file IN ('" . implode("','", $escapedTypes) . "')
            AND f.is_active = 1
        ORDER BY f.created_at DESC, f.id DESC";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function fetchScopedTransactionFilesForDetail(
    mysqli $conn,
    int $transaksiId,
    ?int $detailId,
    array $types,
    string $fields = 'f.*, u.nama AS nama_uploader, dt.nama_produk AS nama_produk_detail'
): array {
    $transaksiId = (int) $transaksiId;
    $detailId = normalizeTransactionDetailId($detailId);
    $types = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $types))));

    if ($transaksiId <= 0 || empty($types)) {
        return [];
    }

    $escapedTypes = array_map([$conn, 'real_escape_string'], $types);
    $joins = "LEFT JOIN users u ON u.id = f.uploaded_by
        LEFT JOIN detail_transaksi dt ON dt.id = f.detail_transaksi_id";
    $where = [
        "f.transaksi_id = {$transaksiId}",
        "f.tipe_file IN ('" . implode("','", $escapedTypes) . "')",
        "f.is_active = 1",
    ];

    if ($detailId > 0) {
        if (canUseLegacyTransactionFilesForDetail($conn, $transaksiId, $detailId, $types)) {
            $where[] = "(f.detail_transaksi_id = {$detailId} OR f.detail_transaksi_id IS NULL OR f.detail_transaksi_id = 0)";
        } else {
            $where[] = "f.detail_transaksi_id = {$detailId}";
        }
    }

    $sql = "SELECT {$fields}
        FROM file_transaksi f
        {$joins}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY f.created_at DESC, f.id DESC";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function groupScopedTransactionFiles(array $files): array
{
    $grouped = [];
    foreach ($files as $file) {
        $transaksiId = (int) ($file['transaksi_id'] ?? 0);
        if ($transaksiId <= 0) {
            continue;
        }

        $detailKey = normalizeTransactionDetailId($file['detail_transaksi_id'] ?? 0);
        $grouped[$transaksiId][$detailKey][] = $file;
    }

    return $grouped;
}

function mergeScopedTransactionFiles(array $groupedByDetail): array
{
    if (empty($groupedByDetail)) {
        return [];
    }

    $merged = [];
    foreach ($groupedByDetail as $rows) {
        foreach ($rows as $row) {
            $merged[] = $row;
        }
    }

    usort($merged, static function (array $left, array $right): int {
        $leftCreated = (string) ($left['created_at'] ?? '');
        $rightCreated = (string) ($right['created_at'] ?? '');
        if ($leftCreated === $rightCreated) {
            return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
        }
        return strcmp($rightCreated, $leftCreated);
    });

    return $merged;
}

function filterScopedTransactionFilesByTypes(array $files, array $types): array
{
    $allowedTypes = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $types))));

    if (empty($allowedTypes)) {
        return [];
    }

    return array_values(array_filter($files, static function (array $file) use ($allowedTypes): bool {
        return in_array(trim((string) ($file['tipe_file'] ?? '')), $allowedTypes, true);
    }));
}

function resolveScopedTransactionFiles(mysqli $conn, array $groupedFiles, int $transaksiId, ?int $detailId, array $types): array
{
    $transaksiId = (int) $transaksiId;
    $detailKey = normalizeTransactionDetailId($detailId);
    $scoped = $groupedFiles[$transaksiId] ?? [];

    if ($transaksiId <= 0 || empty($scoped)) {
        return [];
    }

    if ($detailKey > 0 && !empty($scoped[$detailKey])) {
        return filterScopedTransactionFilesByTypes($scoped[$detailKey], $types);
    }

    if ($detailKey > 0 && !empty($scoped[0]) && canUseLegacyTransactionFilesForDetail($conn, $transaksiId, $detailKey, $types)) {
        return filterScopedTransactionFilesByTypes($scoped[0], $types);
    }

    if ($detailKey <= 0) {
        return filterScopedTransactionFilesByTypes(mergeScopedTransactionFiles($scoped), $types);
    }

    return [];
}
