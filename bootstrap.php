<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

const DATABASE = 'cache_deadlock';

function config(): array
{
    return [
        'driver' => 'mysql',
        'host' => 'mysql',
        'port' => 3306,
        'username' => 'root',
        'password' => 'password',
        'database' => DATABASE,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];
}

/**
 * A fresh connection. Call it after forking so each process has its own.
 */
function connect(): Connection
{
    $capsule = new Capsule();
    $capsule->addConnection(config());
    $connection = $capsule->getConnection();
    $connection->getPdo();

    return $connection;
}

/**
 * Creates the database and Laravel's default cache table.
 */
function setUpDatabase(): void
{
    $config = config();
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . DATABASE);
    $pdo->exec('DROP TABLE IF EXISTS ' . DATABASE . '.cache');
    // Same as Laravel's 0001_01_01_000001_create_cache_table migration
    $pdo->exec('CREATE TABLE ' . DATABASE . '.cache (
        `key` varchar(255) NOT NULL PRIMARY KEY,
        `value` mediumtext NOT NULL,
        `expiration` int NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
