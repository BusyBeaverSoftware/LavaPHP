<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validator;
use Psr\Http\Message\ResponseInterface;

/**
 * A field declaration that is wrong on purpose.
 *
 * The predicate throws, and a predicate that throws is an app defect, not a
 * validation failure. The pack reports it as `invalid_rule` with a 500 and the
 * app's own file and line, rather than as a 422 blaming the caller for a value
 * that was fine.
 *
 * The route exists so that behaviour is exercised over real HTTP, where the
 * difference between a 500 and a 422 is observable, rather than only in a unit
 * test that asserts on the exception.
 *
 * The payload is hardcoded rather than read from the request, because the
 * predicate must actually be REACHED for this fixture to test anything: with an
 * empty body, `slug` would fail `->required()` first and the broken predicate
 * would never run.
 */
final class BrokenController
{
    public function store(): ResponseInterface
    {
        $input = Validator::of([
            'slug' => Field::str()->required()->custom(
                name: 'not_a_reserved_word',
                check: static fn (mixed $value): bool => throw new \RuntimeException('the predicate itself is broken'),
                message: "'{field}' must not be a reserved word.",
                fix: "Send '{field}' as a slug that is not reserved.",
            ),
        ])->validate(['slug' => 'ada-lovelace']);

        return Responses::json(['slug' => $input->string('slug')]);
    }
}
