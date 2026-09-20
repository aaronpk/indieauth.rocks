<?php
/**
 * @var string      $csrf
 * @var string|null $error
 * @var string      $me
 */
?>
<div class="page-header">
    <p class="eyebrow">Check your login</p>
    <h1>Find out why signing in with your website isn't working</h1>
    <p class="lede">
        Enter your website address and this will do exactly what an app does when you sign in with it —
        read your home page, find your IndieAuth server, send you there to approve, and check what comes
        back — showing you every step. When something is wrong, it says which step failed and what to do
        about it.
    </p>
</div>

<?php if ($error !== null) { ?>
    <div class="alert" role="alert"><?= $error ?></div>
<?php } ?>

<section class="card">
    <form method="post" action="/check" class="stack">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <div class="field">
            <label for="me">Your website address</label>
            <input id="me" name="me" type="text" required placeholder="example.com" value="<?= $me ?>"
                   autocapitalize="none" spellcheck="false" inputmode="url" autocomplete="url">
        </div>
        <label class="choice">
            <input type="checkbox" name="token" value="1">
            <span>Also check access tokens, for apps like Micropub clients that post to your site</span>
        </label>
        <button type="submit" class="button">Check my login</button>
    </form>
    <p class="small muted">
        You don't need an account, and this never signs you in here. Nothing you approve gives this site
        access to your website: any token your server issues is cancelled and thrown away when the check
        finishes.
    </p>
</section>

<section class="card">
    <h2>What this is for</h2>
    <p>
        Apps that sign you in with your domain name often report nothing more useful than "login failed".
        The cause is usually one of a handful of things — your home page not naming a server, a server
        whose settings can't be read, or an address that doesn't quite match the one your server reports —
        and each of them looks identical from inside the app.
    </p>
    <p>
        The page you get at the end has its own address, so you can send it to whoever runs your website
        or wrote the app you're trying to use. Secrets are hidden from it.
    </p>
    <p class="small">
        Building an IndieAuth server or app yourself? The <a href="/server-tests">server tests</a> and
        <a href="/client-tests">client tests</a> are far more thorough.
    </p>
</section>
