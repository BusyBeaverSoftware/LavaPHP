<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Auth\PasswordHasher;
use App\Auth\SessionCookie;
use App\Auth\UserRepository;
use App\Problem\CsrfMismatch;
use App\Problem\EmailTaken;
use App\Problem\InvalidCredentials;
use Lava\Core\Http\Responses;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validator;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Register, sign in, sign out.
 *
 * Every handler here answers HTML, which forces one decision over and over:
 * a rejection re-renders the form it came from. `HttpErrors::forReport()` is
 * right for `/api/*` and wrong here — it negotiates, but what it negotiates a
 * browser to is the framework's diagnostics page, a developer's view of the
 * failure rather than the form the person was filling in. The `/api/*` routes
 * do use it; see {@see \App\Http\Api\PostApiController}.
 *
 * The exception is CSRF: a mismatched token is not a user error to fix by
 * retyping, it is a stale tab or a cross-site post. It gets a 403 and the form
 * back with a message, rather than the envelope, because a login page that
 * answers with JSON when a session has quietly expired is a dead end for the
 * person looking at it.
 */
final class AuthController
{
    /**
     * How long a password has to be.
     *
     * Twelve characters and nothing else — no composition rules. Length is the
     * only requirement that reliably increases the search space, and the usual
     * "one uppercase, one symbol" rules are what push people toward `Passw0rd!`
     * and a sticky note.
     */
    private const MIN_PASSWORD = 12;

    public function register(
        ServerRequestInterface $request,
        UserRepository $users,
        PasswordHasher $hasher,
        SessionCookie $cookie,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        $form = self::form($request);

        try {
            $auth->assertCsrf($request, $form);
        } catch (CsrfMismatch $problem) {
            return $this->reject($view, $auth, $request, 'register', 403, FormErrors::single($problem), self::identity($form));
        }

        $input = Validator::of([
            'email' => Field::str()->required()->email()->max(254),
            'display_name' => Field::str()->required()->max(60),
            'password' => Field::str()->required()->min(self::MIN_PASSWORD)->max(200),
            // A cross-field rule is a `custom()` closing over the payload, since
            // a rule is only ever handed its own field's value. It passes when
            // `password` is not a string: that field's own rules refuse it
            // already, and a second complaint about a comparison that never
            // happened would be noise.
            'password_confirm' => Field::str()->required()->custom(
                'matches_password',
                static fn (mixed $confirm): bool => !is_string($form['password'] ?? null)
                    || $confirm === $form['password'],
                'The two passwords do not match.',
                "Send the same value in 'password' and '{field}'.",
            ),
        ])->validate($form);

        if ($input->failed()) {
            return $this->reject($view, $auth, $request, 'register', 422, FormErrors::collect($input->problems()), self::identity($form));
        }


        $email = $input->string('email');

        if ($users->byEmail($email) !== null) {
            return $this->reject($view, $auth, $request, 'register', 409, FormErrors::single(EmailTaken::of($email)), self::identity($form));
        }

        // The check above and the insert below are not atomic, so two simultaneous
        // registrations for one address could both pass it. The unique index on
        // `users.email` is what actually guarantees uniqueness; the check exists
        // to turn the common case into a useful message rather than a 500.
        $user = $users->create($email, $input->string('display_name'), $hasher->hash($input->string('password')));

        return self::signedIn($cookie->authenticated($user->id, $user->sessionEpoch), $cookie, '/');
    }

    public function login(
        ServerRequestInterface $request,
        UserRepository $users,
        PasswordHasher $hasher,
        SessionCookie $cookie,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        $form = self::form($request);
        $next = Redirects::safeNext($form['next'] ?? null);

        try {
            $auth->assertCsrf($request, $form);
        } catch (CsrfMismatch $problem) {
            return $this->reject($view, $auth, $request, 'login', 403, FormErrors::single($problem), ['email' => self::text($form['email'] ?? null), 'next' => $next]);
        }

        $input = Validator::of([
            'email' => Field::str()->required()->email()->max(254),

            // No `min()`: the length policy belongs to registration. Enforcing
            // it here would lock out any account whose password predates the
            // policy, and the failure message would leak the policy to anyone
            // probing for it.
            'password' => Field::str()->required()->max(200),
        ])->validate($form);

        if ($input->failed()) {
            return $this->reject($view, $auth, $request, 'login', 422, FormErrors::collect($input->problems()), ['email' => self::text($form['email'] ?? null), 'next' => $next]);
        }

        $email = $input->string('email');
        $credentials = $users->credentials($email);

        // One branch for "no such account" and "wrong password", because they
        // are the same failure to anyone probing for which addresses exist.
        // `verify()` handles a null hash against a decoy so the two also take
        // the same time — see PasswordHasher::DECOY.
        if (!$hasher->verify($credentials?->passwordHash, $input->string('password'))) {
            return $this->reject(
                $view,
                $auth,
                $request,
                'login',
                401,
                FormErrors::single(InvalidCredentials::of($email)),
                ['email' => $email, 'next' => $next],
            );
        }

        // `$credentials` cannot be null here: verify() only returns true when it
        // compared against a real hash. Stated as a guard rather than a `?->`
        // chain further down, so the invariant is written where it is relied on.
        if ($credentials === null) {
            return $this->reject($view, $auth, $request, 'login', 401, FormErrors::single(InvalidCredentials::of($email)), ['email' => $email, 'next' => $next]);
        }

        if ($hasher->needsRehash($credentials->passwordHash)) {
            $users->rehash($credentials->user->id, $hasher->hash($input->string('password')));
        }

        return self::signedIn(
            $cookie->authenticated($credentials->user->id, $credentials->user->sessionEpoch),
            $cookie,
            $next,
        );
    }

    /**
     * Sign out. POST, and CSRF-checked, which is not a formality: a GET logout
     * can be triggered by any `<img src>` on any page on the internet, and the
     * CSRF token is what makes "only you can do this" true.
     */
    public function logout(
        ServerRequestInterface $request,
        Auth $auth,
        SessionCookie $cookie,
    ): ResponseInterface {
        $auth->assertCsrf($request, self::form($request));

        // An entirely new anonymous session rather than just deleting the
        // cookie: the CSRF nonce has to rotate, or a token captured before
        // signing out would still validate afterwards.
        return Responses::redirect('/', 303)
            ->withHeader(SessionCookie::HEADER, $cookie->header($cookie->anonymous()));
    }

    /** Install an authenticated session and continue to where the user was headed. */
    private static function signedIn(\App\Auth\SessionData $session, SessionCookie $cookie, string $next): ResponseInterface
    {
        return Responses::redirect($next, 303)
            ->withHeader(SessionCookie::HEADER, $cookie->header($session));
    }

    /**
     * Re-render a form with its errors and whatever the user had typed.
     *
     * @param array<string, string> $errors field name => message, as {@see FormErrors} builds them
     * @param array<string, mixed> $values what the form is re-filled with
     */
    private function reject(
        ViewRenderer $view,
        Auth $auth,
        ServerRequestInterface $request,
        string $template,
        int $status,
        array $errors,
        array $values,
    ): ResponseInterface {
        return $view->renderStatus($template, $status, Page::context($auth, $request, [
            'errors' => $errors,
            'values' => $values,
        ]));
    }

    /**
     * The register template reads `values.email` and `values.display_name`; the
     * login template reads `values.email` and `values.next`. One key set for
     * both keeps the template contract in one place.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function identity(array $form): array
    {
        return [
            'email' => self::text($form['email'] ?? null),
            'display_name' => self::text($form['display_name'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function form(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
