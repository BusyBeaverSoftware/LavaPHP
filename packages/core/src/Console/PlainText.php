<?php

declare(strict_types=1);

namespace Lava\Core\Console;

/**
 * A value as it is safe to print to a terminal.
 *
 * `lava` reads its values out of the app's own files — `config/.env`, a config
 * array, a route name, a problem's context — and prints them in aligned tables
 * that an agent reads as ground truth. That makes the text view a trust
 * boundary, and control characters are how it is crossed: a value carrying
 * `\x1b[2K\r` erases its own row and prints whatever the file's author chose in
 * its place, and one carrying a newline forges a row without needing an escape
 * at all. A cloned repository could make `lava check` say it found nothing.
 *
 * So every control character is replaced, once, where the text views pass
 * through. The column widths are measured on the replaced text too, which is
 * why the tables stop misaligning at the same time.
 *
 * `--json` does not come through here: `json_encode` escapes these characters
 * itself, and the machine contract should carry the bytes that are really in
 * the file.
 *
 * @internal terminal rendering
 */
final class PlainText
{
    public static function of(string $text): string
    {
        $safe = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '?', $text);

        // Text that is not valid UTF-8 makes the unicode pattern bail and
        // return null; the byte pattern still takes out everything that could
        // move a cursor, which is the part that matters.
        return $safe ?? (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $text);
    }
}
