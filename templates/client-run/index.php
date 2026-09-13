<?php
/**
 * @var string $csrf
 * @var list<array{public_id: string, created: string}> $runs
 */
?>
<div class="page-header">
    <p class="eyebrow">Client tests</p>
    <h1>Test your IndieAuth client</h1>
    <p class="lede">
        A client run gives you a set of profile URLs, one per test. Sign in to your client with each one.
        This site plays the IndieAuth server for each profile, and watches what your client does.
    </p>
</div>

<section class="card">
    <form method="post" action="/client-runs">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <button type="submit" class="button">Start a new client run</button>
    </form>

    <?php if ($runs !== []) { ?>
        <h2 class="section-gap">Your client runs</h2>
        <ul class="rows">
            <?php foreach ($runs as $run) { ?>
                <li><a href="/client-runs/<?= $run['public_id'] ?>">Started <?= substr($run['created'], 0, 16) ?> UTC</a></li>
            <?php } ?>
        </ul>
    <?php } ?>
</section>
