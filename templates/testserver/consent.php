<?php
/**
 * @var string $test_id
 * @var string $test_title
 * @var bool   $decoy
 * @var string $attempt
 * @var string $csrf
 * @var string $client_id
 * @var string $redirect_uri
 * @var string $scope
 * @var string $me
 * @var list<string> $warnings
 * @var bool   $owner
 * @var string $action
 */
?>
<div class="card test-server">
    <p class="eyebrow">TEST SERVER · not a real sign-in</p>
    <h1><?= $decoy ? 'Decoy server for ' : '' ?><?= $test_id ?>: <?= $test_title ?></h1>

    <?php if ($decoy) { ?>
        <div class="alert">Your client is using the decoy server for this test, which it should have ignored.</div>
    <?php } ?>

    <dl class="facts">
        <dt>Application</dt><dd class="wrap"><?= $client_id ?></dd>
        <dt>Redirect URI</dt><dd class="wrap"><?= $redirect_uri ?></dd>
        <dt>Scope</dt><dd><?= $scope !== '' ? $scope : '(none — sign in only)' ?></dd>
        <dt>Profile URL</dt><dd class="wrap"><?= $me !== '' ? $me : '(not sent)' ?></dd>
    </dl>

    <?php foreach ($warnings as $warning) { ?>
        <div class="notice"><?= $warning ?></div>
    <?php } ?>

    <form method="post" action="<?= $action ?>" class="stack">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="attempt" value="<?= $attempt ?>">
        <?php if (!$owner) { ?>
            <div class="field">
                <label for="pin">PIN from your client test run page</label>
                <input id="pin" name="pin" inputmode="numeric" autocomplete="off" maxlength="6" required>
            </div>
        <?php } ?>
        <div class="actions">
            <button type="submit" name="action" value="approve" class="button">Approve</button>
            <button type="submit" name="action" value="deny" class="button button-quiet">Deny</button>
        </div>
    </form>
</div>
