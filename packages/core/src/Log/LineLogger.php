<?php

declare(strict_types=1);

namespace Lava\Core\Log;

use Psr\Log\AbstractLogger;

/**
 * The built-in PSR-3 logger: one line per entry to stderr (or a given
 * stream), timestamped, with context JSON-appended when present. Deliberately
 * minimal.
 *
 * It is the app's `LoggerInterface` by default, not by force. Core registers
 * `LineLogger` itself, and aliases `LoggerInterface` to it only when neither a
 * pack nor app/Services.php registered that id first ({@see
 * \Lava\Core\Boot\Steps\RegisterDefaultServices}). So an app that wants Monolog
 * registers `LoggerInterface` in app/Services.php, and every service that
 * type-hints the interface receives Monolog — including the log entry `App`
 * writes for a production 500.
 */
final class LineLogger extends AbstractLogger
{
    /** Level name => weight. Public so boot can validate config/logging.php's 'level' eagerly. */
    public const LEVELS = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3,
        'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7,
    ];

    /** @var resource */
    private $stream;

    public function __construct(
        private readonly string $minimumLevel = 'debug',
        mixed $stream = null,
    ) {
        if (!isset(self::LEVELS[$this->minimumLevel])) {
            throw new \InvalidArgumentException(
                "Unknown log level '{$this->minimumLevel}'. Use one of: " . implode(', ', array_keys(self::LEVELS)) . '.',
            );
        }
        if ($stream === null) {
            $stream = fopen('php://stderr', 'w');
        }
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('LineLogger needs an open stream resource.');
        }
        $this->stream = $stream;
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException(
                'Unknown log level ' . (is_string($level) ? "'{$level}'" : get_debug_type($level))
                . '. Use one of: ' . implode(', ', array_keys(self::LEVELS)) . '.',
            );
        }
        if (self::LEVELS[$level] < self::LEVELS[$this->minimumLevel]) {
            return;
        }
        // One record, one line: a message carrying a newline — a route param
        // echoed by an app's problem, say — would otherwise forge a second
        // record that reads as the framework's own (security review, F9).
        $line = sprintf('[%s] %s: %s', date('c'), $level, self::oneLine((string) $message));
        if ($context !== []) {
            if (($context['exception'] ?? null) instanceof \Throwable) {
                $context['exception'] = self::exception($context['exception']);
            }
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }
        fwrite($this->stream, $line . "\n");
    }

    /**
     * PSR-3's `exception` key, as data. Encoded as it is, an exception object
     * prints as `{}`; as an array it keeps its trace and the entry stays one line.
     *
     * @return array<string, mixed>
     */
    /** A message as one line: CR, LF and NUL escaped, everything else untouched. */
    private static function oneLine(string $message): string
    {
        return str_replace(["\r", "\n", "\0"], ['\\r', '\\n', '\\0'], $message);
    }

    private static function exception(\Throwable $exception): array
    {
        $shape = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'at' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => array_map(
                static fn (array $frame): string => isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : '[internal]',
                $exception->getTrace(),
            ),
        ];
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $shape['previous'] = self::exception($previous);
        }

        return $shape;
    }
}