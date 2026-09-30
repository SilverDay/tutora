<?php /** @var callable $e @var int $retentionDays */ ?>
<section class="page legal">
    <h1>Terms of use</h1>
    <?= $partial('pages/_placeholder') ?>
    <p class="muted">Last updated: <span class="todo">[TODO: date]</span></p>

    <h2>1. Scope</h2>
    <p>These terms apply to the use of Tutora at this address, operated by <span class="todo">[TODO: operator, see
        imprint]</span>, by tutors (account holders) and by participants of sessions.</p>

    <h2>2. The service</h2>
    <p>Tutora lets tutors prepare workshops and run them as live sessions that participants join with a code.
        <span class="todo">[TODO: fees, if any; whether the service is offered to consumers, businesses or both.]</span></p>

    <h2>3. Tutor accounts</h2>
    <ul>
        <li>Provide accurate details and keep your password, authenticator app and recovery codes safe.</li>
        <li>You are responsible for the content you upload and the questions you ask in your sessions.</li>
        <li>You inform your participants about the session and its purpose. <span class="todo">[TODO: data
            protection duties of tutors, data processing agreement.]</span></li>
    </ul>

    <h2>4. Acceptable use</h2>
    <p>Do not use Tutora for unlawful content, to harass others, to upload malicious files, or to interfere with the
        service or other users' data. <span class="todo">[TODO: consequences, e.g. removal of content, suspension.]</span></p>

    <h2>5. Data and deletion</h2>
    <p>Session data is deleted automatically <?= $e((string) $retentionDays) ?> days after the session ended.
        Export anything you want to keep before then. See the <a href="/privacy">privacy policy</a>.</p>

    <h2>6. Availability</h2>
    <p><span class="todo">[TODO: availability commitments or their absence, maintenance.]</span></p>

    <h2>7. Liability</h2>
    <p><span class="todo">[TODO]</span></p>

    <h2>8. Termination</h2>
    <p><span class="todo">[TODO: how tutors and the operator can end the use of the service, what happens to the
        data.]</span></p>

    <h2>9. Changes and governing law</h2>
    <p><span class="todo">[TODO: how changes to these terms are announced; governing law and place of
        jurisdiction.]</span></p>
</section>
