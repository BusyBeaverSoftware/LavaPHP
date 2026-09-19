<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Map\MapDocument;
use Lava\Core\Map\ProjectMap;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\StaleMap;

/**
 * `lava map` — write AGENTS.md from the app's own registries.
 *
 * This is pillar 3 made real: the document an agent reads to work in a codebase
 * is generated from the code, so it cannot disagree with `lava routes` or
 * `lava features`. There is no second copy of these facts to keep in sync.
 *
 * `--check` is the other half, and it is the half that matters in CI: it writes
 * nothing and reports whether the committed AGENTS.md still describes the app,
 * as a `stale_map` problem with the usual exit code. A file that was never
 * generated, and a file generated from an older app, are the same verdict with
 * different `context.why` — the fix is `lava map` either way.
 *
 * Note what `--check` does NOT need: git, a timestamp, or a diff. The document
 * carries a hash of the app's declarations (see {@see ProjectMap::fingerprint()}),
 * so freshness is a comparison of two strings, and it is the same answer on any
 * machine, at any time, in any environment.
 */
final class MapCommand extends AppCommand
{
    public function name(): string
    {
        return 'map';
    }

    public function summary(): string
    {
        return 'Write AGENTS.md from the app; --check verifies it is still current.';
    }

    public function flags(): array
    {
        return ['json', 'check', 'env'];
    }

    public function usage(): string
    {
        return 'lava map [--check] [--env=<name>] [--json]';
    }

    /**
     * `path` and `fingerprint` are null because they are facts about the APP,
     * and this seed is written before the app is read. That is not a placeholder
     * waiting to be filled — it is the honest value for an invocation that never
     * built the registries the hash comes from, and it is the value a refused
     * flag, `--help`, and a failed boot all leave in place.
     *
     * `lava.map/1` called both of them strings, which meant every one of those
     * payloads violated the contract it named — an agent parsing `path` would
     * have found the schema lied about what it promised on the one exit path it
     * could not check. `/2` admits null instead (see docs/schemas/lava.map/),
     * which is why this method needs no special case for the failure paths:
     * nothing was read, so nothing is claimed.
     */
    public function emptyPayload(Args $args): array
    {
        return [
            'path' => null,
            'fingerprint' => null,
            'found' => null,
            'fresh' => false,
            'written' => false,
            'counts' => self::zeroCounts(),
        ];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        // The document lists what the app DECLARES, so it is compiled from a boot
        // with every installed pack's gate on: a pack this environment switches
        // off still declares its services, commands and routes, and a map that
        // dropped them read stale on the next machine (Lava Notes, R3-B11).
        $mapped = AppBoot::forMap($app, $args->value('env'));
        if ($mapped instanceof BootFailure) {
            // The boot that would write the map is the answer here. Reporting
            // `stale_map` instead would send the reader to run `lava map`, which
            // could only fail the same way.
            return $io->emit($this->name(), $mapped->problems, failed: true);
        }

        $document = MapDocument::at($mapped->appDir);
        $map = ProjectMap::of($mapped);
        $fingerprint = $map->fingerprint();
        $staleness = $map->staleness($document);

        $io->data('path', $document->path);
        $io->data('fingerprint', $fingerprint);
        $io->data('found', $document->hash());
        $io->data('fresh', $staleness === null);
        $io->data('counts', $map->counts());

        if ($args->bool('check')) {
            // `written` stays false — the payload key is seeded before the boot,
            // and a check that wrote something would be a different command.
            $io->line($staleness === null
                ? "{$document->path} is current."
                : "{$document->path} is not current.");
            $io->line(self::countsLine($map->counts()));

            // `failed: true` is what makes this a CHECK rather than a report.
            // `stale_map` is a warning, so the ordinary rule would exit 0 — and
            // a verification command that answers "no" with a zero exit code is
            // useless to a build. `lava check` is where the same finding is a
            // warning that only `--strict` escalates; here it is the answer.
            return $staleness === null
                ? $io->emit($this->name())
                : $io->emit($this->name(), self::report($staleness), failed: true);
        }

        if (!$document->write($map)) {
            // Also a failure, and for the same reason: the command's one job did
            // not happen. Exit 0 here would tell a caller its AGENTS.md is
            // written when it is not.
            return $io->emit($this->name(), self::report(
                StaleMap::unwritable($document->path, dirname($document->path)),
            ), failed: true);
        }

        $io->data('written', true);
        $io->line("Wrote {$document->path}");
        $io->line(self::countsLine($map->counts()));

        return $io->emit($this->name());
    }

    private static function report(LavaProblem $problem): ProblemReport
    {
        $report = new ProblemReport();
        $report->add($problem);

        return $report;
    }

    /** @param array<string, int> $counts */
    private static function countsLine(array $counts): string
    {
        return 'routes: ' . $counts['routes']
            . '  services: ' . $counts['services']
            . '  features: ' . $counts['features']
            . '  commands: ' . $counts['commands']
            . '  middleware: ' . $counts['middleware']
            . '  packs: ' . $counts['modules']
            . '  env: ' . $counts['env'];
    }

    /** @return array<string, int> */
    private static function zeroCounts(): array
    {
        return [
            'routes' => 0,
            'services' => 0,
            'features' => 0,
            'commands' => 0,
            'middleware' => 0,
            'modules' => 0,
            'env' => 0,
        ];
    }
}
