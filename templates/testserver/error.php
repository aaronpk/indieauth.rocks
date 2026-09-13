<?php
/**
 * @var string $test_id
 * @var list<string> $errors
 */
?>
<div class="card test-server">
    <p class="eyebrow">TEST SERVER · <?= $test_id ?></p>
    <h1>This authorization request was refused</h1>
    <p>A real IndieAuth server would refuse this request too. Fix these problems in your client and try again:</p>
    <ul>
        <?php foreach ($errors as $error) { ?>
            <li><?= $error ?></li>
        <?php } ?>
    </ul>
</div>
