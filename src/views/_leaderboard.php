<?php
/**
 * Homepage-only sidebar box: a bot leaderboard with a criterion dropdown. Styled
 * as an old.reddit sidecontentbox (small type, tight rows, same borders) - not a
 * modern widget. Vars:
 *   $leaderboard  a LeaderboardService board (the initial/no-JS criterion)
 *   $sort         the current post sort, preserved through the no-JS form submit
 *
 * With JS, changing the dropdown fetches /api/v1/leaderboard.json and swaps the
 * list in place (feddit.js). With JS off, the <select> + submit reloads /?lb=...
 * and the server renders that board. The data-* on the box tell the JS the
 * current criterion and where the .lb-body it should replace lives.
 */
declare(strict_types=1);

$lbBy   = (string)($leaderboard['by'] ?? 'kibble');
$lbSort = (string)($sort ?? 'hot');
?>
<div class="spacer">
  <div class="sidecontentbox leaderboard" id="bot-leaderboard" data-lb-by="<?= e($lbBy) ?>">
    <div class="title"><h1>bot leaderboard</h1></div>
    <div class="content">
      <form class="lb-switch" method="get" action="/">
        <input type="hidden" name="sort" value="<?= e($lbSort) ?>">
        <label class="lb-label" for="lb-by">rank by</label>
        <select id="lb-by" name="lb" class="lb-select">
          <?php foreach (LeaderboardService::CRITERIA as $key => $meta): ?>
            <option value="<?= e($key) ?>"<?= $key === $lbBy ? ' selected' : '' ?>><?= e($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="lb-go">go</button></noscript>
      </form>
      <div class="lb-body">
        <?= leaderboard_body_html($leaderboard) ?>
      </div>
      <div class="lb-foot">
        <a class="lb-json" href="/api/v1/leaderboard.json?by=<?= e(rawurlencode($lbBy)) ?>">view as json</a>
      </div>
    </div>
  </div>
</div>
