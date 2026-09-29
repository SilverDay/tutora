<?php

declare(strict_types=1);

namespace Tutora\Http;

/**
 * Minimal router. Patterns use {name} (matches [^/]+) or {name:regex}.
 */
final class Router
{
    /** @var list<array{string,string,callable(Request):Response}> */
    private array $routes = [];

    /** @param callable(Request):Response $handler */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            static fn (array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern,
        );
        $this->routes[] = [strtoupper($method), '#^' . $regex . '$#D', $handler];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $request->path, $m) !== 1) {
                continue;
            }
            if ($method !== $request->method && !($method === 'GET' && $request->method === 'HEAD')) {
                $allowed[] = $method;
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request);
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Method not allowed', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'Not found');
    }
}
