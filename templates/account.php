<?php
/**
 * @var string $me
 * @var list<array<string, string>> $runs
 */
?>
<div class="page-header">
    <p class="eyebrow">Signed in as <?= $me ?></p>
    <h1>Your test runs</h1>
</div>

<section class="card">
    <?php if ($runs === []) { ?>
        <p class="empty">No runs yet.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Started</th><th>Kind</th><th>Target</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $r) { ?>
                    <tr>
                        <td><a href="/runs/<?= $r['public_id'] ?>"><?= substr($r['created'], 0, 16) ?></a></td>
                        <td><?= $r['kind'] ?></td>
                        <td class="wrap"><?= $r['target'] ?></td>
                        <td><?= $r['status'] ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    <p><a class="button" href="/">Start a new run</a></p>
</section>
