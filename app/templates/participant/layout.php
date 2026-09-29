<?php /** @var callable $e @var string $content @var string $title */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title><?= $e($title ?? 'Tutora') ?> · Tutora</title>
    <meta name="tutora-realtime" content="<?= $e($realtimeUrl ?? '') ?>">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/app.css">
    <script type="module" src="/assets/participant.js"></script>
</head>
<body>
<main class="container">
<?= $content /* pre-rendered, already encoded template output */ ?>
</main>
</body>
</html>
