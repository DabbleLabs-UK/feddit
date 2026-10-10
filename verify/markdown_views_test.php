<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/queries.php';
require_once __DIR__ . '/../src/api/ReportService.php';
require_once __DIR__ . '/../src/api/Serialize.php';
$pass = 0; $fail = 0;
function check_view(bool $ok, string $label): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
}
function capture_view(string $view, array $variables): string
{
    extract($variables, EXTR_SKIP);
    ob_start(); require __DIR__ . '/../src/views/' . $view;
    return (string)ob_get_clean();
}
// A pre-existing stored row: rendering must never rewrite its source.
$source = '*old italics* **old bold** ***both***';
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE historical (body TEXT)');
$pdo->prepare('INSERT INTO historical VALUES (?)')->execute([$source]);
$pdo->exec('PRAGMA query_only = ON');
$body = (string)$pdo->query('SELECT body FROM historical')->fetchColumn();
$post = ['id' => 1, 'kind' => 'text', 'url' => null, 'title' => '*Plain title*', 'body' => $body,
    'score' => 1, 'comment_count' => 1, 'created_at' => '2026-09-01 12:00:00',
    'bot_username' => 'example_bot', 'feddit_name' => 'example', 'is_nsfw' => 0];
$comment = ['id' => 2, 'score' => 1, 'body' => $body, 'created_at' => '2026-09-01 12:00:00',
    'bot_username' => 'example_bot', 'children' => [], 'is_bot' => true, 'pruned_children' => 0];
$feddit = ['name' => 'example', 'title' => 'Example', 'subscriber_count' => 1,
    'created_at' => '2026-09-01 12:00:00', 'sidebar_text' => '', 'post_format' => 'any'];
$base = ['post' => $post, 'comment' => $comment, 'postId' => 1, 'postFeddit' => 'example',
    'feddit' => $feddit, 'mods' => [], 'comments' => [$comment]];
foreach (['_comment.php', '_conv_comment.php', 'comments.php'] as $view) {
    $html = capture_view($view, $base);
    check_view(str_contains($html, '<em>old italics</em>') && str_contains($html, '<strong>old bold</strong>'), $view . ' renders historical Markdown');
    check_view(str_contains($html, '<strong><em>both</em></strong>'), $view . ' combined emphasis');
}
$block = ['post' => $post, 'nodes' => [$comment], 'authored_by_bot' => true, 'top_pruned' => 0];
$conversation = capture_view('_conv_block.php', $base + ['block' => $block]);
check_view(substr_count($conversation, '<em>old italics</em>') === 2, 'conversation post and comment share formatting, including loaded fragments');
check_view(str_contains(capture_view('comments.php', $base), '>*Plain title*</a>'), 'post title remains plain text');
check_view($pdo->query('SELECT body FROM historical')->fetchColumn() === $source, 'historical stored body remains unchanged');
check_view(Serialize::post($post)['data']['selftext'] === $source, 'post API remains original Markdown source');
$comment['post_id'] = 1; $comment['parent_comment_id'] = null;
check_view(Serialize::comment($comment)['data']['body'] === $source, 'comment API remains original Markdown source');
echo "PASS: $pass FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
