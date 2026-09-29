<?php /** @var callable $e */ ?>
<section class="card narrow" id="join-view">
    <h1>Join a session</h1>
    <p class="muted">No account needed. Tutora does not track you across sessions.</p>
    <div class="alert" id="join-error" role="alert" hidden></div>
    <form id="join-form" method="post" action="/join">
        <label>Session code <input type="text" name="code" required maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false"></label>
        <label>Display name (optional) <input type="text" name="display_name" maxlength="40" autocomplete="off"></label>
        <button type="submit">Join</button>
    </form>
</section>
<section class="card" id="session-view" hidden>
    <h1 id="session-title"></h1>
    <p class="muted" id="session-status"></p>
    <div id="feedback" role="status" hidden></div>
    <div id="block"></div>
</section>
