<?php

declare(strict_types=1);

namespace Tutora\Whiteboard;

use Tutora\Security\Logger;

final class SidecarWhiteboardModeration implements WhiteboardModeration
{
    /** @var callable(string,string,string):int */
    private $post;

    /** @param (callable(string $url, string $body, string $secret):int)|null $post test seam */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
        private readonly Logger $logger,
        ?callable $post = null,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('WHITEBOARD_INTERNAL_SECRET must be at least 32 characters');
        }
        $this->post = $post ?? self::curlPost(...);
    }

    public function clear(int $sessionId, int $blockId): void
    {
        $this->call(['session_id' => $sessionId, 'action' => 'clear', 'session_block_id' => $blockId]);
    }

    public function removeActor(int $sessionId, string $actorHex): void
    {
        $this->call(['session_id' => $sessionId, 'action' => 'remove_actor', 'actor_id' => $actorHex]);
    }

    public function endSession(int $sessionId): void
    {
        $this->call(['session_id' => $sessionId, 'action' => 'end_session']);
    }

    public function dropSession(int $sessionId): void
    {
        $this->call(['session_id' => $sessionId, 'action' => 'drop_session']);
    }

    public function revokeTutor(int $sessionId): void
    {
        $this->call(['session_id' => $sessionId, 'action' => 'revoke_tutor']);
    }

    /** @param array<string,int|string> $body */
    private function call(array $body): void
    {
        try {
            $status = ($this->post)(rtrim($this->baseUrl, '/') . '/internal/moderate', json_encode($body, JSON_THROW_ON_ERROR), $this->secret);
            if ($status !== 200) {
                $this->logger->warning('Whiteboard moderation not accepted', ['status' => $status, 'action' => $body['action']]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Whiteboard moderation failed', ['action' => $body['action'], 'error' => $e::class]);
        }
    }

    private static function curlPost(string $url, string $body, string $secret): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $secret],
            CURLOPT_TIMEOUT_MS => 2000, CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
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
