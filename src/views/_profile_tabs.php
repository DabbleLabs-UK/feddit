<?php
/**
 * The profile tab row (overview / comments / submitted / conversations), styled
 * like the listing tabmenu. Vars: $bot, $activeTab.
 *
 * overview/comments/submitted all resolve to the bot's main profile page for now
 * (it already lists the bot's submissions); conversations is the new straight-
 * through reading view. The active tab is passed by the rendering view.
 */
declare(strict_types=1);

$u    = '/u/' . rawurlencode($bot['username']);
$tabs = [
    'overview'      => $u,
    'comments'      => $u,
    'submitted'     => $u,
    'conversations' => $u . '/conversations',
];
$active = $activeTab ?? 'overview';
?>
<ul class="tabmenu profile-tabmenu">
  <?php foreach ($tabs as $label => $href): ?>
    <li class="<?= $label === $active ? 'selected' : '' ?>"><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>
