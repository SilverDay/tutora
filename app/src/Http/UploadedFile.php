<?php

declare(strict_types=1);

namespace Tutora\Http;

/**
 * A file received via multipart upload. Only Request::fromGlobals() creates instances from
 * $_FILES, after is_uploaded_file() has confirmed the temp file really is an upload.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $clientName,
        public readonly int $error,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->tmpPath);
    }
}
