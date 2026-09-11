<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Schema;

use Lava\Core\Tests\Support\EnvelopeSchemas;
use Lava\Core\Tests\Support\LavaCli;
use Lava\Core\Tests\Support\LavaResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every `--json` envelope, validated against the schema it claims to obey.
 *
 * The envelope's own `schema` field names the contract, and `docs/schemas/`
 * holds the contract as a file: `lava.check/1` means
 * `docs/schemas/lava.check/1.json`. So this test never lists commands — it runs
 * them, reads the claim off the output, and holds the output to it. That is what
 * makes it a drift check rather than a snapshot: add a key to a payload without
 * touching its schema and this goes red, because the schemas say
 * `additionalProperties: false`.
 *
 * The failed runs are validated too, and they are the more valuable half. The
 * envelope promises its `data` keys on EVERY exit path — including a failed
 * boot — so a payload that only holds together while the app is healthy is a
 * payload an agent cannot parse at the moment it most needs to.
 *
 * Schemas resolve through a registered prefix rather than the network: the `$id`s
 * are `https://lavaphp.dev/...` URLs, and opis would happily try to fetch them.
 * Pointing that prefix at `docs/schemas/` keeps the suite offline and turns a
 * missing `$ref` target into a local failure.
 */
final class JsonSchemaTest extends TestCase
{
    /** The app the healthy-run invocations use. */
    private const APP = 'ok-app';

    /**
     * Contracts documented in `docs/schemas/` that belong to a pack.
     *
     * A colon in a command name cannot survive into a file name — it is illegal
     * in a path on Windows — so `db:status`'s contract is `lava.db.status/1`.
     *
     * @var list<string>
     */
    private const PACK_SCHEMAS = [
        'lava.db.migrate/1',
        'lava.db.new/1',
        'lava.db.rollback/1',
        'lava.db.status/1',
    ];

