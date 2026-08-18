<?php
/**
 * Homepage-only sidebar box: the most ACTIVE sub-feddits right now, ranked by
 * CommunityService (recent activity damped by size, so big communities can't
 * pin themselves to the top). Styled as an old.reddit sidecontentbox - small
 * type, tight rows, same borders - not a modern widget.
 *
 * EXPANDABLE: the first DEFAULT_LIMIT rows show by default; any further rows are
 * rendered but collapsed, revealed in place by a "show more" toggle.
 *   - With JS: the extra rows are hidden (CSS default) and the toggle is revealed
 *     by feddit.js; clicking it expands/collapses in place. No flash.
 *   - With JS off: a <noscript> rule un-hides the extra rows (so nothing is
 *     hidden from crawlers / no-JS readers) and the dead toggle stays hidden.
 *
 * Vars:
 *   $activeCommunities  a CommunityService::active() envelope
 */
declare(strict_types=1);

$acEntries = $activeCommunities['entries'] ?? [];
$acShown   = CommunityService::DEFAULT_LIMIT;
$acExtra   = max(0, count($acEntries) - $acShown);
?>
<div class="spacer">
  <div class="sidecontentbox active-communities" id="active-communities">
    <div class="title"><h1>active communities</h1></div>
    <div class="content">
      <?php if (empty($acEntries)): ?>
        <p class="ac-empty"><?= e((string)($activeCommunities['empty'] ?? 'nothing active right now.')) ?></p>
      <?php else: ?>
        <table class="ac-table">
          <tbody class="ac-head">
            <?php foreach (array_slice($acEntries, 0, $acShown) as $en): ?>
              <?php require __DIR__ . '/_active_community_row.php'; ?>
            <?php endforeach; ?>
          </tbody>
          <?php if ($acExtra > 0): ?>
          <tbody class="ac-extra">
            <?php foreach (array_slice($acEntries, $acShown) as $en): ?>
              <?php require __DIR__ . '/_active_community_row.php'; ?>
            <?php endforeach; ?>
          </tbody>
          <?php endif; ?>
        </table>
        <?php if ($acExtra > 0): ?>
          <a class="ac-toggle" href="#" role="button" aria-expanded="false"
             data-more="show more (<?= (int)$acExtra ?>)" data-less="show less">show more (<?= (int)$acExtra ?>)</a>
          <noscript><style>#active-communities .ac-extra{display:table-row-group}#active-communities .ac-toggle{display:none}</style></noscript>
        <?php endif; ?>
      <?php endif; ?>
      <div class="ac-foot">
        <a class="ac-json" href="/api/v1/communities/active.json">view as json</a>
      </div>
    </div>
  </div>
</div>
