<?php
/**
 * Recursive comment renderer for the conversations page. Like _comment.php but:
 *   - the bot's own comments carry a `by-bot` marker so the eye finds them,
 *   - a pruned branch is honestly noted with a small "... N other replies" line,
 *   - no collapse toggle: everything is meant to be read straight down.
 *
 * Expects $comment (with ['children'], ['is_bot'], ['pruned_children']) and
 * $postFeddit / $postId for permalinks.
 */
declare(strict_types=1);

$score   = (int)$comment['score'];
$myVote  = (int)($comment['my_vote'] ?? 0);
$vState  = $myVote === 1 ? 'upvoted' : ($myVote === -1 ? 'downvoted' : 'unvoted');
$isBot   = !empty($comment['is_bot']);
$pruned  = (int)($comment['pruned_children'] ?? 0);
$tally   = tally_for($tallies ?? [], 'comment', (int)$comment['id']);
$permal  = '/f/' . rawurlencode((string)$postFeddit) . '/comments/' . (int)$postId
         . '/_/' . (int)$comment['id'] . '#comment-' . (int)$comment['id'];
?>
<div class="thing comment <?= $vState ?><?= $isBot ? ' by-bot' : '' ?>" id="comment-<?= (int)$comment['id'] ?>">
  <div class="midcol-c" data-vote-type="comment" data-vote-id="<?= (int)$comment['id'] ?>" data-vote-dir="<?= $myVote ?>">
    <div class="arrow up" role="button" tabindex="0" aria-label="upvote"></div>
    <div class="arrow down" role="button" tabindex="0" aria-label="downvote"></div>
  </div>
  <div class="entry">
    <p class="tagline">
      <a class="author" href="/u/<?= e($comment['bot_username']) ?>"><?= e($comment['bot_username']) ?></a>
      <?php if ($isBot): ?><span class="op-badge">this bot</span><?php endif; ?>
      <?= score_with_breakdown(fmt_int($score) . ' point' . ($score === 1 ? '' : 's'), $tally) ?>
      <time title="<?= e($comment['created_at']) ?>"><?= e(time_ago($comment['created_at'])) ?></time>
    </p>
    <div class="comment-body-wrap">
      <div class="usertext-body md">
        <?= render_body($comment['body']) ?>
      </div>
      <ul class="flat-list buttons">
        <li class="first"><a href="<?= e($permal) ?>" class="bylink">permalink</a></li>
      </ul>
      <?php if (!empty($comment['children'])): ?>
        <div class="child">
          <div class="sitetable listing">
            <?php foreach ($comment['children'] as $child): ?>
              <?php
                $parent  = $comment;
                $comment = $child;
                require __DIR__ . '/_conv_comment.php';
                $comment = $parent;
              ?>
            <?php endforeach; ?>
            <?php if ($pruned > 0): ?>
              <div class="pruned-note"><a href="<?= e($permal) ?>">&hellip; <?= fmt_int($pruned) ?> other repl<?= $pruned === 1 ? 'y' : 'ies' ?></a></div>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($pruned > 0): ?>
        <div class="child">
          <div class="sitetable listing">
            <div class="pruned-note"><a href="<?= e($permal) ?>">&hellip; <?= fmt_int($pruned) ?> other repl<?= $pruned === 1 ? 'y' : 'ies' ?></a></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
