<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Auth\ArraySessionStore;
use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Security\Csrf;

final class CsrfTest extends TestCase
{
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->csrf = new Csrf(new ArraySessionStore(), ['https://tutora.app']);
    }

    public function testGetIsNotChecked(): void
    {
        $this->csrf->verify(new Request('GET', '/'));
        $this->addToAssertionCount(1);
    }

    public function testValidFormTokenAndHeaderAccepted(): void
    {
        $t = $this->csrf->token();
        $this->csrf->verify(new Request('POST', '/x', post: ['_csrf' => $t]));
        $this->csrf->verify(new Request('POST', '/x', headers: ['x-csrf-token' => $t, 'origin' => 'https://tutora.app']));
        $this->addToAssertionCount(2);
    }

    public function testMissingTokenRejected(): void
    {
        $this->csrf->token();
        $this->expectException(HttpException::class);
        $this->csrf->verify(new Request('POST', '/x'));
    }

    public function testWrongTokenRejected(): void
    {
        $this->csrf->token();
        $this->expectException(HttpException::class);
        $this->csrf->verify(new Request('DELETE', '/x', post: ['_csrf' => 'nope']));
    }

    public function testForeignOriginRejectedEvenWithToken(): void
    {
        $t = $this->csrf->token();
        $this->expectException(HttpException::class);
        $this->csrf->verify(new Request('POST', '/x', post: ['_csrf' => $t], headers: ['origin' => 'https://evil.example']));
    }

    public function testNoTokenInSessionRejected(): void
    {
        $this->expectException(HttpException::class);
        $this->csrf->verify(new Request('POST', '/x', post: ['_csrf' => '']));
    }
}
