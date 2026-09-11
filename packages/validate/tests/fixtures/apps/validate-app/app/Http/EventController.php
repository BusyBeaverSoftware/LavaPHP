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
 * A two-date form, where the second field's rule depends on the first.
 *
 * **This is the documented way to write a cross-field rule**, and it is worth
 * reading closely because it is the one thing a per-field pipeline cannot
 * express. A rule's predicate receives its own field's value and nothing else —
 * deliberately, because a rule that could reach into the whole payload would
 * make a field's answer depend on the order fields happen to be declared in.
 *
 * So the payload is read here, in the handler, and captured by the predicate's
 * closure. The dependency is then visible in the code that has it: you can see
 * that `ends_at` is checked against `starts_at`, and you can see that the
 * validator is built per request rather than once. What you cannot do is
 * discover it from a field list, which is the price of not having hidden
 * ordering.
 *
 * The predicate returns TRUE when `starts_at` is not a readable string, and that
 * is the whole idiom for a cross-field rule: a rule has two outcomes, pass or
 * refuse, and "the field I depend on is not there" is not a reason to refuse
 * `ends_at`. `starts_at` has its own `->required()` and its own problem, and
 * returning false here would add a second complaint — about a comparison that
 * never happened — to a request that only has one thing wrong with it. Guarding
 * the read is not enough on its own: the guard has to decide what to do about
 * the missing value, and the answer is to let this field pass.
 */
final class EventController
{
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $body = self::body($request);

        $input = Validator::of([
            'starts_at' => Field::str()->required()->regex('/\d{4}-\d{2}-\d{2}/'),
            'ends_at' => Field::str()->required()->regex('/\d{4}-\d{2}-\d{2}/')->custom(
                name: 'after_starts_at',
                check: static fn (mixed $ends): bool => !is_string($body['starts_at'] ?? null)
                    || (is_string($ends) && $ends >= $body['starts_at']),
                message: "'{field}' must not be earlier than 'starts_at'.",
                fix: "Send '{field}' as a date on or after the value sent for 'starts_at'.",
                expects: ['not_before' => 'starts_at'],
            ),
        ])->validate($body);

        if ($input->failed()) {
            return HttpErrors::forReport($input->report(), $request);
        }

        return Responses::json([
            'event' => [
                'starts_at' => $input->string('starts_at'),
                'ends_at' => $input->string('ends_at'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
