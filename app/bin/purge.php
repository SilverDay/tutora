<?php

declare(strict_types=1);

/**
 * Retention purge (run daily by deploy/systemd/tutora-purge.timer):
 * expired session data, expired pending signups, stale rate-limit rows.
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
