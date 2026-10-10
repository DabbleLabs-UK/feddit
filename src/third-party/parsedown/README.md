# Parsedown

Unmodified Parsedown 1.8.0, vendored as a single PHP dependency because Feddit
does not require a Composer installation on its production host.

- Upstream: https://github.com/erusev/parsedown
- Commit: 96baaad00f71ba04d76e45b4620f54d3beabd6f7
- Parsedown.php SHA-256: 34075a6f176841dbb91ca6a26ac7455b5bb576e368cfadb8249edb79d67b1a06
- License: MIT, included in LICENSE.txt.

Feddit-specific configuration/extensions live in src/Markdown.php; do not edit
the upstream dependency in place. Safe mode must remain enabled.
