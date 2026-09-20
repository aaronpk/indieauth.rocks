<?php
/**
 * @var \IndieAuthRocks\View\Raw   $chart
 * @var \IndieAuthRocks\View\Raw   $period_html
 * @var int                        $days
 * @var array<string, int>         $counts
 * @var list<array{step: string, title: string, count: int}> $stops
 * @var list<array<string, mixed>> $targets
 * @var list<array<string, mixed>> $rows
 * @var array<string, mixed>       $pager
 */
?>
<div class="page-header">
    <p class="eyebrow">Admin</p>
    <h1>Checkups</h1>
    <p class="lede">
        What "Check your login" found for the people who used it. Nobody signs in to run one, so a
        checkup is known only by the website it was run for.
    </p>
</div>

<section class="card">
    <div class="card-header">
        <h2>Checkups started</h2>
        <?= $period_html ?>
    </div>
    <?= $chart ?>
    <p class="muted small">Checkups are deleted after a week, so anything older is already gone.</p>
</section>

<section class="card">
    <h2>How they ended</h2>
    <dl class="facts">
        <dt>Worked all the way through</dt>
        <dd><?= $counts['worked'] ?></dd>
        <dt>Stopped by a problem</dt>
        <dd><?= $counts['stopped'] ?></dd>
        <dt>Left for their server and never came back</dt>
        <dd><?= $counts['away'] ?></dd>
        <dt>Started and left</dt>
        <dd><?= $counts['unfinished'] ?></dd>
    </dl>
</section>

<?php if ($stops !== []) { ?>
    <section class="card">
        <h2>Where they stop</h2>
        <p class="muted small">The first step that stopped the login, in the last <?= $days ?> days.</p>
        <div class="table-wrap">
            <table class="admin-table">
                <tbody>
                <?php foreach ($stops as $stop) { ?>
                    <tr><td><?= $stop['title'] ?></td><td class="num"><?= $stop['count'] ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>

<?php if ($targets !== []) { ?>
    <section class="card">
        <h2>Sites checked</h2>
        <div class="table-wrap">
            <table class="admin-table">
                <thead><tr><th>Website</th><th>Checks</th><th>Last</th></tr></thead>
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
    <h2>Every checkup <span class="muted small">(<?= $pager['total'] ?>)</span></h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Started</th><th>Website</th><th>How it ended</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) { ?>
                <tr>
                    <td class="small"><?= $row['when'] ?></td>
                    <td class="wrap"><a href="/check/<?= $row['id'] ?>"><?= $row['target'] ?></a></td>
                    <td><?= $row['outcome'] ?></td>
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
