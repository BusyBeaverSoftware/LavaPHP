<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Core\Features\Features;
use Lava\Core\Features\FlagSubject;
use Lava\Core\Problem\BadUsage;

/**
 * `lava features` — the flag table with each flag's decided-by layer.
 * `lava features resolve <flag>` — the full layer-by-layer trace for one flag,
 * optionally for a `--subject` (rollout/users need one; anonymous resolves
 * audience flags OFF, and the trace shows that as the deciding layer).
 */
final class FeaturesCommand extends AppCommand
{
    public function name(): string
    {
        return 'features';
    }

    public function summary(): string
    {
        return 'List feature flags, or resolve one with its full layer trace.';
    }

    public function flags(): array
    {
        return ['json', 'subject', 'env'];
    }

    public function usage(): string
    {
        return 'lava features [resolve <flag>] [--subject=<id>] [--env=<name>] [--json]';
    }

    protected function usageProblem(Args $args): ?BadUsage
    {
        if ($args->arg(0) === 'resolve' && $args->arg(1) === null) {
            return BadUsage::missing('flag', $this->usage());
        }
        return null;
    }

    protected function emptyPayload(Args $args): array
    {
        // `resolve` reports one flag; the list reports many. An agent parsing
        // the failed-boot envelope still gets the keys its subcommand promises.
        return $args->arg(0) === 'resolve'
            ? ['flag' => null, 'subject' => null]
            : ['features' => []];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $subject = $args->value('subject');
        // Bind explicitly either way: with no --subject the CLI is anonymous,
        // and anonymous is a policy (audience flags off), not an accident.
        $features = $app->features->forSubject($subject !== null ? new FlagSubject($subject) : null);

        if ($args->arg(0) === 'resolve') {
            return $this->resolve($io, $args, $features);
        }

        return $this->table($io, $features);
    }

    private function table(IO $io, Features $features): int
    {
        $records = [];
        $rows = [];

        foreach ($features->definitions->all() as $feature) {
            $resolution = $features->resolve($feature->name);
            $records[] = [
                'name' => $feature->name,
                'pack' => $feature->pack,
                'description' => $feature->description,
                'default' => $feature->default->setting(),
                'enabled' => $resolution->enabled,
                'decided_by' => $resolution->source->value,
                'setting' => $resolution->setting,
            ];
            $rows[] = [
                $feature->name,
                $resolution->enabled ? 'on' : 'off',
                $resolution->source->value,
                $resolution->setting,
                $feature->pack ?? '-',
            ];
        }

        $io->data('features', $records);
        $io->text((new Table(['flag', 'state', 'decided by', 'setting', 'pack'], $rows))->render());

        return $io->emit($this->name());
    }

    private function resolve(IO $io, Args $args, Features $features): int
    {
        // AppCommand::usageProblem() already rejected the missing-flag case.
        $name = (string) $args->arg(1);

        // An unknown flag throws UnknownFeature; Console's dispatch catch turns
        // it into the standard problem report, nearest-name hint included.
        $resolution = $features->resolve($name);

        $io->data('flag', $resolution->json());
        $io->data('subject', $features->subject?->id);

        $rows = array_map(
            static fn (array $layer): array => [$layer['layer'], $layer['setting']],
            $resolution->trace,
        );
        $io->text((new Table(['layer', 'setting'], $rows))->render());
        $io->text(sprintf(
            "%s: %s (decided by %s)\n",
            $resolution->name,
            $resolution->enabled ? 'on' : 'off',
            $resolution->source->value,
        ));

        return $io->emit($this->name());
    }
}
