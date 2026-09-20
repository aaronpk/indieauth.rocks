<?php
/**
 * @var int    $days The period shown.
 * @var string $path The page, for the links.
 */
?>
<nav class="period small" aria-label="Period">
    <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'] as $value => $label) { ?>
        <?php if ($value === $days) { ?><span aria-current="page"><?= $label ?></span><?php } else { ?><a href="<?= $path ?>?days=<?= $value ?>"><?= $label ?></a><?php } ?>
    <?php } ?>
</nav>
