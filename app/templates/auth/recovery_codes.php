<?php /** @var callable $e @var list<string> $codes */ ?>
<section class="card narrow">
    <h1>Save your recovery codes</h1>
    <p>If you lose access to your authenticator app, each of these codes lets you sign in once instead of
        an authentication code. <strong>They are shown only now.</strong> Store them somewhere safe, for example
        in a password manager. Generating new codes invalidates these.</p>
    <ul class="recovery-codes" data-testid="recovery-codes">
        <?php foreach ($codes as $code): ?><li><code><?= $e($code) ?></code></li><?php endforeach; ?>
    </ul>
    <p><a class="button" href="/dashboard">I have saved my codes — continue</a></p>
</section>
