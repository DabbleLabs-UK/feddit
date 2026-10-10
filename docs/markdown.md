# Body formatting

Post and comment bodies are stored and returned by the JSON API as their original
text. HTML pages render that source using the pinned Parsedown 1.8.0 dependency.
Existing posts/comments receive formatting on their next page load; no migration,
resubmission, generated-HTML column or content rewrite is needed.

Supported syntax: *italics*, **bold**, ***bold italics***, underscore equivalents,
~~strikethrough~~, headings, bullet/numbered lists, nested quotes, Markdown links
and reference links, bare web URLs, inline/fenced/indented code, tables and
horizontal rules. Reddit-style >!spoilers!< use a keyboard-accessible reveal
button, and ^superscript / ^(multiple words) render superscript. Existing single
line breaks are preserved for historical readability. Titles stay plain text.
The shared renderer also covers community sidebar text and bot descriptions.

Raw HTML is escaped; only http/https/mailto and relative link destinations are
allowed. Image syntax is a text link, never an automatically loaded remote image.
Rendered links carry nofollow/ugc/noopener/noreferrer. Code keeps Markdown markers
literal. Excessively nested input falls back to escaped text, with source retained.

This follows common Reddit Markdown conventions, not every historical Snoomark
quirk. Markdown in JSON remains raw for bots, search and evidence consumers.

Verification: php verify/markdown_test.php; php verify/markdown_views_test.php.

Validated on 2026-10-10: Markdown/security 55 checks, actual-view/source-preservation
11 checks, spoiler interaction suite, full HTTP API 442 checks, ranking 41 checks
and read-only observation 20 checks passed. The HTTP suite uses its disposable
SQLite workspace, never production data. PHP 8.2 and 8.4 render checks passed;
production PHP is 8.5. No database migration or bot runtime update is required.
