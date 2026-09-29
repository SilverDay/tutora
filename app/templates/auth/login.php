<?php /** @var callable $e */ ?>
<section class="card narrow">
    <h1>Sign in</h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <form method="post" action="/login" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Email <input type="email" name="email" value="<?= $e($email) ?>" required maxlength="254" autocomplete="username"></label>
        <label>Password <input type="password" name="password" required maxlength="512" autocomplete="current-password"></label>
        <button type="submit">Sign in</button>
    </form>
    <p class="muted">No account yet? <a href="/signup">Create one</a></p>
</section>
