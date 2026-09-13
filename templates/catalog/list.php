<?php
/**
 * @var string $heading
 * @var string $intro
 * @var int    $count
 * @var list<array{name: string, tests: list<array<string, mixed>>}> $sections
 */
?>
<div class="page-header">
    <p class="eyebrow"><?= $count ?> tests</p>
    <h1><?= $heading ?></h1>
    <p class="lede"><?= $intro ?></p>
</div>

<div class="legend small">
    <span class="badge level-must">Required</span> a MUST in the spec; not doing it fails the test.
    <span class="badge level-should">Recommended</span> a SHOULD or security best practice; not doing it is a warning.
    <span class="badge level-may">Optional</span> or <span class="badge level-info">Informational</span> never fail.
</div>

<?php foreach ($sections as $section) { ?>
    <section class="card catalog-section">
        <h2><?= $section['name'] ?></h2>
        <div class="table-wrap">
            <table class="catalog">
                <tbody>
                <?php foreach ($section['tests'] as $test) { ?>
                    <tr>
                        <td class="test-id"><a href="/tests/<?= $test['id'] ?>"><?= $test['id'] ?></a></td>
                        <td>
                            <a href="/tests/<?= $test['id'] ?>"><?= $test['title'] ?></a>
                            <?php if ($test['extended']) { ?><span class="tag">extended</span><?php } ?>
                        </td>
                        <td class="test-meta"><span class="badge level-<?= $test['level'] ?>"><?= $test['level_label'] ?></span></td>
                        <td class="test-meta muted small"><?= $test['mode_label'] ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
<?php } ?>
