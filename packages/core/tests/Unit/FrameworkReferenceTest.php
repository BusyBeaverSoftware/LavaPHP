<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\CommandRegistry;
use Lava\Core\Config\EnvVar;
use Lava\Core\Container\Container;
use Lava\Core\Features\Feature;
use Lava\Core\Features\Flag;
use Lava\Core\Http\Responses;
use Lava\Core\Map\FrameworkReference;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Routing\RouteArgs;
use Lava\Core\Routing\RouteBuilder;
use Lava\Core\Routing\Router;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/**
 * The Framework Reference cannot teach an API the framework does not have.
 *
 * AGENTS.md's reference section is the one piece of documentation an agent
 * relies on with no way to check it — it is read precisely when the reader does
 * not yet know the framework — so a snippet naming a method that was renamed is
 * worse than no snippet at all: the agent writes code that cannot run and has
 * been told, by the framework itself, that it should.
 *
 * What this test proves: every snippet is valid PHP, every core class it names
 * exists, and every framework method it teaches exists with that name.
 *
 * What it does NOT prove: that a snippet is a complete working file (they are
 * minimal on purpose), and that a name from a PACK exists — core's suite must
 * run with only core installed (`cd packages/core && composer install`), so it
 * cannot require `lava/db` to be present. The `modules` snippet naming
 * `Lava\Db\DbModule` is therefore checked by the db pack, which is the package
 * that owns that class.
 */
final class FrameworkReferenceTest extends TestCase
{
    /**
     * Every framework method the reference teaches, and the class that must own
     * it. Asserted in BOTH directions — each pair must exist, and each method
     * name must appear in a snippet — so the list cannot rot into a record of an
     * API the reference stopped mentioning.
     *
     * @var list<array{class-string, string}>
     */
    private const TAUGHT = [
        [Router::class, 'pattern'],
        [Router::class, 'get'],
        [Router::class, 'post'],
        [RouteBuilder::class, 'handler'],
        [RouteBuilder::class, 'middleware'],
        [RouteBuilder::class, 'when'],
        [Container::class, 'singleton'],
        [Container::class, 'value'],
        [CommandRegistry::class, 'add'],
        [EnvVar::class, 'required'],
        [ModuleRef::class, 'of'],
        [Feature::class, 'define'],
        [Flag::class, 'rollout'],
        [Flag::class, 'on'],
        [Responses::class, 'json'],
        [RouteArgs::class, 'int'],
        [TestApp::class, 'boot'],
        [TestClient::class, 'get'],
    ];

    public function testEverySnippetIsValidPhp(): void
    {
        foreach (self::phpArtifacts() as $key => $artifact) {
            // TOKEN_PARSE makes the tokenizer itself reject a syntax error, which
            // is the cheapest possible proof that what the reader is told to type
            // is something PHP would accept.
            try {
                token_get_all($artifact['code'], TOKEN_PARSE);
            } catch (\ParseError $error) {
                self::fail("the '{$key}' snippet ({$artifact['file']}) is not valid PHP: {$error->getMessage()}");
            }
        }

        self::assertNotEmpty(self::phpArtifacts(), 'the reference has no PHP snippets to check');
    }

    public function testEveryCoreClassTheReferenceNamesExists(): void
    {
        foreach (self::phpArtifacts() as $key => $artifact) {
            foreach (self::coreNames($artifact['code']) as $name) {
                self::assertTrue(
                    class_exists($name) || interface_exists($name) || enum_exists($name),
                    "the '{$key}' snippet names {$name}, which does not exist",
                );
            }
        }
    }

    public function testEveryTaughtMethodExistsAndIsTaught(): void
    {
        $all = implode("\n", array_column(self::phpArtifacts(), 'code'));

        foreach (self::TAUGHT as [$class, $method]) {
            self::assertTrue(
                method_exists($class, $method),
                "the reference teaches {$class}::{$method}(), which the framework does not have",
            );
            self::assertStringContainsString(
                $method . '(',
                $all,
                "{$class}::{$method}() is listed as taught but no snippet calls it — remove it from TAUGHT",
            );
        }
    }

    public function testEveryArtifactNamesItsFileAndItsLanguage(): void
    {
        $artifacts = FrameworkReference::artifacts();
        self::assertNotEmpty($artifacts);

        foreach ($artifacts as $key => $artifact) {
            self::assertNotSame('', $artifact['file'], "artifact '{$key}' has no file");
            self::assertNotSame('', $artifact['note'], "artifact '{$key}' has no note");
            self::assertNotSame('', $artifact['code'], "artifact '{$key}' has no code");
            // The fence language is stated, never sniffed from the content — see
            // the class docblock. An unknown one would render as an unhighlighted
            // fence at best, and a wrong one would highlight PHP as plain text.
            self::assertContains($artifact['lang'], ['php', 'text'], "artifact '{$key}' has an unknown lang");
        }
    }

    public function testTheMarkdownEmbedsEveryArtifact(): void
    {
        $markdown = FrameworkReference::markdown();

        foreach (FrameworkReference::artifacts() as $artifact) {
            self::assertStringContainsString('### `' . $artifact['file'] . '`', $markdown);
            self::assertStringContainsString($artifact['code'], $markdown);
            self::assertStringContainsString("```{$artifact['lang']}", $markdown);
        }
    }

    /**
     * @return array<string, array{file: string, lang: string, note: string, code: string}>
     */
    private static function phpArtifacts(): array
    {
        return array_filter(
            FrameworkReference::artifacts(),
            static fn (array $artifact): bool => $artifact['lang'] === 'php',
        );
    }

    /**
     * The `Lava\Core\…` names a snippet mentions, as written — `::class` suffixes,
     * `use` lines, and type declarations alike.
     *
     * @return list<string>
     */
    private static function coreNames(string $code): array
    {
        preg_match_all('/Lava\\\\Core\\\\[A-Za-z0-9_\\\\]+/', $code, $matches);

        return array_values(array_unique($matches[0]));
    }
}
