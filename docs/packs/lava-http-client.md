# lavaphp/http-client

A PSR-18 client on ext-curl, with two rules and a JSON shortcut. One module,
three container ids, five problem codes — and every failure leaving the pack as
a `LavaProblem` with a URL you can read and a fix you can act on.

Three properties are the reason it exists rather than an app calling curl
directly:

- **A method that is not idempotent is never retried.** `retries` is a ceiling,
  not a promise: it applies to `GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS` and
  `TRACE` and to nothing else, whatever it is set to. A POST sent twice is two
  creates, and the pack can see the method — so it makes the distinction itself
  instead of leaving it to every call site to remember.
- **A credential never reaches a report.** The URL's userinfo is masked, so are
  secret-shaped query parameters, and the request's headers and body are printed
  nowhere at all. `TransportFailed` keeps the request object because PSR-18
  requires it; keeping is not printing.
- **The failure says which of the three steps went wrong.** Fetch, status,
  decode: a URL that cannot be sent, a non-2xx, and a body that is not JSON are
  three different codes with three different fixes, rather than one "request
  failed".

Codes this pack raises: `transport_failed`, `bad_request_url`,
`unexpected_status`, `bad_json_response`, `unencodable_json_body` — see
[problem-codes.md](../problem-codes.md).

## Install and enable

```sh
composer require lavaphp/http-client
```

```php
// app/Modules.php
use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\HttpClient\HttpClientModule::class, package: 'lavaphp/http-client', feature: 'http_client'),
];
```

`http_client` is the pack's gate. Do **not** add a `define` entry for it — the
pack defines it, and writing it yourself is a boot problem rather than a
harmless duplicate. Turn the pack off from `set` or from the environment:

```php
// config/features.php
return ['set' => ['http_client' => \Lava\Core\Features\Flag::off()]];
```

```sh
LAVA_FEATURE_HTTP_CLIENT=off
```

Turning it off is not free if your own wiring references the pack: a service in
`app/Services.php` that type-hints `HttpClient` becomes a dangling reference and
boot reports `service_not_registered` naming the file and the line.

## Configure

`config/http_client.php`, optional — every key has a default:

```php
return [
    'timeout'         => 10,                    // seconds for one attempt, connect included
    'connect_timeout' => 5,                     // seconds for the connection alone
    'retries'         => 2,                     // extra attempts, idempotent methods only
    'backoff_ms'      => 100,                   // milliseconds between attempts
    'user_agent'      => 'lavaphp/http-client',
];
```

Every value is read **at register time**, so a bad one is a boot problem naming
the key and the file — not a failure on whichever request happens to touch the
client first. A negative or zero `timeout`, a negative `retries` or
`backoff_ms` is `invalid_config` with the fix `Fix the value of 'timeout' in
config/http_client.php`. The type checks are `Config`'s own; the range checks
are `InvalidConfig::outOfRange()`, which is in core because the rule is about
config values and the fix is text core can write without knowing anything about
HTTP.

`timeout => 0` is refused rather than honoured: curl reads it as "no timeout at
all", which is the opposite of what someone writing `0` means.

The pack reads no environment variable of its own. An app that wants
`HTTP_CLIENT_TIMEOUT` to win should read it in `config/http_client.php`, where
the precedence is visible in one file rather than split between a config reader
and a module.

## The three services

| Id | What it is |
|---|---|
| `HttpClient` | the pack's client: retries, the URL guard, the JSON shortcut |
| `CurlTransport` | the raw ext-curl sender — one request in, one response out, no opinions |
| `ClientOptions` | the values read from config, for a service that wants to know the timeout |

A handler takes `HttpClient` as a typed method parameter, like any other
service:

```php
use App\Rates\RatesToken;
use Lava\Core\Http\Responses;
use Lava\HttpClient\HttpClient;
use Psr\Http\Message\ResponseInterface;

final class RateController
{
    public function show(HttpClient $http, RatesToken $token): ResponseInterface
    {
        $rate = $http->getJson('https://api.example.com/v1/rates?base=EUR', [
            'Authorization' => 'Bearer ' . $token->value,
        ]);

        return Responses::json($rate);
    }
}
```

A handler is constructed with no arguments, so everything it needs is a method
parameter — the token too. `RatesToken` is the app's own small class, registered
from config in `app/Services.php`:
`$c->singleton(RatesToken::class, static fn (): RatesToken => new RatesToken($ctx->config->needString('app.rates_token')));`.
A `$this->token` here would read an empty property and send `Bearer ` with no token.

`CurlTransport` is registered separately from `HttpClient` so a caller can have
one unadorned request — no retries, no status opinion, the response as it
arrived. Neither id can be registered a second time — the container refuses that
everywhere — so a different transport goes in through `HttpClient`'s constructor,
as below. A test that needs the pack's own id to hold a client around a fake
passes it to `TestApp::boot()`:
`replace: [HttpClient::class => new HttpClient($fake, new Psr17Factory())]`.

**There is no `ClientInterface` alias.** Aliasing the PSR-18 interface would be
convenient — type-hint it, get the pack's client — but it would also *occupy*
the standard id, leaving an app that wants its own PSR-18 client under that id
with a `duplicate_service` at boot and no way around it. An app with different
needs constructs its own:

