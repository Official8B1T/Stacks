<?php
// ─────────────────────────────────────────────────────
//   project · Stacks
//   module  · web/audit/fix.php — nightly: fix what an update can, track the rest as issues
//   author  · Jiří "8B1T" Lhotský
// ─────────────────────────────────────────────────────

// Usage: php fix.php <project-dir> [--act]
// Without --act: rewrites composer.lock in place and prints FIXED / UNFIXABLE
// lines (run it on a copy). With --act, inside GitHub Actions: pushes the fix
// to BRANCH, opens its PR, starts the caller workflow on it, and keeps one
// issue per unfixable finding, closed again once main no longer has it.
// WARN · composer update only, never install: the gate boots the site from
//        the PR branch, this job must not run anything from the new lock.

require __DIR__ . '/findings.php';

const BRANCH = 'stacks/audit-oprava';
const LABEL = 'audit';

$dir = $argv[1] ?? '';
$act = in_array('--act', $argv, true);
if ($dir === '' || !is_file("$dir/composer.lock")) {
    fwrite(STDERR, "usage: php fix.php <project-dir> [--act]\n");
    exit(2);
}

// Runs a command, prints its output; returns the exit code.
function run(string $cmd): int
{
    echo "\$ $cmd\n";
    passthru($cmd . ' 2>&1', $code);
    return $code;
}

// Like run(), but any failure stops the job: a half-made PR or issue is worse than none.
function must(string $cmd): string
{
    echo "\$ $cmd\n";
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    if ($code !== 0) {
        echo "$text\n::error title=Nightly audit fix::command failed (exit $code)\n";
        exit(1);
    }
    return $text;
}

// Locked packages that require $package, directly or through other locked packages.
function dependents(string $lock, string $package): array
{
    $data = json_decode(file_get_contents($lock), true);
    $parents = [];
    foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $p) {
        foreach (array_keys($p['require'] ?? []) as $dep) {
            $parents[strtolower($dep)][] = $p['name'];
        }
    }
    $seen = [];
    $todo = [strtolower($package)];
    while ($todo) {
        foreach ($parents[array_pop($todo)] ?? [] as $name) {
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $todo[] = strtolower($name);
            }
        }
    }
    return array_keys($seen);
}

// Updates $packages in the lock; keeps the change only if an advisory of $package is gone.
function attempt(string $dir, string $lock, array $packages, string $package, array $before): bool
{
    $saved = file_get_contents($lock);
    $code = run('composer update ' . implode(' ', array_map('escapeshellarg', $packages)) . ' --with-all-dependencies --no-install'
        . ' --no-scripts --no-plugins --no-interaction --working-dir=' . escapeshellarg($dir));
    $gone = $code === 0 && array_diff_key(
        array_filter($before, fn ($f) => $f['package'] === $package),
        findings($dir)
    );
    if (!$gone) {
        file_put_contents($lock, $saved); // no gain, keep the lock untouched
    }
    return (bool) $gone;
}

// ── PLAN ───────────────────────────────────────────────

$lock = "$dir/composer.lock";
$before = findings($dir);

// One package at a time, so a package nothing can fix does not block one that can.
// NOTE · a parent may pin the package (Kirby pins symfony/yaml), then only a parent
//        update brings the fix; composer.json constraints still cap every version.
foreach (array_unique(array_column($before, 'package')) as $package) {
    if (!attempt($dir, $lock, [$package], $package, $before) && ($parents = dependents($lock, $package))) {
        attempt($dir, $lock, [$package, ...$parents], $package, $before);
    }
}

$left = findings($dir);
$fixed = array_diff_key($before, $left);

foreach ($fixed as $id => $f) {
    echo "FIXED $id · {$f['text']}\n";
}
foreach ($left as $id => $f) {
    echo "UNFIXABLE $id · {$f['text']}\n";
}

if (!$act) {
    exit(0);
}

