<?php
/**
 * @var list<array<string, mixed>> $servers
 * @var list<array<string, mixed>> $clients
 */
$groups = ['Servers' => $servers, 'Clients' => $clients];
?>
<div class="page-header">
    <p class="eyebrow">Implementation reports</p>
    <h1>How implementations do</h1>
    <p class="lede">Results published by the people who build these servers and clients. Run the tests, then publish from your finished run.</p>
</div>

<?php foreach ($groups as $heading => $rows) { ?>
    <section class="card">
        <h2><?= $heading ?></h2>
        <?php if ($rows === []) { ?>
            <p class="empty">No reports published yet.</p>
        <?php } else { ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Implementation</th><th>Passed</th><th>Failed</th><th>Warnings</th><th>Published</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td>
                                <a href="/implementations/<?= $row['id'] ?>"><?= $row['name'] ?></a>
                                <span class="result-summary">by <?= $row['tester'] ?></span>
                            </td>
                            <td><span class="badge outcome-pass"><?= $row['pass'] ?></span></td>
                            <td><span class="badge <?= $row['fail'] > 0 ? 'outcome-fail' : '' ?>"><?= $row['fail'] ?></span></td>
                            <td><span class="badge <?= $row['warning'] > 0 ? 'outcome-warning' : '' ?>"><?= $row['warning'] ?></span></td>
                            <td class="small"><?= $row['published'] ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </section>
<?php } ?>
