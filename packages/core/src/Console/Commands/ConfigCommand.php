<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Config\Secrets;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava config` — every key, its value, and the file that set it. Provenance
 * is the point: "which file wins" is answered by the table rather than by
 * re-reading the loader, because the loader already recorded it.
 *
 * Values whose NAME looks secret are redacted unless `--reveal`. Config files
 * are code and rarely hold secrets, so this is a safety net for the apps that
 * do — and it is symmetric with `lava env`, which redacts for the same reason.
 *
 * The name that matters is the one beside the value, at any depth. A config bag
 * is flattened one level, so `'connections' => ['primary' => ['password' => …]]`
 * arrives as one entry named `app.connections` — a name that looks like nothing
 * — carrying a credential three levels down. Matching only the top key left
 * that in the clear, which is the ordinary shape of a config file.
 */
final class ConfigCommand extends AppCommand
{
    public function name(): string
    {
        return 'config';
    }

    public function summary(): string
    {
        return 'Show every config key with the file that set it.';
    }

    public function flags(): array
    {
        return ['json', 'reveal', 'env'];
    }

    public function usage(): string
    {
        return 'lava config [--reveal] [--env=<name>] [--json]';
    }

    public function emptyPayload(Args $args): array
    {
        return ['config' => []];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $reveal = $args->bool('reveal');
        $records = [];
        $rows = [];

        foreach ($app->config->json() as $entry) {
            $secret = !$reveal && Secrets::looksSecret($entry['key']);
            $value = match (true) {
                $secret => null,
                $reveal => $entry['value'],
                default => self::redactNested($entry['value']),
            };

            $records[] = [
                'key' => $entry['key'],
                'value' => $value,
                'from_file' => $entry['from_file'],
                'secret' => $secret,
            ];
            $rows[] = [
                $entry['key'],
                $secret ? Secrets::redacted() : self::render($value),
                $entry['from_file'] ?? '-',
            ];
        }

        $io->data('config', $records);
        $io->text((new Table(['key', 'value', 'from'], $rows))->render());

        return $io->emit($this->name(), $app->problems);
    }

    /**
     * The value with every secret-looking leaf replaced, at any depth.
     *
     * Keyed by the name beside the value, so `connections.primary.password`
     * goes and `connections.primary.dsn`'s host stays — a report that redacts
     * the whole subtree hides the provenance the command exists to show. A
     * secret-looking key whose value is itself an array is replaced whole,
     * because `'credentials' => [...]` names everything under it.
     */
    private static function redactNested(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $inner) {
            $out[$key] = is_string($key) && Secrets::looksSecret($key)
                ? Secrets::redacted()
                : self::redactNested($inner);
        }

        return $out;
    }

    /**
     * One-line rendering for the text view. Arrays and objects collapse to
     * compact JSON so the table stays greppable — a nested dump would break
     * the column alignment that makes the table worth printing.
     */
    private static function render(mixed $value): string
    {
        if ($value === null) {
            return 'null';
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
