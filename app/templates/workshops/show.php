<?php /** @var callable $e @var callable $partial @var array<string,mixed> $workshop @var list<array<string,mixed>> $blocks @var list<string> $types @var list<array<string,mixed>> $imports */ ?>
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
    <h2>Slides</h2>
    <form method="post" action="/workshops/<?= $e($workshop['id']) ?>/slides" enctype="multipart/form-data" class="row">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label>Import a deck (PDF or .pptx, max 50 MB) <input type="file" name="deck" accept=".pdf,.pptx,application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation" required></label>
        <button type="submit">Upload</button>
    </form>
    <?php foreach ($imports as $imp): ?>
        <div class="import">
            <p><strong><?= $e($imp['original_filename']) ?></strong>
                <span class="muted">· <?= $e($imp['status'] ?? 'unknown') ?><?= $imp['page_count'] !== null ? ' · ' . $e($imp['page_count']) . ' pages' : '' ?></span></p>
            <?php if (in_array($imp['status'], ['pending', 'running'], true)): ?>
                <p class="muted" data-converting="1">Converting… reload the page in a moment.</p>
            <?php endif; ?>
            <?php if ($imp['status'] === 'failed'): ?><div class="alert"><?= $e($imp['error_message']) ?></div><?php endif; ?>
            <?php if ($imp['assets'] !== []): ?>
                <form method="post" action="/workshops/<?= $e($workshop['id']) ?>/slides/<?= $e($imp['id']) ?>/add-all">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <button type="submit" class="secondary">Add all pages as slide blocks</button>
                </form>
                <div class="thumbs">
                <?php foreach ($imp['assets'] as $a): ?>
                    <figure>
                        <img src="/slides/<?= $e($a['id']) ?>" alt="Page <?= $e($a['page_number']) ?>" loading="lazy" width="<?= $e($a['width']) ?>" height="<?= $e($a['height']) ?>">
                        <figcaption>Page <?= $e($a['page_number']) ?> · id <?= $e($a['id']) ?>
                            <form method="post" action="/workshops/<?= $e($workshop['id']) ?>/blocks" class="inline">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="block_type" value="slide">
                                <input type="hidden" name="config" value="{}">
                                <input type="hidden" name="slide_asset_id" value="<?= $e($a['id']) ?>">
                                <button type="submit" class="link">Add</button>
                            </form>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<section class="card">
    <h2>Sequence</h2>
    <?php if ($blocks === []): ?><p class="muted">No blocks yet.</p><?php endif; ?>
    <ol class="blocks">
    <?php foreach ($blocks as $b): ?>
        <li>
            <strong><?= $e($b['block_type']) ?></strong>
            <?php if ($b['slide_asset_id'] !== null): ?><img class="thumb-inline" src="/slides/<?= $e($b['slide_asset_id']) ?>" alt="" loading="lazy"><?php endif; ?>
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

Hide results from participants until you reveal them (poll, meter, rate, rank, word, plot,
word_cloud, write): add "results": "on_reveal" (default "live"), e.g.
poll:       {"question": "Guess first!", "options": ["A", "B"], "results": "on_reveal"}
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
