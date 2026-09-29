<?php

// Test-only SMTP server: one connection, optional STARTTLS with a given cert/key, records
// every command and whether TLS was active when it arrived. Writes a JSON report on exit.
// Usage: php fake_smtp_server.php <port> <cert> <key> <report> [--no-starttls] [--auth=PLAIN,LOGIN]

declare(strict_types=1);

[$_, $port, $cert, $key, $report] = $argv;
$noStartTls = in_array('--no-starttls', $argv, true);
$authOpt = array_values(array_filter($argv, static fn ($a) => str_starts_with($a, '--auth=')));
$mechs = $authOpt === [] ? 'PLAIN LOGIN' : str_replace(',', ' ', substr($authOpt[0], 7));

$ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $err, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
file_put_contents($report . '.ready', '1');
$conn = @stream_socket_accept($server, 20);
$log = ['commands' => [], 'tls' => false, 'auth' => null, 'data' => null];
if ($conn === false) {
    file_put_contents($report, json_encode($log));
    exit(1);
}
stream_set_timeout($conn, 10);
$send = static function (string $l) use ($conn): void { fwrite($conn, $l . "\r\n"); };
$send('220 fake.test ESMTP');
$tls = false;
$pendingLogin = null;
while (($line = fgets($conn)) !== false) {
    $line = rtrim($line, "\r\n");
    $log['commands'][] = ['tls' => $tls, 'line' => $line];
    if ($pendingLogin !== null) {
        $pendingLogin[] = base64_decode($line);
        if (count($pendingLogin) === 1) { $send('334 UGFzc3dvcmQ6'); } else { $log['auth'] = ['user' => $pendingLogin[0], 'pass' => $pendingLogin[1], 'tls' => $tls]; $pendingLogin = null; $send('235 ok'); }
        continue;
    }
    $verb = strtoupper(strtok($line, ' :') ?: '');
    switch ($verb) {
        case 'EHLO':
            fwrite($conn, "250-fake.test\r\n" . (!$noStartTls && !$tls ? "250-STARTTLS\r\n" : '') . ($tls || $noStartTls ? "250-AUTH {$mechs}\r\n" : '') . "250 8BITMIME\r\n");
            break;
        case 'STARTTLS':
            $send('220 go ahead');
            $tls = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
            $log['tls'] = $tls;
            if (!$tls) { break 2; }
            break;
        case 'AUTH':
            $parts = explode(' ', $line);
            if (strtoupper($parts[1] ?? '') === 'PLAIN') {
                $d = explode("\0", (string) base64_decode($parts[2] ?? ''));
                $log['auth'] = ['user' => $d[1] ?? null, 'pass' => $d[2] ?? null, 'tls' => $tls];
                $send('235 ok');
            } else {
                $pendingLogin = [];
                $send('334 VXNlcm5hbWU6');
            }
            break;
        case 'MAIL': $send('250 ok'); break;
        case 'RCPT': $send('250 ok'); break;
        case 'DATA':
            $send('354 end with .');
            $data = '';
            while (($l = fgets($conn)) !== false && $l !== ".\r\n") { $data .= $l; }
            $log['data'] = $data;
            $send('250 queued');
            break;
        case 'QUIT': $send('221 bye'); break 2;
        default: $send('500 unknown');
    }
}
file_put_contents($report, json_encode($log));
