<?php /** @var callable $e @var list<string> $errors */ ?>
<?php if (!empty($errors)): ?>
    <div class="alert" role="alert">
        <ul><?php foreach ($errors as $err): ?><li><?= $e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
