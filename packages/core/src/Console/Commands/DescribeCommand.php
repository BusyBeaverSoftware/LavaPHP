<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Config\ProcessEnv;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Core\Problem\BadUsage;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\UnknownSelector;

/**
 * `lava describe <selector>` — one name, looked up everywhere it could live,
 * answered in full.
 *
 * This is the command that replaces "read five files to find out what
 * `users.show` is". A selector is resolved against routes, services, flags,
 * env vars, and commands in that order — a deliberate, documented precedence,
 * because a name can legitimately be two of those at once and the agent needs
 * a deterministic answer rather than an error.
 *
 * A miss is never silent: the report carries every candidate name by kind, so
 * one call answers "what could I have meant?" without a second.
 */
final class DescribeCommand extends AppCommand
{
    public function name(): string
    {
        return 'describe';
    }

    public function summary(): string
    {
        return 'Explain one route, service, flag, env var, or command by name.';
    }

    public function flags(): array
    {
        return ['json', 'reveal', 'env'];
    }

    public function arguments(): array
    {
        return ['selector'];
    }

    public function usage(): string
    {
        return 'lava describe <selector> [--reveal] [--env=<name>] [--json]';
    }

    protected function usageProblem(Args $args): ?BadUsage
    {
        return $args->arg(0) === null ? BadUsage::missing('selector', $this->usage()) : null;
    }

    public function emptyPayload(Args $args): array
    {
        return ['selector' => $args->arg(0), 'kind' => null, 'match' => null];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        // AppCommand::usageProblem() already rejected the missing-selector case.
        $selector = (string) $args->arg(0);

        $match = $this->match($app, $selector, $args->bool('reveal'));

        if ($match === null) {
            $report = new ProblemReport();
            $report->add(UnknownSelector::of($selector, $this->nearest($app, $selector), [
                'routes' => $app->router->names(),
                'services' => $app->container->ids(),
                'flags' => $app->features->definitions->names(),
                'env' => self::envNames($app),
                'commands' => array_map(
                    static fn (\Lava\Core\Console\Command $c): string => $c->name(),
                    $app->commands()->all(),
                ),
            ]));
            return $io->emit($this->name(), $report);
        }

        [$kind, $record] = $match;
        $io->data('selector', $selector);
        $io->data('kind', $kind);
        $io->data('match', $record);
        $io->text(self::render($kind, $record));

        return $io->emit($this->name(), $app->problems);
    }

    /**
     * The documented precedence. Route first because it is what people paste;
     * command last because `lava list` already answers that question.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function match(App $app, string $selector, bool $reveal): ?array
    {
        $route = $app->router->route($selector);
        if ($route !== null) {
            $plan = $app->router->plan($selector);
            return ['route', $route->json() + $plan->json() + [
                'state' => $route->feature === null || $app->features->on($route->feature) ? 'active' : 'disabled',
            ]];
        }

        if ($app->container->has($selector)) {
            $record = $app->container->describe($selector);
            $trace = $app->container->traces()[$selector] ?? null;
            // The record answers for an alias's target; file and line are where
            // the selector itself was registered, as in `lava services`.
            $at = $app->container->declaredAt($selector);
            return ['service', ['file' => $at->file, 'line' => $at->line] + $record->json() + [
                'alias_of' => $record->id !== $selector ? $record->id : null,
                'dependencies' => $trace?->dependencies() ?? [],
                'dependents' => $trace?->dependents() ?? [],
            ]];
        }

        if ($app->features->definitions->has($selector)) {
            $resolution = $app->features->resolve($selector);
            return ['flag', $resolution->json() + [
                'pack' => $app->features->definitions->get($selector)?->pack,
                'declared_at' => $app->features->definitions->declaredAt($selector),
            ]];
        }

        $env = self::envRecord($app, $selector, $reveal);
        if ($env !== null) {
            return ['env', $env];
        }

        $command = $this->commandRecord($app, $selector);
        return $command !== null ? ['command', $command] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function envRecord(App $app, string $selector, bool $reveal): ?array
    {
        $var = null;
        $declared = false;
        foreach ($app->envVars() as $entry) {
            if ($entry['name'] === $selector) {
                $var = $entry['var'];
                $declared = true;
                break;
            }
        }
        if (!$declared) {
            return null;
        }

        $fromFile = $app->dotEnv[$selector] ?? null;
        $real = ProcessEnv::real($selector);
        $value = $real ?? $fromFile;
        $secret = $var !== null && $var->secret;

        return [
            'name' => $selector,
            'required' => $var !== null && $var->required,
            'secret' => $secret,
            'description' => $var !== null ? $var->description : '',
            'set' => $value !== null,
            // Same distinction `lava env` draws: boot records which names the
            // file actually supplied, so an overridden .env entry reads 'env'.
            'source' => match (true) {
                array_key_exists($selector, $app->envFromFile) => 'dotenv',
                $real !== null => 'env',
                default => null,
            },
            'value' => $value !== null && (!$secret || $reveal) ? $value : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function commandRecord(App $app, string $selector): ?array
    {
        // The app's own registry, not the one this command was built with: a
        // pack that contributes commands is only visible through the former,
        // and `describe db:migrate` has to answer for it.
        $command = $app->commands()->get($selector);
        if ($command === null) {
            return null;
        }
        return [
            'name' => $command->name(),
            'summary' => $command->summary(),
            'pack' => $command->pack(),
            'flags' => $command->flags(),
            'arguments' => $command->arguments(),
            'usage' => $command->usage(),
        ];
    }

    /**
     * The closest name across every namespace — including commands, which
     * resolution checks last but a typo is just as likely to be. `describe
     * rutes` should point at `lava routes`, not shrug.
     */
    private function nearest(App $app, string $selector): ?string
    {
        $candidates = [
            ...$app->router->names(),
            ...$app->container->ids(),
            ...$app->features->definitions->names(),
            ...self::envNames($app),
            ...array_map(
                static fn (\Lava\Core\Console\Command $c): string => $c->name(),
                $app->commands()->all(),
            ),
        ];

        $best = null;
        $distance = 4; // three edits is a different word, not a typo
        foreach ($candidates as $candidate) {
            $candidateDistance = levenshtein($selector, $candidate);
            if ($candidateDistance < $distance) {
                $best = $candidate;
                $distance = $candidateDistance;
            }
        }
        return $best;
    }

    /** @return list<string> */
    private static function envNames(App $app): array
    {
        return array_map(
            static fn (array $entry): string => $entry['name'],
            $app->envVars(),
        );
    }

    /** @param array<string, mixed> $record */
    private static function render(string $kind, array $record): string
    {
        $rows = [];
        foreach ($record as $key => $value) {
            $rows[] = [$key, self::renderValue($value)];
        }
        return "{$kind}\n" . (new Table(['field', 'value'], $rows))->render();
    }

    private static function renderValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return json_encode($value) ?: '?';
    }
}
