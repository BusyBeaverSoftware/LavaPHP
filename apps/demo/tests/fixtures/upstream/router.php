<?php

declare(strict_types=1);

/*
 * The upstream the demo's own tests fetch.
 *
 * A `php -S` router script, so it is a separate process with no autoloader —
 * which is why the counter-file prefix below is a plain literal duplicated from
 * tests/Support/UpstreamServer.php rather than shared, and why nothing here is
 * declared with `const`: the server re-executes this file for every request in
 * the same process, so a top-level constant would be a redeclare warning from
 * the second request onward. A test asserts the two copies of the prefix agree.
 *
 * One route per thing `lavaphp/http-client` has to get right. The demo's
 * `App\Upstream\Upstream` builds its URL as `<base>/health`, so the caller
 * chooses the behaviour by choosing the base: point `UPSTREAM_URL` at
 * `http://127.0.0.1:PORT/broken` and the fetch becomes
 * `http://127.0.0.1:PORT/broken/health`.
 *
 * `/flaky-<id>` carries a caller-supplied id in the PATH rather than a query
 * string, because the client appends `/health` to whatever it is given and a
 * query string would end up in the middle of the path. The id is what lets two
 * tests use the same route without sharing a counter.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

/** A response that promises more bytes than it sends — a transport failure on demand. */
$truncate = static function (): void {
    header('Content-Type: application/json');
    header('Content-Length: 100');
    echo 'short';
};

if ($path === '/ok/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
    return;
}

if ($path === '/echo/health') {
    // Everything the client decided, reported back from the other end of a
    // socket — the only way to prove the configured User-Agent was sent rather
    // than merely stored.
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    ], JSON_THROW_ON_ERROR);
    return;
}

if ($path === '/broken/health') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'the upstream is having a bad day'], JSON_THROW_ON_ERROR);
    return;
}

if ($path === '/drop/health') {
    $truncate();
    return;
}

if (preg_match('#^/flaky-([0-9a-f]+)/health$#', $path, $matches) === 1) {
    $file = sys_get_temp_dir() . '/lava-demo-upstream-flaky-' . $matches[1];
    $seen = is_file($file) ? (int) file_get_contents($file) : 0;
    file_put_contents($file, (string) ($seen + 1));

    if ($seen === 0) {
        // No response arrived on the first attempt, and a complete one on the
        // second — which is exactly the boundary the pack retries on.
        $truncate();
        return;
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'attempt' => $seen + 1], JSON_THROW_ON_ERROR);
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'no such upstream route', 'path' => $path], JSON_THROW_ON_ERROR);
