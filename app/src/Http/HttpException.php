<?php

declare(strict_types=1);

namespace Tutora\Http;

/** An error whose message is safe to show to the client. */
final class HttpException extends \RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, string $publicMessage, public readonly array $headers = [])
    {
        parent::__construct($publicMessage, $status);
    }
}
