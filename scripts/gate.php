<?php

declare(strict_types=1);

/**
 * TEMPORARY real-SAPI gate (the M2 verify step): boots fixture apps under a
 * real `php -S` child and drives them over HTTP — proving what no
 * TestClient-style dispatch can: RequestFactory::fromGlobals under a real
 * SAPI, the Emitter writing real headers, config/.env loading in a fresh
 * process, and the Accept-negotiated diagnostics page end to end.
 *
 * DELETE once `lava serve` (M4) + the CLI golden tests own this coverage.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

const HOST = '127.0.0.1';
const PORT = '18473';

$checks = 0;
$failures = 0;

/**
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function http(string $method, string $path, array $headers = []): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headerLines,
        // 404/405 are expected outcomes here, not transport errors.
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);

    $body = @file_get_contents('http://' . HOST . ':' . PORT . $path, false, $context);
    if ($body === false) {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }

    $status = 0;
    $parsed = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $parsed, 'body' => $body];
}

/**
 * A php -S master SIGTERMed by stopServer can leave workers holding the port,
 * serving stale code to the next run. Kill anything matching this gate's port
 * and wait until the port is actually free.
 */
function killStaleServers(): void
{
    exec('pkill -f ' . escapeshellarg('php -S ' . HOST . ':' . PORT) . ' > /dev/null 2>&1');
    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        $sock = @fsockopen(HOST, (int) PORT, $errno, $errstr, 0.1);
        if ($sock === false) {
            return;
        }
        fclose($sock);
        usleep(50_000);
    }
}

/** @return resource the php -S process */
function startServer(string $appDir, array $env = [])
{
    killStaleServers();
    $envPrefix = 'env ' . escapeshellarg('LAVA_FIXTURE_APP_DIR=' . $appDir) . ' ';
    foreach ($env as $name => $value) {
        $envPrefix .= 'env ' . escapeshellarg("{$name}={$value}") . ' ';
    }
    $prepend = escapeshellarg(dirname(__DIR__) . '/scripts/fixture-autoload.php');
    $cmd = $envPrefix
        . 'env PHP_CLI_SERVER_WORKERS=2 php -d auto_prepend_file=' . $prepend
        . ' -S ' . HOST . ':' . PORT
        . ' -t ' . escapeshellarg($appDir . '/public')
        . ' ' . escapeshellarg($appDir . '/public/index.php');

    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    fclose($pipes[0]);

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $sock = @fsockopen(HOST, (int) PORT, $errno, $errstr, 0.1);
        if ($sock !== false) {
            fclose($sock);
            return $proc;
        }
        if (!proc_get_status($proc)['running']) {
            break;
        }
        usleep(50_000);
    }
    fwrite(STDERR, "FAIL: php -S did not come up for {$appDir}\n");
    exit(1);
}

function stopServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

function check(string $what, bool $ok): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "ok: {$what}\n";
    } else {
        $failures++;
        echo "FAIL: {$what}\n";
    }
}

function repr(mixed $value): string
{
    if (!is_string($value)) {
        return var_export($value, true);
    }
    return mb_strlen($value) > 120
        ? var_export(mb_substr($value, 0, 120), true) . '…'
        : var_export($value, true);
}

function checkSame(string $what, mixed $expected, mixed $actual): void
{
    global $checks, $failures;
    $checks++;
    if ($expected === $actual) {
        echo "ok: {$what}\n";
    } else {
        $failures++;
        echo "FAIL: {$what} — expected " . repr($expected) . ', got ' . repr($actual) . "\n";
    }
}

$fixtures = dirname(__DIR__) . '/packages/core/tests/fixtures/apps';

