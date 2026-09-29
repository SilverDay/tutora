<?php /** @var callable $e @var callable $partial */ ?>
<script type="module" src="/assets/verify.js"></script>
<section class="card narrow">
    <h1>Confirm your account</h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <form method="post" action="/verify-email" id="verify-form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="token" id="verify-token" value="<?= $e($token ?? '') ?>">
        <label>Password you chose at signup <input type="password" name="password" required maxlength="512" autocomplete="current-password"></label>
        <button type="submit">Confirm account</button>
    </form>
    <noscript><p class="alert">Please enable JavaScript to confirm your account.</p></noscript>
    <p class="muted" id="verify-missing" hidden>This page must be opened from the link in your confirmation email.</p>
</section>
