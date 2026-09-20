<?php
/**
 * @var \IndieAuthRocks\View\Raw   $chart
 * @var \IndieAuthRocks\View\Raw   $period_html
 * @var int                        $days
 * @var array<string, int>         $totals
 * @var list<array<string, mixed>> $failures
 * @var list<array<string, mixed>> $targets
 * @var list<array<string, mixed>> $rows
 * @var array<string, mixed>       $pager
 */
?>
<div class="page-header">
    <p class="eyebrow">Admin</p>
    <h1>Server tests</h1>
    <p class="lede">Runs of the server suite, and what servers get wrong most often.</p>
</div>

<section class="card">
    <div class="card-header">
        <h2>Runs started</h2>
        <?= $period_html ?>
    </div>
    <?= $chart ?>
    <dl class="facts">
        <dt>Finished</dt>
        <dd><?= $totals['server:complete'] ?? 0 ?> of <?= $totals['server'] ?? 0 ?></dd>
        <dt>Still open</dt>
        <dd><?= $totals['server:running'] ?? 0 ?></dd>
    </dl>
    <p class="muted small">Unpublished runs are deleted after 30 days.</p>
</section>

<?php if ($failures !== []) { ?>
    <section class="card">
        <h2>Failing most</h2>
        <p class="muted small">Across every server tested in the last <?= $days ?> days.</p>
        <div class="table-wrap">
            <table class="admin-table">
                <thead><tr><th>Test</th><th>Section</th><th>Fails</th><th>Warnings</th><th>Runs</th></tr></thead>
                <tbody>
                <?php foreach ($failures as $row) { ?>
                    <tr>
                        <td><a href="/tests/<?= $row['test_id'] ?>"><?= $row['test_id'] ?></a> <?= $row['title'] ?></td>
                        <td class="small"><?= $row['section'] ?></td>
                        <td class="num"><?= $row['fails'] ?></td>
                        <td class="num"><?= $row['warns'] ?></td>
                        <td class="num"><?= $row['runs'] ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>

<?php if ($targets !== []) { ?>
    <section class="card">
        <h2>Servers tested</h2>
        <div class="table-wrap">
            <table class="admin-table">
                <thead><tr><th>Profile URL</th><th>Runs</th><th>Last</th></tr></thead>
                <tbody>
                <?php foreach ($targets as $target) { ?>
                    <tr>
                        <td class="wrap"><code><?= $target['target_url'] ?></code></td>
                        <td class="num"><?= $target['runs'] ?></td>
                        <td class="small"><?= $target['last_at'] ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>

<section class="card">
    <h2>Every run <span class="muted small">(<?= $pager['total'] ?>)</span></h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Started</th><th>Target</th><th>Status</th><th>Graded</th><th>Fails</th><th>Who</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr>
                    <td class="small"><?= $row['when'] ?></td>
                    <td class="wrap"><a href="/runs/<?= $row['id'] ?>"><?= $row['target'] ?></a></td>
                    <td><?= $row['status'] ?><?= $row['mode'] === 'cli' ? ' · cli' : '' ?></td>
                    <td class="num"><?= $row['graded'] ?></td>
                    <td class="num"><?= $row['fails'] ?></td>
                    <td class="wrap small"><?= $row['tester'] ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager['newer'] !== null || $pager['older'] !== null) { ?>
        <p class="small">
            <?php if ($pager['newer'] !== null) { ?><a href="<?= $pager['newer'] ?>">Newer</a><?php } ?>
            <?php if ($pager['older'] !== null) { ?><a href="<?= $pager['older'] ?>">Older</a><?php } ?>
            <span class="muted">Page <?= $pager['page'] ?> of <?= $pager['pages'] ?></span>
        </p>
    <?php } ?>
</section>
