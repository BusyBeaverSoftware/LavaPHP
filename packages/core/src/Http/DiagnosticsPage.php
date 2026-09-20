<?php

declare(strict_types=1);

namespace Lava\Core\Http;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;

/**
 * The boot/runtime diagnostics page: a ProblemReport as hand-escaped HTML.
 * The core never depends on a template engine — this file is the reason
 * broken apps still render something readable in a browser.
 *
 * In prod only the problem sentence and the fix are shown; everything else
 * is dev-mode information — the context, and the source, which is an absolute
 * path on the server.
 *
 * @internal the dev error page's rendering
 */
final class DiagnosticsPage
{
    public static function render(ProblemReport $report, string $env): string
    {
        $verbose = $env !== 'prod';
        $count = $report->count();
        $title = self::e('LavaPHP found ' . $count . ' problem' . ($count === 1 ? '' : 's'));

        $cards = '';
        foreach ($report->problems() as $problem) {
            $cards .= self::card($problem, $verbose);
        }

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
body{margin:0;padding:24px 16px;background:#f5f1ec;color:#2b2620;font:15px/1.55 system-ui,sans-serif}
main{max-width:760px;margin:0 auto}
h1{font-size:19px;margin:0 0 20px}
article{background:#fff;border:1px solid #d9d2c7;border-radius:8px;padding:16px 18px;margin:0 0 14px}
code{font:13px/1.4 ui-monospace,monospace;background:#f0ebe3;padding:1px 5px;border-radius:4px}
.fix{margin-top:10px;padding:10px 12px;background:#eef6ec;border:1px solid #cfe3cb;border-radius:6px}
.fix:before{content:"FIX";font-weight:700;font-size:11px;letter-spacing:.08em;color:#2e6d28;margin-right:8px}
dl{margin:10px 0 0;grid-template-columns:auto 1fr;display:grid;gap:2px 14px;font-size:13px}
dt{color:#7a7263;font-weight:600}
dd{margin:0}
</style>
</head>
<body>
<main>
<h1>{$title}</h1>
{$cards}
</main>
</body>
</html>

HTML;
    }

    private static function card(LavaProblem $problem, bool $verbose): string
    {
        $code = self::e($problem->code());
        $severity = self::e($problem->severity()->value);
        $message = self::e($problem->getMessage());
        $fix = self::e($problem->fix);

        $meta = "<code>{$code}</code> · {$severity}";
        if ($verbose && $problem->source !== null) {
            $meta .= ' · <code>' . self::e((string) $problem->source) . '</code>';
        }

        $context = '';
        if ($verbose && $problem->context !== []) {
            $rows = '';
            foreach ($problem->context as $key => $value) {
                $key = self::e((string) $key);
                $value = self::e(is_scalar($value) || $value === null ? (string) $value : (json_encode($value) ?: ''));
                $rows .= "<dt>{$key}</dt><dd>{$value}</dd>";
            }
            $context = "<dl>{$rows}</dl>";
        }

        return <<<HTML
<article>
<div>{$meta}</div>
<p>{$message}</p>
<div class="fix">{$fix}</div>
{$context}
</article>

HTML;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}