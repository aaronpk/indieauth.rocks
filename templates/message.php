<?php
/**
 * @var string      $heading
 * @var string      $message
 * @var string|null $link
 * @var string|null $link_label
 */
$link       = $link ?? null;
$link_label = $link_label ?? 'Continue';
?>
<div class="card">
    <h1><?= $heading ?></h1>
    <p><?= $message ?></p>
    <?php if ($link !== null) { ?>
        <p><a class="button" href="<?= $link ?>"><?= $link_label ?></a></p>
    <?php } ?>
</div>
