<?php
// ─────────────────────────────────────────────────────
//   project · Stacks
//   module  · web/monitor/monitor.php — is every live site up; alert after two failed checks
//   author  · Jiří "8B1T" Lhotský
// ─────────────────────────────────────────────────────

// Usage: php monitor.php <sites.json> <state.json> [--act]
// Prints OK / FAIL / ALERT / RECOVER per site and rewrites state.json.
// --act (needs GH_TOKEN with Issues read/write on every site repo): opens one
// issue per outage in the site's repo, closes it with the duration on recovery.
// A site with "zdravi" (path) is also asked how it is, with env ZDRAVI_TOKEN:
// a stale or failed aktualizace.sh, a server off `nasazeno` or a full disk
// count as a failed check like an outage does.
// NOTE · a site that is down does not fail this run: the alert is the issue.
//        The run fails only when the monitor itself cannot do its job.

date_default_timezone_set('Europe/Prague');

const ALERT_AFTER = 2;     // consecutive failed checks before an issue
const CERT_WARN_DAYS = 14; // Let's Encrypt renews at 30 days left, 14 means renewal failed
const LABEL = 'vypadek';
const ZDRAVI_MAX_HOURS = 2; // aktualizace.sh runs hourly; one missed run is noise, two are a broken cron
const ZDRAVI_MIN_DISK = 10; // % free

[$self, $sitesFile, $stateFile] = $argv + [null, '', ''];
$act = in_array('--act', $argv, true);
$sites = json_decode((string) @file_get_contents($sitesFile), true);
if (!is_array($sites) || $stateFile === '') {
    fwrite(STDERR, "usage: php monitor.php <sites.json> <state.json> [--act]\n");
    exit(2);
}
$state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$now = time();

// ── CHECKS ─────────────────────────────────────────────

function fetch(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CERTINFO => true,
        CURLOPT_USERAGENT => 'Official8B1T-Stacks-monitor (+https://github.com/Official8B1T/Stacks)',
    ]);
    $body = curl_exec($ch);
    $r = [
        'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'errno' => curl_errno($ch),
        'error' => curl_error($ch),
        'time' => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2),
        'location' => curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        'body' => is_string($body) ? $body : '',
        'certinfo' => curl_getinfo($ch, CURLINFO_CERTINFO) ?: [],
    ];
    curl_close($ch);
    return $r;
}

// List of problems in plain Czech, each with the likely layer; empty = healthy.
function check(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST);
    if (gethostbynamel($host) === false) {
        return ["DNS: $host se nepřekládá na adresu · záznam v DNS nebo DNS server"];
    }

    $r = fetch($url);
    $p = [];
    if ($r['errno'] !== 0) {
        $hint = match ($r['errno']) {
            7 => 'server na portu 443 nepřijímá spojení · nginx nebo celý server neběží, nebo firewall',
            28 => 'odpověď nepřišla do 20 s · přetížený server nebo zaseknuté PHP',
            35, 60 => 'TLS spojení selhalo · certifikát nebo konfigurace HTTPS',
            default => 'spojení selhalo',
        };
        $p[] = "HTTPS $url: {$r['error']} (curl {$r['errno']}) · $hint";
        return $p;
    }

    if ($r['code'] !== 200) {
        $hint = match (true) {
            in_array($r['code'], [502, 503, 504], true) => 'nginx běží, aplikace (PHP-FPM) za ním neodpovídá',
            $r['code'] >= 500 => 'chyba aplikace (PHP nebo Kirby)',
            $r['code'] >= 300 && $r['code'] < 400 => 'přesměrování na ' . ($r['location'] ?: '?'),
            in_array($r['code'], [401, 403], true) => 'přístup odepřen · konfigurace, nebo blokace IP monitoru (GitHub)',
            default => 'neočekávaný stav',
        };
        $p[] = "HTTPS $url vrací {$r['code']} · $hint";
    } elseif (strlen($r['body']) < 200 || stripos($r['body'], '</html>') === false) {
        $p[] = "HTTPS $url vrací 200, ale bez úplné stránky (" . strlen($r['body']) . ' B) · aplikace spadla během vykreslení';
    }

    $expire = $r['certinfo'][0]['Expire date'] ?? null;
    if ($expire !== null && ($ts = strtotime($expire)) !== false) {
        $days = (int) floor(($ts - time()) / 86400);
        if ($days < CERT_WARN_DAYS) {
            $p[] = "Certifikát $host vyprší za $days dní (" . date('j. n. Y', $ts) . ') · obnova Let\'s Encrypt v Plesku neproběhla';
        }
    }
    return $p;
}

