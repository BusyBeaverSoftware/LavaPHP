<?php

declare(strict_types=1);

namespace App\Http;

use App\Blog\Post;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validated;
use Lava\Validate\Validation\Validator;

/**
 * The post editor's form: what it starts from, what it accepts, and how to read
 * a validated result.
 *
 * Store and update validate the same three fields, so the rules are declared
 * once. The `*Values` statics are the other half of the same idea — a form that
 * fails validation has to be re-rendered **with what the user typed**, and
 * without a single place that maps a form array into template values, store and
 * update drift into two slightly different editors.
 */
final class PostForm
{
    /** A new post: published by default, because the common case is writing to publish. */
    public const BLANK = ['title' => '', 'body' => '', 'published' => true];

    /** @return array<string, mixed> */
    public static function fromPost(Post $post): array
    {
        return ['title' => $post->title, 'body' => $post->body, 'published' => $post->published];
    }

    /**
     * What to show after a rejected submit. Echoing the submission back is not a
     * courtesy — a form that clears itself on error is a form that loses work,
     * and the values are escaped by Twig on the way out.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function fromForm(array $form): array
    {
        return [
            'title' => self::text($form['title'] ?? null),
            'body' => self::text($form['body'] ?? null),
            'published' => self::checkbox($form['published'] ?? null),
        ];
    }

    public static function validator(): Validator
    {
        return Validator::of([
            'title' => Field::str()->required()->max(200),

            // No `required()` and no `min()`: a title-only post is a legitimate
            // link, and rejecting one would be this app inventing a rule the
            // user did not ask for.
            'body' => Field::str(),

            'published' => Field::bool(),
        ]);
    }

    public static function title(Validated $input): string
    {
        return $input->has('title') ? $input->string('title') : '';
    }

    public static function body(Validated $input): string
    {
        return $input->has('body') ? $input->string('body') : '';
    }

    public static function published(Validated $input): bool
    {
        return $input->has('published') && $input->bool('published');
    }

    /**
     * An unchecked checkbox is not submitted at all, so absence means false —
     * which is why this cannot be `(bool) $value`.
     *
     * The accepted spellings are `Field::bool()`'s, restated here because the
     * pack exposes no way to coerce a value outside a validation run: `coerce()`
     * lives on the rule, and the only path to it is `Validated::bool()` after a
     * `validate()`. This is the one form value that needs coercing for DISPLAY
     * (the value written into the `checked` attribute) rather than for storage,
     * so it is the one that has to spell them out.
     */
    private static function checkbox(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'on'
            || $value === 'true';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
