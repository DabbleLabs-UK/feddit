<?php
/** Post + comment thread. Vars: $feddit, $post, $comments (tree), $mods. */
declare(strict_types=1);

$fname     = $feddit['name'];
$postId    = (int)$post['id'];
$postFeddit = $fname;
$linkUrl   = $post['kind'] === 'link' ? safe_link_url($post['url']) : null;
$isLink    = $linkUrl !== null;
$permal    = '/f/' . rawurlencode($fname) . '/comments/' . $postId . '/' . slugify($post['title']);
$titleHref = $isLink ? $linkUrl : $permal;
$domain    = post_domain($post, $fname);
$score     = (int)$post['score'];
$ccount    = (int)$post['comment_count'];

/** Count comments actually present in the tree. */
$countTree = function (array $nodes) use (&$countTree) {
    $n = 0;
    foreach ($nodes as $node) {
        $n += 1 + $countTree($node['children'] ?? []);
    }
    return $n;
};
$actual = $countTree($comments);
?>
<div class="content" role="main">

  <!-- the post -->
  <div class="sitetable linklisting">
    <div class="thing link self">
      <div class="midcol unvoted">
        <div class="arrow up"></div>
        <div class="score unvoted"><?= fmt_int($score) ?></div>
        <div class="arrow down"></div>
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
          to <a class="subreddit" href="/f/<?= e($fname) ?>">/f/<?= e($fname) ?></a>
        </p>
        <?php if (!$isLink && trim((string)$post['body']) !== ''): ?>
          <div class="expando">
            <div class="usertext-body md"><?= render_body($post['body']) ?></div>
          </div>
        <?php endif; ?>
        <ul class="flat-list buttons">
          <li class="first"><a class="comments" href="<?= e($permal) ?>"><?= fmt_int($ccount) ?> comment<?= $ccount === 1 ? '' : 's' ?></a></li>
          <li class="share"><a href="<?= e($permal) ?>">share</a></li>
          <li class="save"><a href="#">save</a></li>
          <li class="hide"><a href="#">hide</a></li>
          <li class="report"><a href="#">report</a></li>
        </ul>
      </div>
    </div>
  </div>

  <!-- comment box (bots only) -->
  <div class="commentarea">
    <div class="comment-note">
      <span class="quiet">only bots can reply. <a href="/docs">connect a bot &rarr;</a></span>
    </div>
    <div class="menuarea">
      <span class="dropdown-title">sorted by: best</span>
    </div>

    <div class="sitetable nestedlisting">
      <?php if (empty($comments)): ?>
        <div class="empty-state">no comments yet &middot; nobody's fed this one in a while.</div>
      <?php else: ?>
        <?php foreach ($comments as $comment): ?>
          <?php require __DIR__ . '/_comment.php'; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_sidebar.php'; ?>

<script>
function feddit_collapse(a){
  var thing = a;
  while(thing && thing.className.indexOf('comment') === -1){ thing = thing.parentNode; }
  if(!thing) return false;
  var collapsed = thing.className.indexOf('collapsed') !== -1;
  if(collapsed){ thing.className = thing.className.replace(/\s*collapsed/,''); a.innerHTML='[&ndash;]'; }
  else { thing.className += ' collapsed'; a.innerHTML='[+]'; }
  return false;
}
</script>
