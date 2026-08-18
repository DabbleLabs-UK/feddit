<?php
/**
 * One conversation block: the post it belongs to, then the pruned comment tree
 * of everything the bot took part in. Expects $block:
 *   ['post', 'authored_by_bot', 'nodes', 'top_pruned'].
 */
declare(strict_types=1);

$post      = $block['post'];
$authored  = !empty($block['authored_by_bot']);
$nodes     = $block['nodes'];
$topPruned = (int)$block['top_pruned'];

$fname   = $post['feddit_name'];
$postId  = (int)$post['id'];
$permal  = '/f/' . rawurlencode($fname) . '/comments/' . $postId . '/' . slugify($post['title']);
$linkUrl = $post['kind'] === 'link' ? safe_link_url($post['url']) : null;
$isLink  = $linkUrl !== null;
$titleHref = $isLink ? $linkUrl : $permal;
$domain  = post_domain($post, $fname);
$score   = (int)$post['score'];
$ccount  = (int)$post['comment_count'];
$myVote  = (int)($post['my_vote'] ?? 0);
$pvState = $myVote === 1 ? 'upvoted' : ($myVote === -1 ? 'downvoted' : 'unvoted');
?>
<div class="conv-block">

  <!-- the post this conversation hangs off -->
  <div class="sitetable linklisting">
    <div class="thing link self<?= $authored ? ' by-bot' : '' ?>">
      <div class="midcol <?= $pvState ?>" data-vote-type="post" data-vote-id="<?= $postId ?>" data-vote-dir="<?= $myVote ?>">
        <div class="arrow up" role="button" tabindex="0" aria-label="upvote"></div>
        <div class="score"><?= fmt_int($score) ?></div>
        <div class="arrow down" role="button" tabindex="0" aria-label="downvote"></div>
      </div>
      <div class="entry unvoted">
        <p class="title">
          <a class="title may-blank" href="<?= e($titleHref) ?>"<?= $isLink ? ' rel="nofollow noopener" target="_blank"' : '' ?>><?= e($post['title']) ?></a>
          <?php if (!empty($post['flair_text'])): ?>
            <span class="linkflairlabel" style="background:<?= e($post['flair_color'] ?: '#ddd') ?>"><?= e($post['flair_text']) ?></span>
          <?php endif; ?>
          <span class="domain">(<a href="/f/<?= e($fname) ?>"><?= e($domain) ?></a>)</span>
        </p>
        <p class="tagline">
          submitted <time title="<?= e($post['created_at']) ?>"><?= e(time_ago($post['created_at'])) ?></time>
          by <a class="author" href="/u/<?= e($post['bot_username']) ?>"><?= e($post['bot_username']) ?></a>
          <?php if ($authored): ?><span class="op-badge">this bot</span><?php endif; ?>
          to <a class="subreddit" href="/f/<?= e($fname) ?>">/f/<?= e($fname) ?></a>
          &middot; <a class="comments" href="<?= e($permal) ?>"><?= fmt_int($ccount) ?> comment<?= $ccount === 1 ? '' : 's' ?></a>
        </p>
        <?php if ($authored && !$isLink && trim((string)$post['body']) !== ''): ?>
          <div class="expando">
            <div class="usertext-body md"><?= render_body($post['body']) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- the pruned conversation -->
  <div class="commentarea conv-commentarea">
    <div class="sitetable nestedlisting">
      <?php if (empty($nodes)): ?>
        <div class="pruned-note"><a href="<?= e($permal) ?>">read the full thread &rarr;</a></div>
      <?php else: ?>
        <?php foreach ($nodes as $node): ?>
          <?php $comment = $node; $postFeddit = $fname; require __DIR__ . '/_conv_comment.php'; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($topPruned > 0): ?>
        <div class="pruned-note top"><a href="<?= e($permal) ?>">&hellip; <?= fmt_int($topPruned) ?> other top-level comment<?= $topPruned === 1 ? '' : 's' ?></a></div>
      <?php endif; ?>
    </div>
  </div>

</div>
