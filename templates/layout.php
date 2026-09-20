<?php
/**
 * @var string      $title
 * @var string      $content      Pre-rendered markup.
 * @var string      $site_name
 * @var string      $home_url
 * @var string|null $nav_user
 * @var string|null $nav_csrf
 * @var string|null $script
 * @var bool        $wide
 * @var bool        $show_menu    False on the consent screens a client test sends people to.
 * @var string|null $menu_section The menu item to mark as the current page.
 */
$section  = $menu_section ?? null;
$showMenu = $show_menu ?? true;

$current = static fn (string $key): string => $key === $section ? ' aria-current="page"' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title === $site_name ? $site_name : $title . ' · ' . $site_name ?></title>
    <link rel="stylesheet" href="<?= $home_url ?>assets/app.css?v=<?= $view->version('assets/app.css') ?>">
</head>
<body>
    <header class="site-header">
        <div class="bar">
            <a class="brand" href="<?= $home_url ?>"><span class="brand-mark" aria-hidden="true"></span><?= $site_name ?></a>
            <?php if ($showMenu) { ?>
                <button type="button" class="menu-button" popovertarget="site-menu">
                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18"><path d="M2 4h14M2 9h14M2 14h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Menu
                </button>
            <?php } ?>
        </div>
    </header>

    <?php if ($showMenu) { ?>
        <div class="frame">
            <nav class="site-menu" id="site-menu" aria-label="Main" popover>
                <div class="menu-group">
                    <a href="<?= $home_url ?>check"<?= $current('check') ?>>Check your login</a>
                </div>
                <div class="menu-group">
                    <p class="menu-heading">Test suites</p>
                    <a href="<?= $home_url ?>server-tests"<?= $current('server-tests') ?>>Server tests</a>
                    <a href="<?= $home_url ?>client-tests"<?= $current('client-tests') ?>>Client tests</a>
                    <a href="<?= $home_url ?>implementations"<?= $current('reports') ?>>Reports</a>
                </div>
                <?php if ($nav_admin ?? false) { ?>
                    <div class="menu-group">
                        <p class="menu-heading">Admin</p>
                        <a href="<?= $home_url ?>admin"<?= $current('admin') ?>>Overview</a>
                        <a href="<?= $home_url ?>admin/checkups"<?= $current('admin-checkups') ?>>Checkups</a>
                        <a href="<?= $home_url ?>admin/server-tests"<?= $current('admin-server') ?>>Server tests</a>
                        <a href="<?= $home_url ?>admin/client-tests"<?= $current('admin-client') ?>>Client tests</a>
                    </div>
                <?php } ?>
                <div class="menu-group menu-footer">
                    <?php if ($nav_user !== null) { ?>
                        <p class="menu-user">Signed in as <strong><?= $nav_user ?></strong></p>
                        <a href="<?= $home_url ?>account"<?= $current('account') ?>>Your runs</a>
                        <form method="post" action="<?= $home_url ?>logout">
                            <input type="hidden" name="_csrf" value="<?= $nav_csrf ?>">
                            <button type="submit" class="link-button">Sign out</button>
                        </form>
                    <?php } else { ?>
                        <a href="<?= $home_url ?>sign-in"<?= $current('sign-in') ?>>Sign in</a>
                    <?php } ?>
                </div>
            </nav>
            <main class="shell<?= $wide ? ' wide' : '' ?>">
                <?= $content ?>
            </main>
        </div>
    <?php } else { ?>
        <main class="shell<?= $wide ? ' wide' : '' ?>">
            <?= $content ?>
        </main>
    <?php } ?>

    <footer class="site-footer">
        <nav aria-label="Footer">
            <a href="<?= $home_url ?>check">Check your login</a>
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
