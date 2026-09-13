<?php
/**
 * @var array<string, mixed> $report
 * @var array<string, int> $counts
 * @var list<array{name: string, rows: list<array<string, mixed>>}> $sections
 * @var bool $owner
 * @var string $csrf
 */
$symbols = ['pass' => '✓', 'fail' => '✗', 'warning' => '!', 'skipped' => '–', 'not_run' => '·', 'needs_input' => '?', 'error' => '⚠'];
?>
<div class="page-header">
    <p class="eyebrow"><a href="/implementations">Implementation reports</a> · <?= $report['kind'] === 'server' ? 'Server' : 'Client' ?></p>
    <h1><?= $report['name'] ?></h1>
    <?php if ($report['url'] !== '') { ?><p><a href="<?= $report['url'] ?>" rel="nofollow ugc"><?= $report['url'] ?></a></p><?php } ?>
    <p class="run-counts">
        <span class="badge outcome-pass"><?= $counts['pass'] ?? 0 ?> passed</span>
        <span class="badge outcome-fail"><?= $counts['fail'] ?? 0 ?> failed</span>
        <span class="badge outcome-warning"><?= $counts['warning'] ?? 0 ?> warnings</span>
        <span class="badge"><?= $counts['skipped'] ?? 0 ?> skipped</span>
    </p>
    <p class="muted small">
        Published <?= substr($report['published'], 0, 16) ?> UTC by <?= $report['tester'] ?>
        <?php if ($report['target'] !== '') { ?> · tested with <?= $report['target'] ?><?php } ?>
        <?php if ($report['version'] !== '') { ?> · suite <?= $report['version'] ?><?php } ?>
    </p>
    <?php if (!$report['verified']) { ?>
        <p class="alert">Unverified: signing in through this server did not succeed during the run, so these results do not show that the tester controls <?= $report['target'] ?>.</p>
    <?php } ?>
</div>

<?php foreach ($sections as $section) { ?>
    <section class="card catalog-section">
        <h2><?= $section['name'] ?></h2>
        <div class="table-wrap">
            <table class="results">
                <tbody>
                <?php foreach ($section['rows'] as $row) { ?>
                    <tr class="result-<?= $row['outcome'] ?>">
                        <td class="result-icon"><span class="outcome outcome-<?= $row['outcome'] ?>"><?= $symbols[html_entity_decode($row['outcome'])] ?? '·' ?></span></td>
                        <td class="test-id"><a href="/tests/<?= $row['id'] ?>"><?= $row['id'] ?></a></td>
                        <td>
                            <span class="result-title"><?= $row['title'] ?></span>
                            <?php if ($row['summary'] !== '') { ?><span class="result-summary"><?= $row['summary'] ?></span><?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>

<?php if ($owner) { ?>
    <section class="card card-danger">
        <form method="post" action="/implementations/<?= $report['id'] ?>/delete">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <button type="submit" class="button button-danger-quiet">Remove this report</button>
        </form>
    </section>
<?php } ?>
