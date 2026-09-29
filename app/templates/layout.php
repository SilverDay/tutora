<?php /** @var callable $e @var string $content @var string $title @var string $csrf @var bool $signedIn */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'Tutora') ?> · Tutora</title>
    <meta name="csrf-token" content="<?= $e($csrf) ?>">
    <meta name="tutora-realtime" content="<?= $e($realtimeUrl ?? '') ?>">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">Tutora</a>
    <?php if ($signedIn): ?>
        <nav>
            <a href="/dashboard">Dashboard</a>
            <a href="/account/password">Account</a>
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button type="submit" class="link">Sign out</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
<?= $content /* pre-rendered, already encoded template output */ ?>
</main>
</body>
</html>
