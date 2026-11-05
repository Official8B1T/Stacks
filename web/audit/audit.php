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

// ── AUDIT ──────────────────────────────────────────────

// Advisory id → description, plus "abandoned:<package>" for abandoned packages.
function findings(string $dir): array
{
    $cmd = 'composer audit --locked --format=json --no-interaction --working-dir=' . escapeshellarg($dir);
    exec($cmd, $out, $code);
    $data = json_decode(implode("\n", $out), true);
    if (!is_array($data)) {
        echo "::error title=Dependency audit::composer audit in $dir returned no JSON (exit $code)\n";
        exit(2);
    }

    $found = [];
    foreach ($data['advisories'] ?? [] as $package => $list) {
        foreach ($list as $a) {
            $found[$a['advisoryId']] = "$package · {$a['title']} · {$a['link']}";
        }
    }
    foreach ($data['abandoned'] ?? [] as $package => $replacement) {
        $found["abandoned:$package"] = "$package is abandoned" . ($replacement ? ", use $replacement" : '');
    }
    return $found;
}

// ── MAIN ───────────────────────────────────────────────

$head = $argv[1] ?? '';
$base = $argv[2] ?? '';
if ($head === '') {
    fwrite(STDERR, "usage: php audit.php <project-dir> [<base-dir>]\n");
    exit(2);
}

$now = findings($head);
$new = $base === '' ? $now : array_diff_key($now, findings($base));

foreach (array_diff_key($now, $new) as $id => $text) {
    echo "::warning title=Known before this change::$id · $text\n";
}
foreach ($new as $id => $text) {
    echo "::error title=Dependency audit::$id · $text\n";
}
echo count($now) . ' finding(s), ' . count($new) . ' new' . ($base === '' ? ' (no base, all count as new)' : '') . "\n";
exit($new ? 1 : 0);
