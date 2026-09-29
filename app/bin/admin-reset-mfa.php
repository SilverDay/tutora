<?php

declare(strict_types=1);

/**
 * Audited MFA reset for a tutor account.
 * Usage: php bin/admin-reset-mfa.php <email> --operator="Name" --reason="Ticket 123: lost phone" [--yes]
 */

require __DIR__ . '/../vendor/autoload.php';

use Tutora\App;
use Tutora\Config;

$email = '';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(operator|reason)=(.*)$/s', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2];
    } elseif ($arg === '--yes') {
        $opts['yes'] = true;
    } elseif ($email === '' && !str_starts_with($arg, '-')) {
        $email = $arg;
    } else {
        $email = '';
        break;
    }
}
if ($email === '' || !isset($opts['operator'], $opts['reason'])) {
    fwrite(STDERR, "Usage: php bin/admin-reset-mfa.php <email> --operator=\"Name\" --reason=\"...\" [--yes]\n");
    exit(2);
}
if (!isset($opts['yes'])) {
    fwrite(STDOUT, "Reset two-factor authentication for {$email}? Type the address again to confirm: ");
    if (trim((string) fgets(STDIN)) !== $email) {
        fwrite(STDERR, "Aborted.\n");
        exit(1);
    }
}
$config = Config::fromEnvironment(__DIR__ . '/../../.env');
$reset = App::adminMfaReset($config);
try {
    $ok = $reset->reset($email, (string) $opts['operator'], (string) $opts['reason']);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
fwrite($ok ? STDOUT : STDERR, $ok ? "MFA reset; the tutor has been notified and must enrol again.\n" : "No account with that address.\n");
exit($ok ? 0 : 1);
