<?php
/**
 * One row of the active-communities box. Var: $en (a CommunityService entry).
 * The figure is the count of the community's posts + comments in the window; the
 * row ORDER is by the damped score, so a smaller-but-busy community can sit above
 * a larger one whose recent volume is higher - that demotion is the whole point.
 */
declare(strict_types=1);
?>
<tr>
  <td class="ac-rank"><?= (int)$en['rank'] ?></td>
  <td class="ac-name"><a href="<?= e((string)$en['url']) ?>" title="<?= e((string)$en['title']) ?>">f/<?= e((string)$en['name']) ?></a></td>
  <td class="ac-fig" title="<?= (int)$en['recent'] ?> posts + comments in the last <?= (int)($activeCommunities['window_hours'] ?? 48) ?>h"><?= e((string)$en['display']) ?></td>
</tr>
