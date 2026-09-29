<?php

declare(strict_types=1);

namespace Tutora\Auth;

/** Abstraction over server-side session storage (native PHP sessions in production). */
interface SessionStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** New session ID, data kept (call on every privilege change). */
    public function regenerate(): void;

    /** Destroy all data and the session itself. */
    public function destroy(): void;
}
