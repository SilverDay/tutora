<?php

declare(strict_types=1);

namespace Tutora\Database;

use PDO;
use Tutora\Config;

final class ConnectionFactory
{
    public static function fromConfig(Config $config, string $prefix = 'DB_'): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->string($prefix . 'HOST', '127.0.0.1'),
            $config->int($prefix . 'PORT', 3306),
            $config->string($prefix . 'NAME'),
        );
        return self::create($dsn, $config->string($prefix . 'USER'), $config->string($prefix . 'PASSWORD'));
    }

    public static function create(string $dsn, string $user, string $password): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // real server-side prepared statements
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        return $pdo;
    }
}
