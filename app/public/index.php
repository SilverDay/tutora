<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tutora\App;
use Tutora\Auth\NativeSessionStore;
use Tutora\Config;
use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;

$config = Config::fromEnvironment(dirname(__DIR__, 2) . '/.env');
ini_set('display_errors', '0');

try {
    $request = Request::fromGlobals();
} catch (HttpException $e) {
    (new Response($e->status, $e->getMessage(), ['Content-Type' => 'text/plain; charset=utf-8']))->send();
    return;
}

$secure = $config->isProduction() || str_starts_with($config->string('APP_BASE_URL', ''), 'https://');
$app = new App($config, new NativeSessionStore($secure));
$app->handle($request)->send();
