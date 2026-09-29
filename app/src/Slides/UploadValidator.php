<?php

declare(strict_types=1);

namespace Tutora\Slides;

use Tutora\Support\ValidationException;

/**
 * First line of defence for uploaded decks (spec: magic-byte validation + size cap).
 * The real parsing happens only inside the sandboxed converter.
 */
final class UploadValidator
{
    public const PDF = 'pdf';
    public const PPTX = 'pptx';

    /** @return self::PDF|self::PPTX */
    public static function detect(string $path, string $originalName, int $maxBytes): string
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new ValidationException(['The uploaded file is empty.']);
        }
        if ($size > $maxBytes) {
            throw new ValidationException([sprintf('The file is larger than %d MB.', intdiv($maxBytes, 1048576))]);
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $fh = fopen($path, 'rb');
        $head = (string) fread($fh, 8);
        fclose($fh);

        $type = null;
        if (str_starts_with($head, '%PDF-')) {
            $type = self::PDF;
        } elseif (str_starts_with($head, "PK\x03\x04") && self::looksLikePptx($path, $size)) {
            $type = self::PPTX;
        }
        if ($type === null) {
            throw new ValidationException(['Only PDF and PowerPoint (.pptx) files can be imported.']);
        }
        if ($ext !== $type) {
            throw new ValidationException(['The file extension does not match its content.']);
        }
        return $type;
    }

    /**
     * A PPTX is an OOXML zip whose central directory lists [Content_Types].xml and
     * ppt/presentation.xml. File names in the central directory are stored uncompressed,
     * so a bounded read of the archive tail is enough to check without unzipping.
     */
    private static function looksLikePptx(string $path, int $size): bool
    {
        $fh = fopen($path, 'rb');
        $tailLen = min($size, 262144);
        fseek($fh, $size - $tailLen);
        $tail = (string) fread($fh, $tailLen);
        fclose($fh);
        return str_contains($tail, "PK\x05\x06")
            && str_contains($tail, '[Content_Types].xml')
            && str_contains($tail, 'ppt/presentation.xml');
    }

    /** Display-only name: basename, no control chars, bounded length. */
    public static function displayName(string $original): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $name = (string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', mb_check_encoding($name, 'UTF-8') ? $name : 'upload');
        return mb_substr($name === '' ? 'upload' : $name, 0, 200, 'UTF-8');
    }
}
