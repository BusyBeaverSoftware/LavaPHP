<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Config\Config;
use Lava\Core\Config\DotEnv;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\InvalidEnvFile;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testKeysAreFilePrefixedAndCarryProvenance(): void
    {
        $config = (new Config())
            ->with('app.base_url', 'http://x', 'config/app.php')
            ->with('logging.level', 'debug', 'config/logging.php');

        self::assertTrue($config->has('app.base_url'));
        self::assertSame(['app.base_url', 'logging.level'], $config->keys());
        self::assertSame('config/app.php', $config->provenance('app.base_url'));
        self::assertSame('http://x', $config->needString('app.base_url'));
    }

    public function testRequiredAccessorNamesTheConfigFileToCreate(): void
    {
        $config = new Config();

        try {
            $config->needString('app.base_url');
            self::fail('InvalidConfig expected');
        } catch (InvalidConfig $problem) {
            self::assertSame('invalid_config', $problem->code());
            self::assertStringContainsString("Set 'base_url' in config/app.php", $problem->fix);
        }
    }

    public function testEveryAccessorAMissingKeyFixNamesExists(): void
    {
        // The label reads `integer`; the method is `int()`. Deriving one from the
        // other once told the reader to call `$config->integer()` (Lava Notes, B12).
        $config = new Config();
        $calls = [
            static fn () => $config->needString('app.a'),
            static fn () => $config->needInt('app.b'),
            static fn () => $config->needBool('app.c'),
            static fn () => $config->needArray('app.d'),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('InvalidConfig expected');
            } catch (InvalidConfig $problem) {
                self::assertSame(1, preg_match('/\$config->([a-zA-Z]+)\(/', $problem->fix, $match), $problem->fix);
                self::assertTrue(method_exists(Config::class, $match[1]), "the fix names \$config->{$match[1]}(), which does not exist");
            }
        }
    }

    public function testAKeyFromAFileNothingReadsSaysSo(): void
    {
        // `config/cache.php` is not read by core, so "set it there" alone sends the
        // reader to create a file that changes nothing (Lava Notes, B1).
        $config = (new Config())->with('app.name', 'Blog', 'config/app.php');

        try {
            $config->needInt('cache.ttl');
            self::fail('InvalidConfig expected');
        } catch (InvalidConfig $problem) {
            self::assertStringContainsString('No key came from config/cache.php', $problem->fix);
            self::assertStringContainsString('core reads config/app.php and config/logging.php', $problem->fix);
        }

        try {
            $config->needInt('app.port');
            self::fail('InvalidConfig expected');
        } catch (InvalidConfig $problem) {
            self::assertStringNotContainsString('No key came from', $problem->fix);
        }
    }

    public function testWrongTypeNamesTheFileThatDeclaredIt(): void
    {
        $config = (new Config())->with('app.debug', 5, 'config/app.php');

        try {
            $config->needBool('app.debug');
            self::fail('InvalidConfig expected');
        } catch (InvalidConfig $problem) {
            self::assertSame('invalid_config', $problem->code());
            self::assertStringContainsString('must be boolean, got int', $problem->getMessage());
            self::assertStringContainsString("Fix the value of 'debug' in config/app.php", $problem->fix);
        }
    }

    public function testWrongTypeWithoutProvenanceStillNamesTheConfigFile(): void
    {
        // A Config built from values alone (no provenance) must still render a
        // well-formed file name — the splitKey fallback, never "config/.php".
        $config = new Config(['app.debug' => 5]);

        try {
            $config->needBool('app.debug');
            self::fail('InvalidConfig expected');
        } catch (InvalidConfig $problem) {
            self::assertStringContainsString('config/app.php', $problem->fix);
            self::assertStringNotContainsString('config/.php', $problem->fix);
        }
    }

    public function testOptionalAccessorsDefaultButStillTypeCheck(): void
    {
        $config = (new Config())->with('app.debug', true, 'config/app.php');

        self::assertSame('fallback', $config->string('app.missing', 'fallback'));
        self::assertTrue($config->bool('app.debug', false));

        $this->expectException(InvalidConfig::class);
        $config->string('app.debug', 'x'); // true is not a string — still a problem
    }

    public function testWithRejectsDuplicateKeys(): void
    {
        $config = (new Config())->with('app.a', 1, 'config/app.php');

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('set by two files');
        $config->with('app.a', 2, 'config/other.php');
    }

    public function testDotEnvParsesEverythingAndCollectsBadLines(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lava-env-');
        file_put_contents($tmp, "# comment\n\nPLAIN=1\nQUOTED=\"two words\"\nSINGLE='keep'\nbad line\n1BAD=x\n");
        $report = new ProblemReport();
        $values = DotEnv::load($tmp, $report);
        unlink($tmp);

        self::assertSame(['PLAIN' => '1', 'QUOTED' => 'two words', 'SINGLE' => 'keep'], $values);
        self::assertCount(2, $report->problems());

        [$first, $second] = $report->problems();
        self::assertInstanceOf(InvalidEnvFile::class, $first);
        self::assertInstanceOf(InvalidEnvFile::class, $second);
        self::assertSame(6, $first->source->line); // 'bad line' — no equals sign
        self::assertSame(7, $second->source->line); // '1BAD' — keys start A-Z or _
    }

    public function testAMalformedLineReportsItsKeyAndNeverItsValue(): void
    {
        // A security review found this reaching every command's report, the
        // diagnostics page and any CI log running `lava check --strict`: the
        // two shapes that fail this parser most often both carry a credential.
        $tmp = tempnam(sys_get_temp_dir(), 'lava-env-');
        file_put_contents($tmp, implode("\n", [
            'export DB_PASSWORD=hunter2-LEAKED',        // `export` puts a space in the key
            'stripe_secret_key=sk_live_LEAKED',         // lowercase keys are refused too
            'sk_live_PASTED_ON_ITS_OWN_LINE_LEAKED',    // no `=`: the line IS a value
            str_repeat('qwerty', 10) . '=LEAKED',       // base64 padding makes the key half a secret
        ]) . "\n");
        $report = new ProblemReport();
        DotEnv::load($tmp, $report);
        unlink($tmp);

        $contents = array_map(
            static fn (LavaProblem $problem): string => (string) ($problem->context['content'] ?? ''),
            $report->problems(),
        );
        self::assertSame(
            ['export DB_PASSWORD=<redacted>', 'stripe_secret_key=<redacted>', '<redacted>', '<redacted>'],
            $contents,
        );

        foreach ($report->problems() as $problem) {
            $printed = $problem->getMessage() . $problem->fix . json_encode($problem->context);
            self::assertStringNotContainsString('LEAKED', $printed, 'a value escaped into the report');
        }
    }
}