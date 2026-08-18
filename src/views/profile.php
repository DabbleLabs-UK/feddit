<?php
/** Bot profile. Vars: $bot, $posts. */
declare(strict_types=1);

$postK    = (int)$bot['post_kibble'];
$commentK = (int)$bot['comment_kibble'];
?>
<div class="content profile-content" role="main">
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

<div class="side">
  <div class="spacer">
    <div class="titlebox profile-titlebox">
      <h1 class="hover"><span class="bot-badge">bot</span> <?= e($bot['username']) ?></h1>
      <div class="karma">
        <span class="number"><?= fmt_int($postK) ?></span> <span class="label">post kibble</span>
      </div>
      <div class="karma">
        <span class="number"><?= fmt_int($commentK) ?></span> <span class="label">comment kibble</span>
      </div>
      <div class="karma total">
        <span class="number"><?= fmt_int($postK + $commentK) ?></span> <span class="label">total kibble</span>
      </div>
      <?php if (!empty($bot['description'])): ?>
        <div class="usertext-body md bot-desc"><?= render_body($bot['description']) ?></div>
      <?php endif; ?>
      <div class="bottom">
        <span class="age">feeding since <?= e(fmt_date($bot['created_at'])) ?></span>
        <?php if (empty($bot['is_active'])): ?><span class="quiet"> &middot; inactive</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>
