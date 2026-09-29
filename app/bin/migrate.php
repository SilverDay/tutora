<?php

declare(strict_types=1);

// Usage: php bin/migrate.php
require __DIR__ . '/../vendor/autoload.php';

use Tutora\Config;
use Tutora\Database\ConnectionFactory;
use Tutora\Database\Migrator;

$config = Config::fromEnvironment(__DIR__ . '/../../.env');
$applied = (new Migrator(ConnectionFactory::fromConfig($config), __DIR__ . '/../migrations'))->migrate();
echo $applied === [] ? "Schema up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
