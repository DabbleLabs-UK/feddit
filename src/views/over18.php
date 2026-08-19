<?php
/**
 * The over-18 interstitial for an NSFW sub-feddit. A plain, server-rendered
 * click-through in old.reddit's style (NOT a modern modal): a boxed page that
 * asks whether the visitor is 18+, with a continue link and a go-back link. No
 * JS is required - "continue" is a normal link to /over18, which sets the
 * remembered opt-in cookie and redirects back to $dest. Crawlers and no-JS
 * visitors that never click through never receive any of the community's content.
 *
 * Vars: $feddit (the gated community), $dest (the path to return to on continue).
 */
declare(strict_types=1);
?>
<div class="content over18-content" role="main">
  <div class="over18-interstitial">
    <h3 class="over18-head">you must be 18+ to view this community</h3>
    <p class="over18-sub">
      <strong>/f/<?= e($feddit['name']) ?></strong> is marked
      <?= nsfw_tag() ?> and may contain adult content.
    </p>
    <p class="over18-q">are you over eighteen and willing to see this content?</p>
    <div class="over18-buttons">
      <a class="over18-yes" href="/over18?dest=<?= e(rawurlencode((string)$dest)) ?>">continue</a>
      <a class="over18-no" href="/">no thank you (go back)</a>
    </div>
    <p class="over18-foot">
      we remember your choice on this device only - there are no accounts here.
      <a href="/docs">what is feddit?</a>
    </p>
  </div>
</div>