```php
// app/Services.php
use Nyholm\Psr7\Factory\Psr17Factory;

$c->singleton(MyClient::class, static fn (Container $c): MyClient => new MyClient(
    new \Lava\HttpClient\HttpClient($theirTransport, new Psr17Factory(), $options),
));
```

The pack's own registration is the same shape, and it is worth reading: the
options are read from config **once**, at register time, and captured by the
factories. A factory that read config when it ran would make a service's
behaviour depend on when it was first resolved — and would move a bad `timeout`
from a boot problem to whichever request happened to touch the client first.

## Requests

| Call | Returns |
|---|---|
| `sendRequest($request)` | a `ResponseInterface`, whatever its status |
| `request($method, $url, $headers = [], $body = '')` | the same, built from parts |
| `get($url, $headers = [])` / `post($url, $body = '', $headers = [])` | the same, for the two common verbs |
| `json($method, $url, $body = [], $headers = [])` | a decoded `array`, or a problem |
| `getJson($url, $headers = [])` / `postJson($url, $body = [], $headers = [])` | the same, for the two common verbs |

**`sendRequest()` is the PSR-18 boundary, and it is drawn where PSR-18 draws
it.** It returns the response whatever its status — a 404 is a result, not an
exception — and throws only when no response arrived
(`transport_failed`, a `NetworkExceptionInterface`) or when the request could
not be sent at all (`bad_request_url`, a `RequestExceptionInterface`).

**Retries are "no response arrived", and nothing else.** A connection refused, a
DNS failure, a timeout, a response cut short: those are retried, for idempotent
methods, up to `retries` extra times with `backoff_ms` between them. A 5xx is
*not* retried, because a response that arrived is a response — retrying it
without honouring `Retry-After` and without jitter is how one slow service
becomes a stampede, and PSR-18 already gave the caller a perfectly good way to
decide for itself.

**An empty `$body` sends no body at all**, not `{}` — so `postJson($url, [])` is
a POST with an empty body and `getJson()` is a GET that sends nothing. One rule
for every caller, rather than a special case per verb.

`json()` requires a 2xx and a body that decodes to an object or array. A JSON
*scalar* is a `bad_json_response` too: `"ok"` decodes cleanly and is still not
something a caller can index, and `json_error` says which of the two happened.

## The scheme rule

The client fetches `http` and `https` and refuses everything else. This is a
security rule, not a formality: curl will happily read `file:///etc/passwd` and
speak `gopher://`, and a URL handed to this pack is exactly the kind of value
that arrives from outside — a webhook target, a callback, a URL read out of a
row. An HTTP client that fetches whatever scheme it is given is a
file-disclosure and SSRF primitive.

A URL with no scheme, no host, or a scheme that is not `http`/`https` is
`bad_request_url`, raised **before** the transport sees it, so the rule holds
whatever transport an app injected.

## Redirects are not followed

`CurlTransport` sets `CURLOPT_FOLLOWLOCATION => false`, so a 301 arrives as a
301 with its `Location` header. That is what PSR-18 says a response is — and
following a redirect to another host would silently resend the `Authorization`
header somewhere it was never meant to go.

An app that wants redirects handles them explicitly, which is a place to decide
whether the new host may see the credentials.

## What a failure looks like

```json
{"code":"transport_failed",
 "problem":"GET https://***@api.example.com/v1/rates?token=*** could not be sent: Connection refused",
 "fix":"Check that the host is reachable from where the app runs — a container cannot see the host machine's localhost. If the service is up and slow, raise the timeout: 'timeout' in config/http_client.php, or $options->timeout for one call.",
 "context":{"method":"GET","url":"https://***@api.example.com/v1/rates?token=***","reason":"Connection refused"},
 "source":null,"severity":"fatal"}
```

The body of a *response* travels in `context['body']`, bounded and elided. The
body of a *request* does not travel at all: `unencodable_json_body` does not
take the payload as a parameter, so no call site can pass one by accident.

`unexpected_status` picks its fix from the status, because a 404, a 401 and a
503 are three different mistakes in three different places and the status is
already known when the message is written.

## Testing this pack

| Layer | Location | Needs |
|---|---|---|
| URL rules, options, client, module | `packages/http-client/tests/Unit/` | nothing — a scripted transport, no sockets |
| Problem shapes and codes | `packages/http-client/tests/Problem/` | nothing |
| The sender and the wiring, live | `packages/http-client/tests/Http/` | `php -S`, started by the harness |

```sh
# From the repo root.
vendor/bin/phpunit --testsuite=http-client

# The pack on its own, the way a consumer installs it.
cd packages/http-client && composer install && vendor/bin/phpunit
```

`tests/Support/LocalServer.php` starts a real `php -S` on a free port with
`tests/fixtures/server/router.php` behind it, once per test process, and stops it
from a shutdown function so a failed run leaves no orphan. One route per thing
the client has to get right — including `/drop`, which promises a
`Content-Length` it does not deliver, because that is how a *transport* failure
is produced on demand over a real socket.

The live tests reach the pack through a booted fixture app
(`tests/fixtures/apps/http-app/`), whose `config/http_client.php` deliberately
sets every value to something other than the default. That is what makes the
wiring test prove the config file was read rather than that the defaults happen
to be right — and the `provenance()` assertion fails with `'(unset)'` if the file
is renamed away.

`LocalServer` deliberately does **not** use core's `ServedApp` helper: that one
runs `lava serve`, which boots a fixture through the CLI, and a pack that
depended on it would stop being installable on its own.
