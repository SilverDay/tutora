<?php /** @var callable $e @var callable $partial */ ?>
<section class="card narrow">
    <h1>Two-factor authentication</h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <form method="post" action="/login/mfa">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Code from your authenticator app
            <input type="text" name="code" pattern="[0-9 ]{6,7}|[A-Za-z2-7 \-]{16,22}" maxlength="22" required autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" autofocus>
        </label>
        <p class="hint">Lost your authenticator? Enter one of your recovery codes (XXXX-XXXX-XXXX-XXXX) instead.</p>
        <button type="submit">Verify</button>
    </form>
</section>
