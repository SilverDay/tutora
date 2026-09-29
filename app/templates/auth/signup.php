<?php /** @var callable $e @var callable $partial */ ?>
<section class="card narrow">
    <h1>Create your tutor account</h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <form method="post" action="/signup">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Your name <input type="text" name="display_name" value="<?= $e($name) ?>" required maxlength="100" autocomplete="name"></label>
        <label>Email <input type="email" name="email" value="<?= $e($email) ?>" required maxlength="254" autocomplete="username"></label>
        <label>Password <input type="password" name="password" required minlength="12" maxlength="128" autocomplete="new-password"></label>
        <p class="muted">At least 12 characters. Passwords found in known data breaches are rejected.</p>
        <button type="submit">Create account</button>
    </form>
    <p class="muted">Already registered? <a href="/login">Sign in</a></p>
</section>
