<?php /** @var callable $e @var callable $partial */ ?>
<section class="card narrow">
    <h1>Two-factor authentication</h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <form method="post" action="/login/mfa">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Code from your authenticator app
            <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code" autofocus>
        </label>
        <button type="submit">Verify</button>
    </form>
</section>
