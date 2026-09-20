<?php
/**
 * @var array<string, int>          $totals
 * @var array<string, mixed>        $charts        Raw SVG, one per kind.
 * @var \IndieAuthRocks\View\Raw    $period_html
 * @var int                         $days
 * @var list<array<string, mixed>>  $server_failures
 * @var list<array<string, mixed>>  $client_failures
 * @var list<array{step: string, title: string, count: int}> $stops
 * @var array<string, int>          $checkup_counts
 * @var list<array<string, mixed>>  $recent
 * @var list<array<string, mixed>>  $testers
 */
$figure = static fn (string $label, int $value): string => $label . ': ' . $value;
?>
<div class="page-header">
    <p class="eyebrow">Admin</p>
    <h1>What is happening</h1>
    <p class="lede">
        Everything anyone has run here, as far back as it is kept: unpublished test runs are deleted
        after 30 days and checkups after a week.
    </p>
</div>

<section class="card">
    <h2>Right now</h2>
    <dl class="facts">
        <dt>Checkups</dt>
        <dd><a href="/admin/checkups"><?= $totals['checkup'] ?? 0 ?></a></dd>
        <dt>Server test runs</dt>
        <dd><a href="/admin/server-tests"><?= $totals['server'] ?? 0 ?></a></dd>
        <dt>Client test runs</dt>
        <dd><a href="/admin/client-tests"><?= $totals['client'] ?? 0 ?></a></dd>
        <dt>People signed in</dt>
        <dd><?= $totals['testers'] ?? 0 ?></dd>
        <dt>Published reports</dt>
        <dd><?= $totals['reports'] ?? 0 ?></dd>
    </dl>
</section>

<section class="card">
    <div class="card-header">
        <h2>Activity</h2>
        <?= $period_html ?>
    </div>

    <h3 class="small">Checkups</h3>
    <?= $charts['checkup'] ?>
    <h3 class="small">Server test runs</h3>
    <?= $charts['server'] ?>
    <h3 class="small">Client test runs</h3>
    <?= $charts['client'] ?>
</section>

<section class="card">
    <h2>How far people get</h2>
    <dl class="facts">
        <dt>Checkups that worked</dt>
        <dd><?= $checkup_counts['worked'] ?></dd>
        <dt>Checkups stopped by a problem</dt>
        <dd><?= $checkup_counts['stopped'] ?></dd>
        <dt>Checkups that never came back</dt>
        <dd><?= $checkup_counts['away'] ?></dd>
        <dt>Server runs finished</dt>
        <dd><?= $totals['server:complete'] ?? 0 ?> of <?= $totals['server'] ?? 0 ?></dd>
    </dl>
    <p class="muted small">
        A server run is finished when every step it could run has run; a checkup is finished when the
        code came back and was exchanged.
    </p>
</section>

<?php if ($stops !== []) { ?>
    <section class="card">
        <h2>Where checkups stop</h2>
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

<?php foreach ([['Server tests failing most', $server_failures], ['Client tests failing most', $client_failures]] as [$heading, $failures]) { ?>
    <?php if ($failures !== []) { ?>
        <section class="card">
            <h2><?= $heading ?></h2>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Test</th><th>Fails</th><th>Warnings</th><th>Runs</th></tr></thead>
                    <tbody>
                    <?php foreach ($failures as $row) { ?>
                        <tr>
                            <td><a href="/tests/<?= $row['test_id'] ?>"><?= $row['test_id'] ?></a> <?= $row['title'] ?></td>
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
<?php } ?>

<section class="card">
    <h2>Latest runs</h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Started</th><th>Kind</th><th>Target</th><th>Status</th><th>Who</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $run) { ?>
                <tr>
                    <td class="small"><?= $run['created_at'] ?></td>
                    <td><?= $run['kind'] ?></td>
                    <td class="wrap">
                        <a href="<?= $run['kind'] === 'checkup' ? '/check/' : ($run['kind'] === 'client' ? '/client-runs/' : '/runs/') ?><?= $run['public_id'] ?>">
                            <?= $run['target_url'] ?? $run['public_id'] ?>
                        </a>
                    </td>
                    <td><?= $run['status'] ?></td>
                    <td class="wrap small"><?= $run['tester'] ?? '' ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>People</h2>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Profile</th><th>First seen</th><th>Last signed in</th><th>Runs</th></tr></thead>
            <tbody>
            <?php foreach ($testers as $tester) { ?>
                <tr>
                    <td class="wrap"><?= $tester['me'] ?></td>
                    <td class="small"><?= $tester['created_at'] ?></td>
                    <td class="small"><?= $tester['last_login_at'] ?? '' ?></td>
                    <td class="num"><?= $tester['runs'] ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</section>
