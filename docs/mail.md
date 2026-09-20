# Sending email

LavaPHP ships no mailer. A mail library is a service like any other, wired in
`app/Services.php`; the framework's part is the view pack for message bodies and
`replace:` for tests. This page follows what an outside build needed for
password resets and comment notifications (Lava Notes, R2-G5), with
[symfony/mailer](https://symfony.com/doc/current/mailer.html) as the library.

## Wire a mailer

```sh
composer require symfony/mailer
```

```php
// app/Services.php
use Lava\Core\Boot\AppContext;
use Lava\Core\Config\EnvVar;
use Lava\Core\Config\ProcessEnv;
use Lava\Core\Container\Container;
use Lava\Core\Problem\InvalidConfig;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

return function (Container $c, AppContext $ctx): void {
    // Declared, so `lava env` lists it with the value redacted.
    $c->value(EnvVar::CONTAINER_ID, [
        EnvVar::required('MAILER_DSN', 'Where mail goes, e.g. smtp://user:pass@smtp.example.com:587', secret: true),
    ]);

    // `null://null` drops every message, which is right for a development
    // machine and wrong for production: a deploy that forgets MAILER_DSN would
    // boot green and silently discard every password reset. So the default
    // applies outside production only, and prod refuses to boot without it —
    // the same rule SESSION_SECRET follows, for the same reason.
    $dsn = ProcessEnv::real('MAILER_DSN')
        ?? ($ctx->env === 'prod' ? throw new InvalidConfig(
            'MAILER_DSN is not set, and this app must not discard mail silently.',
            'Set MAILER_DSN in the deployment environment.',
            ['name' => 'MAILER_DSN'],
        ) : 'null://null');
    $c->singleton(MailerInterface::class, static fn (): MailerInterface => new Mailer(Transport::fromDsn($dsn)));
};
```

`EnvVar::CONTAINER_ID` holds one list: an app that already declares variables
adds this one to that list. A handler takes `MailerInterface` as a parameter,
and `lava services` shows where it was registered.

**A declaration is not a guarantee.** `EnvVar::required()` is what `lava env` and
`lava check` read; a production boot does not consult it, so a variable whose
absence must stop the deploy is refused in the factory, as above. The
alternative — booting and discarding mail — is a security control that fails
open: the password-reset link nobody receives is indistinguishable from the one
an attacker intercepted.

**`smtp://` negotiates TLS opportunistically.** Symfony's ESMTP transport starts
TLS when the relay advertises it and sends in the clear when it does not, so a
misconfigured relay takes the credentials in that DSN with it. Use `smtps://`
(implicit TLS, port 465) or a relay you have verified advertises STARTTLS.

## Write the body with a template

`ViewRenderer::renderToString()` returns the text. lavaphp/view escapes HTML in
every template, and that is not configurable, so a plain-text template turns it
off around its own body; otherwise an `&` in a name arrives as `&amp;`.

**Keep those templates behind their own namespace.** A template with escaping
off is the one file in an app that must never be rendered as a page, and a name
is a weak way to say so — `views/emails/reset.txt.twig` is one `render()` call,
or one `{% include %}` from an HTML page, away from being served unescaped.
Declaring the directory as a namespace makes the escaping-off set a place rather
than a convention:

```php
// config/view.php
'namespaces' => ['text' => 'views/emails'],
```

```twig
{# views/emails/password-reset.txt.twig — rendered as '@text/password-reset.txt' #}
{% autoescape false %}
Hello {{ name }},

To choose a new password, open this link within the hour:
{{ link }}
{% endautoescape %}
```

```php
use Symfony\Component\Mime\Email;

$mailer->send((new Email())
    ->from('no-reply@example.com')
    ->to($user['email'])
    ->subject('Reset your password')
    ->text($view->renderToString('@text/password-reset.txt', ['name' => $user['name'], 'link' => $link])));
```

Never render such a template as an HTML page: its values are not escaped. Build
the link from the configured base URL, never from the request's `Host` header,
which the client controls.

Headers go through `Email`'s setters, never through concatenated strings. An
address with a line break in it, the start of a header injection, is refused
with an exception, and a subject is encoded for you. A body line longer than
the 998 octets RFC 5322 allows is sent quoted-printable, so a pasted comment
with no line breaks still reaches the relay intact.

## Do not let sending reveal who has an account

A reset form that says "if an account uses that email, we sent a link" keeps
its promise only if the response takes as long either way. Sending over SMTP
inside the request for known addresses only is a timing oracle. Send after the
response, from a queue the app owns: a table of messages and an app command
that cron runs, which is how LavaPHP does scheduled work. Or do the same work
for both branches.

## Test it

Swap the mailer for one whose transport records instead of sending:

```php
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

final class RecordingTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public array $sent = [];

    protected function doSend(SentMessage $message): void
    {
        $this->sent[] = $message;
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}

$transport = new RecordingTransport();
$app = TestApp::boot(dirname(__DIR__), replace: [MailerInterface::class => new Mailer($transport)]);

(new TestClient($app))->form('POST', '/forgot-password', ['email' => 'ada@example.com']);

$email = $transport->sent[0]->getOriginalMessage();
self::assertInstanceOf(Email::class, $email);
self::assertStringContainsString('/reset-password?token=', (string) $email->getTextBody());
```

A command test takes the same map: `new TestConsole($dir, replace: [...])`.
`replace:` only replaces an id something registered, so the test fails with
`bad_replacement` if `app/Services.php` stops registering `MailerInterface`.
