<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Problem\BadTestReport;

/**
 * JUnit XML → {@see TestRun}. The structured-output substrate the plan chose
 * PHPUnit for: `--log-junit` gives a stable, documented format, so `lava test`
 * and `lava check` read results instead of scraping a human summary line that
 * PHPUnit is free to reword.
 *
 * DOM rather than SimpleXML because the walk is over a nested tree of
 * `testsuite` elements (PHPUnit nests one per file inside one per configured
 * suite) and because DOM reports malformed XML as a value instead of a
 * warning — a truncated report is a diagnosis we want to report, not noise in
 * the agent's stdout.
 */
final class Junit
{
    /** @throws BadTestReport when the XML is not well-formed */
    public static function parse(string $xml): TestRun
    {
        $document = self::document($xml);
        $xpath = new \DOMXPath($document);

        $suites = self::suiteNames($xpath);

        $cases = [];
        $tests = 0;
        $failures = 0;
        $errors = 0;
        $skipped = 0;
        $assertions = 0;
        $time = 0.0;

        foreach (self::nodes($xpath, '//testcase') as $node) {
            $tests++;
            $assertions += self::int($node, 'assertions');
            $time += self::float($node, 'time');

            $outcome = self::outcome($node);
            if ($outcome === null) {
                continue; // passed — not reported, see TestRun
            }

            [$status, $message, $type] = $outcome;
            match ($status) {
                'failed' => $failures++,
                'error' => $errors++,
                default => $skipped++,
            };

            $cases[] = [
                'name' => $node->getAttribute('name'),
                'class' => $node->getAttribute('class'),
                'file' => $node->getAttribute('file'),
                'line' => self::int($node, 'line'),
                'status' => $status,
                'type' => $type,
                'message' => $message,
            ];
        }

        return new TestRun(
            // One configured suite is the norm; with several there is no single
            // answer, and `suites` carries them all rather than picking one.
            $suites === [] ? null : (count($suites) === 1 ? $suites[0] : null),
            $suites,
            $tests,
            $failures,
            $errors,
            $skipped,
            $assertions,
            round($time, 3),
            $cases,
        );
    }

    /** @throws BadTestReport */
    private static function document(string $xml): \DOMDocument
    {
        $internal = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($internal);

        if (!$loaded) {
            $first = $errors[0] ?? null;
            throw BadTestReport::malformed(
                $first === null
                    ? 'no parser detail available'
                    : trim($first->message) . " (line {$first->line})",
            );
        }

        return $document;
    }

    /**
     * The names of the suites the app CONFIGURED.
     *
     * `--log-junit` wraps them all in one synthetic `<testsuite>` named after
     * the configuration file, so reading the top level would report
     * `/path/phpunit.xml.dist` as the suite name — useless to a reader and
     * different on every machine. The configured names are the children; the
     * top level is only the answer when there are none (a report from another
     * producer, or one with no configured suites to name).
     *
     * Both root shapes are matched because both are valid JUnit: `<testsuites>`
     * wrapping one or more `<testsuite>`, and a bare `<testsuite>` root, which
     * other producers emit for a single-suite report.
     *
     * @return list<string>
     */
    private static function suiteNames(\DOMXPath $xpath): array
    {
        $roots = self::nodes($xpath, '/testsuites/testsuite | /testsuite');

        $suites = [];
        foreach ($roots as $root) {
            foreach (self::nodes($xpath, 'testsuite', $root) as $child) {
                $suites[] = $child->getAttribute('name');
            }
        }
        if ($suites !== []) {
            return $suites;
        }

        foreach ($roots as $root) {
            $suites[] = $root->getAttribute('name');
        }
        return $suites;
    }

    /**
     * @return list<\DOMElement>
     */
    private static function nodes(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): array
    {
        $list = $xpath->query($expression, $context);
        if ($list === false) {
            return []; // unreachable: the expressions are literals, never invalid
        }

        $nodes = [];
        foreach ($list as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    /**
     * The first failure/error/skipped child, or null when the case passed.
     * A case can carry only one of the three — PHPUnit stops at the first.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function outcome(\DOMElement $case): ?array
    {
        foreach (['failure' => 'failed', 'error' => 'error', 'skipped' => 'skipped'] as $tag => $status) {
            $node = $case->getElementsByTagName($tag)->item(0);
            if ($node instanceof \DOMElement) {
                return [$status, trim($node->textContent), $node->getAttribute('type')];
            }
        }
        return null;
    }

    private static function int(\DOMElement $node, string $attribute): int
    {
        $raw = $node->getAttribute($attribute);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    private static function float(\DOMElement $node, string $attribute): float
    {
        $raw = $node->getAttribute($attribute);
        return is_numeric($raw) ? (float) $raw : 0.0;
    }
}
