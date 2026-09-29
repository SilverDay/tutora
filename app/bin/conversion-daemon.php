<?php

declare(strict_types=1);

/**
 * Supervised long-running conversion daemon (spec: not a cron trigger; polls every 1-2 s).
 * Run under systemd as a dedicated non-root user (see deploy/systemd/tutora-converter.service).
 *
 * Usage: php bin/conversion-daemon.php [--once] [--allow-root]
 */

require __DIR__ . '/../vendor/autoload.php';

use Tutora\Config;
use Tutora\Database\ConnectionFactory;
use Tutora\Security\Logger;
use Tutora\Slides\ConversionWorker;
use Tutora\Slides\DockerConverterRunner;
use Tutora\Slides\SlideStorage;
use Tutora\Support\SystemClock;

$once = in_array('--once', $argv, true);
if (function_exists('posix_getuid') && posix_getuid() === 0 && !in_array('--allow-root', $argv, true)) {
    fwrite(STDERR, "Refusing to run as root. Use a dedicated service user (or --allow-root for local development).\n");
    exit(1);
}

$config = Config::fromEnvironment(__DIR__ . '/../../.env');
$logger = new Logger();
$optional = static fn (string $k): ?string => $config->string($k, '') === '' ? null : $config->string($k);
$worker = new ConversionWorker(
    ConnectionFactory::fromConfig($config),
    new SlideStorage($config->string('STORAGE_PATH')),
    new DockerConverterRunner(
        $config->string('CONVERTER_IMAGE', 'tutora-converter:latest'),
        $config->string('CONVERTER_RUNTIME', 'docker'),
        $config->string('CONVERTER_USER', '65532:65532'),
        $config->string('CONVERTER_MEMORY', '1g'),
        $config->string('CONVERTER_CPUS', '1'),
        $config->int('CONVERTER_PIDS_LIMIT', 256),
        $optional('CONVERTER_SECCOMP_PROFILE'),
        $optional('CONVERTER_APPARMOR_PROFILE'),
    ),
    new SystemClock(),
    $logger,
    $config->int('CONVERTER_TIMEOUT', 120),
    $config->int('CONVERTER_MAX_PAGES', 300),
);

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running, $logger): void {
        $running = false;
        $logger->info('Conversion daemon stopping after current job');
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$logger->info('Conversion daemon started');
$lastMaintenance = 0;
do {
    if (time() - $lastMaintenance >= 60) {
        $worker->recoverStale();
        $worker->purgeFailedSources();
        $lastMaintenance = time();
    }
    $job = $worker->processNext();
    if ($job === null && !$once) {
        usleep(1_500_000);
    }
} while ($running && !$once);
