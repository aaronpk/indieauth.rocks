<?php
/**
 * @var string $run_id
 * @var array<string, mixed> $test
 * @var string $outcome
 * @var string $summary
 * @var list<array<string, mixed>> $exchanges
 */
?>
<div class="page-header">
    <p class="eyebrow"><a href="/runs/<?= $run_id ?>">Back to the run</a></p>
    <h1><span class="test-number"><?= $test['id'] ?></span> <?= $test['title'] ?></h1>
    <p><span class="badge outcome-<?= $outcome ?>"><?= $outcome ?></span> <?= $summary ?></p>
    <p class="muted small">Secrets such as codes, verifiers and tokens are redacted: each shows its length and the start of its SHA-256 hash, so you can tell identical values apart.</p>
</div>

<?php if ($exchanges === []) { ?>
    <div class="card"><p class="empty">No HTTP requests were recorded for this test.</p></div>
<?php } ?>

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
