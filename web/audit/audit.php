<?php
// ─────────────────────────────────────────────────────
//   project · Stacks
//   module  · web/audit/audit.php — composer audit that blocks only what a change adds
//   author  · Jiří "8B1T" Lhotský
// ─────────────────────────────────────────────────────

// Usage: php audit.php <project-dir> [<base-dir>]
// Exit 0 = clean or nothing new, 1 = new finding, 2 = audit could not run (fail closed).
// NOTE · an advisory already present in the base must not block an unrelated
//        update; on 2026-11-05 it held back a security fix on RawPlanet.

require __DIR__ . '/findings.php';

$head = $argv[1] ?? '';
$base = $argv[2] ?? '';
if ($head === '') {
    fwrite(STDERR, "usage: php audit.php <project-dir> [<base-dir>]\n");
    exit(2);
}

$now = findings($head);
$new = $base === '' ? $now : array_diff_key($now, findings($base));

foreach (array_diff_key($now, $new) as $id => $f) {
    echo "::warning title=Known before this change::$id · {$f['text']}\n";
}
foreach ($new as $id => $f) {
    echo "::error title=Dependency audit::$id · {$f['text']}\n";
}
echo count($now) . ' finding(s), ' . count($new) . ' new' . ($base === '' ? ' (no base, all count as new)' : '') . "\n";
exit($new ? 1 : 0);
