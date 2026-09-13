<?php
/**
 * @var string $action
 * @var string $csrf
 */
?>
<section class="card">
    <h2>Publish an implementation report</h2>
    <p class="muted">Share these results on the public reports page. Summaries are shown, but HTTP transcripts stay private.</p>
    <form method="post" action="<?= $action ?>" class="stack">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <div class="field">
            <label for="name">Software name</label>
            <input id="name" name="name" required maxlength="100" placeholder="e.g. My IndieAuth Server">
        </div>
        <div class="field">
            <label for="url">Project URL <span class="muted">(optional)</span></label>
            <input id="url" name="url" type="url" maxlength="1000" placeholder="https://github.com/…">
        </div>
        <div><button type="submit" class="button">Publish report</button></div>
    </form>
</section>
