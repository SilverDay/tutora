<?php

declare(strict_types=1);

namespace Tutora\Realtime;

use Tutora\Security\Logger;

/**
 * Sends broadcasts to the relay's internal endpoint. Delivery is best effort: the HTTP
 * state endpoints are authoritative and clients re-fetch on reconnect or revision gaps,
 * so a relay outage degrades latency, not correctness. Failures are logged by message
 * type only (never payloads).
 */
final class RelayBroadcaster implements Broadcaster
{
    /** @var callable(string,string,string):int */
    private $post;

    /**
     * @param (callable(string $url, string $body, string $secret):int)|null $post test seam returning HTTP status
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly Logger $logger,
        ?callable $post = null,
        private readonly int $timeoutMs = 1000,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('RELAY_INTERNAL_SECRET must be at least 32 characters');
        }
        $this->post = $post ?? $this->curlPost(...);
    }

    public function broadcast(int $sessionId, array $message, ?string $targetRole = null): void
    {
        $body = ['session_id' => $sessionId, 'message' => $message];
        if ($targetRole !== null) {
            $body['target_role'] = $targetRole;
        }
        $type = is_string($message['type'] ?? null) ? $message['type'] : '?';
        try {
            $status = ($this->post)(rtrim($this->baseUrl, '/') . '/internal/broadcast', json_encode($body, JSON_THROW_ON_ERROR), $this->secret);
            if ($status !== 202) {
                $this->logger->warning('Relay broadcast not accepted', ['status' => $status, 'type' => $type, 'session_id' => $sessionId]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Relay broadcast failed', ['type' => $type, 'session_id' => $sessionId, 'error' => $e::class]);
        }
    }

    private function curlPost(string $url, string $body, string $secret): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            // internal endpoint: never route through an outbound proxy
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false) {
            throw new \RuntimeException('connection failed');
        }
        return $status;
    }
}
