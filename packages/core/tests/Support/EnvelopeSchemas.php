<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * The `docs/schemas/` plumbing every package's envelope test needs.
 *
 * Schemas resolve through a registered prefix rather than the network: the
 * `$id`s are `https://lavaphp.dev/...` URLs, and opis would happily try to
 * fetch them. Pointing that prefix at `docs/schemas/` keeps the suite offline
 * and turns a missing `$ref` target into a local failure.
 *
 * Shared rather than copied per package because the resolver is the thing that
 * has to agree: if core's tests resolved `$id`s one way and a pack's another,
 * a `$ref` between their schemas would pass in one suite and fail in the other
 * for no reason a reader could see.
 */
final class EnvelopeSchemas
{
    private const SCHEMA_BASE = 'https://lavaphp.dev/schemas/';

    /** A validator that resolves the prefix and collects every error. */
    public static function validator(): Validator
    {
        return new Validator(self::loader(), 50, false);
    }

    public static function loader(): SchemaLoader
    {
        $resolver = new SchemaResolver();
        $resolver->registerPrefix(self::SCHEMA_BASE, self::docsDir());

        return new SchemaLoader(new SchemaParser(), $resolver, true);
    }

    /** `lava.check/1` → the URL its schema is registered under. */
    public static function url(string $schema): string
    {
        return self::SCHEMA_BASE . $schema . '.json';
    }

    public static function uri(string $schema): Uri
    {
        $uri = Uri::parse(self::url($schema));
        Assert::assertNotNull($uri, "{$schema} is not a usable URI");

        return $uri;
    }

    /** `lava.check/1` → `docs/schemas/lava.check/1.json`. */
    public static function file(string $schema): string
    {
        return self::docsDir() . '/' . $schema . '.json';
    }

    public static function docsDir(): string
    {
        // packages/core/tests/Support → the repository root. `dirname(__DIR__, 4)`
        // lands there from any file at this depth, which is what lets the
        // packages share this class.
        return dirname(__DIR__, 4) . '/docs/schemas';
    }

    /** @return list<string> absolute paths of every schema file, sorted */
    public static function schemaFiles(): array
    {
        $files = glob(self::docsDir() . '/*/*.json');
        Assert::assertIsArray($files, 'docs/schemas/ is unreadable');
        sort($files);

        return $files;
    }

    /** Every schema name `docs/schemas/` documents, e.g. `lava.routes/1`. */
    public static function schemaNames(): array
    {
        $names = [];
        foreach (self::schemaFiles() as $file) {
            $names[] = basename(dirname($file)) . '/' . basename($file, '.json');
        }

        return $names;
    }

    /**
     * The envelope as opis wants it: `json_decode`'s DEFAULT mode, which yields
     * stdClass objects.
     *
     * opis will not accept a PHP associative array as a JSON object —
     * `Helper::getJsonType()` returns null for anything that is not an indexed
     * array — so validating the array form would fail every command for a reason
     * that has nothing to do with the payload. Decoding the raw stdout also
     * means the schema is checked against the bytes the command actually wrote,
     * not against a re-encoding of them.
     */
    public static function json(LavaResult $result): object
    {
        $decoded = json_decode(trim($result->stdout));
        Assert::assertIsObject($decoded, "stdout was not a JSON object (exit {$result->exit}): {$result->stdout}");

        return $decoded;
    }

    public static function explain(?ValidationError $error): string
    {
        if ($error === null) {
            return '(no error object)';
        }

        return json_encode(
            (new ErrorFormatter())->format($error, false),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) ?: $error->message();
    }

    /** Validates a result against the schema it claims, and says what broke. */
    public static function assertObeys(LavaResult $result, string $label): void
    {
        $schema = $result->schema();
        $errors = self::validator()->validate(self::json($result), self::url($schema));

        Assert::assertTrue(
            $errors->isValid(),
            "{$label} emitted {$schema}, which its own schema rejects:\n" . self::explain($errors->error()),
        );
    }
}
