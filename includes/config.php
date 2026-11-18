<?php

require_once __DIR__ . '/database_settings.php';

try {
    $db = iconic_database_settings();
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
    $dbh = new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    iconic_database_unavailable('connection failed');
}
unset($db, $dsn);
