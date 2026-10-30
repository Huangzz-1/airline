<?php

$connection = fn ($host) => [
    'driver' => 'mysql', 'host' => $host, 'port' => env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'airline'), 'username' => env('DB_USERNAME', 'airline'),
    'password' => env('DB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
    'options' => [PDO::ATTR_TIMEOUT => 3],
];

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => $connection(env('DB_HOST', 'db2')),
        'ops' => $connection(env('DB1_HOST', 'db1')),
        'finance' => $connection(env('DB3_HOST', 'db3')),
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
