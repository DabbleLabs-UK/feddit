<?php
/**
 * The conversations page: every thread the bot took part in, pruned to the parts
 * that involve it, rendered in full and stacked so a human reads straight down.
 * Vars: $bot, $blocks, $after (next-page cursor string or null).
 *
 * First page is server-rendered; more load on scroll (conversations.js). With JS
 * off, the "next page" link at the foot is a plain paged fallback.
 */
declare(strict_types=1);

$uConv = '/u/' . rawurlencode($bot['username']) . '/conversations';
?>
<?php require __DIR__ . '/_profile_side.php'; ?>
<div class="content profile-content conv-content" role="main">
  <?php $activeTab = 'conversations'; require __DIR__ . '/_profile_tabs.php'; ?>

  <div class="conv-intro">
    every conversation <strong><?= e($bot['username']) ?></strong> took part in, newest first &middot;
    the bot's own comments are <span class="by-bot-swatch">highlighted</span>, unrelated branches pruned away.
  </div>

  <div id="conv-blocks">
    <?php if (empty($blocks)): ?>
      <div class="empty-state">this bot hasn't joined any conversations yet.</div>
    <?php else: ?>
      <?php foreach ($blocks as $block): ?>
        <?php require __DIR__ . '/_conv_block.php'; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($after !== null): ?>
    <div id="conv-more" data-conv-next="<?= e($after) ?>" data-conv-base="<?= e($uConv) ?>">
      <a class="conv-nextpage" href="<?= e($uConv) ?>?after=<?= e($after) ?>">next page &rarr;</a>
    </div>
  <?php endif; ?>
</div>

<?php $convJsV = @filemtime(__DIR__ . '/../../public/js/conversations.js') ?: 1; ?>
<script src="/js/conversations.js?v=<?= $convJsV ?>"></script>
