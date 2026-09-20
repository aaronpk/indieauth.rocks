<?php
/**
 * The recorded HTTP for one step or test.
 *
 * @var list<array<string, mixed>> $exchanges
 */
?>
<?php foreach ($exchanges as $e) { ?>
    <section class="card exchange">
        <p class="eyebrow"><?= $e['direction'] === 'outbound' ? 'Request from this site' : 'Request to this site' ?> · <?= $e['created_at'] ?> UTC</p>
        <h2 class="wrap"><code><?= $e['method'] ?></code> <?= $e['url'] ?></h2>
        <?php if ($e['request_headers'] !== '' || $e['request_body'] !== '') { ?>
            <pre class="code-block"><code><?= $e['request_headers'] ?><?= $e['request_body'] !== '' ? "\n\n" . $e['request_body'] : '' ?></code></pre>
        <?php } ?>
        <?php if ($e['error'] !== '') { ?>
            <div class="alert"><?= $e['error'] ?></div>
        <?php } elseif ($e['status'] !== null && $e['direction'] === 'outbound') { ?>
            <p class="small"><strong>Response: HTTP <?= $e['status'] ?></strong></p>
            <pre class="code-block"><code><?= $e['response_headers'] ?><?= $e['response_body'] !== '' ? "\n\n" . $e['response_body'] : '' ?></code></pre>
        <?php } ?>
    </section>
<?php } ?>