// ── ACT ────────────────────────────────────────────────

$repo = getenv('GITHUB_REPOSITORY');
$workflow = getenv('CALLER_WORKFLOW'); // .github/workflows/<file> of the site
$runUrl = getenv('GITHUB_SERVER_URL') . "/$repo/actions/runs/" . getenv('GITHUB_RUN_ID');
$q = 'escapeshellarg';

if ($fixed) {
    $ids = implode(', ', array_keys($fixed));
    $list = implode("\n", array_map(fn ($id, $f) => "- `$id` · {$f['text']}", array_keys($fixed), $fixed));
    must('git -C ' . $q($dir) . ' config user.name "github-actions[bot]"');
    must('git -C ' . $q($dir) . ' config user.email "41898282+github-actions[bot]@users.noreply.github.com"');
    must('git -C ' . $q($dir) . ' checkout -B ' . BRANCH);
    must('git -C ' . $q($dir) . ' add composer.lock');
    must('git -C ' . $q($dir) . ' commit -m ' . $q("deps: fix $ids\n\nUpdated by the nightly Web Stack audit: $runUrl"));
    // the branch belongs to this job alone, rewriting it is expected
    must('git -C ' . $q($dir) . ' push --force origin ' . BRANCH);

    $open = must('gh pr list --repo ' . $q($repo) . ' --head ' . BRANCH . ' --state open --json number --jq ".[0].number // empty"');
    if ($open === '') {
        must('gh pr create --repo ' . $q($repo) . ' --base main --head ' . BRANCH
            . ' --title ' . $q("deps: oprava zranitelnosti $ids")
            . ' --body ' . $q("Noční audit našel zranitelnost, kterou odstraní `composer update -W`:\n\n$list\n\n"
                . "Kontrolu spouští noční běh přes `workflow_dispatch`; po zelené se PR sloučí sám, pokud mění jen `composer.lock`.\n\nBěh: $runUrl"));
    }
    // a PR or push made with GITHUB_TOKEN starts no checked run; dispatch does
    must('gh workflow run ' . $q(basename($workflow)) . ' --repo ' . $q($repo) . ' --ref ' . BRANCH);
}

must('gh label create ' . LABEL . ' --repo ' . $q($repo) . ' --color d73a4a --description "Zranitelnost závislosti, kterou noční audit neumí opravit" --force');
$issues = json_decode(must('gh issue list --repo ' . $q($repo) . ' --label ' . LABEL . ' --state open --json number,title --limit 100'), true);

// Issue title starts with "Audit: <id> ·", the id is the key between runs.
$tracked = [];
foreach ($issues as $i) {
    if (preg_match('/^Audit: (\S+) ·/', $i['title'], $m)) {
        $tracked[$m[1]] = $i['number'];
    }
}

foreach ($left as $id => $f) {
    if (isset($tracked[$id])) {
        continue;
    }
    must('gh issue create --repo ' . $q($repo) . ' --label ' . LABEL
        . ' --title ' . $q("Audit: $id · {$f['package']}")
        . ' --body ' . $q("{$f['text']}\n\n`composer update {$f['package']} -W` ani spolu s balíčky, které na něm závisí, nenašel verzi bez téhle zranitelnosti: "
            . "brání tomu omezení verzí v `composer.json`, nebo oprava zatím nevyšla. Kontrola PR ji jen hlásí, nesouvisející aktualizace neblokuje.\n\n"
            . "Issue se zavře samo, až zranitelnost z `main` zmizí. Rozhodnutí o riziku patří sem.\n\nBěh: $runUrl"));
}

foreach ($tracked as $id => $number) {
    if (!isset($before[$id])) {
        must("gh issue close $number --repo " . $q($repo) . ' --comment ' . $q("Zranitelnost už v `main` není. Běh: $runUrl"));
    }
}

echo count($fixed) . ' fixed via PR, ' . count($left) . " tracked as issues\n";
