<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesParams(): void
    {
        $r = new Router();
        $r->add('GET', '/session/{id:\d+}/state', static fn (Request $q) => Response::json(['id' => $q->intParam('id')]));
        $resp = $r->dispatch(new Request('GET', '/session/42/state'));
        self::assertSame('{"id":42}', $resp->body);
    }

    public function testCharacterClassPlaceholder(): void
    {
        $r = new Router();
        $r->add('POST', '/quiz/{question:[A-Za-z0-9_-]+}/answer', static fn (Request $q) => new Response(200, $q->param('question')));
        self::assertSame('q-1_a', $r->dispatch(new Request('POST', '/quiz/q-1_a/answer'))->body);
        $this->expectException(HttpException::class);
        $r->dispatch(new Request('POST', '/quiz/q%201/answer'));
    }

    public function testAnchoredMatch(): void
    {
        $r = new Router();
        $r->add('GET', '/a', static fn () => new Response());
        $this->expectException(HttpException::class);
        $r->dispatch(new Request('GET', '/a/../b'));
    }

    public function testMethodNotAllowed(): void
    {
        $r = new Router();
        $r->add('POST', '/join', static fn () => new Response());
        try {
            $r->dispatch(new Request('GET', '/join'));
            self::fail('expected exception');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('POST', $e->headers['Allow']);
        }
    }

    public function testRedirectIsLocalOnly(): void
    {
        self::assertSame('/', Response::redirect('https://evil.example')->headers['Location']);
        self::assertSame('/', Response::redirect('//evil.example')->headers['Location']);
        self::assertSame('/', Response::redirect('/\\evil.example')->headers['Location']);
        self::assertSame('/dashboard', Response::redirect('/dashboard')->headers['Location']);
    }
}
