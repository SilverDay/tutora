<?php /** @var callable $e @var int $retentionDays @var int $maxLiveHours @var int $uploadMaxMb */ ?>
<section class="page">
    <h1>Frequently asked questions</h1>

    <h2>For participants</h2>
    <details class="faq">
        <summary>Do I need an account or an app?</summary>
        <p>No. Open <a href="/join">/join</a> in a current web browser, enter the 6-character code your tutor
            shows you and, if you like, a display name.</p>
    </details>
    <details class="faq">
        <summary>Who can see my answers?</summary>
        <p>Your answers are never shown with your name, neither to other participants nor to the tutor. Results of
            polls and similar activities are shown as totals. Free-text answers in a Write activity are seen only by
            the tutor. Cards on a Wall are visible to everyone in the session, but without their author.</p>
    </details>
    <details class="faq">
        <summary>What happens if I lose my connection or reload the page?</summary>
        <p>Tutora reconnects you automatically in the same browser tab, as long as you were active in the last 30
            minutes. If you close the tab, join again with the code.</p>
    </details>

    <h2>For tutors</h2>
    <details class="faq">
        <summary>Which files can I import as slides?</summary>
        <p>PowerPoint (.pptx) and PDF files up to <?= $e((string) $uploadMaxMb) ?> MB. Each page is converted to an
            image. Animations, transitions and embedded videos are not carried over.</p>
    </details>
    <details class="faq">
        <summary>Why is two-factor authentication mandatory?</summary>
        <p>Your account holds your workshops and your participants' answers. An authenticator app (any app that
            supports TOTP codes) protects them even if your password leaks. At setup you also get one-time recovery
            codes: store them somewhere safe.</p>
    </details>
    <details class="faq">
        <summary>I lost my authenticator and my recovery codes. What now?</summary>
        <p>Contact the operator of this service (see the <a href="/imprint">imprint</a>). After verifying that the
            account is yours, they can reset two-factor authentication so you can set it up again.</p>
    </details>
    <details class="faq">
        <summary>How long is session data kept?</summary>
        <p>A session ends when you end it, or automatically <?= $e((string) $maxLiveHours) ?> hours after it
            started. All of its data (answers, wall cards, drawings, snapshots) is deleted
            <?= $e((string) $retentionDays) ?> days after the session ended. Export the results as CSV before then
            if you want to keep them. Encrypted backups can hold deleted data for up to 14 more days.</p>
    </details>
    <details class="faq">
        <summary>Does Tutora use AI?</summary>
        <p>Only if the operator of this service has enabled it, and only when you ask for it: you can have the
            responses of a Write activity summarised. The summary is shown to participants only if you share it, and
            only once at least 3 people have answered. See the <a href="/privacy">privacy policy</a> for which
            provider is used.</p>
    </details>
    <details class="faq">
        <summary>How do I delete my account?</summary>
        <p>Contact the operator of this service (see the <a href="/imprint">imprint</a>).</p>
    </details>

    <p>Something else? Contact us via the details in the <a href="/imprint">imprint</a>.</p>
</section>
