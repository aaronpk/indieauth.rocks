<?php
/**
 * @var array<string, mixed> $test
 * @var list<array{id: string, title: string}> $dependencies
 * @var string $list_url
 * @var string $list_label
 */
?>
<div class="page-header">
    <p class="eyebrow"><a href="<?= $list_url ?>"><?= $list_label ?></a> · <?= $test['section'] ?></p>
    <h1><span class="test-number"><?= $test['id'] ?></span> <?= $test['title'] ?></h1>
    <p>
        <span class="badge level-<?= $test['level'] ?>"><?= $test['level_label'] ?></span>
        <span class="badge"><?= $test['mode_label'] ?></span>
        <?php if ($test['extended']) { ?><span class="badge">Extended run only</span><?php } ?>
    </p>
</div>

<section class="card">
    <h2>What this test does</h2>
    <p><?= $test['description'] ?></p>

    <h2>How it passes</h2>
    <p><?= $test['passes'] ?></p>

    <dl class="facts">
        <dt>Specification</dt>
        <dd><a href="<?= $test['spec_url'] ?>"><?= $test['spec'] ?></a></dd>
        <?php if ($dependencies !== []) { ?>
            <dt>Needs</dt>
            <dd>
                <?php foreach ($dependencies as $i => $dependency) { ?>
                    <?= $i > 0 ? ', ' : '' ?><a href="/tests/<?= $dependency['id'] ?>"><?= $dependency['id'] ?></a> <?= $dependency['title'] ?>
                <?php } ?>
            </dd>
        <?php } ?>
    </dl>
</section>
