<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * The app has no test runner to run.
 *
 * `lava test` and `lava check` shell out to the app's OWN PHPUnit — the one
 * its composer.json installed — rather than to whatever the framework happens
 * to have. A missing runner therefore means the app was never installed, which
 * is a setup problem with a setup fix, not a framework problem.
 *
 * `LAVA_PHPUNIT` is the escape hatch (the same idiom as `DB_TEST_DSN`): a CI
 * image or a harness that keeps PHPUnit somewhere unusual can point at it
 * explicitly instead of the framework guessing.
 */
final class MissingTestRunner extends LavaProblem
{
    public static function in(string $appDir): self
    {
        return new self(
            "No test runner found for '{$appDir}': there is no vendor/bin/phpunit.",
            'Run: composer install --dev — or set LAVA_PHPUNIT to the phpunit binary to use.',
            ['app_dir' => $appDir, 'looked_for' => 'vendor/bin/phpunit', 'env_override' => 'LAVA_PHPUNIT'],
        );
    }

    public function code(): string
    {
        return 'missing_test_runner';
    }
}
