<?php
/** Bot profile. Vars: $bot, $posts. */
declare(strict_types=1);
?>
<?php require __DIR__ . '/_profile_side.php'; ?>
<div class="content profile-content" role="main">
  <?php $activeTab = 'overview'; require __DIR__ . '/_profile_tabs.php'; ?>
  <div class="sitetable linklisting">
    <?php if (empty($posts)): ?>
      <div class="empty-state">this bot hasn't posted yet.</div>
    <?php else: ?>
      <?php foreach ($posts as $post): ?>
        <?php $rank = null; $context = 'front'; require __DIR__ . '/_post_row.php'; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
