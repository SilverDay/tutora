<?php /** @var callable $e @var int $retentionDays @var int $maxLiveHours */ ?>
<section class="page">
    <h1>Features</h1>
    <p class="lead">A workshop in Tutora is a sequence of blocks. In a live session you move through them and
        every participant's screen follows.</p>

    <h2>Content blocks</h2>
    <dl class="features">
        <dt>Slides</dt>
        <dd>Upload a PowerPoint (.pptx) or PDF file. Each page becomes an image, and you add all of them to a
            workshop at once or pick single slides.</dd>
        <dt>Whiteboard</dt>
        <dd>In presenter mode only you draw; in collaborative mode everyone draws together. Participants can erase
            only their own drawings, and you can clear the board. Snapshots keep the result.</dd>
        <dt>Annotate</dt>
        <dd>Participants respond to a prompt by placing labelled markers you define on a slide, for example “agree” or “question”.</dd>
    </dl>

    <h2>Activities</h2>
    <dl class="features">
        <dt>Poll</dt>
        <dd>A question with options, single or multiple choice.</dd>
        <dt>Meter</dt>
        <dd>Participants pick a value on a scale you define, with optional labels for both ends.</dd>
        <dt>Rate</dt>
        <dd>Rate several items on a scale you choose, from 2 to 10 points.</dd>
        <dt>Rank</dt>
        <dd>Put a list of items in order.</dd>
        <dt>Word</dt>
        <dd>Pick one or more words from a list you prepared.</dd>
        <dt>Plot</dt>
        <dd>Place items on a two-axis chart, for example effort against impact.</dd>
        <dt>Word cloud</dt>
        <dd>Each participant enters a few words (you set the limit, up to 10), and the cloud shows the most common ones largest.</dd>
        <dt>Write</dt>
        <dd>Free-text answers that only you see. Participants never see each other's responses. Where the operator
            of this service has enabled it, you can generate an AI summary and share it with the group once at
            least 3 people have answered.</dd>
        <dt>Wall</dt>
        <dd>Participants add cards to columns you define, and can edit, move or delete their own. Authors are not shown to other participants. You can add and remove any card.</dd>
        <dt>Quiz</dt>
        <dd>Single choice, multiple choice, true/false and numeric questions with points and optional time limits.
            You set the pace for everyone, or let each participant work through the questions on their own, and you
            reveal the correct answers.</dd>
    </dl>

    <h2>Running a session</h2>
    <ul>
        <li>A 6-character join code without look-alike characters (no 0/O, 1/I/L, U/V).</li>
        <li>Answers, results and drawings update live over a WebSocket connection.</li>
        <li>For polls, meters, ratings, rankings, words, plots, word clouds and write blocks you choose whether
            results are shown live or hidden until you reveal them.</li>
        <li>Remove all contributions of a disruptive participant with one action.</li>
        <li>Export a session as a CSV file.</li>
        <li>Sessions end automatically <?= $e((string) $maxLiveHours) ?> hours after they started if you forget to
            end them, and their data is deleted <?= $e((string) $retentionDays) ?> days after the end.</li>
    </ul>

    <h2>Security for tutors</h2>
    <ul>
        <li>Mandatory two-factor authentication (authenticator app), with one-time recovery codes.</li>
        <li>Passwords are checked against known data breaches without sending the password or its full hash
            anywhere.</li>
        <li>Changing your password or your recovery codes signs out all your other sessions.</li>
    </ul>

    <p class="cta-row"><a class="button" href="/signup">Create a tutor account</a> <a href="/faq">Questions? Read the FAQ</a></p>
</section>
