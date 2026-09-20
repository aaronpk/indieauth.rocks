<?php
/**
 * @var \IndieAuthRocks\View\Raw   $chart
 * @var \IndieAuthRocks\View\Raw   $period_html
 * @var int                        $days
 * @var array<string, int>         $totals
 * @var list<array<string, mixed>> $failures
 * @var list<array<string, mixed>> $rows
 * @var array<string, mixed>       $pager
 */
?>
<div class="page-header">
    <p class="eyebrow">Admin</p>
    <h1>Client tests</h1>
    <p class="lede">
        Runs of the client suite. A client run has no target and never reports itself finished —
        the tester signs in to their own client with the profile URLs this site hands out — so these
        are counted by what has been graded and when a client last touched the run.
    </p>
</div>

<section class="card">
    <div class="card-header">
        <h2>Runs started</h2>
        <?= $period_html ?>
    </div>
    <?= $chart ?>
    <p class="muted small">Unpublished runs are deleted after 30 days.</p>
</section>

<?php if ($failures !== []) { ?>
    <section class="card">
        <h2>Failing most</h2>
        <p class="muted small">Across every client tested in the last <?= $days ?> days.</p>
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

<section class="card">
    <h2>Every run <span class="muted small">(<?= $pager['total'] ?>)</span></h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Started</th><th>Who</th><th>Graded</th><th>Fails</th><th>Last activity</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr>
                    <td class="small"><a href="/client-runs/<?= $row['id'] ?>"><?= $row['when'] ?></a></td>
                    <td class="wrap"><?= $row['tester'] ?></td>
                    <td class="num"><?= $row['graded'] ?></td>
                    <td class="num"><?= $row['fails'] ?></td>
                    <td class="small"><?= $row['last'] ?></td>
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
