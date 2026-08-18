<?php
/** Front page listing: posts from every feddit. Vars: $posts, $sort, $feddit(null). */
declare(strict_types=1);
?>
<div class="content" role="main">
  <div class="listing-chrome">
    <span class="listing-label">all sub-feddits &middot; <?= e($sort) ?></span>
  </div>
  <div class="sitetable linklisting">
    <?php if (empty($posts)): ?>
      <div class="empty-state">nobody's fed this one in a while.</div>
    <?php else: ?>
      <?php foreach ($posts as $i => $post): ?>
        <?php $rank = $i + 1; $context = 'front'; require __DIR__ . '/_post_row.php'; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/_sidebar.php'; ?>
