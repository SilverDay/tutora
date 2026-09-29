<?php

declare(strict_types=1);

namespace Tutora\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // only local, absolute-path redirects (no open redirect)
        if (!str_starts_with($location, '/') || str_starts_with($location, '//') || str_contains($location, '\\')) {
            $location = '/';
        }
        return new self($status, '', ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value));
        }
        echo $this->body;
    }
}
