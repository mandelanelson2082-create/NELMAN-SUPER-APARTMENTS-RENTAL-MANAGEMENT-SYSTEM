<?php
declare(strict_types=1);

/** Single shared MySQLi connection. */
function db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** Run a prepared statement and return the result set as an array of assoc rows. */
function db_all(string $sql, array $params = [], string $types = ''): array
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** Run a prepared statement and return the first row (or null). */
function db_one(string $sql, array $params = [], string $types = ''): ?array
{
    $rows = db_all($sql, $params, $types);
    return $rows[0] ?? null;
}

/** Run a prepared statement (INSERT/UPDATE/DELETE) and return affected rows. */
function db_exec(string $sql, array $params = [], string $types = ''): int
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/** Run an INSERT and return the new id. */
function db_insert(string $sql, array $params = [], string $types = ''): int
{
    db_exec($sql, $params, $types);
    return (int) db()->insert_id;
}

/** Fetch a single scalar value. */
function db_value(string $sql, array $params = [], string $types = '')
{
    $row = db_one($sql, $params, $types);
    return $row ? array_values($row)[0] : null;
}