    /**
     * One entry per invocation: the argv, and the fixture app to run it in.
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function invocations(): array
    {
        return [
            'about' => [['about', '--json'], self::APP],
            'check' => [['check', '--json'], self::APP],
            'config' => [['config', '--json'], self::APP],
            'describe' => [['describe', 'users.show', '--json'], self::APP],
            'env' => [['env', '--json'], self::APP],
            'features' => [['features', '--json'], self::APP],
            // The subcommand reports the PARENT's schema: an agent that learned
            // `lava.features/1` does not learn a second shape for `resolve`.
            'features resolve' => [['features', 'resolve', 'beta_greeting', '--json'], self::APP],
            'list' => [['list', '--json'], self::APP],
            'routes' => [['routes', '--json'], self::APP],
            'services' => [['services', '--json'], self::APP],
            // `serve` blocks by design, so its success path cannot be run here;
            // the usage-error path is the one to check, and it carries the same
            // seven keys on purpose — the payload is seeded before any check.
            'serve' => [['serve', '--json', '--port=99999'], self::APP],
            'test' => [['test', '--json'], self::APP],

            // ── failed runs: the payload shape has to survive ───────────────
            // A usage error is checked before the boot, so these two are the
            // only invocations where the payload is emitted without the app
            // ever being consulted — and the schema still requires its keys.
            // They once emitted `"data":[]`, a LIST where the contract says
            // object, because the payload was seeded after the check.
            'describe with no selector' => [['describe', '--json'], self::APP],
            'features resolve with no flag' => [['features', 'resolve', '--json'], self::APP],
            'check on an app that cannot boot' => [['check', '--no-tests', '--json'], 'broken-wiring-app'],
            'routes on an app that cannot boot' => [['routes', '--json'], 'broken-wiring-app'],
            'about on an app that cannot boot' => [['about', '--json'], 'broken-wiring-app'],
            'list on an app that cannot boot' => [['list', '--json'], 'broken-wiring-app'],
            'describe on an app that cannot boot' => [['describe', 'users.show', '--json'], 'broken-wiring-app'],
            'test with no runner installed' => [['test', '--json'], 'module-app'],
            'test with a runner that produced nothing' => [['test', '--json'], 'bad-suite-app'],
            'check on a red suite' => [['check', '--json'], 'red-app'],
            'env with a required variable unset' => [['env', '--json'], 'env-app'],
            'config with a wrong-shaped artifact' => [['config', '--json'], 'bad-commands-app'],
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('invocations')]
    public function testTheEnvelopeObeysTheSchemaItClaims(array $args, string $fixture): void
    {
        $result = LavaCli::run($args, self::fixture($fixture));

        $schema = $result->schema();
        $errors = EnvelopeSchemas::validator()->validate(EnvelopeSchemas::json($result), EnvelopeSchemas::url($schema));

        self::assertTrue(
            $errors->isValid(),
            '`lava ' . implode(' ', $args) . "` on {$fixture} emitted {$schema}, which its own schema rejects:\n"
            . EnvelopeSchemas::explain($errors->error()),
        );
    }

    public function testTheClaimedSchemaFileExists(): void
    {
        // Separate from validation so a missing file reads as a missing file
        // rather than as a validator failure: the first is a packaging mistake,
        // the second is a contract change.
        foreach (self::invocations() as $label => [$args, $fixture]) {
            $schema = LavaCli::run($args, self::fixture($fixture))->schema();

            self::assertFileExists(
                EnvelopeSchemas::file($schema),
                "`{$label}` claims {$schema}, but " . EnvelopeSchemas::file($schema) . ' does not exist.',
            );
        }
    }

    public function testEverySchemaIdMatchesItsPath(): void
    {
        // `$id` is not decoration: it is the base URI every `$ref` inside the
        // file resolves against. Rename a file without renaming its `$id` and
        // cross-references point at a URL that no longer exists — which the
        // prefix resolver would hide, since it maps URLs to paths and would
        // cheerfully load the file under either name.
        foreach (EnvelopeSchemas::schemaFiles() as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($schema, "{$file} is not a JSON object");

            $expected = EnvelopeSchemas::url(basename(dirname($file)) . '/' . basename($file, '.json'));
            self::assertSame($expected, $schema['$id'] ?? null, "{$file} declares the wrong \$id");
        }
    }

    public function testEverySchemaLoadsThroughThePrefixResolver(): void
    {
        // Loading is the real test of a schema file: opis parses every keyword,
        // resolves the draft from `$schema`, and resolves each `$ref` — so a
        // typo'd keyword or a `$ref` pointing at a file that is not there fails
        // HERE, rather than as a confusing error inside some later validation.
        foreach (EnvelopeSchemas::schemaFiles() as $file) {
            $schema = basename(dirname($file)) . '/' . basename($file, '.json');

            try {
                $loaded = EnvelopeSchemas::loader()->loadSchemaById(EnvelopeSchemas::uri($schema));
            } catch (\Throwable $error) {
                self::fail("{$file} could not be loaded as a schema: {$error->getMessage()}");
            }

            self::assertNotNull($loaded, "{$file} did not resolve through the prefix resolver");
        }
    }

    public function testEveryCoreCommandHasASchema(): void
    {
        // The convention this test enforces: a core command named `x` emits
        // `lava.x/1` and is documented by `docs/schemas/lava.x/1.json`. It is a
        // convention, not a law — but a new command that breaks it should break
        // this test, so that the decision to break it is made deliberately.
        $listed = LavaCli::run(['list', '--json'], self::fixture(self::APP));

        $core = [];
        foreach ($listed->data()['commands'] as $command) {
            self::assertIsArray($command);
            if (($command['pack'] ?? null) === 'core') {
                $core[] = (string) $command['name'];
            }
        }
        self::assertNotEmpty($core, 'lava list reported no core commands');

        // And the reverse: a schema file nothing claims is a contract for a
        // command that no longer exists.
        $documented = [];
        foreach (EnvelopeSchemas::schemaFiles() as $file) {
            $documented[] = basename(dirname($file)) . '/' . basename($file, '.json');
        }

        $expected = array_map(static fn (string $name): string => "lava.{$name}/1", $core);
        $expected[] = 'lava-envelope/1'; // the shared vocabulary, not a command

        // Pack commands are named `pack:command`, so they are absent from an
        // app that does not enable the pack, and this test cannot enumerate
        // them without depending on a pack's fixtures — which is exactly the
        // coupling the packs exist to avoid. They are listed here instead, and
        // each pack's own schema test holds its own to this list: it asserts
        // the same set in the other direction, so a deleted or stale pack
        // schema fails there rather than going unnoticed here.
        $expected = array_merge($expected, self::PACK_SCHEMAS);

        sort($documented);
        sort($expected);
        self::assertSame($expected, $documented, 'docs/schemas/ and the core command set disagree');
    }

    public function testTheProblemObjectIsTheSameShapeEverywhere(): void
    {
        // The strongest single claim the envelope makes: a problem read from a
        // failed boot, from a typo, from `lava check`, and from a wrong-shaped
        // artifact all validate against ONE definition. If they ever diverge, an
        // agent's error handler needs a branch per source.
        $problemSchema = EnvelopeSchemas::url('lava-envelope/1') . '#/$defs/problem';

        $sources = [
            'a failed boot' => LavaCli::run(['routes', '--json'], self::fixture('broken-wiring-app')),
            'a usage error' => LavaCli::run(['nope', '--json'], self::fixture(self::APP)),
            'a wrong-shaped artifact' => LavaCli::run(['check', '--no-tests', '--json'], self::fixture('bad-commands-app')),
        ];

        foreach ($sources as $label => $result) {
            $envelope = EnvelopeSchemas::json($result);
            $problems = $envelope->problems ?? [];
            self::assertIsArray($problems, "{$label} carried no problems array");
            self::assertNotEmpty($problems, "{$label} produced no problems to validate");

            foreach ($problems as $index => $problem) {
                $errors = EnvelopeSchemas::validator()->validate($problem, $problemSchema);
                self::assertTrue(
                    $errors->isValid(),
                    "the problem from {$label} (#{$index}) is not the shared problem shape:\n"
                    . EnvelopeSchemas::explain($errors->error()),
                );
            }
        }
    }

    private static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/apps/' . $name;
    }
}
