<?php

function transactionWorkflowSupportReady(mysqli $conn): bool
{
    static $ensured = null;
    if ($ensured !== null) {
        return $ensured;
    }

    if (!schemaTableExists($conn, 'transaksi')) {
        return $ensured = false;
    }

    if (!schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
        if (!appSchemaAutoMigrateEnabled()) {
            return $ensured = false;
        }

        $ok = $conn->query("ALTER TABLE transaksi ADD COLUMN workflow_step VARCHAR(30) NOT NULL DEFAULT 'production' AFTER status");
        if (!$ok) {
            return $ensured = false;
        }
    }

    transactionWorkflowRepairStoredSteps($conn);

    return $ensured = schemaColumnExists($conn, 'transaksi', 'workflow_step');
}

function transactionWorkflowNormalizeStep(string $step): string
{
    $normalized = strtolower(trim($step));
    if ($normalized === 'design') {
        return 'production';
    }

    $allowed = ['draft', 'cashier', 'production', 'done', 'cancelled'];

    return in_array($normalized, $allowed, true) ? $normalized : 'production';
}

function transactionWorkflowItemsRequireDesign(array $items): bool
{
    foreach ($items as $item) {
        $category = strtolower(trim((string) ($item['kat_tipe'] ?? $item['kategori_tipe'] ?? '')));
        if (in_array($category, ['printing', 'apparel'], true)) {
            return true;
        }
    }

    return false;
}

function transactionWorkflowRemainingSql(mysqli $conn, string $tableAlias = 't'): string
{
    $prefix = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';
    $totalExpr = "COALESCE({$prefix}total, 0)";
    $paidExpr = "LEAST(COALESCE({$prefix}bayar, 0), {$totalExpr})";
    $fallbackExpr = "GREATEST(0, {$totalExpr} - {$paidExpr})";

    if (!schemaColumnExists($conn, 'transaksi', 'sisa_bayar')) {
        return $fallbackExpr;
    }

    return "GREATEST(COALESCE({$prefix}sisa_bayar, 0), {$fallbackExpr})";
}

function transactionWorkflowProductionActiveJobCondition(mysqli $conn, string $produksiAlias = 'pr'): string
{
    if (!schemaTableExists($conn, 'produksi') || !schemaColumnExists($conn, 'produksi', 'status')) {
        return '1 = 1';
    }

    $prefix = $produksiAlias !== '' ? rtrim($produksiAlias, '.') . '.' : '';

    return "COALESCE({$prefix}status, '') <> 'batal'";
}

function transactionWorkflowHasRelatedJobsCondition(mysqli $conn, string $transaksiAlias = 't'): string
{
    if (!schemaTableExists($conn, 'produksi')
        || !schemaColumnExists($conn, 'produksi', 'transaksi_id')
        || !schemaColumnExists($conn, 'produksi', 'tipe_dokumen')) {
        return '0 = 1';
    }

    $prefix = $transaksiAlias !== '' ? rtrim($transaksiAlias, '.') . '.' : '';
    $activeJobCondition = transactionWorkflowProductionActiveJobCondition($conn, 'pr');

    return "EXISTS (
        SELECT 1
        FROM produksi pr
        WHERE pr.transaksi_id = {$prefix}id
          AND pr.tipe_dokumen IN ('JO', 'SPK')
          AND {$activeJobCondition}
    )";
}

function transactionWorkflowAllRelatedJobsCompletedCondition(mysqli $conn, string $transaksiAlias = 't'): string
{
    if (!schemaTableExists($conn, 'produksi')
        || !schemaColumnExists($conn, 'produksi', 'transaksi_id')
        || !schemaColumnExists($conn, 'produksi', 'tipe_dokumen')
        || !schemaColumnExists($conn, 'produksi', 'status')) {
        return '0 = 1';
    }

    $prefix = $transaksiAlias !== '' ? rtrim($transaksiAlias, '.') . '.' : '';
    $activeJobCondition = transactionWorkflowProductionActiveJobCondition($conn, 'pr');

    return "EXISTS (
        SELECT 1
        FROM produksi pr
        WHERE pr.transaksi_id = {$prefix}id
          AND pr.tipe_dokumen IN ('JO', 'SPK')
          AND {$activeJobCondition}
    ) AND NOT EXISTS (
        SELECT 1
        FROM produksi pr
        WHERE pr.transaksi_id = {$prefix}id
          AND pr.tipe_dokumen IN ('JO', 'SPK')
          AND {$activeJobCondition}
          AND COALESCE(pr.status, '') <> 'selesai'
    )";
}

