<?php

function &schemaRuntimeCache(): array
{
    static $cache = [
        'table_exists' => [],
        'column_exists' => [],
        'table_columns' => [],
        'column_definition' => [],
    ];

    return $cache;
}

function schemaCanCacheNegativeResults(): bool
{
    return function_exists('appSchemaAutoMigrateEnabled') ? !appSchemaAutoMigrateEnabled() : true;
}

function schemaTableExists(mysqli $conn, string $table): bool
{
    $table = strtolower(trim($table));
    if ($table === '') {
        return false;
    }

    $cache = &schemaRuntimeCache();
    $canCacheNegative = schemaCanCacheNegativeResults();

    if (array_key_exists($table, $cache['table_exists'])) {
        return (bool) $cache['table_exists'][$table];
    }

    if ($canCacheNegative && array_key_exists($table, $cache['table_columns'])) {
        return !empty($cache['table_columns'][$table]);
    }

    $escapedTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");
    $exists = (bool) ($result && $result->num_rows > 0);

    if ($result instanceof mysqli_result) {
        $result->free();
    }

    if ($exists || $canCacheNegative) {
        $cache['table_exists'][$table] = $exists;
    }

    return $exists;
}

function schemaColumnExists(mysqli $conn, string $table, string $column): bool
{
    $table = strtolower(trim($table));
    $column = strtolower(trim($column));
    if ($table === '' || $column === '') {
        return false;
    }

    $cache = &schemaRuntimeCache();
    $canCacheNegative = schemaCanCacheNegativeResults();
    $columnCacheKey = $table . '.' . $column;

    if (array_key_exists($columnCacheKey, $cache['column_exists'])) {
        return (bool) $cache['column_exists'][$columnCacheKey];
    }

    if (isset($cache['table_columns'][$table]) && in_array($column, $cache['table_columns'][$table], true)) {
        return $cache['column_exists'][$columnCacheKey] = true;
    }

    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    $exists = (bool) ($result && $result->num_rows > 0);

    if ($result instanceof mysqli_result) {
        $result->free();
    }

    if ($exists) {
        $cache['column_exists'][$columnCacheKey] = true;
        if (isset($cache['table_columns'][$table]) && !in_array($column, $cache['table_columns'][$table], true)) {
            $cache['table_columns'][$table][] = $column;
            sort($cache['table_columns'][$table]);
        }
        return true;
    }

    if ($canCacheNegative) {
        $cache['column_exists'][$columnCacheKey] = false;
    }

    return false;
}

function schemaFetchScalar(mysqli $conn, string $sql, string $types = '', ...$params)
{
    if ($types === '') {
        $result = $conn->query($sql);
        if (!$result) {
            return 0;
        }

        $row = $result->fetch_row();
        return $row[0] ?? 0;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row[0] ?? 0;
}

function schemaFetchAllAssoc(mysqli $conn, string $sql, string $types = '', ...$params): array
{
    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function schemaFetchAssoc(mysqli $conn, string $sql, string $types = '', ...$params): array
{
    if ($types === '') {
        $result = $conn->query($sql);
        if (!$result) {
            return [];
        }

        $row = $result->fetch_assoc();
        return is_array($row) ? $row : [];
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : [];
}

function schemaFetchCount(mysqli $conn, string $sql, string $types = '', ...$params): int
{
    return (int) schemaFetchScalar($conn, $sql, $types, ...$params);
}

function schemaTableColumns(mysqli $conn, string $table): array
{
    $table = strtolower(trim($table));
    if ($table === '') {
        return [];
    }

    $cache = &schemaRuntimeCache();
    $canCacheNegative = schemaCanCacheNegativeResults();
    if ($canCacheNegative && array_key_exists($table, $cache['table_columns'])) {
        return $cache['table_columns'][$table];
    }

    if (!schemaTableExists($conn, $table)) {
        return $canCacheNegative ? ($cache['table_columns'][$table] = []) : [];
    }

    $escapedTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}`");
    if (!$result) {
        return [];
    }

    $columns = [];
    while ($column = $result->fetch_assoc()) {
        $field = strtolower(trim((string) ($column['Field'] ?? '')));
        if ($field !== '') {
            $columns[] = $field;
            $cache['column_exists'][$table . '.' . $field] = true;
        }
    }

    $columns = array_values(array_unique(array_filter($columns, 'strlen')));
    sort($columns);

    if ($canCacheNegative || !empty($columns)) {
        $cache['table_columns'][$table] = $columns;
        $cache['table_exists'][$table] = !empty($columns);
    }

    return $columns;
}

function schemaColumnDefinition(mysqli $conn, string $table, string $column): array
{
    $table = strtolower(trim($table));
    $column = strtolower(trim($column));
    if ($table === '' || $column === '') {
        return [];
    }

    $cache = &schemaRuntimeCache();
    $canCacheNegative = schemaCanCacheNegativeResults();
    $cacheKey = $table . '.' . $column;
    if ($canCacheNegative && array_key_exists($cacheKey, $cache['column_definition'])) {
        return $cache['column_definition'][$cacheKey];
    }

    if (!schemaTableExists($conn, $table) || !schemaColumnExists($conn, $table, $column)) {
        return $canCacheNegative ? ($cache['column_definition'][$cacheKey] = []) : [];
    }

    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    if (!$result) {
        return [];
    }

    $row = $result->fetch_assoc();
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    $definition = is_array($row) ? $row : [];
    if ($canCacheNegative || !empty($definition)) {
        $cache['column_definition'][$cacheKey] = $definition;
    }

    return $definition;
}

function schemaCountRelatedRows(mysqli $conn, string $table, string $column, int $referenceId): int
{
    if (!schemaTableExists($conn, $table) || !schemaColumnExists($conn, $table, $column)) {
        return 0;
    }

    return (int) schemaFetchScalar(
        $conn,
        "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?",
        'i',
        $referenceId
    );
}

function schemaBuildDependencyList(mysqli $conn, int $referenceId, array $checks): array
{
    $dependencies = [];

    foreach ($checks as $label => $config) {
        $table = (string) ($config['table'] ?? '');
        $column = (string) ($config['column'] ?? '');
        if ($table === '' || $column === '') {
            continue;
        }

        $count = schemaCountRelatedRows($conn, $table, $column, $referenceId);
        if ($count > 0) {
            $dependencies[] = $label . ' (' . number_format($count) . ')';
        }
    }

    return $dependencies;
}
