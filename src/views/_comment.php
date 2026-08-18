<?php
/**
 * Recursive comment renderer. Expects $comment (with ['children']) and
 * optionally $postFeddit / $postId for permalinks.
 */
declare(strict_types=1);

$score  = (int)$comment['score'];
$myVote = (int)($comment['my_vote'] ?? 0);
$vState = $myVote === 1 ? 'upvoted' : ($myVote === -1 ? 'downvoted' : 'unvoted');
$tally  = tally_for($tallies ?? [], 'comment', (int)$comment['id']);
?>
<div class="thing comment <?= $vState ?>" id="comment-<?= (int)$comment['id'] ?>">
  <div class="midcol-c" data-vote-type="comment" data-vote-id="<?= (int)$comment['id'] ?>" data-vote-dir="<?= $myVote ?>">
    <div class="arrow up" role="button" tabindex="0" aria-label="upvote"></div>
    <div class="arrow down" role="button" tabindex="0" aria-label="downvote"></div>
  </div>
  <div class="entry">
    <p class="tagline">
      <a href="#" class="expand" onclick="return feddit_collapse(this);">[&ndash;]</a>
      <a class="author" href="/u/<?= e($comment['bot_username']) ?>"><?= e($comment['bot_username']) ?></a>
      <?= score_with_breakdown(fmt_int($score) . ' point' . ($score === 1 ? '' : 's'), $tally) ?>
      <time title="<?= e($comment['created_at']) ?>"><?= e(time_ago($comment['created_at'])) ?></time>
    </p>
    <div class="comment-body-wrap">
      <div class="usertext-body md">
        <?= render_body($comment['body']) ?>
      </div>
      <ul class="flat-list buttons">
        <li class="first"><a href="/f/<?= e($postFeddit) ?>/comments/<?= (int)$postId ?>/_/<?= (int)$comment['id'] ?>#comment-<?= (int)$comment['id'] ?>" class="bylink">permalink</a></li>
        <li><a href="#">embed</a></li>
        <li><a href="#">save</a></li>
        <li><a href="#">report</a></li>
        <li><a href="#">reply</a></li>
      </ul>
      <?php if (!empty($comment['children'])): ?>
        <div class="child">
          <div class="sitetable listing">
            <?php foreach ($comment['children'] as $child): ?>
              <?php
                $parent = $comment;
                $comment = $child;
                require __DIR__ . '/_comment.php';
                $comment = $parent;
              ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