// What the server says about itself (site/plugins/zdravi + aktualizace.sh),
// the part an outside probe cannot see; same plain-Czech list as check().
function checkZdravi(string $url, string $token): array
{
    $r = fetch($url, ['X-Zdravi: ' . $token]);
    if ($r['errno'] !== 0 || $r['code'] !== 200) {
        return ["Zdraví $url vrací " . ($r['errno'] ? "chybu curl {$r['errno']}" : $r['code'])
            . ' · plugin zdravi chybí, nebo ZDRAVI_TOKEN nesedí s webstack.zdraviHash v config.php'];
    }
    $d = json_decode($r['body'], true);
    if (!is_array($d)) {
        return ["Zdraví $url nevrací JSON · plugin zdravi"];
    }

    $p = [];
    $beh = $d['beh'] ?? null;
    if (!is_array($beh)) {
        $p[] = 'aktualizace.sh ještě nezapsal tep (site/logs/zdravi.json chybí) · cron ho nespouští, nebo je na serveru stará verze skriptu';
    } else {
        $ts = strtotime((string) ($beh['cas'] ?? ''));
        $hodin = $ts ? (time() - $ts) / 3600 : INF;
        if ($hodin > ZDRAVI_MAX_HOURS) {
            $p[] = 'aktualizace.sh naposledy běžel ' . ($ts ? 'před ' . round($hodin, 1) . ' h (' . date('j. n. H:i', $ts) . ')' : 'nikdy') . ' · cron v /etc/cron.d neběží';
        }
        if (($beh['kod'] ?? 0) !== 0) {
            $p[] = "aktualizace.sh skončil kódem {$beh['kod']}: " . ($beh['chyba'] ?: '?') . ' · site/logs/aktualizace.log na serveru';
        } elseif (($beh['chyba'] ?? '') !== '') {
            $p[] = "aktualizace.sh hlásí: {$beh['chyba']} · site/logs/aktualizace.log na serveru";
        }
        if (($beh['aktualni'] ?? false) !== true) {
            $p[] = 'Server nestojí na ověřené větvi nasazeno (HEAD ' . substr((string) ($beh['head'] ?? ''), 0, 7) . ', nasazeno ' . substr((string) ($beh['cil'] ?? ''), 0, 7) . ') · rozjetá historie, vyřešit ručně na serveru';
        }
    }
    if (isset($d['disk']) && is_numeric($d['disk']) && $d['disk'] < ZDRAVI_MIN_DISK) {
        $p[] = "Na disku serveru zbývá {$d['disk']} % · web brzy přestane zapisovat (relace, cache, objednávky)";
    }
    return $p;
}

// ── GITHUB ─────────────────────────────────────────────

function gh(string $args): string
{
    exec('gh ' . $args . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    if ($code !== 0) {
        echo "$text\n::error title=Web monitor::gh $args failed (exit $code)\n";
        exit(1);
    }
    return $text;
}

function openIssue(string $repo, string $web): ?int
{
    $list = json_decode(gh('issue list --repo ' . escapeshellarg($repo) . ' --label ' . LABEL
        . ' --state open --json number,title --limit 50'), true) ?: [];
    foreach ($list as $i) {
        if ($i['title'] === "Výpadek: $web") {
            return $i['number'];
        }
    }
    return null;
}

// ── MAIN ───────────────────────────────────────────────

$runUrl = getenv('GITHUB_SERVER_URL') . '/' . getenv('GITHUB_REPOSITORY') . '/actions/runs/' . getenv('GITHUB_RUN_ID');

if ($act) {
    // an expired token must fail now, not silently at the next outage
    foreach (array_unique(array_column($sites, 'repo')) as $repo) {
        gh('issue list --repo ' . escapeshellarg($repo) . ' --limit 1 --json number');
    }
}

// a site that asks for a health check without the token would never alert
$token = (string) getenv('ZDRAVI_TOKEN');
if ($token === '' && array_filter(array_column($sites, 'zdravi'))) {
    echo "::error title=Web monitor::ZDRAVI_TOKEN is not set, health checks cannot run\n";
    exit(1);
}

foreach ($sites as $s) {
    $web = $s['web'];
    $problems = check($s['url']);
    // only a site that answers can say how it is
    if (!$problems && !empty($s['zdravi'])) {
        $problems = checkZdravi(rtrim($s['url'], '/') . '/' . $s['zdravi'], $token);
    }
    $prev = $state[$web] ?? ['fails' => 0, 'since' => null];

    if (!$problems) {
        $issue = $act ? openIssue($s['repo'], $web) : null;
        if ($prev['fails'] >= ALERT_AFTER || $issue !== null) {
            $minutes = $prev['since'] ? (int) round(($now - $prev['since']) / 60) : null;
            echo "RECOVER $web" . ($minutes !== null ? " after ~$minutes min" : '') . "\n";
            if ($issue !== null) {
                gh("issue close $issue --repo " . escapeshellarg($s['repo']) . ' --comment ' . escapeshellarg(
                    'Web znovu odpovídá (' . date('j. n. H:i', $now) . ').'
                    . ($minutes !== null ? " Výpadek trval přibližně $minutes min, první chybná kontrola " . date('j. n. H:i', $prev['since']) . '.' : '')
                    . "\n\nBěh: $runUrl"
                ));
            }
        } else {
            echo "OK $web\n";
        }
        $state[$web] = ['fails' => 0, 'since' => null];
        continue;
    }

    $fails = $prev['fails'] + 1;
    $since = $prev['since'] ?? $now;
    $state[$web] = ['fails' => $fails, 'since' => $since];
    foreach ($problems as $p) {
        echo "   $p\n";
    }

    if ($fails < ALERT_AFTER) {
        echo "FAIL $web ($fails/" . ALERT_AFTER . ")\n";
        continue;
    }
    echo "ALERT $web ($fails× since " . date('H:i', $since) . ")\n";
    if ($act && openIssue($s['repo'], $web) === null) {
        $repo = escapeshellarg($s['repo']);
        gh('label create ' . LABEL . " --repo $repo --color b60205 --description \"Web neodpovídá (monitor Stacks)\" --force");
        gh("issue create --repo $repo --label " . LABEL . ' --title ' . escapeshellarg("Výpadek: $web") . ' --body ' . escapeshellarg(
            "**$web neodpovídá správně** od " . date('j. n. H:i', $since) . ", ověřeno $fails× po sobě (poslední kontrola " . date('H:i', $now) . ").\n\n"
            . "Co nejde:\n" . implode("\n", array_map(fn ($p) => "- $p", $problems)) . "\n\n"
            . "Issue se zavře samo, až web zase odpoví.\n\nMonitor: Official8B1T/Stacks, běh: $runUrl"
        ));
    }
}

file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT) . "\n");
