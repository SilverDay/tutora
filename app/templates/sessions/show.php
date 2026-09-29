<?php /** @var callable $e @var callable $partial @var array<string,mixed> $session @var array<string,mixed> $state @var list<array<string,mixed>> $blocks @var list<array<string,mixed>> $snapshots */ ?>
<?php $current = $state['current_block']; ?>
<?php if ($session['status'] === 'live'): ?><script type="module" src="/assets/tutor-session.js"></script><?php endif; ?>
<?php if ($current !== null && in_array($current['type'], ['whiteboard', 'annotate'], true) && $session['status'] === 'live'): ?><script type="module" src="/assets/whiteboard.bundle.js"></script><?php endif; ?>
<section class="card">
    <h1><?= $e($session['workshop_title_snapshot']) ?></h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <?php if ($session['status'] !== 'ended'): ?>
        <p class="joincode">Join at <strong>/join</strong> with code <code><?= $e($session['join_code']) ?></code></p>
    <?php else: ?>
        <p class="muted">This session has ended. Data is kept until <?= $e(substr((string) $session['expires_at'], 0, 10)) ?> (UTC).</p>
    <?php endif; ?>
    <p class="muted">Revision <?= $e($state['session_revision']) ?> · status <?= $e($state['status']) ?>
        <?php if ($session['status'] === 'live'): ?>
            · <span id="presence" data-session-id="<?= $e($session['id']) ?>">connecting…</span>
        <?php endif; ?>
    </p>
</section>

<?php if ($current !== null): ?>
<section class="card">
    <h2>Current: <?= $e($current['type']) ?></h2>
    <?php if ($current['slide_asset_id'] !== null): ?>
        <img class="slide" src="/slides/<?= $e($current['slide_asset_id']) ?>" alt="Current slide">
    <?php elseif ($current['type'] === 'slide'): ?>
        <p class="muted">This slide image is no longer available.</p>
    <?php endif; ?>
    <p><?= $e($current['config']['prompt'] ?? $current['config']['question'] ?? '') ?></p>

    <?php if ($current['type'] === 'quiz' && $session['status'] === 'live'): ?>
        <?php $quiz = $current['state']['quiz'] ?? null; ?>
        <ol class="quiz-admin">
        <?php foreach ($current['config']['questions'] as $i => $q): ?>
            <?php $qs = $quiz['questions'][$i] ?? []; ?>
            <li>
                <strong><?= $e($q['prompt']) ?></strong>
                <span class="muted">(<?= $e($q['type']) ?><?= isset($q['time_limit_seconds']) ? ', ' . $e($q['time_limit_seconds']) . ' s' : '' ?>)</span>
                <?php if ($current['config']['pacing'] === 'tutor'): ?>
                    · <span class="muted"><?= $e($qs['status'] ?? 'PENDING') ?></span>
                    <?php foreach (['start' => 'Start', 'reveal' => 'Reveal'] as $action => $label): ?>
                        <?php if (($action === 'start' && ($qs['status'] ?? 'PENDING') === 'PENDING') || ($action === 'reveal' && ($qs['status'] ?? '') === 'OPEN')): ?>
                        <form method="post" action="/sessions/<?= $e($session['id']) ?>/quiz/<?= $e($action) ?>" class="inline">
                            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="block" value="<?= $e($current['id']) ?>">
                            <input type="hidden" name="question" value="<?= $e($q['id']) ?>">
                            <button type="submit"><?= $e($label) ?></button>
                        </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
        <?php if ($current['config']['pacing'] === 'self'): ?><p class="muted">Self-paced: participants work through the questions on their own.</p><?php endif; ?>
    <?php endif; ?>

    <?php if ($current['type'] === 'wall' && $session['status'] === 'live'): ?>
        <form method="post" action="/sessions/<?= $e($session['id']) ?>/wall/cards" class="row">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="block" value="<?= $e($current['id']) ?>">
            <label>Seed a card <input type="text" name="text" maxlength="500" required></label>
            <label>Column
                <select name="column_id">
                    <?php foreach ($current['config']['columns'] as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['label']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Add</button>
        </form>
    <?php endif; ?>

    <?php if (in_array($current['type'], ['whiteboard', 'annotate'], true) && $session['status'] === 'live'): ?>
        <div id="whiteboard" data-session-id="<?= $e($session['id']) ?>" data-block-id="<?= $e($current['id']) ?>"
             data-kind="<?= $e($current['type']) ?>" data-mode="<?= $e($current['config']['mode'] ?? 'collaborative') ?>"
             data-asset-id="<?= $e($current['slide_asset_id'] ?? '') ?>"
             data-tags="<?= $e(json_encode($current['config']['tags'] ?? [], JSON_UNESCAPED_UNICODE)) ?>"></div>
        <form method="post" action="/sessions/<?= $e($session['id']) ?>/whiteboard/clear" class="row">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="block" value="<?= $e($current['id']) ?>">
            <?php if (($current['config']['mode'] ?? 'collaborative') !== 'presenter'): ?>
                <button type="submit" class="danger">Clear board</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <div id="tutor-results" class="results" data-session-id="<?= $e($session['id']) ?>"></div>
</section>
<?php endif; ?>

<?php if ($snapshots !== []): ?>
<section class="card">
    <h2>Whiteboard snapshots</h2>
    <div class="thumbs">
    <?php foreach ($snapshots as $snap): ?>
        <figure>
            <a href="/snapshots/<?= $e($snap['id']) ?>"><img src="/snapshots/<?= $e($snap['id']) ?>" alt="Snapshot" loading="lazy"></a>
            <figcaption>Block <?= $e($snap['session_block_id']) ?> · <?= $e(substr((string) $snap['captured_at'], 11, 8)) ?> UTC</figcaption>
        </figure>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

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
            <p class="muted" data-testid="auto-end-note">Live sessions end automatically <?= (int) ($maxLiveHours ?? 24) ?> hours after they started.</p>
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
    <form method="post" action="/sessions/<?= $e($session['id']) ?>/export" class="inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="secondary">Export results (CSV)</button>
    </form>
</section>
