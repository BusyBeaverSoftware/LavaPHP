<?php

declare(strict_types=1);

namespace Lava\Core\Map;

/**
 * One pack's answer to "what of this package is an app meant to call?".
 *
 * `lava api` indexes a package by reflecting over its `src/`, and every class
 * it finds is either indexed, promoted because an indexed signature names it,
 * or excluded by a rule declared here — there is no fourth state, and
 * `ApiSurfaceTest` fails the build on one. That is the whole mechanism: a class
 * added in an unlisted namespace goes red rather than quietly missing from the
 * index, which is what lets `lava api` answer "no, the framework has nothing
 * for that" with authority instead of "I did not find it".
 *
 * Scope is decided by PATH rules plus `@internal` on the class, and
 * deliberately not by `final` (139 of core's 157 types are final, so it carries
 * no signal) and not by a hand-kept list of every method (a list of hundreds of
 * entries is the rot this feature exists to prevent).
 *
 * Each pack owns its own surface, beside the code it describes, in the idiom of
 * {@see \Lava\Core\Modules\ProvidesMapSection}: core cannot know which of a
 * pack's directories are its plumbing, and a pack should not have to edit core
 * to say so.
 */
abstract class ApiSurface
{
    /** The pack's short name — `core`, `db` — as the map and the CLI spell it. */
    abstract public function pack(): string;

    /** The Composer package, e.g. `lavaphp/db`, used to read its installed version. */
    abstract public function package(): string;

    /** The PSR-4 prefix the source root maps to, with a trailing separator: `Lava\Db\`. */
    abstract public function namespacePrefix(): string;

    /** The absolute path of the package's `src/`, wherever it is installed. */
    abstract public function sourceRoot(): string;

    /**
     * The directories under `src/` that hold API, each with what lives there —
     * `(root)` for the source root itself.
     *
     * This is the half of the accounting with teeth. Exclusions alone cannot
     * fail: whatever is not excluded is indexed, so a class in a brand-new
     * directory would join the framework's published API without anyone
     * deciding that it should. Declaring the directories makes the new one go
     * red, and the fix is one line either way — name it here, or exclude it.
     *
     * @return array<string, string> directory => what it holds
     */
    abstract public function groups(): array;

    /**
     * Path prefixes under `src/` that are not API, each with the reason.
     *
     * The reason is not decoration: it is what the drift guard prints when a new
     * class lands in one of these directories, and what a reader needs to judge
     * whether the rule still applies. Prefixes are matched against the path
     * relative to {@see sourceRoot()}, so they end in `/` — `Problem/`, not
     * `Problem`.
     *
     * @return array<string, string> path prefix => why it is not part of the API
     */
    abstract public function exclusions(): array;

    /**
     * Classes that are API wherever they sit, each with why — the one override
     * that points the other way from `@internal`.
     *
     * A path rule is the right shape for a directory of plumbing and the wrong
     * shape for a directory that holds plumbing plus one extension point:
     * `Console/Commands/` is `lava list`'s business, except for `AppCommand`,
     * which is the class the generated `AGENTS.md` tells an app to extend. A
     * fourth outside build asked `lava api AppCommand`, was told the framework
     * had nothing, and began writing its own — by the method this index exists
     * to replace. So an exception is declarable, and `ApiSurfaceTest` proves the
     * set is sufficient: every `Lava\` type the framework reference names must be
     * indexed.
     *
     * Checked before the path rules and after `@internal`, so a class cannot be
     * both an extension point and internal without the author saying which.
     *
     * @return array<class-string, string> class => why it is API despite its location
     */
    public function extensionPoints(): array
    {
        return [];
    }

    /**
     * One worked example per entry-point class: the classes an app starts from.
     *
     * Only entry points, because an example is the one hand-written thing here
     * and hand-written things rot: roughly two dozen across the framework can be
     * kept true, and 900 could not. Every example is parsed and checked by
     * `ApiExampleTest` — each `Lava\` name must exist and each method it calls
     * must exist on the class it is called on — so an example naming a renamed
     * method fails the build rather than teaching the rename.
     *
     * @return array<class-string, string> class => a snippet that calls it
     */
    abstract public function examples(): array;

    /**
     * The feature that gates this pack, or null for core, which is never gated.
     *
     * Declared here rather than read from the module's `PackInfo`, because this
     * must answer without booting an app or instantiating a module — and
     * `ApiSurfaceTest` asserts the two agree, so the copy cannot drift.
     */
    public function feature(): ?string
    {
        return null;
    }
}
