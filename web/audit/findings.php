<?php
// ─────────────────────────────────────────────────────
//   project · Stacks
//   module  · web/audit/findings.php — what composer audit reports for one project
//   author  · Jiří "8B1T" Lhotský
// ─────────────────────────────────────────────────────

// Advisory id (or "abandoned:<package>") → ['package' => …, 'text' => …].
// Exits 2 when the audit cannot run: an unknown state must never read as clean.
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
            $found[$a['advisoryId']] = ['package' => $package, 'text' => "$package · {$a['title']} · {$a['link']}"];
        }
    }
    foreach ($data['abandoned'] ?? [] as $package => $replacement) {
        $found["abandoned:$package"] = ['package' => $package, 'text' => "$package is abandoned" . ($replacement ? ", use $replacement" : '')];
    }
    return $found;
}
