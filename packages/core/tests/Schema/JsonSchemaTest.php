<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Schema;

use Lava\Core\Console\ExitCode;
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
 * `docs/schemas/lava.check/1.json`. So this test never restates a payload by
 * hand — it runs the commands, reads the claim off the output, and holds the
 * output to it. That is what makes it a drift check rather than a snapshot: add
 * a key to a payload without touching its schema and this goes red, because the
 * schemas say `additionalProperties: false`. Even the union of commands and
 * schemas is read off `lava list` rather than written out here.
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
     * One entry per invocation: the argv, and the fixture app to run it in.
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function invocations(): array
    {
        return [
            'about' => [['about', '--json'], self::APP],
            // The roster and one class in full: the two shapes `symbols` takes,
            // empty and populated, under the same six required keys.
            'api' => [['api', '--json'], self::APP],
            'api for one class' => [['api', 'Router', '--json'], self::APP],
            'check' => [['check', '--json'], self::APP],
            'config' => [['config', '--json'], self::APP],
            'describe' => [['describe', 'users.show', '--json'], self::APP],
            'env' => [['env', '--json'], self::APP],
            'features' => [['features', '--json'], self::APP],
            // The subcommand reports the PARENT's schema: an agent that learned
            // `lava.features/1` does not learn a second shape for `resolve`.
            'features resolve' => [['features', 'resolve', 'beta_greeting', '--json'], self::APP],
            'list' => [['list', '--json'], self::APP],
            // `--check`, not the write: the default mode writes AGENTS.md into
            // the fixture, and a test run must not mutate a tracked fixture. The
            // write path is covered by MapCommandTest, in a temp-dir copy.
            'map' => [['map', '--check', '--json'], self::APP],
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
            // The report is readable and incomplete, which is a third failure
            // shape: a problem in `problems[]` alongside a payload whose counts
            // come from the report and are therefore all zero.
            'test with a report that cannot explain the exit code' => [['test', '--json'], 'setup-error-app'],
            'check on a report that cannot explain the exit code' => [['check', '--json'], 'setup-error-app'],
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

    /**
     * The union, in one app: every contract a command claims has a file under
     * `docs/schemas/`, and every file there is claimed by a command.
     *
     * Both directions need an app that has every pack — the command set is a
     * boot decision, so an app that loads no pack cannot be asked about a pack's
     * commands. That app is `packed-app`, the smallest app whose
     * `app/Modules.php` names a real pack's module class.
     *
     * The claim is read off the payload (`commands[].schema`) rather than
     * re-derived from the command name, so the colon-to-dot rule and the
     * per-command version have exactly one home, `Envelope::schema()`. A `/2`
     * bump that forgot this payload's copy therefore cannot happen: there is no
     * copy. This used to be a list of pack contracts in this file, which meant a
     * pack adding a command had to edit a CORE test; now it edits a fixture
     * app's manifest, which is the same edit an app makes to use the pack. A
     * list in a test is a copy of the registry; an app manifest is a use of it.
     */
    public function testEverySchemaIsClaimedAndEveryClaimIsDocumented(): void
    {
        if (!class_exists(\Lava\Db\DbModule::class)) {
            self::markTestSkipped('lavaphp/db is not installed, so no fixture can boot with a pack enabled');
        }

        $listed = LavaCli::run(['list', '--json'], self::fixture('packed-app'));
        self::assertTrue(
            $listed->data()['booted'] === true,
            "the packed fixture did not boot, so its command set is the core one only:\n{$listed->stderr}",
        );

        $claimed = [];
        foreach ($listed->data()['commands'] as $command) {
            self::assertIsArray($command);
            $claimed[] = (string) $command['schema'];
        }
        $claimed[] = 'lava-envelope/1'; // the shared vocabulary, not a command

        $documented = EnvelopeSchemas::schemaNames();

        sort($claimed);
        sort($documented);
        self::assertSame($claimed, $documented, implode("\n", [
            'docs/schemas/ and the command set disagree. A contract only in the list is',
            'a command claiming a schema file that does not exist; one only in the files',
            "is a document describing a command nothing registers. Both are edited in",
            'docs/schemas/ — and a pack that adds a command belongs in the packed-app',
            "fixture's app/Modules.php, not in a list in this test.",
        ]));
    }

    /**
     * A REJECTED invocation, for every command in an app that has every pack:
     * the envelope the kernel emits before the command runs must still obey
     * the contract that command claims.
     *
     * This is what holds the flag check to the envelope contract, and it is
     * generated from `lava list` rather than written out case by case. Every
     * `lava.<cmd>/N` says `required: [...]` with `additionalProperties: false`,
     * and a usage error is emitted WITHOUT the command running — so the payload
     * has to be a declared shape ({@see \Lava\Core\Console\Command::emptyPayload()})
     * rather than something the command happens to write on its way past. A
     * command added to core or to a pack tomorrow is covered here without
     * anyone remembering to add it, because `packed-app` is what puts that
     * pack's commands in the list.
     */
    public function testARejectedInvocationObeysTheSchemaItsCommandClaims(): void
    {
        if (!class_exists(\Lava\Db\DbModule::class)) {
            self::markTestSkipped('lavaphp/db is not installed, so no fixture can boot with a pack enabled');
        }

        $listed = LavaCli::run(['list', '--json'], self::fixture('packed-app'));
        self::assertTrue(
            $listed->data()['booted'] === true,
            "the packed fixture did not boot, so its command set is the core one only:\n{$listed->stderr}",
        );

        foreach ($listed->data()['commands'] as $command) {
            self::assertIsArray($command);
            $name = (string) $command['name'];

            $result = LavaCli::run([$name, '--lava-no-such-flag', '--json'], self::fixture('packed-app'));

            self::assertSame(
                ExitCode::Usage,
                $result->exit,
                "`lava {$name}` accepted a flag it does not declare:\n{$result->stdout}{$result->stderr}",
            );
            // The same contract the command claims on a real run — the schema
            // is not swapped for a generic one just because the run failed.
            self::assertSame((string) $command['schema'], $result->schema());
            EnvelopeSchemas::assertObeys($result, "`lava {$name} --lava-no-such-flag --json`");
        }
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
