<?php

function customerSupportReady(mysqli $conn): bool
{
    static $ensured = null;
    if ($ensured !== null) {
        return $ensured;
    }

    if (!schemaTableExists($conn, 'pelanggan')) {
        if (!appSchemaAutoMigrateEnabled()) {
            return $ensured = false;
        }

        $created = $conn->query(
            "CREATE TABLE pelanggan (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                nama VARCHAR(150) NOT NULL,
                telepon VARCHAR(40) NULL,
                email VARCHAR(120) NULL,
                alamat TEXT NULL,
                is_mitra TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!$created) {
            return $ensured = false;
        }
    }

    $requiredColumns = [
        'telepon' => "ALTER TABLE pelanggan ADD COLUMN telepon VARCHAR(40) NULL",
        'email' => "ALTER TABLE pelanggan ADD COLUMN email VARCHAR(120) NULL",
        'alamat' => "ALTER TABLE pelanggan ADD COLUMN alamat TEXT NULL",
        'is_mitra' => "ALTER TABLE pelanggan ADD COLUMN is_mitra TINYINT(1) NOT NULL DEFAULT 0",
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (schemaColumnExists($conn, 'pelanggan', $column)) {
            continue;
        }

        if (!appSchemaAutoMigrateEnabled()) {
            return $ensured = false;
        }

        if (!$conn->query($sql)) {
            return $ensured = false;
        }
    }

    return $ensured = schemaColumnExists($conn, 'pelanggan', 'telepon')
        && schemaColumnExists($conn, 'pelanggan', 'email')
        && schemaColumnExists($conn, 'pelanggan', 'alamat')
        && schemaColumnExists($conn, 'pelanggan', 'is_mitra');
}
