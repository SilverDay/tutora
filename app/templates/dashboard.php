<?php /** @var callable $e @var callable $partial @var list<array<string,mixed>> $workshops @var list<array<string,mixed>> $sessions */ ?>
<section class="card">
    <h1>Workshops</h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <?php if ($workshops === []): ?>
        <p class="muted">No workshops yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Title</th><th>Blocks</th><th>Updated (UTC)</th></tr></thead>
            <tbody>
            <?php foreach ($workshops as $w): ?>
                <tr>
                    <td><a href="/workshops/<?= $e($w['id']) ?>"><?= $e($w['title']) ?></a></td>
                    <td><?= $e($w['block_count']) ?></td>
                    <td><?= $e(substr((string) $w['updated_at'], 0, 16)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <form method="post" action="/workshops" class="row">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>New workshop title <input type="text" name="title" required maxlength="200"></label>
        <button type="submit">Create</button>
    </form>
</section>

<section class="card">
    <h2>Sessions</h2>
    <?php if ($sessions === []): ?>
        <p class="muted">No sessions yet. Start one from a workshop.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Workshop</th><th>Status</th><th>Code</th><th>Participants</th><th>Started (UTC)</th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><a href="/sessions/<?= $e($s['id']) ?>"><?= $e($s['workshop_title_snapshot']) ?></a></td>
                    <td><?= $e($s['status']) ?></td>
                    <td><?= $s['status'] === 'ended' ? '—' : '<code>' . $e($s['join_code']) . '</code>' ?></td>
                    <td><?= $e($s['participant_count']) ?></td>
                    <td><?= $e(substr((string) $s['started_at'], 0, 16)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
