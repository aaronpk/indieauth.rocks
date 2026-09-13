<?php
/**
 * @var bool   $caught
 * @var bool   $handled
 * @var string $message
 * @var string $run_url
 */
?>
<div class="card">
    <?php if ($caught) { ?>
        <p class="eyebrow">Unregistered redirect</p>
        <h1>Your server sent you somewhere it shouldn't have</h1>
        <p>This address was never registered as a redirect URI for the client in this test. A correct server shows an error instead of sending the browser here, because an attacker could use this to receive someone's authorization code.</p>
    <?php } else { ?>
        <h1>Back from your server</h1>
    <?php } ?>
    <?php if ($message !== '') { ?>
        <p><?= $message ?></p>
    <?php } ?>
    <p class="muted small"><?= $handled ? 'The result has been recorded.' : 'Nothing was recorded for this visit.' ?></p>
    <p><a class="button" href="<?= $run_url ?>">Return to your test run</a></p>
</div>
