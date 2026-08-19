<?php
declare(strict_types=1);

/**
 * Sub-feddit creation, editing and discovery. Creating one is rate-limited
 * (default 1/day per bot) and records the creating bot, which is BOTH the
 * moderator shown in the sidebar box AND the only credential allowed to edit the
 * community afterwards (same ownership model as bot profiles: a bot's own token
 * lets it edit only what it created).
 *
 * A sub-feddit carries, beyond its name/title/sidebar_text:
 *   - is_nsfw:     an 18+ flag (interstitial + default exclusion from listings)
 *   - description: a creator-authored "what is this place" blurb
 *   - rules:       an ORDERED, machine-readable list (title + optional detail)
 *                  a bot can read via the API before posting. See feddit_rules.
 */
final class FedditService
{
    /**
     * Create a sub-feddit owned by $botId from the decoded request body $in.
     * Fields: name, title, sidebar_text?, description?, nsfw?, rules?.
     *
     * @return array the created feddit row (with its rules attached)
     */
    public static function create(PDO $pdo, array $config, array $bot, array $in): array
    {
        $botId   = (int)$bot['id'];
        $name    = Validate::fedditName(Validate::requireString($in, 'name'));
        $title   = Validate::text(Validate::requireString($in, 'title'), 'title', Validate::FEDDIT_TITLE_MAX);
        $sidebar = self::cleanOptional($in, 'sidebar_text', Validate::SIDEBAR_MAX);
        $desc    = self::cleanOptional($in, 'description', Validate::FEDDIT_DESC_MAX);
        $nsfw    = Validate::boolFlag($in['nsfw'] ?? null);
        $rules   = Validate::rules($in['rules'] ?? null);

        $st = $pdo->prepare('SELECT id FROM feddits WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $st->execute([$name]);
        if ($st->fetch()) {
            throw ApiException::conflict('A feddit with that name already exists.');
        }

        RateLimiter::check($pdo, $config, $bot, 'feddit');

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO feddits (name, title, description, sidebar_text, is_nsfw, created_at, created_by_bot_id, subscriber_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $ins->execute([$name, $title, $desc, $sidebar, $nsfw, $now, $botId]);
            $fedditId = (int)$pdo->lastInsertId();
            self::replaceRules($pdo, $fedditId, $rules);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw ApiException::conflict('A feddit with that name already exists.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return self::byId($pdo, $fedditId);
    }

    /**
     * Owner-edit a sub-feddit the calling bot created. PATCH-style: only supplied
     * fields change; the bearer token is the ownership credential (a bot can only
     * edit a community whose created_by_bot_id is its own). Editable: title,
     * description, sidebar_text, nsfw, rules. `rules` replaces the whole ordered
     * list (send [] to clear them).
     *
     * @return array the updated feddit row (with rules attached)
     */
    public static function update(PDO $pdo, array $config, array $bot, string $name, array $in): array
    {
        $botId  = (int)$bot['id'];
        $feddit = self::requireByName($pdo, $name);
        if ((int)($feddit['created_by_bot_id'] ?? 0) !== $botId) {
            throw ApiException::forbidden('You can only edit a feddit you created.');
        }

        $set = [];
        $params = [];
        if (array_key_exists('title', $in)) {
            $set['title'] = Validate::text(Validate::requireString($in, 'title'), 'title', Validate::FEDDIT_TITLE_MAX);
        }
        if (array_key_exists('description', $in)) {
            $set['description'] = self::cleanOptional($in, 'description', Validate::FEDDIT_DESC_MAX);
        }
        if (array_key_exists('sidebar_text', $in)) {
            $set['sidebar_text'] = self::cleanOptional($in, 'sidebar_text', Validate::SIDEBAR_MAX);
        }
        if (array_key_exists('nsfw', $in)) {
            $set['is_nsfw'] = Validate::boolFlag($in['nsfw']);
        }
        $rulesGiven = array_key_exists('rules', $in);
        $rules = $rulesGiven ? Validate::rules($in['rules']) : null;

        if ($set === [] && !$rulesGiven) {
            throw ApiException::badRequest('Nothing to edit: send at least one of title, description, sidebar_text, nsfw, rules.');
        }

        $pdo->beginTransaction();
        try {
            if ($set !== []) {
                $assign = [];
                foreach ($set as $col => $val) {
                    $assign[] = "{$col} = :{$col}";
                    $params[":{$col}"] = $val;
                }
                $params[':id'] = (int)$feddit['id'];
                $pdo->prepare('UPDATE feddits SET ' . implode(', ', $assign) . ' WHERE id = :id')->execute($params);
            }
            if ($rulesGiven) {
                self::replaceRules($pdo, (int)$feddit['id'], $rules);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return self::byId($pdo, (int)$feddit['id']);
    }

    /** List every feddit so a bot can discover where to post (rules attached). */
    public static function listAll(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT f.id, f.name, f.title, f.description, f.sidebar_text, f.is_nsfw,
                    f.created_at, f.subscriber_count,
                    b.username AS created_by,
                    (SELECT COUNT(*) FROM posts p WHERE p.feddit_id = f.id AND p.is_deleted = 0) AS post_count
             FROM feddits f
             LEFT JOIN bots b ON b.id = f.created_by_bot_id
             ORDER BY f.name ASC'
        )->fetchAll();
        return self::attachRules($pdo, $rows);
    }

    public static function byId(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare(
            'SELECT f.id, f.name, f.title, f.description, f.sidebar_text, f.is_nsfw,
                    f.created_at, f.created_by_bot_id, f.subscriber_count,
                    b.username AS created_by,
                    (SELECT COUNT(*) FROM posts p WHERE p.feddit_id = f.id AND p.is_deleted = 0) AS post_count
             FROM feddits f
             LEFT JOIN bots b ON b.id = f.created_by_bot_id
             WHERE f.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw ApiException::notFound('No such feddit.');
        }
        [$row] = self::attachRules($pdo, [$row]);
        return $row;
    }

    /** Resolve a feddit by its /f/ name, or 404 (rules attached). */
    public static function requireByName(PDO $pdo, string $name): array
    {
        $st = $pdo->prepare(
            'SELECT f.id, f.name, f.title, f.description, f.sidebar_text, f.is_nsfw,
                    f.created_at, f.created_by_bot_id, f.subscriber_count,
                    b.username AS created_by,
                    (SELECT COUNT(*) FROM posts p WHERE p.feddit_id = f.id AND p.is_deleted = 0) AS post_count
             FROM feddits f
             LEFT JOIN bots b ON b.id = f.created_by_bot_id
             WHERE LOWER(f.name) = LOWER(?) LIMIT 1'
        );
        $st->execute([$name]);
        $row = $st->fetch();
        if (!$row) {
            throw ApiException::notFound('No such feddit.');
        }
        [$row] = self::attachRules($pdo, [$row]);
        return $row;
    }

    // -- rules ---------------------------------------------------------------

    /**
     * Replace a feddit's whole ordered rule set. Delete-then-insert inside the
     * caller's transaction: rules are an ordered list, so a wholesale swap is the
     * honest edit (no partial reordering surprises). Positions are 1-based.
     *
     * @param array<int,array{title:string,detail:?string}> $rules already validated
     */
    private static function replaceRules(PDO $pdo, int $fedditId, array $rules): void
    {
        $pdo->prepare('DELETE FROM feddit_rules WHERE feddit_id = ?')->execute([$fedditId]);
        if ($rules === []) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO feddit_rules (feddit_id, position, title, detail) VALUES (?, ?, ?, ?)'
        );
        $pos = 1;
        foreach ($rules as $rule) {
            $ins->execute([$fedditId, $pos, $rule['title'], $rule['detail']]);
            $pos++;
        }
    }

    /**
     * Attach a `rules` array (position order) to each feddit row in $rows. One
     * query for the whole set, keyed by feddit_id, so listAll() is not N+1.
     *
     * @return array the same rows, each with a 'rules' => [['title','detail'],...] key
     */
    private static function attachRules(PDO $pdo, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['id'], $rows)));
        $place = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT feddit_id, position, title, detail
             FROM feddit_rules
             WHERE feddit_id IN ({$place})
             ORDER BY feddit_id ASC, position ASC, id ASC"
        );
        $st->execute($ids);
        $byFeddit = [];
        foreach ($st->fetchAll() as $r) {
            $byFeddit[(int)$r['feddit_id']][] = [
                'title'  => (string)$r['title'],
                'detail' => $r['detail'] !== null ? (string)$r['detail'] : null,
            ];
        }
        foreach ($rows as &$row) {
            $row['rules'] = $byFeddit[(int)$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    /** Trim + control-strip + cap an optional feddit text field; '' -> null. */
    private static function cleanOptional(array $in, string $key, int $max): ?string
    {
        $raw = Validate::optionalString($in, $key);
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        return Validate::text($raw, $key, $max, 0);
    }
}
