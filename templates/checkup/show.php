<?php
/**
 * One checkup, told step by step.
 *
 * @var array<string, mixed>       $checkup
 * @var array<string, mixed>       $summary
 * @var list<array<string, mixed>> $steps
 * @var string                     $csrf
 */
?>
<div class="page-header">
    <p class="eyebrow"><a href="/check">Check your login</a> · <code><?= $checkup['target'] ?></code></p>
    <h1><?= $summary['headline'] ?></h1>
    <?php if ($summary['fix'] !== null) { ?>
        <p class="lede"><?= $summary['fix'] ?></p>
    <?php } ?>
    <?php if ($summary['notes'] !== null) { ?>
        <p class="small muted"><?= $summary['notes'] ?></p>
    <?php } ?>
</div>

<?php if ($checkup['can_go']) { ?>
    <section class="card action-card">
        <p class="eyebrow">Next</p>
        <p>
            Your website checks out so far. The rest of a login happens at your own server: continue, and
            you'll be sent there to approve, exactly as an app would send you.
        </p>
        <p><a class="button" href="/check/<?= $checkup['id'] ?>/go">Continue — sign in with your server</a></p>
    </section>
<?php } elseif ($checkup['phase'] === 'discovered' && $checkup['is_owner']) { ?>
    <section class="card">
        <p>
            The login can't go any further while the problem above is there: an app would stop in the same
            place. Once it's fixed, run this check again.
        </p>
        <p><a class="button button-quiet" href="/check">Check again</a></p>
    </section>
<?php } ?>

<?php foreach ($steps as $step) { ?>
    <section class="card checkup-step" id="<?= $step['id'] ?>">
        <p class="eyebrow"><?= $step['title'] ?></p>
        <h2><span class="<?= $step['badge'] ?>"><?= $step['state_label'] ?></span></h2>
        <p class="wrap"><?= $step['headline'] ?></p>

        <?php if ($step['meaning'] !== null) { ?>
            <p class="muted"><?= $step['meaning'] ?></p>
        <?php } ?>

        <?php if ($step['fix'] !== null) { ?>
            <p class="fix"><strong>What to do:</strong> <?= $step['fix'] ?></p>
        <?php } ?>

        <?php if ($step['facts'] !== []) { ?>
            <dl class="facts">
                <?php foreach ($step['facts'] as $label => $value) { ?>
                    <dt><?= $label ?></dt>
                    <dd class="wrap"><code><?= $value ?></code></dd>
                <?php } ?>
            </dl>
        <?php } ?>

        <?php if ($step['links'] !== []) { ?>
            <p class="small">
                <?php foreach ($step['links'] as $link) { ?>
                    <a href="<?= $link['url'] ?>"><?= $link['text'] ?></a>
                <?php } ?>
            </p>
        <?php } ?>

        <?php if ($step['exchanges_html'] !== null) { ?>
            <details class="small">
                <summary>Show the <?= $step['exchange_count'] === 1 ? 'request' : $step['exchange_count'] . ' requests' ?></summary>
                <?= $step['exchanges_html'] ?>
            </details>
        <?php } ?>
    </section>
<?php } ?>

<section class="card">
    <h2>Sharing this page</h2>
    <p>
        Anyone with this page's address can read it, which is the point: send it to whoever runs your
        website, or to the people who make the app you're trying to sign in to. Codes, tokens and
        passwords are replaced with their length and a short fingerprint, so nothing on it can be used to
        sign in as you.
    </p>
    <?php if ($checkup['is_owner']) { ?>
        <form method="post" action="/check/<?= $checkup['id'] ?>/delete" class="stack">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <button type="submit" class="link-button small">Delete this check now</button>
        </form>
    <?php } ?>
    <p class="small muted">Checks are deleted automatically after a week.</p>
</section>
