<?php /** @var callable $e @var array<string,mixed> $session @var array<string,mixed> $state @var list<array<string,mixed>> $blocks */ ?>
<section class="card">
    <h1><?= $e($session['workshop_title_snapshot']) ?></h1>
    <?php if ($session['status'] !== 'ended'): ?>
        <p class="joincode">Join at <strong>/join</strong> with code <code><?= $e($session['join_code']) ?></code></p>
    <?php else: ?>
        <p class="muted">This session has ended. Data is kept until <?= $e(substr((string) $session['expires_at'], 0, 10)) ?> (UTC).</p>
    <?php endif; ?>
    <p class="muted">Revision <?= $e($state['session_revision']) ?> · status <?= $e($state['status']) ?></p>
</section>

<section class="card">
    <h2>Sequence</h2>
    <ol class="blocks">
    <?php foreach ($blocks as $b): ?>
        <li class="<?= (int) $b['id'] === (int) $state['current_session_block_id'] ? 'current' : '' ?>">
            <?php if ($session['status'] === 'live'): ?>
                <form method="post" action="/sessions/<?= $e($session['id']) ?>/navigate" class="inline">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="session_block_id" value="<?= $e($b['id']) ?>">
                    <button type="submit" class="link"><?= $e($b['block_type']) ?></button>
                </form>
            <?php else: ?>
                <?= $e($b['block_type']) ?>
            <?php endif; ?>
            <span class="muted"><?= $e($b['config_snapshot']['prompt'] ?? $b['config_snapshot']['question'] ?? '') ?></span>
        </li>
    <?php endforeach; ?>
    </ol>
    <?php if ($session['status'] === 'live'): ?>
        <div class="row">
            <?php foreach (['prev' => 'Previous', 'next' => 'Next'] as $dir => $label): ?>
                <form method="post" action="/sessions/<?= $e($session['id']) ?>/navigate" class="inline">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="direction" value="<?= $e($dir) ?>">
                    <button type="submit"><?= $e($label) ?></button>
                </form>
            <?php endforeach; ?>
            <form method="post" action="/sessions/<?= $e($session['id']) ?>/end" class="inline">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button type="submit" class="danger">End session</button>
            </form>
        </div>
    <?php else: ?>
        <form method="post" action="/sessions/<?= $e($session['id']) ?>/delete">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="danger">Delete session data now</button>
        </form>
    <?php endif; ?>
</section>
