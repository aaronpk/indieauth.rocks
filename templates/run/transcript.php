<?php
/**
 * @var string $run_id
 * @var array<string, mixed> $test
 * @var string $outcome
 * @var string $summary
 * @var array<string, mixed> $options What can be done about this test now.
 * @var string $csrf
 * @var string|null $notice
 * @var list<array<string, mixed>> $exchanges
 * @var \IndieAuthRocks\View\Raw $exchanges_html
 * @var int $hidden_count Requests in the same step that were made for other tests.
 */
?>
<div class="page-header">
    <p class="eyebrow"><a href="/runs/<?= $run_id ?>">Server test run</a> · <?= $test['section'] ?><?php if ($options['kind'] === 'continue') { ?> · <a href="/runs/<?= $run_id ?>/step">the step in progress</a><?php } ?></p>
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

    <?php if ($options['kind'] === 'run') { ?>
        <form method="post" action="/runs/<?= $run_id ?>/tests/<?= $test['id'] ?>/rerun" class="stack">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <div><button type="submit" class="button"><?= $options['label'] ?></button></div>
            <p class="hint"><?= $options['hint'] ?></p>
        </form>
    <?php } elseif ($options['kind'] === 'continue') { ?>
        <p class="hint"><?= $options['hint'] ?></p>
        <p><a class="button" href="/runs/<?= $run_id ?>/step"><?= $options['label'] ?></a></p>
        <?php if ($options['restartable']) { ?>
            <form method="post" action="/runs/<?= $run_id ?>/restart">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <button type="submit" class="link-button small">Start this step again</button>
            </form>
        <?php } ?>
    <?php } else { ?>
        <p class="muted small"><?= $options['reason'] ?></p>
    <?php } ?>
</section>

<section class="card">
    <h2>HTTP log</h2>
    <p class="muted small">Secrets such as codes, verifiers and tokens are redacted: each shows its length and the start of its SHA-256 hash, so you can tell identical values apart.</p>
    <?php if ($hidden_count > 0) { ?>
        <p class="muted small">This test ran alongside others. <?= $hidden_count ?> other <?= $hidden_count === 1 ? 'request' : 'requests' ?> made for those tests <?= $hidden_count === 1 ? 'is' : 'are' ?> not shown here.</p>
    <?php } ?>
    <?php if ($exchanges === []) { ?>
        <p class="empty">No HTTP requests were recorded for this test.</p>
    <?php } ?>
</section>

<?= $exchanges_html ?>
