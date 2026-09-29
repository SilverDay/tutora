<?php

declare(strict_types=1);

/**
 * Maintenance (run hourly by deploy/systemd/tutora-purge.timer): ends sessions live for longer
 * than SESSION_MAX_LIVE_HOURS (default 24), then purges expired session data, expired pending
 * signups and stale rate-limit rows.
 * Usage: php bin/purge.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Tutora\App;
use Tutora\Config;

$config = Config::fromEnvironment(__DIR__ . '/../../.env');
$stats = App::retentionPurger($config)->run();
// counts only (no identifiers) so the journal holds no personal data
echo json_encode($stats), "\n";
exit($stats['failed'] > 0 ? 1 : 0);
