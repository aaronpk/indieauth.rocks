<?php
/**
 * @var string $run_id
 * @var string $pin
 * @var array<string, int> $counts
 * @var list<array{name: string, rows: list<array<string, mixed>>}> $sections
 */
$symbols = ['pass' => '✓', 'fail' => '✗', 'warning' => '!', 'skipped' => '–', 'not_run' => '·', 'needs_input' => '?', 'error' => '⚠'];
?>
<div class="page-header">
    <p class="eyebrow">Client test run</p>
    <h1>Sign in to your client with each test profile</h1>
    <p class="run-counts">
        <span class="badge outcome-pass"><?= $counts['pass'] ?? 0 ?> passed</span>
        <span class="badge outcome-fail"><?= $counts['fail'] ?? 0 ?> failed</span>
        <span class="badge outcome-warning"><?= $counts['warning'] ?? 0 ?> warnings</span>
        <span class="badge"><?= $counts['needs_input'] ?? 0 ?> need your answer</span>
    </p>
</div>

<section class="card">
    <p>
        Each profile URL below belongs to a test IndieAuth server run by this site. When your client sends you to
        it, you'll see a <strong>TEST SERVER</strong> approval screen. If your client opens it in a browser where
        you are not signed in to this site, enter this PIN to approve:
    </p>
    <p class="pin"><code><?= $pin ?></code></p>
    <p class="muted small">After signing in (or failing to), open the test to tell us what your client showed.</p>
</section>

<?= $view->partial('partials/publish', ['action' => '/client-runs/' . html_entity_decode($run_id) . '/publish', 'csrf' => html_entity_decode($csrf)]) ?>

<?php foreach ($sections as $section) { ?>
    <section class="card catalog-section">
        <h2><?= $section['name'] ?></h2>
        <div class="table-wrap">
            <table class="results">
                <tbody>
                <?php foreach ($section['rows'] as $i => $row) { ?>
                    <tr class="result-<?= $row['outcome'] ?>">
                        <td class="result-icon"><span class="outcome outcome-<?= $row['outcome'] ?>"><?= $symbols[html_entity_decode($row['outcome'])] ?? '·' ?></span></td>
                        <td class="test-id"><a href="/client-runs/<?= $run_id ?>/tests/<?= $row['id'] ?>"><?= $row['id'] ?></a></td>
                        <td>
                            <a class="result-title" href="/client-runs/<?= $run_id ?>/tests/<?= $row['id'] ?>"><?= $row['title'] ?></a>
                            <?php if ($row['summary'] !== '') { ?><span class="result-summary"><?= $row['summary'] ?></span><?php } ?>
                            <?php if ($row['profile'] !== null) { ?>
                                <span class="result-summary"><code id="p-<?= $row['id'] ?>"><?= $row['profile'] ?></code></span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>
