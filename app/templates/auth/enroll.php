<?php /** @var callable $e @var callable $partial @var string $secret @var string $uri */ ?>
<script type="module" src="/assets/enroll-qr.js"></script>
<section class="card narrow">
    <h1>Set up two-factor authentication</h1>
    <p>Two-factor authentication is required for all tutor accounts. Scan this QR code with your authenticator
       app (any app supporting TOTP), or enter the setup key manually, then enter the 6-digit code it shows.</p>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <canvas id="totp-qr" class="totp-qr" data-otpauth="<?= $e($uri) ?>" role="img" aria-label="QR code containing the setup key" hidden></canvas>
    <p><strong>Setup key:</strong></p>
    <p class="secret"><code><?= $e(trim(chunk_split($secret, 4, ' '))) ?></code></p>
    <details>
        <summary>Setup link (for apps that accept an otpauth:// URI)</summary>
        <p class="secret"><code><?= $e($uri) ?></code></p>
    </details>
    <form method="post" action="/login/enroll">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>6-digit code
            <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code">
        </label>
        <button type="submit">Activate</button>
    </form>
</section>
