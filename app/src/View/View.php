<?php

declare(strict_types=1);

namespace Tutora\View;

use Tutora\Http\Response;

/**
 * Plain-PHP templates. Every template receives $e (HTML encoder) and must use it for all
 * dynamic output; raw output is only used for the pre-rendered $content in the layout.
 */
final class View
{
    /** @param array<string,mixed> $shared variables available to every template (e.g. csrf token) */
    public function __construct(private readonly string $directory, private array $shared = [])
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $vars */
    public function render(string $template, array $vars = [], int $status = 200, string $layout = 'layout'): Response
    {
        $content = $this->fetch($template, $vars);
        $page = $this->fetch($layout, $vars + ['content' => $content]);
        return Response::html($page, $status);
    }

    /** @param array<string,mixed> $vars */
    public function fetch(string $template, array $vars = []): string
    {
        if (preg_match('#^[a-z0-9_/]+$#', $template) !== 1) {
            throw new \InvalidArgumentException('Invalid template name');
        }
        $file = $this->directory . '/' . $template . '.php';
        $vars = $vars + $this->shared + [
            'e' => \Tutora\Security\Html::e(...),
            'partial' => fn (string $name, array $partialVars = []): string => $this->fetch($name, $partialVars),
        ];
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $t) {
                ob_end_clean();
                throw $t;
            }
        })($file, $vars);
    }
}