function transactionWorkflowRepairStoredSteps(mysqli $conn): void
{
    if (!schemaTableExists($conn, 'transaksi') || !schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
        return;
    }

    $remainingExpr = transactionWorkflowRemainingSql($conn, 't');
    $hasRelatedJobsExpr = transactionWorkflowHasRelatedJobsCondition($conn, 't');
    $allRelatedJobsDoneExpr = transactionWorkflowAllRelatedJobsCompletedCondition($conn, 't');

    $conn->query(
        "UPDATE transaksi t
         SET t.workflow_step = CASE
             WHEN t.status = 'batal' THEN 'cancelled'
             WHEN t.status = 'draft' THEN 'draft'
             WHEN {$remainingExpr} > 0.000001 THEN 'cashier'
             WHEN {$hasRelatedJobsExpr} AND NOT ({$allRelatedJobsDoneExpr}) THEN 'production'
             ELSE 'done'
         END"
    );
}

function transactionWorkflowResolveAdvanceStep(array $trx): string
{
    return transactionPaymentResolveRemaining($trx) > 0.000001 ? 'cashier' : 'production';
}

function transactionWorkflowAdvanceActionLabel(array $trx): string
{
    return transactionWorkflowResolveAdvanceStep($trx) === 'cashier'
        ? 'Kirim ke Kasir'
        : 'Lanjut ke Produksi';
}

function transactionWorkflowFetchTransactionContext(mysqli $conn, int $transaksiId): array
{
    if ($transaksiId <= 0 || !schemaTableExists($conn, 'transaksi')) {
        return [];
    }

    $fields = ['id', 'total', 'bayar', 'status'];
    if (schemaColumnExists($conn, 'transaksi', 'sisa_bayar')) {
        $fields[] = 'sisa_bayar';
    }
    if (schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
        $fields[] = 'workflow_step';
    }

    $stmt = $conn->prepare(
        "SELECT " . implode(', ', $fields) . "
         FROM transaksi
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $transaksiId);
    $stmt->execute();
    $trx = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $trx;
}

function transactionWorkflowResolveStep(array $trx): string
{
    $columnValue = trim((string) ($trx['workflow_step'] ?? ''));
    if ($columnValue !== '') {
        $step = transactionWorkflowNormalizeStep($columnValue);
        if ($step === 'production' && transactionPaymentResolveRemaining($trx) > 0.000001) {
            return 'cashier';
        }

        return $step;
    }

    $status = strtolower(trim((string) ($trx['status'] ?? '')));
    if ($status === 'batal') {
        return 'cancelled';
    }
    if ($status === 'draft') {
        return 'draft';
    }

    return transactionPaymentResolveRemaining($trx) > 0.000001 ? 'cashier' : 'production';
}

function transactionWorkflowLabel(string $step): string
{
    return [
        'draft' => 'Draft Invoice',
        'cashier' => 'Menunggu Kasir',
        'production' => 'Produksi',
        'done' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ][transactionWorkflowNormalizeStep($step)] ?? 'Produksi';
}

function transactionWorkflowBadgeClass(string $step): string
{
    return [
        'draft' => 'secondary',
        'cashier' => 'warning',
        'production' => 'primary',
        'done' => 'success',
        'cancelled' => 'danger',
    ][transactionWorkflowNormalizeStep($step)] ?? 'secondary';
}

function transactionWorkflowSetStep(mysqli $conn, int $transaksiId, string $step): bool
{
    if ($transaksiId <= 0 || !transactionWorkflowSupportReady($conn)) {
        return false;
    }

    $normalizedStep = transactionWorkflowNormalizeStep($step);
    $stmt = $conn->prepare("UPDATE transaksi SET workflow_step = ? WHERE id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $normalizedStep, $transaksiId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function transactionWorkflowCanCollectPayment(array $trx): bool
{
    return in_array(transactionWorkflowResolveStep($trx), ['draft', 'cashier'], true)
        && transactionPaymentCanBeSettled($trx);
}

function transactionWorkflowCanProcessPayment(mysqli $conn, int $transaksiId): bool
{
    $trx = transactionWorkflowFetchTransactionContext($conn, $transaksiId);
    return $trx ? transactionWorkflowCanCollectPayment($trx) : false;
}

function transactionWorkflowMoveToProduction(mysqli $conn, int $transaksiId): bool
{
    $trx = transactionWorkflowFetchTransactionContext($conn, $transaksiId);
    if (!$trx) {
        return false;
    }

    return transactionWorkflowSetStep($conn, $transaksiId, transactionWorkflowResolveAdvanceStep($trx));
}

function transactionWorkflowIsProductionOpen(array $trx): bool
{
    return in_array(transactionWorkflowResolveStep($trx), ['production', 'done'], true);
}
