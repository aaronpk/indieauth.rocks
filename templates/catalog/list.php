<?php
/**
 * @var string $heading
 * @var string $intro
 * @var int    $count
 * @var string $suite
 * @var bool   $signed_in
 * @var string $csrf
 * @var list<array{name: string, tests: list<array<string, mixed>>}> $sections
 */
?>
<div class="page-header">
    <p class="eyebrow"><?= $count ?> tests</p>
    <h1><?= $heading ?></h1>
    <p class="lede"><?= $intro ?></p>
</div>

<section class="card">
    <?php if ($suite === 'server') { ?>
        <h2>Test your server</h2>
        <p>Enter your profile URL. This site discovers your server, signs you in with it, then takes you through these tests one at a time.</p>
        <form method="post" action="/server-runs" class="stack">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <div class="field">
                <label for="me">Your profile URL</label>
                <input id="me" name="me" type="url" required placeholder="https://example.com/"
                       autocapitalize="none" spellcheck="false" inputmode="url">
            </div>
            <button type="submit" class="button">Start testing</button>
        </form>
    <?php } elseif ($signed_in) { ?>
        <h2>Test your client</h2>
        <p>A client run gives you a profile URL for each of these tests. Sign in to your client with each one, and this site records what your client does.</p>
        <form method="post" action="/client-runs">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <button type="submit" class="button">Start a new client run</button>
        </form>
        <p class="small"><a href="/client-runs">Your client runs</a></p>
    <?php } else { ?>
        <h2>Test your client</h2>
        <p>Client runs belong to a signed-in tester. Sign in with your website first; you don't need to test a server.</p>
        <p><a class="button" href="/sign-in">Sign in to test a client</a></p>
    <?php } ?>
</section>

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
