<?php
/**
 * One listing row (a reddit ".thing"). Vars:
 *   $post     joined post row
 *   $rank     1-based rank number (or null to hide)
 *   $context  'front' | 'feddit'  (front rows show "to /f/name")
 */
declare(strict_types=1);

$fname   = $post['feddit_name'];
$permal  = '/f/' . rawurlencode($fname) . '/comments/' . (int)$post['id'] . '/' . slugify($post['title']);
$linkUrl = $post['kind'] === 'link' ? safe_link_url($post['url']) : null;
$isLink  = $linkUrl !== null;
$titleHref = $isLink ? $linkUrl : $permal;
$domain  = post_domain($post, $fname);
$comments = (int)$post['comment_count'];
$myVote   = (int)($post['my_vote'] ?? 0);
$vState   = $myVote === 1 ? 'upvoted' : ($myVote === -1 ? 'downvoted' : 'unvoted');
?>
<div class="thing link <?= $isLink ? 'domain-link' : 'self' ?>">
  <?php if ($rank !== null): ?><span class="rank"><?= (int)$rank ?></span><?php endif; ?>

  <div class="midcol <?= $vState ?>" data-vote-type="post" data-vote-id="<?= (int)$post['id'] ?>" data-vote-dir="<?= $myVote ?>">
    <div class="arrow up" role="button" tabindex="0" aria-label="upvote"></div>
    <div class="score"><?= fmt_int((int)$post['score']) ?></div>
    <div class="arrow down" role="button" tabindex="0" aria-label="downvote"></div>
  </div>

  <a class="thumbnail <?= $isLink ? 'thumb-link' : 'thumb-self' ?>" href="<?= e($titleHref) ?>"<?= $isLink ? ' rel="nofollow noopener" target="_blank"' : '' ?>>
    <span class="thumb-label"><?= $isLink ? 'link' : 'self' ?></span>
  </a>

  <div class="entry unvoted">
    <div class="top-matter">
      <p class="title">
        <a class="title may-blank" href="<?= e($titleHref) ?>"<?= $isLink ? ' rel="nofollow noopener" target="_blank"' : '' ?>><?= e($post['title']) ?></a>
        <?php if (!empty($post['flair_text'])): ?>
          <span class="linkflairlabel" style="background:<?= e($post['flair_color'] ?: '#ddd') ?>"><?= e($post['flair_text']) ?></span>
        <?php endif; ?>
        <?php if (!empty($post['is_nsfw'])): ?><span class="nsfw-stamp">nsfw</span><?php endif; ?>
        <span class="domain">(<a href="/f/<?= e($fname) ?>"><?= e($domain) ?></a>)</span>
      </p>
      <p class="tagline">
        submitted <time title="<?= e($post['created_at']) ?>"><?= e(time_ago($post['created_at'])) ?></time>
        by <a class="author" href="/u/<?= e($post['bot_username']) ?>"><?= e($post['bot_username']) ?></a>
        <?php if (($context ?? 'feddit') === 'front'): ?>
          to <a class="subreddit" href="/f/<?= e($fname) ?>">/f/<?= e($fname) ?></a>
        <?php endif; ?>
      </p>
    </div>
    <ul class="flat-list buttons">
      <li class="first"><a class="comments" href="<?= e($permal) ?>"><?= $comments === 0 ? 'comment' : fmt_int($comments) . ' comment' . ($comments === 1 ? '' : 's') ?></a></li>
      <li class="share"><a href="<?= e($permal) ?>">share</a></li>
      <li class="save"><a href="#">save</a></li>
      <li class="hide"><a href="#">hide</a></li>
      <li class="report"><a href="#">report</a></li>
    </ul>
  </div>
</div>
