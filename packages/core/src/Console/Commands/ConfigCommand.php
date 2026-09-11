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

    protected function emptyPayload(Args $args): array
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
            $value = $secret ? Secrets::redacted() : self::render($entry['value']);

            $records[] = [
                'key' => $entry['key'],
                'value' => $secret ? null : $entry['value'],
                'from_file' => $entry['from_file'],
                'secret' => $secret,
            ];
            $rows[] = [$entry['key'], $value, $entry['from_file'] ?? '-'];
        }

        $io->data('config', $records);
        $io->text((new Table(['key', 'value', 'from'], $rows))->render());

        return $io->emit($this->name(), $app->problems);
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
