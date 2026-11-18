<?php

function iconic_database_unavailable(string $reason): void
{
    // Never log connection strings, database exceptions, or credentials.
    error_log('IconicOnline database: ' . $reason);
    http_response_code(503);
    exit('Service temporarily unavailable.');
}

function iconic_database_settings(): array
{
    $settings = [];
    foreach (['host', 'name', 'user', 'password'] as $key) {
        $value = getenv('ICONIC_DB_' . strtoupper($key));
        if ($value === false || $value === '') {
            iconic_database_unavailable('configuration is incomplete');
        }
        $settings[$key] = $value;
    }

    $port = getenv('ICONIC_DB_PORT');
    $settings['port'] = filter_var($port === false ? '3306' : $port, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if ($settings['port'] === false || strpbrk($settings['host'] . $settings['name'], ";\r\n\0") !== false) {
        iconic_database_unavailable('configuration is invalid');
    }

    return $settings;
}
