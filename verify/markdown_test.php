<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/helpers.php';
$pass = 0;
$fail = 0;
function check_markdown(bool $condition, string $label): void
{
    global $pass, $fail;
    $condition ? $pass++ : $fail++;
    echo ($condition ? 'ok   ' : 'FAIL ') . $label . "\n";
}
function markdown_dom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    $doc->loadHTML('<meta charset="UTF-8"><div id="fixture">' . $html . '</div>');
    libxml_clear_errors(); libxml_use_internal_errors($before);
    return new DOMXPath($doc);
}
foreach ([
    ['*italic*', '<em>italic</em>', 'single asterisk'],
    ['**bold**', '<strong>bold</strong>', 'double asterisk'],
    ['***both***', '<strong><em>both</em></strong>', 'triple asterisk'],
    ['_italic_ __bold__ ___both___', '<strong><em>both</em></strong>', 'underscore variants'],
    ['**bold and *italic* inside**', '<strong>bold and <em>italic</em> inside</strong>', 'nested emphasis'],
    ['~~removed~~', '<del>removed</del>', 'strikethrough'],
    ["# Heading\n\n## Second", '<h2>Second</h2>', 'headings'],
    ["> quote\n> **bold**", '<blockquote>', 'blockquote'],
    ["- one\n- two", '<ul>', 'bullet list'],
    ["1. one\n2. two", '<ol>', 'numbered list'],
    ["- parent\n  - child", '<ul>', 'nested list'],
    ['`*literal*`', '<code>*literal*</code>', 'inline code'],
    ["```text\n**literal**\n```", '<code class="language-text">**literal**</code>', 'fenced code'],
    ["    **literal**", '<code>**literal**</code>', 'indented code'],
    ["one\n\ntwo", "<p>one</p>\n<p>two</p>", 'paragraphs'],
    ["one\ntwo", '<br />', 'historical single line breaks'],
    ["one\r\n\r\ntwo", '<p>two</p>', 'Windows line endings'],
    ["a | b\n--- | ---\nx | y", '<table>', 'table'],
    ["one\n\n---\n\ntwo", '<hr />', 'horizontal rule'],
    ['[Feddit](https://feddit.dabblelabs.uk)', 'href="https://feddit.dabblelabs.uk"', 'named link'],
    ['[thread](/f/botlife/comments/1)', 'href="/f/botlife/comments/1"', 'relative link'],
    ["[reference][a]\n\n[a]: https://example.com", 'href="https://example.com"', 'reference link'],
    ['https://example.com/a_b?x=1&y=2', 'href="https://example.com/a_b?x=1&amp;y=2"', 'URL and underscores'],
    ['\\*literal\\*', '*literal*', 'escaped stars'],
    ['bot_name and unclosed *marker', 'bot_name and unclosed *marker', 'ordinary underscores/unclosed marker'],
    ['x^2 and ^(multiple words)', '<sup>multiple words</sup>', 'superscript'],
    ['\\^literal', '^literal', 'escaped superscript'],
    ['>!secret **bold**!<', '<strong>bold</strong>', 'spoiler at start of paragraph'],
    ['before >!secret!< after', 'class="md-spoiler"', 'inline spoiler'],
    ['`>!literal!<`', '<code>&gt;!literal!&lt;</code>', 'spoiler markers in code'],
] as [$input, $expected, $label]) {
    check_markdown(str_contains(render_body($input), $expected), $label);
}
check_markdown(render_body(null) === '' && render_body('  ') === '', 'empty content');
check_markdown(!str_contains(render_body('\\*literal\\*'), '<em>'), 'escaped stars remain literal');
$spoiler = markdown_dom(render_body('>!secret **bold** [link](https://example.com)!<'));
check_markdown($spoiler->query('//button[@type="button" and @aria-expanded="false"]')->length === 1, 'native accessible spoiler button');
check_markdown($spoiler->query('//button/span[@aria-hidden="true"]')->length === 1, 'spoiler hidden before reveal');
check_markdown($spoiler->query('//button//a')->length === 0, 'no nested interactive link inside spoiler button');
check_markdown(!str_contains(render_body('>!unclosed'), 'md-spoiler'), 'unclosed spoiler not fabricated');
render_body("[stale]: https://example.com");
check_markdown(!str_contains(render_body('[stale]'), '<a'), 'reference definitions do not leak across bodies');
foreach ([
    '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<svg onload=alert(1)>',
    '[x](javascript:alert(1))', '[x](JaVaScRiPt:alert(1))', '[x](javascript&#58;alert(1))',
    '[x](data:text/html;base64,PHNjcmlwdD4=)', '[x](vbscript:msgbox(1))', '[x](file:///etc/passwd)',
    "[x](java\tscript:alert(1))", '<a href="javascript:alert(1)">click</a>',
    '>!<img src=x onerror=alert(1)>!<', '[**<script>bad</script>**](https://example.com)',
] as $attack) {
    $xpath = markdown_dom(render_body($attack));
    $bad = $xpath->query('//script | //img | //svg | //iframe | //*[@onload or @onerror or @onclick]')->length;
    foreach ($xpath->query('//a[@href]') as $link) {
        $url = preg_replace('/[\x00-\x20]+/', '', $link->getAttribute('href'));
        if (preg_match('/^(javascript|vbscript|data|file):/i', $url)) { $bad++; }
    }
    check_markdown($bad === 0, 'safe untrusted input: ' . substr($attack, 0, 45));
}
$image = markdown_dom(render_body('![photo](https://tracking.example/pixel.png)'));
check_markdown($image->query('//img')->length === 0 && $image->query('//a')->length === 1, 'images are explicit links, not tracking requests');
check_markdown(str_contains(render_body('[x](https://example.com)'), 'rel="nofollow ugc noopener noreferrer"'), 'untrusted link relationship');
$unicode = "caf\xC3\xA9 \xF0\x9F\x98\x80";
check_markdown(str_contains(render_body('**' . $unicode . '**'), $unicode), 'UTF-8 content preserved');
$deep = str_repeat('> ', 100) . '**deep**';
check_markdown(str_contains(render_body($deep), '**deep**'), 'excessive nesting falls back to readable escaped source');
check_markdown(render_body('*after*') === '<p><em>after</em></p>', 'renderer recovers after nesting fallback');
echo "PASS: $pass FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
