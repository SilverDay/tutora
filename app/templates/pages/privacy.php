<?php /** @var callable $e @var int $retentionDays @var int $maxLiveHours */ ?>
<section class="page legal">
    <h1>Privacy policy</h1>
    <?= $partial('pages/_placeholder') ?>
    <p class="muted">Last updated: <span class="todo">[TODO: date]</span></p>

    <h2>1. Who is responsible</h2>
    <p>The controller for the processing described here is <span class="todo">[TODO: operator name, address,
        email]</span>. <span class="todo">[TODO: data protection officer, if one is required.]</span></p>
    <p><span class="todo">[TODO: define the roles for data in tutors' sessions. Tutors decide what they ask their
        participants, so they may be controllers and the operator a processor, which requires a data processing
        agreement with each tutor.]</span></p>

    <h2>2. Data we process</h2>
    <h3>2.1 Visiting the website</h3>
    <p>When you open a page, the web server receives technical data such as your IP address, the time, the page
        requested and your browser's user agent, and may record it in its access log.
        <span class="todo">[TODO: whether access logs are kept, what they contain, and for how long; legal basis,
        e.g. Art. 6(1)(f) GDPR.]</span> Tutora sets one strictly necessary cookie; see
        <a href="/cookies">cookies and storage</a>.</p>

    <h3>2.2 Tutor accounts</h3>
    <ul>
        <li><strong>Account data:</strong> email address, display name, password (stored only as an Argon2id hash),
            the secret of your authenticator app (stored encrypted) and your recovery codes (stored only as hashes).</li>
        <li><strong>Your content:</strong> workshops, uploaded presentations and the images generated from them.</li>
        <li><strong>Security log:</strong> events such as sign-up, failed sign-ins and two-factor authentication
            events, with the IP address and time. <span class="todo">[TODO: retention period; the log is not deleted
            automatically at the moment.]</span></li>
        <li><strong>Emails:</strong> we send the sign-up confirmation and security notices (when a recovery code was used or two-factor authentication was reset)
            through <span class="todo">[TODO: email provider]</span>.</li>
        <li><strong>Password breach check:</strong> when you choose a password, our server sends the first 5
            characters of its SHA-1 hash to the Have I Been Pwned service (api.pwnedpasswords.com). Neither your
            password, its full hash nor your IP address are sent.</li>
    </ul>
    <p>Legal basis: <span class="todo">[TODO: e.g. Art. 6(1)(b) GDPR for the account, Art. 6(1)(f) GDPR for the
        security log and breach check.]</span></p>

    <h3>2.3 Participants</h3>
    <ul>
        <li><strong>Joining:</strong> an optional display name and a random identifier for the session. You don't
            need an account, and you are not recognised across sessions.</li>
        <li><strong>Contributions:</strong> your answers, wall cards and drawings. They are never shown with your
            name, neither to other participants nor to the tutor.</li>
        <li><strong>Abuse protection:</strong> your IP address is used to limit repeated join attempts. It is
            stored only as a hash in a short-lived counter.</li>
    </ul>
    <p>Legal basis: <span class="todo">[TODO]</span></p>

    <h3>2.4 AI summaries</h3>
    <p><span class="todo">[TODO: currently not enabled on this service. If enabled: when a tutor asks for a
        summary of a Write activity, the responses of that activity are sent to [provider], under a data
        processing agreement, with [location / transfer safeguards].]</span></p>

    <h2>3. How long we keep data</h2>
    <ul>
        <li>Sessions end when the tutor ends them, or automatically <?= $e((string) $maxLiveHours) ?> hours after
            they started. All data of a session is deleted <?= $e((string) $retentionDays) ?> days after it ended.</li>
        <li>Unconfirmed sign-ups are deleted after their confirmation link expires (24 hours).</li>
        <li>Account data is kept until the account is deleted.</li>
        <li>Backups are encrypted and kept for 14 days, so deleted data can remain in a backup for up to 14 more
            days. Backups are not edited afterwards.</li>
    </ul>

    <h2>4. Recipients</h2>
    <p><span class="todo">[TODO: hosting provider, email provider, AI provider if enabled, each with its role,
        location and any transfer outside the EU/EEA with its safeguards.]</span></p>

    <h2>5. Your rights</h2>
    <p>Under the GDPR you have the right to access, rectification, erasure, restriction of processing, data
        portability and objection, and to withdraw consent you have given. You may also lodge a complaint with a
        supervisory authority, <span class="todo">[TODO: competent authority]</span>. To exercise your rights,
        contact <span class="todo">[TODO: contact]</span>.</p>

    <h2>6. Security</h2>
    <p>Connections are encrypted (HTTPS). Two-factor authentication is mandatory for tutors, customers' data is
        kept separate, uploaded files are converted in an isolated sandbox without network access, and backups are
        always encrypted.</p>
</section>
