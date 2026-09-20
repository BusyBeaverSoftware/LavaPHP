<?php

declare(strict_types=1);

namespace Lava\Validate;

use Lava\Core\Map\ApiSurface;

/** `lavaphp/validate`'s public surface: the validator, its fields and the result. */
final class ValidateApiSurface extends ApiSurface
{
    public function pack(): string
    {
        return 'validate';
    }

    public function package(): string
    {
        return 'lavaphp/validate';
    }

    public function feature(): string
    {
        return 'validate';
    }

    public function namespacePrefix(): string
    {
        return 'Lava\\Validate\\';
    }

    public function sourceRoot(): string
    {
        return __DIR__;
    }

    public function groups(): array
    {
        return [
            '(root)' => 'the module',
            'Validation' => 'the validator, its fields and rules, and the validated result',
        ];
    }

    public function exclusions(): array
    {
        return [
            'Problem/' => 'every problem is catalogued in docs/problem-codes.md, under its own drift guard',
        ];
    }

    public function examples(): array
    {
        return [
            \Lava\Validate\Validation\Validator::class => <<<'PHP'
                use Lava\Core\Http\Responses;
                use Lava\Validate\Validation\Field;
                use Lava\Validate\Validation\Validator;

                $input = Validator::of([
                    'title' => Field::str()->required()->max(200),
                    'email' => Field::str()->required()->email(),
                    'tags' => Field::any(),
                ])->validate($body);

                if ($input->failed()) {
                    // ONE response carrying every bad field, each with its own message.
                    return Responses::json($input->report()->json(), 422);
                }

                $title = $input->string('title');
                PHP,

            \Lava\Validate\Validation\Field::class => <<<'PHP'
                use Lava\Validate\Validation\Field;

                // A field starts from the type it must be, then narrows.
                $rules = [
                    'title' => Field::str()->required()->min(3)->max(200),
                    'age' => Field::int()->min(0),
                    'role' => Field::str()->in(['author', 'editor']),
                    'slug' => Field::str()->regex('/^[a-z0-9-]+$/'),
                    'id' => Field::str()->uuid(),
                ];
                PHP,

            \Lava\Validate\Validation\Validated::class => <<<'PHP'
                use Lava\Validate\Validation\Validated;

                function titleOf(Validated $input): string
                {
                    // Typed accessors, so a validated value is never re-checked
                    // by the handler that asked for it.
                    return $input->valid() ? $input->string('title') : '(untitled)';
                }
                PHP,
        ];
    }
}
