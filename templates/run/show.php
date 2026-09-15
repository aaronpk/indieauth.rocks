<?php
/**
 * @var array<string, mixed>      $run
 * @var bool                      $owner
 * @var array<string, mixed>|null $upcoming
 * @var bool                      $finished
 * @var int                       $approvals
 * @var array<string, int>        $counts
 * @var list<array{name: string, rows: list<array<string, mixed>>}> $sections
 * @var string                    $csrf
 * @var string|null               $notice
 */
$symbols = ['pass' => '✓', 'fail' => '✗', 'warning' => '!', 'skipped' => '–', 'not_run' => '·', 'needs_input' => '?', 'error' => '⚠'];
?>
<div class="page-header">
    <p class="eyebrow">Server test run</p>
    <h1 class="wrap"><?= $run['target'] ?></h1>
    <p class="run-counts">
        <span class="badge outcome-pass"><?= $counts['pass'] ?? 0 ?> passed</span>
        <span class="badge outcome-fail"><?= $counts['fail'] ?? 0 ?> failed</span>
        <span class="badge outcome-warning"><?= $counts['warning'] ?? 0 ?> warnings</span>
        <span class="badge"><?= $counts['skipped'] ?? 0 ?> skipped</span>
        <?php if ($run['signed_in'] !== null) { ?>
            <span class="muted small">Signed in as <?= $run['signed_in'] ?></span>
        <?php } ?>
    </p>
</div>

<?php if ($notice !== null) { ?>
    <div class="notice" role="status"><?= $notice ?></div>
<?php } ?>

<?php if ($owner && $finished) { ?>
    <section class="card action-card" data-action="finished">
        <h2>All done</h2>
        <p>The run is complete. Tokens obtained during the run have been revoked where your server allows it.</p>
    </section>
<?php } elseif ($owner && $upcoming !== null) { ?>
    <?php // Only what is next is named here; its instructions appear once it is started. ?>
    <section class="card action-card" data-action="<?= $upcoming['in_progress'] ? 'in-progress' : 'upcoming' ?>" data-step="<?= $upcoming['step'] ?>">
        <p class="eyebrow"><?= $upcoming['in_progress'] ? 'In progress' : 'Up next' ?><?= $approvals > 0 ? ' · about ' . $approvals . ' approval' . ($approvals === 1 ? '' : 's') . ' left' : '' ?></p>
        <h2><?= $upcoming['title'] ?></h2>
        <?php if (count($upcoming['tests']) > 1) { ?>
            <p class="muted small">Checks <?= implode(', ', $upcoming['tests']) ?>.</p>
        <?php } ?>
        <?php if ($upcoming['in_progress']) { ?>
            <p><a class="button" href="/runs/<?= $run['public_id'] ?>/step">Continue this test</a></p>
        <?php } else { ?>
            <form method="post" action="/runs/<?= $run['public_id'] ?>/start">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <button type="submit" class="button">Start test</button>
            </form>
        <?php } ?>
    </section>
<?php } elseif (!$owner) { ?>
    <div class="notice">You are viewing someone else's test run.</div>
<?php } ?>

<?php if ($can_publish) { ?>
    <?= $view->partial('partials/publish', ['action' => '/runs/' . html_entity_decode($run['public_id']) . '/publish', 'csrf' => html_entity_decode($csrf)]) ?>
<?php } ?>

<?php foreach ($sections as $section) { ?>
    <section class="card catalog-section">
        <h2><?= $section['name'] ?></h2>
        <div class="table-wrap">
            <table class="results">
                <tbody>
                <?php foreach ($section['rows'] as $row) { ?>
                    <?php // The owner goes to the test within this run, where it can be run again; anyone else to the catalog. ?>
                    <?php $test_url = $owner ? '/runs/' . $run['public_id'] . '/tests/' . $row['id'] : '/tests/' . $row['id']; ?>
                    <tr class="result-<?= $row['outcome'] ?><?= $row['next'] ? ' is-next' : '' ?>">
                        <td class="result-icon"><span class="outcome outcome-<?= $row['outcome'] ?>" title="<?= $row['label'] ?>" aria-label="<?= $row['label'] ?>"><?= $symbols[html_entity_decode($row['outcome'])] ?? '·' ?></span></td>
                        <td class="test-id"><a href="<?= $test_url ?>"><?= $row['id'] ?></a></td>
                        <td>
                            <?php if ($owner) { ?>
                                <a class="result-title" href="<?= $test_url ?>"><?= $row['title'] ?></a>
                            <?php } else { ?>
                                <span class="result-title"><?= $row['title'] ?></span>
                            <?php } ?>
                            <?php if ($row['summary'] !== '') { ?><span class="result-summary"><?= $row['summary'] ?></span><?php } ?>
                        </td>
                        <td class="test-meta small">
                            <?php if ($row['next']) { ?><span class="badge"><?= $upcoming['in_progress'] ? 'In progress' : 'Up next' ?></span><?php } ?>
                            <?php if ($row['has_log']) { ?><a href="/runs/<?= $run['public_id'] ?>/tests/<?= $row['id'] ?>">HTTP log</a><?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>
