<?php
/**
 * @var string $run_id
 * @var array<string, mixed> $test
 * @var string $outcome
 * @var string $summary
 * @var string|null $rerun_reason
 * @var string $rerun_hint
 * @var string $csrf
 * @var string|null $notice
 * @var list<array<string, mixed>> $exchanges
 */
?>
<div class="page-header">
    <p class="eyebrow"><a href="/runs/<?= $run_id ?>">Server test run</a> · <?= $test['section'] ?></p>
    <h1><span class="test-number"><?= $test['id'] ?></span> <?= $test['title'] ?></h1>
    <p><span class="badge outcome-<?= $outcome ?>"><?= $outcome ?></span> <?= $summary ?></p>
</div>

<?php if ($notice !== null) { ?>
    <div class="notice" role="status"><?= $notice ?></div>
<?php } ?>

<section class="card">
    <p><?= $test['description'] ?></p>
    <p class="muted"><strong>Passes when:</strong> <?= $test['passes'] ?></p>
    <p class="small"><a href="<?= $test['spec_url'] ?>"><?= $test['spec'] ?></a> · <a href="/tests/<?= $test['id'] ?>">About this test</a></p>

    <?php if ($rerun_reason === null) { ?>
        <form method="post" action="/runs/<?= $run_id ?>/tests/<?= $test['id'] ?>/rerun" class="stack">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <div><button type="submit" class="button">Run this test again</button></div>
            <p class="hint"><?= $rerun_hint ?></p>
        </form>
    <?php } else { ?>
        <p class="muted small"><?= $rerun_reason ?></p>
    <?php } ?>
</section>

<section class="card">
    <h2>HTTP log</h2>
    <p class="muted small">Secrets such as codes, verifiers and tokens are redacted: each shows its length and the start of its SHA-256 hash, so you can tell identical values apart.</p>
    <?php if ($exchanges === []) { ?>
        <p class="empty">No HTTP requests were recorded for this test.</p>
    <?php } ?>
</section>

<?php foreach ($exchanges as $e) { ?>
    <section class="card exchange">
        <p class="eyebrow"><?= $e['direction'] === 'outbound' ? 'Request from this site' : 'Request to this site' ?> · <?= $e['created_at'] ?> UTC</p>
        <h2 class="wrap"><code><?= $e['method'] ?></code> <?= $e['url'] ?></h2>
        <?php if ($e['request_headers'] !== '' || $e['request_body'] !== '') { ?>
            <pre class="code-block"><code><?= $e['request_headers'] ?><?= $e['request_body'] !== '' ? "\n\n" . $e['request_body'] : '' ?></code></pre>
        <?php } ?>
        <?php if ($e['error'] !== '') { ?>
            <div class="alert"><?= $e['error'] ?></div>
        <?php } elseif ($e['status'] !== null && $e['direction'] === 'outbound') { ?>
            <p class="small"><strong>Response: HTTP <?= $e['status'] ?></strong></p>
            <pre class="code-block"><code><?= $e['response_headers'] ?><?= $e['response_body'] !== '' ? "\n\n" . $e['response_body'] : '' ?></code></pre>
        <?php } ?>
    </section>
<?php } ?>
