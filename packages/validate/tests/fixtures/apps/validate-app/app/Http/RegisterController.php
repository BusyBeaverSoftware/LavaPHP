<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\HttpErrors;
use Lava\Core\Http\Responses;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A registration form, validated field by field.
 *
 * The whole field set is declared inline, which is the honest shape for one
 * route: the validator is a value, so building it per request costs an array
 * and hides nothing. A field set shared by several routes would move to a
 * service and be built once.
 *
 * The response echoes the values that were read back, so a test can see that
 * `age` arrived as the string `"34"` and was read as the int 34. It does NOT
 * echo the password — only its length — because a handler that reflects
 * credentials into a response body is the habit this pack's redaction exists to
 * make unnecessary.
 */
final class RegisterController
{
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = Validator::of([
            'email' => Field::str()->required()->email()->max(254),
            'password' => Field::str()->required()->min(12),
            'age' => Field::int()->required()->min(18)->max(120),
            'nickname' => Field::str()->max(20),
            'role' => Field::any()->required()->in(['editor', 'admin']),
            'terms' => Field::bool()->required(),
        ])->validate(self::body($request));

        if ($input->failed()) {
            return HttpErrors::forReport($input->report(), $request);
        }

        return Responses::json([
            'user' => [
                'email' => $input->string('email'),
                'age' => $input->int('age'),
                'nickname' => $input->has('nickname') ? $input->string('nickname') : null,
                'role' => $input->string('role'),
                'terms' => $input->bool('terms'),
            ],
            'password_length' => mb_strlen($input->string('password')),
        ]);
    }

    /**
     * The parsed body as an array.
     *
     * A body that was not an object never reaches a handler — `RequestBody`
     * turns it into a 400 before routing — so the fallback is for a request
     * with no body at all, which is a request that sent no fields.
     *
     * @return array<string, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
