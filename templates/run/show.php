<?php
/**
 * @var array<string, mixed>      $run
 * @var bool                      $owner
 * @var array<string, mixed>|null $action
 * @var int                       $approvals
 * @var array<string, int>        $counts
 * @var list<array{name: string, rows: list<array<string, mixed>>}> $sections
 * @var string                    $csrf
 * @var string|null               $notice
 */
$symbols = ['pass' => '✓', 'fail' => '✗', 'warning' => '!', 'skipped' => '–', 'not_run' => '·', 'needs_input' => '?', 'error' => '⚠'];
?>
<div class="page-header">
    <p class="eyebrow">Server test run</p>
    <h1 class="wrap"><?= $run['target'] ?></h1>
    <p class="run-counts">
        <span class="badge outcome-pass"><?= $counts['pass'] ?? 0 ?> passed</span>
        <span class="badge outcome-fail"><?= $counts['fail'] ?? 0 ?> failed</span>
        <span class="badge outcome-warning"><?= $counts['warning'] ?? 0 ?> warnings</span>
        <span class="badge"><?= $counts['skipped'] ?? 0 ?> skipped</span>
        <?php if ($run['signed_in'] !== null) { ?>
            <span class="muted small">Signed in as <?= $run['signed_in'] ?></span>
        <?php } ?>
    </p>
</div>

<?php if ($notice !== null) { ?>
    <div class="notice" role="status"><?= $notice ?></div>
<?php } ?>

<?php if ($owner && $action !== null) { ?>
    <section class="card action-card" data-action="<?= $action['type'] ?>" data-step="<?= $action['step'] ?? '' ?>" data-intent="<?= $action['intent'] ?? '' ?>">
        <?php if ($action['type'] === 'finished') { ?>
            <h2>All done</h2>
            <p>The run is complete. Tokens obtained during the run have been revoked where your server allows it.</p>
        <?php } else { ?>
            <p class="eyebrow">Next step<?= $approvals > 0 ? ' · about ' . $approvals . ' approval' . ($approvals === 1 ? '' : 's') . ' left' : '' ?></p>
            <h2><?= $action['title'] ?></h2>

            <?php if ($action['instructions'] !== '') { ?>
                <p><?= $action['instructions'] ?></p>
            <?php } ?>

            <?php if ($action['type'] === 'navigate') { ?>
                <p><a class="button" id="go" href="/runs/<?= $run['public_id'] ?>/go/<?= $action['attempt_id'] ?>"><?= $action['intent'] === 'approve' ? 'Open the request' : 'Open the request' ?></a></p>
                <details class="small">
                    <summary>Show the request</summary>
                    <pre class="code-block"><code><?= $action['url'] ?></code></pre>
                </details>
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
        <?php } ?>
    </section>
<?php } elseif (!$owner) { ?>
    <div class="notice">You are viewing someone else's test run.</div>
<?php } ?>

<?php if ($can_publish) { ?>
    <?= $view->partial('partials/publish', ['action' => '/runs/' . html_entity_decode($run['public_id']) . '/publish', 'csrf' => html_entity_decode($csrf)]) ?>
<?php } ?>

<?php foreach ($sections as $section) { ?>
    <section class="card catalog-section">
        <h2><?= $section['name'] ?></h2>
        <div class="table-wrap">
            <table class="results">
                <tbody>
                <?php foreach ($section['rows'] as $row) { ?>
                    <tr class="result-<?= $row['outcome'] ?>">
                        <td class="result-icon"><span class="outcome outcome-<?= $row['outcome'] ?>" title="<?= $row['label'] ?>" aria-label="<?= $row['label'] ?>"><?= $symbols[html_entity_decode($row['outcome'])] ?? '·' ?></span></td>
                        <td class="test-id"><a href="/tests/<?= $row['id'] ?>"><?= $row['id'] ?></a></td>
                        <td>
                            <span class="result-title"><?= $row['title'] ?></span>
                            <?php if ($row['summary'] !== '') { ?><span class="result-summary"><?= $row['summary'] ?></span><?php } ?>
                        </td>
                        <td class="test-meta small">
                            <?php if ($row['has_log']) { ?><a href="/runs/<?= $run['public_id'] ?>/tests/<?= $row['id'] ?>">HTTP log</a><?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>
