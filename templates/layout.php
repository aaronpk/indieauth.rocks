<?php
/**
 * @var string      $title
 * @var string      $content   Pre-rendered markup.
 * @var string      $site_name
 * @var string      $home_url
 * @var string|null $nav_user
 * @var string|null $nav_csrf
 * @var string|null $script
 * @var bool        $wide
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title === $site_name ? $site_name : $title . ' · ' . $site_name ?></title>
    <link rel="stylesheet" href="<?= $home_url ?>assets/app.css">
</head>
<body>
    <header class="site-header">
        <div class="bar">
            <a class="brand" href="<?= $home_url ?>"><span class="brand-mark" aria-hidden="true"></span><?= $site_name ?></a>
            <nav class="nav" aria-label="Main">
                <a href="<?= $home_url ?>server-tests">Server tests</a>
                <a href="<?= $home_url ?>client-tests">Client tests</a>
                <a href="<?= $home_url ?>implementations">Reports</a>
                <?php if ($nav_user !== null) { ?>
                    <a href="<?= $home_url ?>account"><?= $nav_user ?></a>
                    <form method="post" action="<?= $home_url ?>logout" class="nav-form">
                        <input type="hidden" name="_csrf" value="<?= $nav_csrf ?>">
                        <button type="submit" class="link-button">Sign out</button>
                    </form>
                <?php } ?>
            </nav>
        </div>
    </header>

    <main class="shell<?= $wide ? ' wide' : '' ?>">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <nav aria-label="Footer">
            <a href="<?= $home_url ?>server-tests">Server tests</a>
            <a href="<?= $home_url ?>client-tests">Client tests</a>
            <a href="https://indieauth.spec.indieweb.org/">IndieAuth spec</a>
        </nav>
        <p>A test suite for the <a href="https://indieauth.spec.indieweb.org/">IndieAuth</a> protocol.</p>
    </footer>

    <?php if ($script !== null) { ?>
        <script src="<?= $home_url ?>assets/<?= $script ?>"></script>
    <?php } ?>
</body>
</html>
