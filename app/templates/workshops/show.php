<?php /** @var callable $e @var callable $partial @var array<string,mixed> $workshop @var list<array<string,mixed>> $blocks @var list<string> $types */ ?>
<section class="card">
    <h1><?= $e($workshop['title']) ?></h1>
    <?= $partial('_errors', ['errors' => $errors]) ?>
    <form method="post" action="/workshops/<?= $e($workshop['id']) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Title <input type="text" name="title" value="<?= $e($workshop['title']) ?>" required maxlength="200"></label>
        <label>Description <textarea name="description" rows="2" maxlength="5000"><?= $e($workshop['description']) ?></textarea></label>
        <button type="submit">Save</button>
    </form>
    <form method="post" action="/workshops/<?= $e($workshop['id']) ?>/sessions" class="row">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit">Start live session</button>
    </form>
</section>

<section class="card">
    <h2>Sequence</h2>
    <?php if ($blocks === []): ?><p class="muted">No blocks yet.</p><?php endif; ?>
    <ol class="blocks">
    <?php foreach ($blocks as $b): ?>
        <li>
            <strong><?= $e($b['block_type']) ?></strong>
            <form method="post" action="/blocks/<?= $e($b['id']) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <textarea name="config" rows="4" class="mono"><?= $e(json_encode($b['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
                <label>Slide image id <input type="text" name="slide_asset_id" value="<?= $e($b['slide_asset_id']) ?>" inputmode="numeric"></label>
                <button type="submit">Save block</button>
            </form>
            <div class="row">
                <?php foreach (['up' => 'Move up', 'down' => 'Move down'] as $dir => $label): ?>
                    <form method="post" action="/blocks/<?= $e($b['id']) ?>/move" class="inline">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="direction" value="<?= $e($dir) ?>">
                        <button type="submit" class="secondary"><?= $e($label) ?></button>
                    </form>
                <?php endforeach; ?>
                <form method="post" action="/blocks/<?= $e($b['id']) ?>/delete" class="inline">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button type="submit" class="danger">Delete block</button>
                </form>
            </div>
        </li>
    <?php endforeach; ?>
    </ol>

    <h3>Add block</h3>
    <form method="post" action="/workshops/<?= $e($workshop['id']) ?>/blocks">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Type
            <select name="block_type">
                <?php foreach ($types as $t): ?><option value="<?= $e($t) ?>"><?= $e($t) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Configuration (JSON) <textarea name="config" rows="5" class="mono">{}</textarea></label>
        <label>Slide image id (slide / whiteboard / annotate) <input type="text" name="slide_asset_id" inputmode="numeric"></label>
        <button type="submit">Add block</button>
    </form>
    <details>
        <summary>Configuration examples</summary>
        <pre class="mono"><?= $e(<<<'TXT'
poll:       {"question": "Which topic next?", "options": ["Auth", "Crypto"], "max_selections": 1}
meter:      {"prompt": "Confidence?", "min": 0, "max": 10, "step": 1}
rate:       {"items": ["Pace", "Clarity"], "scale": 5}
rank:       {"items": ["A", "B", "C"]}
word:       {"items": ["Fun", "Hard", "Useful"], "max_selections": 2}
plot:       {"x_axis": {"label": "Effort", "min": 0, "max": 10}, "y_axis": {"label": "Impact", "min": 0, "max": 10}, "items": ["Idea 1"]}
word_cloud: {"prompt": "One word for today?", "max_words_per_participant": 3}
write:      {"prompt": "What was unclear?"}
wall:       {"prompt": "Retro", "columns": ["Good", "Improve"]}
quiz:       {"pacing": "tutor", "questions": [{"type": "single", "prompt": "2+2?", "options": ["3", "4"], "correct_answer": "o2", "time_limit_seconds": 20}]}
whiteboard: {"mode": "presenter"}
annotate:   {"prompt": "Label the diagram", "tags": ["Risk", "Asset"]}
TXT) ?></pre>
    </details>
</section>

<section class="card">
    <form method="post" action="/workshops/<?= $e($workshop['id']) ?>/delete">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit" class="danger">Delete workshop</button>
        <span class="muted">Past sessions keep their own snapshot.</span>
    </form>
</section>
