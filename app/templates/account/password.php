<?php /** @var callable $e @var callable $partial @var bool $done */ ?>
<section class="card narrow">
    <h1>Change password</h1>
    <?php if ($done): ?><div class="notice" role="status">Your password has been changed.</div><?php endif; ?>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <form method="post" action="/account/password">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Current password <input type="password" name="current_password" required maxlength="512" autocomplete="current-password"></label>
        <label>New password <input type="password" name="new_password" required minlength="12" maxlength="128" autocomplete="new-password"></label>
        <label>Authentication code <input type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code"></label>
        <button type="submit">Change password</button>
    </form>
</section>
