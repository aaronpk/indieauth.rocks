<?php
/**
 * @var string      $site_name
 * @var string      $csrf
 * @var string|null $nav_user
 */
?>
<section class="hero">
    <p class="eyebrow">Test suite</p>
    <h1><span class="highlight">IndieAuth</span> Rocks!</h1>
    <p class="lede">
        Check that your IndieAuth server or client follows the
        <a href="https://indieauth.spec.indieweb.org/">specification</a> — including the parts
        that keep people's accounts safe when something goes wrong.
    </p>
</section>

<section class="roles">
    <div class="card role">
        <h2>Test your server</h2>
        <p>
            Enter your profile URL. This site acts as an IndieAuth client: it discovers your
            server, signs you in with it, then runs through authorization, token, refresh,
            revocation and security tests. Some steps ask you to approve or cancel a request.
        </p>
        <form method="post" action="/server-runs" class="stack">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <div class="field">
                <label for="me">Your profile URL</label>
                <input id="me" name="me" type="url" required placeholder="https://example.com/"
                       autocapitalize="none" spellcheck="false" inputmode="url">
            </div>
            <button type="submit" class="button">Start testing</button>
        </form>
        <p class="small"><a href="/server-tests">See all server tests</a></p>
    </div>

    <div class="card role">
        <h2>Test your client</h2>
        <p>
            This site acts as an IndieAuth server with a set of test profile URLs. Sign in to your
            client with each one: some are set up normally, others misbehave on purpose to check that
            your client catches it.
        </p>
        <?php if ($nav_user !== null) { ?>
            <p><a class="button" href="/client-runs">Go to client tests</a></p>
        <?php } else { ?>
            <p><a class="button" href="/sign-in">Sign in to test a client</a></p>
        <?php } ?>
        <p class="small"><a href="/client-tests">See all client tests</a></p>
    </div>
</section>

<section class="card role-checkup">
    <h2>Trouble signing in somewhere?</h2>
    <p>
        If an app won't let you sign in with your domain name and only says "login failed", this walks
        through the whole login with your own server and shows you every step — where it stopped, and
        what to do about it. No account needed.
    </p>
    <form method="post" action="/check" class="stack">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <div class="field">
            <label for="check-me">Your website address</label>
            <input id="check-me" name="me" type="text" required placeholder="example.com"
                   autocapitalize="none" spellcheck="false" inputmode="url" autocomplete="url">
        </div>
        <button type="submit" class="button button-quiet">Check my login</button>
    </form>
</section>

<section class="features">
    <div class="feature">
        <h3>Baseline behavior</h3>
        <p>Discovery, authorization requests, PKCE, code exchange, token responses and profile information.</p>
    </div>
    <div class="feature">
        <h3>Security checks</h3>
        <p>Unregistered redirect URIs, code replay, wrong verifiers, <code>iss</code> and <code>state</code> handling, and identity verification.</p>
    </div>
    <div class="feature">
        <h3>Implementation reports</h3>
        <p>Publish your results so others can see how well your software supports IndieAuth.</p>
    </div>
</section>
