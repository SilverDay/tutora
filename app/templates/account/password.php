<?php /** @var callable $e @var callable $partial @var bool $done @var int $recoveryRemaining */ ?>
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
<section class="card narrow">
    <h2>Recovery codes</h2>
    <p data-testid="recovery-remaining">You have <strong><?= (int) $recoveryRemaining ?></strong> unused recovery code<?= $recoveryRemaining === 1 ? '' : 's' ?>.</p>
    <p>Generating new codes invalidates all existing ones.</p>
    <form method="post" action="/account/recovery-codes">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Current password <input type="password" name="current_password" required maxlength="512" autocomplete="current-password"></label>
        <label>Authentication code <input type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code"></label>
        <button type="submit">Generate new recovery codes</button>
    </form>
</section>