// ── ok-app ────────────────────────────────────────────────────────────────
$app = $fixtures . '/ok-app';
$server = startServer($app);
try {
    $health = http('GET', '/health');
    checkSame('GET /health is 200', 200, $health['status']);
    checkSame('GET /health Content-Type is json', 'application/json', $health['headers']['content-type'] ?? '');
    checkSame('GET /health body', '{"status":"ok"}', $health['body']);

    $head = http('HEAD', '/health');
    checkSame('HEAD /health is 200', 200, $head['status']);
    checkSame('HEAD /health has no body', '', $head['body']);

    $user = http('GET', '/users/42');
    checkSame('GET /users/42 is 200', 200, $user['status']);
    $userBody = json_decode($user['body'], true) ?? [];
    checkSame('typed param {id:int} injects int 42', 42, $userBody['user']['id'] ?? null);
    checkSame('middleware order global→auth', ['global', 'auth'], $userBody['order'] ?? null);
    checkSame('real headers emitted (X-Timing)', 'global', $user['headers']['x-timing'] ?? null);
    checkSame('real headers emitted (X-Auth)', 'yes', $user['headers']['x-auth'] ?? null);

    $greet = http('GET', '/greet/ada');
    checkSame('GET /greet/ada body (service + param + .env)', '[prod] Hello, ada!', $greet['body']);

    $beta = http('GET', '/beta/dashboard');
    checkSame('flagged-on beta route is 200', 200, $beta['status']);

    $notFound = http('GET', '/users/abc');
    checkSame('GET /users/abc is 404', 404, $notFound['status']);
    $nfProblem = json_decode($notFound['body'], true)['problems'][0] ?? [];
    checkSame('404 body is a route_not_found problem', 'route_not_found', $nfProblem['code'] ?? null);
    check('404 fix names the route command', str_contains($nfProblem['fix'] ?? '', 'lava routes --json'));
    checkSame('no Accept header defaults to JSON', 'application/json', $notFound['headers']['content-type'] ?? '');

    $html = http('GET', '/users/abc', ['Accept' => 'text/html']);
    checkSame('Accept: text/html negotiates the diagnostics page', 404, $html['status']);
    check('diagnostics page is html', str_starts_with($html['headers']['content-type'] ?? '', 'text/html'));
    check('diagnostics page shows the code', str_contains($html['body'], 'route_not_found'));
    check('diagnostics page shows the fix', str_contains($html['body'], 'lava routes --json'));

    $notAllowed = http('POST', '/users/42');
    checkSame('POST /users/42 is 405', 405, $notAllowed['status']);
    checkSame('405 carries Allow: GET', 'GET', $notAllowed['headers']['allow'] ?? null);
    checkSame('405 body is a method_not_allowed problem', 'method_not_allowed', json_decode($notAllowed['body'], true)['problems'][0]['code'] ?? null);
} finally {
    stopServer($server);
}

// ── module-app: the pack's route over real HTTP ──────────────────────────
$app = $fixtures . '/module-app';
$server = startServer($app);
try {
    $quota = http('GET', '/demo/quota');
    checkSame('module route /demo/quota is 200', 200, $quota['status']);
    checkSame('module handler answers from its own service', ['limit' => 42], json_decode($quota['body'], true) ?: null);

    checkSame('app route still served', 200, http('GET', '/')['status']);
} finally {
    stopServer($server);
}

// ── module-app with the pack gated OFF ────────────────────────────────────
$server = startServer($app, ['LAVA_FEATURE_DEMO_PACK' => 'off']);
try {
    $quota = http('GET', '/demo/quota');
    checkSame('pack off: /demo/quota is a real 404', 404, $quota['status']);
    checkSame('pack off: 404 body is route_not_found', 'route_not_found', json_decode($quota['body'], true)['problems'][0]['code'] ?? null);
    checkSame('pack off: app routes unaffected', 200, http('GET', '/')['status']);
} finally {
    stopServer($server);
}

// ── subject-app: audience gating over real HTTP ───────────────────────────
$app = $fixtures . '/subject-app';
$server = startServer($app);
try {
    checkSame('subject-app health is 200', 200, http('GET', '/health')['status']);

    $pilot = http('GET', '/team/board', ['X-User-Id' => 'u1']);
    checkSame('pilot subject sees the users-gated route', 200, $pilot['status']);
    checkSame('users-gated body names its route', ['board' => 'team.board'], json_decode($pilot['body'], true) ?: null);
    checkSame('other subject gets a real 404', 404, http('GET', '/team/board', ['X-User-Id' => 'u2'])['status']);
    checkSame('anonymous gets a real 404', 404, http('GET', '/team/board')['status']);

    checkSame('identified subject passes rollout:100', 200, http('GET', '/early', ['X-User-Id' => 'z9'])['status']);
    checkSame('anonymous is out of the rollout', 404, http('GET', '/early')['status']);
} finally {
    stopServer($server);
}

echo $failures === 0 ? "GATE: all {$checks} checks passed\n" : "GATE: {$failures} of {$checks} checks FAILED\n";
killStaleServers(); // leave no servers behind for the next run — or the session
exit($failures === 0 ? 0 : 1);