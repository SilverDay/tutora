<?php

declare(strict_types=1);

/**
 * Regenerates the PHP-minted token vectors used by the relay (Go) and whiteboard (Node)
 * tests, so both verify exactly what Tutora\Security\HmacToken produces.
 * Deterministic (fixed keys and time): php tests/Support/generate_token_vectors.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Tutora\Security\HmacToken;
use Tutora\Support\FrozenClock;

$clock = new FrozenClock('@1772359200');
$now = $clock->now()->getTimestamp();
$write = static function (string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "wrote {$file}\n";
};

$relayKey = str_repeat("\x11", 32);
$write(__DIR__ . '/../../../relay/internal/relay/testdata/php_tokens.json', [
    'key_hex' => bin2hex($relayKey),
    'now_unix' => $now,
    'participant' => (new HmacToken($relayKey, HmacToken::AUD_RELAY, $clock))->issue(['sid' => 42, 'actor' => '0123456789abcdef0123456789abcdef', 'role' => 'participant'], 60),
    'tutor' => (new HmacToken($relayKey, HmacToken::AUD_RELAY, $clock))->issue(['sid' => 42, 'actor' => 'tutor', 'role' => 'tutor'], 60),
    'whiteboard_audience' => (new HmacToken($relayKey, HmacToken::AUD_WHITEBOARD, $clock))->issue(['sid' => 42, 'actor' => 'tutor', 'role' => 'tutor'], 60),
]);

$wbKey = str_repeat("\x22", 32);
$write(__DIR__ . '/../../../whiteboard/test/php_tokens.json', [
    'key_hex' => bin2hex($wbKey),
    'now_unix' => $now,
    'participant' => (new HmacToken($wbKey, HmacToken::AUD_WHITEBOARD, $clock))->issue(['sid' => 7, 'bid' => 70, 'actor' => '0123456789abcdef0123456789abcdef', 'role' => 'participant', 'kind' => 'annotate', 'tags' => ['t1', 't2']], 60),
    'relay_audience' => (new HmacToken($wbKey, HmacToken::AUD_RELAY, $clock))->issue(['sid' => 7, 'bid' => 70, 'actor' => 'tutor', 'role' => 'tutor', 'kind' => 'whiteboard', 'tags' => []], 60),
]);
