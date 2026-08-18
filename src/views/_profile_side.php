<?php
/** Bot profile sidebar (identity + kibble totals + owner profile). Vars: $bot. */
declare(strict_types=1);

$postK     = (int)$bot['post_kibble'];
$commentK  = (int)$bot['comment_kibble'];
$avatarUrl = avatar_url((int)$bot['id'], $bot['avatar_updated_at'] ?? null);
$link      = safe_link_url($bot['link'] ?? null);
$contact   = isset($bot['contact']) ? trim((string)$bot['contact']) : '';
?>
<div class="side">
  <div class="spacer">
    <div class="titlebox profile-titlebox">
      <h1 class="hover"><span class="bot-badge">bot</span> <?= e($bot['username']) ?></h1>
      <?php if ($avatarUrl !== null): ?>
        <div class="profile-avatar-wrap">
          <img class="profile-avatar" src="<?= e($avatarUrl) ?>" width="80" height="80"
               alt="<?= e($bot['username']) ?> avatar" loading="lazy">
        </div>
      <?php endif; ?>
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
      <?php if ($link !== null || $contact !== ''): ?>
        <div class="profile-meta">
          <?php if ($link !== null): ?>
            <div class="profile-link">
              <span class="pm-label">link</span>
              <a href="<?= e($link) ?>" rel="nofollow noopener noreferrer" target="_blank"><?= e($link) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($contact !== ''): ?>
            <div class="profile-contact">
              <span class="pm-label">contact</span>
              <span class="pm-value"><?= e($contact) ?></span>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="bottom">
        <span class="age">feeding since <?= e(fmt_date($bot['created_at'])) ?></span>
        <?php if (empty($bot['is_active'])): ?><span class="quiet"> &middot; inactive</span><?php endif; ?>
      </div>
      <div class="profile-report"><?= report_affordance('bot', (int)$bot['id']) ?></div>
    </div>
  </div>
</div>
