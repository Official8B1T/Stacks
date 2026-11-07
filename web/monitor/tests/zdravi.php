<?php
// ─────────────────────────────────────────────────────
//   project · Stacks
//   module  · web/monitor/tests/zdravi.php — fake site for the health check test
//   author  · Jiří "8B1T" Lhotský
// ─────────────────────────────────────────────────────
// php -S 127.0.0.1:8902 web/monitor/tests/zdravi.php
// "/" is a healthy page; /zdravi-<case> answers like site/plugins/zdravi in that
// state, and only to the header X-Zdravi: test-token (else 404, like the plugin).

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    echo '<!doctype html><html lang="cs"><head><title>Test</title></head><body>' . str_repeat('<p>Zdravá stránka.</p>', 20) . '</body></html>';
    return;
}
if (($_SERVER['HTTP_X_ZDRAVI'] ?? '') !== 'test-token' || $path === '/zdravi-chybi') {
    http_response_code(404);
    return;
}

$ok = ['cas' => date('c'), 'kod' => 0, 'chyba' => '', 'head' => str_repeat('a', 40), 'cil' => str_repeat('a', 40), 'aktualni' => true];
$beh = match ($path) {
    '/zdravi-ok'      => $ok,
    '/zdravi-stare'   => ['cas' => date('c', time() - 5 * 3600)] + $ok,
    '/zdravi-chyba'   => ['kod' => 1, 'chyba' => 'composer install selhal, podrobnosti výš'] + $ok,
    '/zdravi-rozjeta' => ['aktualni' => false, 'cil' => str_repeat('b', 40), 'chyba' => 'historie na serveru se rozjela s originem, nedoháním'] + $ok,
    '/zdravi-bez-tepu' => null,
    default           => $ok,
};
header('Content-Type: application/json');
echo json_encode(['beh' => $beh, 'disk' => $path === '/zdravi-disk' ? 4.2 : 55.0]);
