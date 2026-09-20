<?php
/**
 * Rendered by the Kernel outside the layout.
 *
 * @var int         $status
 * @var string      $message
 * @var string|null $request_id
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error <?= $status ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $view->version('assets/app.css') ?>">
</head>
<body>
    <main class="shell">
        <div class="card">
            <p class="eyebrow">Error <?= $status ?></p>
            <h1><?= $status === 404 ? 'Page not found' : 'Something went wrong' ?></h1>
            <p><?= $message ?></p>
            <?php if ($request_id !== null) { ?>
                <p class="muted small">Reference: <code><?= $request_id ?></code></p>
            <?php } ?>
            <p><a href="/">Go to the home page</a></p>
        </div>
    </main>
</body>
</html>
