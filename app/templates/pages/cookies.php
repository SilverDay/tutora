<?php /** @var callable $e */ ?>
<section class="page legal">
    <h1>Cookies and browser storage</h1>
    <?= $partial('pages/_placeholder') ?>
    <p>Tutora uses no tracking, advertising or analytics cookies and loads no third-party content. It stores only
        what the service needs to work:</p>
    <table class="stack">
        <thead><tr><th>Name</th><th>Type</th><th>Who</th><th>Purpose</th><th>Lifetime</th></tr></thead>
        <tbody>
        <tr>
            <td data-label="Name" class="mono">__Host-tutora</td>
            <td data-label="Type">Cookie (first-party; Secure, HttpOnly, SameSite=Strict)</td>
            <td data-label="Who">Every visitor</td>
            <td data-label="Purpose">Keeps tutors signed in and protects forms against cross-site request forgery.</td>
            <td data-label="Lifetime">Until the browser is closed. A tutor's sign-in also ends after inactivity.</td>
        </tr>
        <tr>
            <td data-label="Name" class="mono">tutora.resume</td>
            <td data-label="Type">Session storage in your browser</td>
            <td data-label="Who">Participants</td>
            <td data-label="Purpose">Lets you rejoin the session after a reload or a lost connection.</td>
            <td data-label="Lifetime">Until the browser tab is closed.</td>
        </tr>
        </tbody>
    </table>
    <p>The whiteboard component reads developer settings from your browser's local storage if any are present.
        It writes nothing there.</p>
    <p><span class="todo">[TODO: legal assessment, e.g. that these are strictly necessary under § 25(2) no. 2
        TDDDG and therefore need no consent.]</span></p>
</section>
