<?php

function payrollScheduleSupportReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $canAutoMigrate = appSchemaAutoMigrateEnabled();

    if (schemaTableExists($conn, 'karyawan') && schemaColumnExists($conn, 'karyawan', 'metode_gaji')) {
        $row = schemaColumnDefinition($conn, 'karyawan', 'metode_gaji');
        $type = strtolower((string) ($row['Type'] ?? ''));
        if ($type !== '' && strpos($type, 'bagi_hasil') === false) {
            if (!$canAutoMigrate) {
                return $ready = false;
            }

            $ok = $conn->query(
                "ALTER TABLE karyawan
                 MODIFY COLUMN metode_gaji ENUM('bulanan','mingguan','borongan','bagi_hasil')
                 NOT NULL DEFAULT 'bulanan'"
            );
            if (!$ok) {
                return $ready = false;
            }
        }
    }

    if (schemaTableExists($conn, 'slip_gaji')) {
        if (!schemaColumnExists($conn, 'slip_gaji', 'metode_gaji')) {
            if (!$canAutoMigrate) {
                return $ready = false;
            }

            $ok = $conn->query("ALTER TABLE slip_gaji ADD COLUMN metode_gaji VARCHAR(30) NULL AFTER karyawan_id");
            if (!$ok) {
                return $ready = false;
            }
        }
        if (!schemaColumnExists($conn, 'slip_gaji', 'jadwal_bayar')) {
            if (!$canAutoMigrate) {
                return $ready = false;
            }

            $ok = $conn->query("ALTER TABLE slip_gaji ADD COLUMN jadwal_bayar DATE NULL AFTER periode_selesai");
            if (!$ok) {
                return $ready = false;
            }
        }
    }

    return $ready = true;
}

function payrollNormalizeMethod(string $method): string
{
    $normalized = strtolower(trim($method));
    $allowed = ['bulanan', 'mingguan', 'borongan', 'bagi_hasil'];

    return in_array($normalized, $allowed, true) ? $normalized : 'bulanan';
}

function payrollMethodLabel(string $method): string
{
    return [
        'bulanan' => 'Bulanan',
        'mingguan' => 'Mingguan',
        'borongan' => 'Borongan',
        'bagi_hasil' => 'Bagi Hasil',
    ][payrollNormalizeMethod($method)] ?? 'Bulanan';
}

function payrollScheduleRuleLabel(string $method): string
{
    return [
        'bulanan' => 'Dibayar setiap tanggal 28',
        'mingguan' => 'Dibayar setiap hari Sabtu',
        'borongan' => 'Dibayar setiap hari Sabtu',
        'bagi_hasil' => 'Dibayar setiap tanggal 5',
    ][payrollNormalizeMethod($method)] ?? 'Dibayar sesuai jadwal payroll';
}

function payrollResolveScheduledPayDate(string $method, string $periodEnd): string
{
    $method = payrollNormalizeMethod($method);
    $end = new DateTimeImmutable($periodEnd !== '' ? $periodEnd : date('Y-m-d'));

    if ($method === 'bulanan') {
        return $end->setDate((int) $end->format('Y'), (int) $end->format('n'), 28)->format('Y-m-d');
    }

    if (in_array($method, ['mingguan', 'borongan'], true)) {
        $weekday = (int) $end->format('w');
        if ($weekday === 6) {
            return $end->format('Y-m-d');
        }

        $delta = 6 - $weekday;
        if ($delta < 0) {
            $delta += 7;
        }

        return $end->modify('+' . $delta . ' days')->format('Y-m-d');
    }

    $nextMonth = $end->modify('first day of next month');
    return $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('n'), 5)->format('Y-m-d');
}

function payrollResolveSuggestedPeriod(string $method, ?string $referenceDate = null): array
{
    $method = payrollNormalizeMethod($method);
    $reference = new DateTimeImmutable(($referenceDate && trim($referenceDate) !== '') ? $referenceDate : date('Y-m-d'));

    if ($method === 'bulanan') {
        $periodStart = $reference->modify('first day of this month');
        $periodEnd = $reference->modify('last day of this month');
    } elseif (in_array($method, ['mingguan', 'borongan'], true)) {
        $weekday = (int) $reference->format('w');
        $periodEnd = $weekday === 0 ? $reference->modify('-1 day') : $reference->modify('this saturday');
        $periodStart = $periodEnd->modify('-5 days');
    } else {
        $periodStart = $reference->modify('first day of last month');
        $periodEnd = $reference->modify('last day of last month');
    }

    return [
        'periode_mulai' => $periodStart->format('Y-m-d'),
        'periode_selesai' => $periodEnd->format('Y-m-d'),
        'jadwal_bayar' => payrollResolveScheduledPayDate($method, $periodEnd->format('Y-m-d')),
        'metode_gaji' => $method,
        'metode_label' => payrollMethodLabel($method),
        'jadwal_label' => payrollScheduleRuleLabel($method),
    ];
}
