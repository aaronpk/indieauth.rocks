<?php
/**
 * The step in progress in a server test run.
 *
 * @var array<string, mixed> $run
 * @var array<string, mixed> $action
 * @var int                  $approvals
 * @var string               $csrf
 * @var string|null          $notice
 */
?>
<div class="page-header">
    <p class="eyebrow"><a href="/runs/<?= $run['public_id'] ?>">Server test run</a> · <?= $run['target'] ?></p>
    <h1><?= $action['title'] ?></h1>
    <?php if (count($action['tests']) > 1) { ?>
        <p class="muted small">Checks <?= implode(', ', $action['tests']) ?>.</p>
    <?php } elseif (count($action['tests']) === 1) { ?>
        <p class="small"><a href="/runs/<?= $run['public_id'] ?>/tests/<?= $action['tests'][0] ?>">About this test</a></p>
    <?php } ?>
</div>

<?php if ($notice !== null) { ?>
    <div class="notice" role="status"><?= $notice ?></div>
<?php } ?>

<section class="card action-card" data-action="<?= $action['type'] ?>" data-step="<?= $action['step'] ?? '' ?>" data-intent="<?= $action['intent'] ?? '' ?>">
    <p class="eyebrow">In progress<?= $approvals > 0 ? ' · about ' . $approvals . ' more approval' . ($approvals === 1 ? '' : 's') . ' after this' : '' ?></p>

    <?php if ($action['instructions'] !== '') { ?>
        <p><?= $action['instructions'] ?></p>
    <?php } ?>

    <?php if ($action['type'] === 'navigate') { ?>
        <p><a class="button" id="go" href="/runs/<?= $run['public_id'] ?>/go/<?= $action['attempt_id'] ?>">Open the request</a></p>
        <details class="small">
            <summary>Show the request</summary>
            <pre class="code-block"><code><?= $action['url'] ?></code></pre>
        </details>
        <form method="post" action="/runs/<?= $run['public_id'] ?>/restart" class="restart-form">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <p class="hint">Opened it already and never got back? <button type="submit" class="link-button">Start this step again</button>.</p>
        </form>
    <?php } ?>

    <?php if ($action['type'] === 'wait') { ?>
        <p>Continue after <strong><?= gmdate('H:i:s', (int) $action['until']) ?> UTC</strong>.</p>
        <form method="post" action="/runs/<?= $run['public_id'] ?>/resume">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <button type="submit" class="button">Continue</button>
        </form>
    <?php } ?>

    <?php if ($action['question'] !== null) { $q = $action['question']; ?>
        <form method="post" action="/runs/<?= $run['public_id'] ?>/answer" class="stack question-form">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="hidden" name="key" value="<?= $q['key'] ?>">
            <fieldset>
                <legend><?= $q['prompt'] ?></legend>
                <?php if ($q['hint'] !== '') { ?><p class="hint"><?= $q['hint'] ?></p><?php } ?>
                <?php if ($q['free_text']) { ?>
                    <input name="value" type="text" autocomplete="off" spellcheck="false">
                <?php } else { ?>
                    <?php foreach ($q['choices'] as $value => $label) { ?>
                        <label class="choice"><input type="radio" name="value" value="<?= $value ?>" required> <span><?= $label ?></span></label>
                    <?php } ?>
                <?php } ?>
            </fieldset>
            <div><button type="submit" class="button button-quiet">Submit answer</button></div>
        </form>
    <?php } ?>

    <form method="post" action="/runs/<?= $run['public_id'] ?>/skip" class="skip-form">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <button type="submit" class="link-button small">Skip this step</button>
    </form>
</section>

<p class="small"><a href="/runs/<?= $run['public_id'] ?>">Back to the list of tests</a></p>
