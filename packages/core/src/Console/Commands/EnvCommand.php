<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Config\EnvAudit;
use Lava\Core\Config\ProcessEnv;
use Lava\Core\Config\Secrets;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;
use Lava\Core\Problem\ProblemReport;

/**
 * `lava env` — what environment variables this app reads, whether each has a
 * value, and where that value came from.
 *
 * The list itself comes from {@see App::envVars()}, which unions the app's
 * declarations, its packs', and the bare config/.env entries nothing claimed.
 * This command adds the two things only it can know: the live value, and which
 * layer supplied it.
 *
 * A required-but-unset var is a Warn, not a Fatal — this is a report, and
 * `lava check --strict` is where it becomes a build failure.
 */
final class EnvCommand extends AppCommand
{
    public function name(): string
    {
        return 'env';
    }

    public function summary(): string
    {
        return 'Show declared environment variables and where their values come from.';
    }

    public function flags(): array
    {
        return ['json', 'reveal', 'env'];
    }

    public function usage(): string
    {
        return 'lava env [--reveal] [--env=<name>] [--json]';
    }

    public function emptyPayload(Args $args): array
    {
        return ['env' => [], 'resolved_env' => null, 'env_file' => null];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $reveal = $args->bool('reveal');
        $override = $args->value('env');
        $report = new ProblemReport();
        $records = [];
        $rows = [];

        foreach ($app->envVars() as $entry) {
            $name = $entry['name'];
            $var = $entry['var'];
            $required = $var !== null && $var->required;
            $real = ProcessEnv::real($name);
            // Boot promoted .env values into the real environment, so `real`
            // alone cannot tell the two apart. envFromFile is boot's own record
            // of which names actually took their value from the file; a name
            // the shell already defined is absent from it and reads as 'env'.
            $promoted = $app->envFromFile[$name] ?? null;

            $source = match (true) {
                // `--env` overrides LAVA_ENV for the boot only, so by the time
                // this runs the process env is back to normal and the value
                // that decided the boot is nowhere to be found. Saying 'unset'
                // beside a value would be nonsense; the flag IS the source.
                $override !== null && $name === 'LAVA_ENV' => 'flag',
                $promoted !== null => 'dotenv',
                $real !== null => 'env',
                default => null,
            };
            $value = $override !== null && $name === 'LAVA_ENV'
                ? $override
                : ($real ?? $app->dotEnv[$name] ?? null);
            // A declaration is authoritative; the name heuristic only covers
            // vars nothing claimed (a bare .env entry). Shared with `describe`,
            // which must not disagree about what it may print.
            $secret = Secrets::isSecret($var, $name);
            $description = $var !== null ? $var->description : '';

            $records[] = [
                'name' => $name,
                'required' => $required,
                'secret' => $secret,
                'description' => $description,
                'declared_by' => $entry['by'],
                'set' => $value !== null,
                'source' => $source,
                // `--reveal` is a property of the invocation, not of the view:
                // it must open the envelope too, or `--json --reveal` would
                // silently disagree with `--reveal`.
                'value' => $value !== null && (!$secret || $reveal) ? $value : null,
            ];
            $rows[] = [
                $name,
                $required ? 'yes' : 'no',
                $source ?? 'unset',
                $value === null ? '-' : ($secret && !$reveal ? Secrets::redacted() : $value),
                $description,
            ];
        }

        // The unset-required rule lives in one place, shared with `lava check`:
        // a report that calls a variable missing while a build of the same app
        // passes would be the framework disagreeing with itself.
        foreach (EnvAudit::missing($app) as $problem) {
            $report->add($problem);
        }

        $io->data('env', $records);
        $io->data('resolved_env', $app->env);
        $io->data('env_file', is_file($app->appDir . '/config/.env') ? 'config/.env' : null);

        $io->text((new Table(['name', 'req', 'source', 'value', 'description'], $rows))->render());
        $io->text(sprintf("resolved environment: %s\n", $app->env));

        // Boot's own warnings travel with the report — a broken .env line is
        // boot's finding, and this command is where the user comes to see it.
        $report->merge($app->problems);

        return $io->emit($this->name(), $report);
    }
}
