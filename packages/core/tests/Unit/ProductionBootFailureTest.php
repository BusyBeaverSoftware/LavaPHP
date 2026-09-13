<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a client sees of a boot failure in production.
 *
 * Found by an outside build (Lava Notes, R2-B3): in `prod` the response
 * withheld a boot failure's context and source, but `unexpected_failure` had
 * already written the exception's message and its absolute `file:line` into the
 * sentence and the fix, which production keeps. A factory that failed to reach
 * `redis://:hunter2@…` sent the password and the server's layout to every
 * visitor. The apps are written to a temporary directory so the throw can carry
 * a credential, the way a real driver's message does.
 */
final class ProductionBootFailureTest extends TestCase
{
    private const SECRET = 'redis://:hunter2@cache.internal:6379 connection refused';

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            self::remove($this->dir);
        }
    }

    /** @return iterable<string, array{string, string}> the file that throws, and its source */
    public static function failingFiles(): iterable
    {
        yield 'a service factory the wiring sweep resolves' => [
            'app/Services.php',
            "<?php\n\nreturn function (\\Lava\\Core\\Container\\Container \$c): void {\n"
                . "    \$c->singleton('cache', static fn () => throw new \\RuntimeException('" . self::SECRET . "'));\n"
                . "};\n",
        ];
        yield 'a config file' => [
            'config/app.php',
            "<?php\n\nthrow new \\RuntimeException('" . self::SECRET . "');\n",
        ];
    }

    #[DataProvider('failingFiles')]
    public function testInProductionTheResponseCarriesNeitherTheMessageNorAPath(string $file, string $source): void
    {
        $failure = $this->bootWith($file, $source, 'prod');

        foreach (['application/json', 'text/html'] as $accept) {
            $body = self::body($failure, $accept);

            self::assertStringNotContainsString('hunter2', $body, $accept);
            self::assertStringNotContainsString($this->dir, $body, $accept);
        }
    }

    #[DataProvider('failingFiles')]
    public function testTheReportKeepsBothForTheCli(string $file, string $source): void
    {
        // Withheld from the client, not lost: `lava check` and a front
        // controller's error_log() print the report's text, context included.
        $text = $this->bootWith($file, $source, 'prod')->text();

        self::assertStringContainsString('hunter2', $text);
        self::assertStringContainsString($this->dir . '/' . $file, $text);
    }

    #[DataProvider('failingFiles')]
    public function testOutsideProductionTheResponseIsStillTheWholeDiagnosis(string $file, string $source): void
    {
        $body = self::body($this->bootWith($file, $source, 'dev'), 'application/json');

        self::assertStringContainsString('hunter2', $body);
        self::assertStringContainsString($this->dir . '/' . $file, $body);
    }

    private function bootWith(string $file, string $source, string $env): BootFailure
    {
        $this->dir = sys_get_temp_dir() . '/lava-prod-boot-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir(dirname($this->dir . '/' . $file), 0o777, true));
        self::assertNotFalse(file_put_contents($this->dir . '/' . $file, $source));

        $failure = TestApp::boot($this->dir, ['LAVA_ENV' => $env]);

        self::assertInstanceOf(BootFailure::class, $failure);
        self::assertSame($env, $failure->env);

        return $failure;
    }

    private static function body(BootFailure $failure, string $accept): string
    {
        return (string) $failure->toResponse((new ServerRequest('GET', '/'))->withHeader('Accept', $accept))->getBody();
    }

    private static function remove(string $dir): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
