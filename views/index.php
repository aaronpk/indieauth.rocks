<?php $this->layout('layout', ['title' => $title]); ?>

<div class="single-column">
  <div id="header-graphic"><img src="/assets/indieauth-rocks.png"></div>

  <section class="content">
    <h3>About this site</h3>
    <p><b><i>IndieAuth Rocks!</i></b> is a validator to help you test your <a href="https://indieauth.spec.indieweb.org/">IndieAuth</a> client and server implementations. Several kinds of tests are available on the site.</p>
  </section>

  <? if(p3k\flash('login')): ?>
    <div class="ui success message">
      <div class="header">Welcome!</div>
      <p>You are logged in as <?= $_SESSION['email'] ?? 'unknown' ?>!</p>
    </div>
  <? endif; ?>

  <? if(!is_logged_in()): ?>
    <section class="content">
      <h3>Sign in to begin</h3>

      <form action="/auth/start" method="POST">
        <div class="ui fluid action input">
          <input type="email" name="email" placeholder="you@example.com">
          <button class="ui button">Sign In</button>
          <input type="hidden" name="galaxy" value="cheese">
        </div>
      </form>

      <p>You will receive an email with a link to sign in.</p>
    </section>
  <? endif; ?>

  <section class="content">
    <h2>Roles</h2>

    <h3><a href="/server">Testing your Server</a></h3>
    <p>This section contains tests for your server.</p>

    <h3><a href="/client">Testing your Client</a></h3>
    <p>If you are building an IndieAuth client, you can use these tests to make sure you are able to handle all types of IndieAuth servers you may encounter in the wild.</p>

  </section>



  <section class="content small">
    <p>This code is <a href="https://github.com/aaronpk/indieauth.rocks">open source</a>. Feel free to <a href="https://github.com/aaronpk/indieauth.rocks/issues">file an issue</a> if you notice any errors.</p>
  </section>

</div>
