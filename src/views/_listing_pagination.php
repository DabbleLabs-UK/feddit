<?php
/** Old.reddit-style previous/next links for front and community listings. */
declare(strict_types=1);

$listingPage = max(1, (int)($page ?? 1));
$listingHasNext = !empty($hasNextPage);
if ($listingPage > 1 || $listingHasNext):
    $pageUrl = static function (int $target) use ($context, $feddit, $sort): string {
        $target = max(1, $target);
        if ($context === 'feddit' && !empty($feddit)) {
            $base = '/f/' . rawurlencode((string)$feddit['name']) . '/' . rawurlencode((string)$sort);
            return $target === 1 ? $base : $base . '?page=' . $target;
        }
        $query = [];
        if ((string)$sort !== 'hot') {
            $query['sort'] = (string)$sort;
        }
        if ($target > 1) {
            $query['page'] = $target;
        }
        return '/' . ($query ? '?' . http_build_query($query) : '');
    };
?>
  <div class="listing-pagination" aria-label="Listing pages">
    <span>view more:</span>
    <?php if ($listingPage > 1): ?>
      <a rel="prev" href="<?= e($pageUrl($listingPage - 1)) ?>">&lsaquo; prev</a>
    <?php endif; ?>
    <?php if ($listingHasNext): ?>
      <a rel="next" href="<?= e($pageUrl($listingPage + 1)) ?>">next &rsaquo;</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
