<?php

declare(strict_types=1);

namespace Lava\View\Tests\Support;

/**
 * A throwaway template directory, written for one test and deleted after it.
 *
 * The pack's unit tests are about a template being wrong — it does not compile,
 * it names a variable nobody passed, it asks for a route that does not exist.
 * Those are properties of template TEXT, so each test writes the exact text it
 * is about, in a real directory the real loader reads, rather than sharing a
 * fixture file whose content would then have to stay in step with every test
 * that renders it. A shared fixture would also make the tests order-dependent:
 * the first one to edit it would break the rest.
 *
 * Under `sys_get_temp_dir()`, never under the package: a test that dies between
 * the write and the delete leaves a directory outside the repository instead of
 * a stray file that `git status` reports and someone commits.
 */
final class Templates
{
    private function __construct(private readonly string $dir)
    {
    }

    /**
     * Writes the given files and returns the directory holding them.
     *
     * @param array<string, string> $files name relative to the directory => contents
     */
    public static function make(array $files): self
    {
        $dir = sys_get_temp_dir() . '/lava-view-' . bin2hex(random_bytes(6));

        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0o777, true);
            }
            file_put_contents($path, $contents);
        }

        return new self($dir);
    }

    /** An empty directory — the "nothing has been written yet" case. */
    public static function empty(): self
    {
        $dir = sys_get_temp_dir() . '/lava-view-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);

        return new self($dir);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function remove(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->dir);
    }
}
