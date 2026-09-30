<?php /** @var callable $e @var bool $signedIn @var int $retentionDays */ ?>
<section class="hero">
    <h1>Run live workshops everyone can join in seconds</h1>
    <p class="lead">Tutora puts your slides, a shared whiteboard and interactive activities into one live
        session. Participants join from their own browser with a short code: no app, no account.</p>
    <div class="cta-row">
        <?php if ($signedIn): ?>
            <a class="button" href="/dashboard">Go to your dashboard</a>
        <?php else: ?>
            <a class="button" href="/signup">Create a tutor account</a>
        <?php endif; ?>
        <a class="button secondary" href="/join">Join a session</a>
    </div>
</section>

<section class="section">
    <h2>How it works</h2>
    <ol class="steps">
        <li>
            <h3>Build a workshop</h3>
            <p>Line up blocks: slides imported from PowerPoint or PDF, whiteboards and activities such as polls,
                word clouds, quizzes or a shared wall.</p>
        </li>
        <li>
            <h3>Start a session</h3>
            <p>Tutora creates a 6-character join code. Participants enter it at <strong>/join</strong>, with an
                optional display name.</p>
        </li>
        <li>
            <h3>Run it live</h3>
            <p>You move from block to block and every screen follows. Answers and drawings appear live, and you
                can hide results until you reveal them.</p>
        </li>
    </ol>
</section>

<section class="section">
    <h2>Activities for every moment</h2>
    <ul class="tiles">
        <li><strong>Poll</strong> Single or multiple choice</li>
        <li><strong>Word cloud</strong> Collect words and see the most common ones grow</li>
        <li><strong>Quiz</strong> Scored questions, paced by you or by each participant</li>
        <li><strong>Wall</strong> Shared cards in columns</li>
        <li><strong>Whiteboard</strong> Draw together, or present your own drawing</li>
        <li><strong>Rate, rank, meter, plot, write</strong> and more</li>
    </ul>
    <p><a href="/features">See all features</a></p>
</section>

<section class="section">
    <h2>Private by design</h2>
    <ul class="checks">
        <li>Participants join anonymously. No account, no tracking across sessions.</li>
        <li>No advertising, analytics or third-party scripts.</li>
        <li>Session data is deleted automatically <?= $e((string) $retentionDays) ?> days after the session ends.</li>
        <li>Tutor accounts are protected by mandatory two-factor authentication.</li>
        <li>Backups are always encrypted.</li>
    </ul>
    <p><a href="/privacy">Read the privacy policy</a></p>
</section>

<section class="section cta-final card">
    <h2>Ready for your next workshop?</h2>
    <div class="cta-row">
        <?php if ($signedIn): ?>
            <a class="button" href="/dashboard">Go to your dashboard</a>
        <?php else: ?>
            <a class="button" href="/signup">Create a tutor account</a>
            <a class="button secondary" href="/faq">Read the FAQ</a>
        <?php endif; ?>
    </div>
</section>
