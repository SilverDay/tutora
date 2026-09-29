<?php

declare(strict_types=1);

namespace Tutora\Http;

/**
 * Immutable HTTP request. The client IP is REMOTE_ADDR only: Apache runs PHP directly
 * (no proxy in front of PHP), so X-Forwarded-For is never trusted.
 */
final class Request
{
    /** @var array<string,string> route parameters, set by the router */
    public array $params = [];

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers lower-cased header names
     * @param array<string,string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $body = '',
        public readonly string $clientIp = '0.0.0.0',
    ) {
    }

    public static function fromGlobals(int $maxBodyBytes = 1_048_576): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $body = (string) file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if (strlen($body) > $maxBodyBytes) {
            throw new HttpException(413, 'Request body too large');
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $_POST,
            $headers,
            array_map('strval', array_filter($_COOKIE, 'is_string')),
            $body,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isStateChanging(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $ct = (string) $this->header('content-type');
        if (!str_starts_with(strtolower($ct), 'application/json')) {
            throw new HttpException(415, 'Expected application/json');
        }
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Malformed JSON');
        }
        if (!is_array($data)) {
            throw new HttpException(400, 'Expected a JSON object');
        }
        return $data;
    }

    public function input(string $key): ?string
    {
        $v = $this->post[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? throw new \LogicException("Missing route parameter {$key}");
    }

    public function intParam(string $key): int
    {
        $v = $this->param($key);
        if (!ctype_digit($v) || strlen($v) > 19) {
            throw new HttpException(404, 'Not found');
        }
        return (int) $v;
    }
}
