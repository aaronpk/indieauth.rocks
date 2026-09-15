<?php
/**
 * @var string      $csrf
 * @var string      $next
 * @var string|null $error
 * @var string      $me
 */
?>
<div class="page-header">
    <p class="eyebrow">Sign in</p>
    <h1>Sign in with your website</h1>
    <p class="lede">
        Client test runs belong to the person who started them. Enter your profile URL: your browser goes
        to your IndieAuth server to confirm it's you, then comes back here. This site only asks who you are,
        so no tokens are issued.
    </p>
</div>

<?php if ($error !== null) { ?>
    <div class="alert" role="alert"><?= $error ?></div>
<?php } ?>

<section class="card">
    <form method="post" action="/sign-in" class="stack">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="next" value="<?= $next ?>">
        <div class="field">
            <label for="me">Your profile URL</label>
            <input id="me" name="me" type="url" required placeholder="https://example.com/" value="<?= $me ?>"
                   autocapitalize="none" spellcheck="false" inputmode="url">
        </div>
        <button type="submit" class="button">Sign in</button>
    </form>
    <p class="small muted">Testing a server? <a href="/server-tests">Start a server test</a> instead: its first step signs you in too.</p>
</section>
