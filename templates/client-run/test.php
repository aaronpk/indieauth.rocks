<?php
/**
 * @var string $run_id
 * @var array<string, mixed> $test
 * @var string $outcome
 * @var string $summary
 * @var bool $signin
 * @var string|null $profile
 * @var string|null $typed
 * @var string|null $special
 * @var list<array{label: string, at: string, bad: bool}> $evidence
 * @var int $attempt
 * @var string $answer
 * @var array<string, string> $choices
 * @var string $pin
 * @var string $csrf
 * @var string|null $notice
 * @var list<array{method: string, url: string, at: string, body: string}> $requests From this attempt.
 * @var list<array{method: string, url: string, at: string, body: string}> $earlier From earlier attempts.
 */
?>
<div class="page-header">
    <p class="eyebrow"><a href="/client-runs/<?= $run_id ?>">Client test run</a> · <?= $test['section'] ?></p>
    <h1><span class="test-number"><?= $test['id'] ?></span> <?= $test['title'] ?></h1>
    <p><span class="badge outcome-<?= $outcome ?>"><?= $outcome ?></span> <?= $summary ?></p>
</div>

<?php if ($notice !== null) { ?>
    <div class="notice" role="status"><?= $notice ?></div>
<?php } ?>

<section class="card">
    <p><?= $test['description'] ?></p>
    <p class="muted"><strong>Passes when:</strong> <?= $test['passes'] ?></p>

    <?php if ($profile !== null) { ?>
        <p>Sign in to your client with <?= $typed !== null ? 'this, typed without https://' : 'this profile URL' ?>:</p>
        <div class="copy-field">
            <pre class="code-block"><code id="profile-url"><?= $typed ?? $profile ?></code></pre>
            <button type="button" class="copy-button" data-copy="profile-url" hidden>Copy</button>
        </div>
        <p class="hint">If the approval screen asks for a PIN, it is <code><?= $pin ?></code>.</p>
    <?php } ?>

    <?php if ($special === 'unsolicited') { ?>
        <p>After signing in with C101, open this link. It sends your browser to your client's redirect URI with a valid code and a state your client never created.</p>
        <p><a class="button" href="/client-runs/<?= $run_id ?>/unsolicited">Send an unsolicited response</a></p>
    <?php } elseif ($special === 'replay') { ?>
        <p>After signing in with C101, open this link. It replays the redirect your client received then.</p>
        <p><a class="button" href="/client-runs/<?= $run_id ?>/replay">Replay the C101 response</a></p>
    <?php } ?>
</section>

<?php if ($signin) { ?>
    <section class="card">
        <h2>What your client did</h2>
        <p class="muted small">
            <?= $attempt > 1 ? 'Attempt ' . $attempt . '. ' : '' ?>Only the latest attempt counts: signing in to your client with this profile again replaces what earlier attempts showed.
        </p>
        <?php if ($evidence === []) { ?>
            <p class="empty">Nothing yet.</p>
        <?php } else { ?>
            <ul class="evidence">
                <?php foreach ($evidence as $item) { ?>
                    <li class="<?= $item['bad'] ? 'bad' : '' ?>"><?= $item['label'] ?> <span class="muted small"><?= $item['at'] ?></span></li>
                <?php } ?>
            </ul>
        <?php } ?>

        <form method="post" action="/client-runs/<?= $run_id ?>/tests/<?= $test['id'] ?>/answer" class="stack question-form">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <fieldset>
                <legend>What did your client show?</legend>
                <?php foreach ($choices as $value => $label) { ?>
                    <label class="choice">
                        <input type="radio" name="value" value="<?= $value ?>" required <?= $answer === $value ? 'checked' : '' ?>>
                        <span><?= $label ?></span>
                    </label>
                <?php } ?>
            </fieldset>
            <div><button type="submit" class="button button-quiet">Save answer</button></div>
        </form>
    </section>

    <?php if ($requests !== [] || $earlier !== []) { ?>
        <section class="card">
            <h2>Requests from your client</h2>
            <?php if ($requests === []) { ?>
                <p class="empty">None in this attempt yet.</p>
            <?php } else { ?>
                <ul class="rows">
                    <?php foreach ($requests as $r) { ?>
                        <li class="row-stack">
                            <div class="wrap"><code><?= $r['method'] ?></code> <?= $r['url'] ?> <span class="muted small"><?= $r['at'] ?></span>
                                <?php if ($r['body'] !== '') { ?><pre class="code-block"><code><?= $r['body'] ?></code></pre><?php } ?>
                            </div>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>

            <?php if ($earlier !== []) { ?>
                <details class="section-gap">
                    <summary><?= count($earlier) ?> earlier request<?= count($earlier) === 1 ? '' : 's' ?>, from earlier attempts</summary>
                    <ul class="rows">
                        <?php foreach ($earlier as $r) { ?>
                            <li class="row-stack">
                                <div class="wrap"><code><?= $r['method'] ?></code> <?= $r['url'] ?> <span class="muted small"><?= $r['at'] ?></span>
                                    <?php if ($r['body'] !== '') { ?><pre class="code-block"><code><?= $r['body'] ?></code></pre><?php } ?>
                                </div>
                            </li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
        </section>
    <?php } ?>
<?php } ?>
