<?php

require_once __DIR__ . '/database_settings.php';

try {
    $db = iconic_database_settings();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($db['host'], $db['user'], $db['password'], $db['name'], $db['port']);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    iconic_database_unavailable('connection failed');
}
unset($db);
