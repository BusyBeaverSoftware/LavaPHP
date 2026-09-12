# Releasing LavaPHP

What has to be true before a tag exists, in the order you check it. Every step
names the command that proves it, because a checklist item you cannot run is a
checklist item that gets asserted instead of verified.

Versioning is by git tag alone. No `composer.json` in this repository carries a
`version` field, and no `lava` command reports a framework version — `lava about`
reports the *runtime's* facts (PHP, extensions, PDO drivers, which packs the app
can see) and deliberately not this. A hand-written `version` field would be a
second answer to a question the tag already answers, and the kind that goes stale
the first time a tag is cut from a branch.

## The 0.x policy

0.1.0 is the first release and it is pre-1.0. The promise it makes is narrow and
worth stating exactly, because "pre-1.0" is otherwise read as "no promises":

- **Stable**: the four pillars, the fixed user-authored artifact paths, the
  problem `code` values, and every `--json` envelope schema. These are the
  contracts an agent pins, and they change only with a schema `/N` bump — see
  [conventions.md](conventions.md#the-cli-contract).
- **Not stable**: PHP class and method signatures inside a pack, `DECISIONS.md`'s
  open findings, and anything a pack marks `@internal`. A 0.x minor may change
  these.

## Before the tag

Run these from a clean checkout. The first three are the whole gate; the rest
are the claims the gate cannot make on its own.

A clean checkout needs three installs, and the last two are the ones that are
easy to forget: `apps/*/vendor/` and `packages/*/vendor/` are gitignored, and the
root install fills only the root `vendor/`. Running step 4 or 5 without its own
install fails with `./vendor/bin/lava: No such file or directory` — which is a
missing prerequisite, not a broken release.

```sh
composer install                      # steps 1–3, the monorepo
(cd apps/demo && composer install)    # step 4
(cd packages/app && composer install) # step 5
```

1. **`composer verify`** — the suite, PHPStan at level 8 across every pack's
   `src`, `apps/demo/app`, `apps/demo/tests` and `tools`, and `lava/core` alone
   at level `max`. Expect `[OK] No errors` twice and a green suite.
2. **`composer coverage`** — per-pack line coverage with a floor per pack,
   counted with the `bin/lava` subprocesses included. Expect exit 0 and a
   non-zero "child processes captured" count; a zero there means the instrument
   is blind, not that the code is covered. Needs `pcov` and `pdo_sqlite`.
3. **`composer check:floor`** — every tracked file parses on the oldest PHP
   `composer.json` claims to support (8.3 today). Expect `N file(s) parse on PHP
   8.3`. Needs docker unless the host PHP *is* the floor. This is not a
   restatement of step 1: `verify` runs on whatever PHP you have, and a
   construct 8.4 added parses happily there while being a parse error — which
   is fatal to the whole file, not to one statement — on 8.3.
4. **`lava check --strict` on `apps/demo`** — the canonical app boots, its map is
   current, and its suite is green, with warnings promoted to failures. This is
   the end-to-end proof that the packs still work together in an app that
   actually uses them.
5. **`lava map --check` on `packages/app`** — the skeleton's committed
   `AGENTS.md` is accurate from a fresh install, with no `lava map` run first.
6. **Each pack installs standalone** — `db`, `validate`, `view` and
   `http-client`, each copied out with only `core` beside it, `composer install`
   then `composer validate --strict`. This is the decoupling claim: a pack that
   has grown an undeclared dependency on a sibling fails here and nowhere else.
   The `--strict` is the point of the step rather than a flourish: a pack that
   requires `lava/core: @dev` installs happily and fails `--strict`, and it is
   also the pack that could not be published. The four declare `lava/core:
   ^0.1.0` against a version-pinned `../core` path repository, so the gate can
   be strict without either half being a lie.
7. **CI is green on the tag commit.** The seven jobs are the authority on PHP
   8.3, 8.4 and 8.5, on a machine that is not this one. Push a branch, watch the
   jobs go green, and only then cut the tag — a tag on a commit CI has not seen
   is a tag that may have to move, and a tag that moves is worse than a late
   one.

   The jobs are the authority, and they are also *slower and blunter* than the
   local gate: PHPUnit stops at the first test file that will not compile, so a
   commit with three 8.4-isms needs three pushes to learn about all three.
   That is what step 3 is for — run it before the push, and the first CI run is
   about the things only CI can tell you.

## The tag

```
git tag -a 0.1.0 -m "0.1.0"
```

Push it only when the release is meant to be published — the push is what makes
it a release, and a pushed tag is not reversible in the way a local one is.

**The push does not, by itself, publish anything to Packagist.** Packagist
learns about a tag from a webhook on a package that is *already registered*, so
the order runs the other way from what "the push makes it a release" suggests:
register the package, put the webhook in place, and only then is a tag push
visible to the world. Measured on 2026-09-12, **none of the six packages exists
on Packagist** — `packagist.org/packages/lava/core.json` and the five siblings
all return `404` — so a tag push today creates the tag on GitHub and leaves
`composer require lava/core` failing for everyone. The names are unclaimed, so
registering them is available on demand; until it is done, a green tag push is
evidence about the code and not a distribution event.

Two consequences worth expecting. The push triggers a **full duplicate run of
all seven CI jobs** on the tagged commit, because the workflow triggers on any
push with no `tags:` filter and no job branches on `github.ref`; it is
redundant but harmless. And the tagged commit is whatever the tag points at:
move the tag to the commit whose record should ship, not to a convenient one.

**Registering the packages is not enough either — and the reason is structural.**
Packagist reads `composer.json` **only at the root of a repository**: it serves
GitHub's whole-repo archive rather than a subdirectory of it, so it cannot
publish `packages/db` out of this monorepo, and this repo's root manifest is
`lava/lava`, the monorepo project itself. Publishing any one of the six packages
therefore requires a **split mirror** — a repository containing just that
directory — which is what Symfony and Laravel do and what `splitsh-lite` exists
for. There is no single-repo path to Packagist, which is what makes "ship only
`lava/core` this round" the same machinery as shipping all six rather than a
cheaper alternative to it.

On top of that, five of the six manifests carry a development arrangement that
distribution cannot keep:

- Each of `lava/db`, `lava/validate`, `lava/view`, `lava/http-client` and
  `lava/app` declares `repositories: {"lava/core": {"type": "path", "url":
  "../core"}}`. Measured 2026-09-12: this is **ignored when the package is a
  dependency** — Composer reads `repositories` only from the root package, and a
  consumer installs these as dependencies — so `composer require lava/db` is
  unaffected by it. It is fatal only where the package *is* the root:
  `composer create-project lava/app` (the app skeleton is the root in its own
  `create-project`), and cloning a split mirror and installing there. A missing
  path repository is a hard error, not a silent fallback
  (`PathRepository.php:163`: *"The `url` supplied for the path (../core)
  repository does not exist"*), which is the failure `packages/app/README.md`
  describes.
- Those same five declare `lava/core: ^0.1.0`, resolved during development from
  the path repository's `options.versions` pin. This is the shape a published
  package needs: a real constraint rather than `@dev`, which Composer calls
  unbound and `validate --strict` rejects. The path repository is what the split
  still has to strip; the constraint itself is already correct, because a
  published `lava/core` at `0.1.0` satisfies `^0.1.0` from Packagist exactly as
  the pinned path repository satisfies it here.

`lava/core` is the exception and the easy case: no path repository, no `lava/*`
dependency, only real Packagist requirements — publishable verbatim, and the
right package to prove a split pipeline on. The other five need the path
repository and its `options.versions` pin stripped **in the split**
(`splitsh-lite` is a pure prefix split and rewrites nothing, so this is a
post-processing step); their `require` blocks need no edit. Which set ships, and
how the mirrors are pushed, is a packaging decision rather than a mechanical
one; see DECISIONS.md 228–232.

One maintenance consequence of the tightened constraints: `^0.1.0` now appears
in six manifests, so a release bumps them together or the packs resolve to a
core older than the one they were tested against.

## What is deliberately not part of a release

- **No `composer.lock` is committed** for any pack or app, and the omission is
  load-bearing rather than housekeeping: a lock generated in this monorepo pins
  `lava/core` to a `../core` path repository, which exists only here. Shipping
  one would hand a consumer a lock that cannot resolve. The root lock IS
  committed — it is a project, and its dev tooling is worth pinning.
- **No `CHANGELOG.md`.** The record is `DECISIONS.md` (every judgement call with
  its rationale and the date) plus `git log`, and a changelog maintained by hand
  beside those is a third account of the same events that drifts first and is
  believed second. If consumers ask for one, it should be generated from the
  commit log at tag time rather than written alongside it.

## Known gaps at 0.1.0

Stated here rather than discovered later, because a release checklist that
implies everything was verified is worse than one that lists what was not.

- **CI has been observed green, once, on the tag commit.** All seven jobs passed
  on the public repository for run
  [34696518049](https://github.com/BusyBeaverSoftware/LavaPHP/actions/runs/34696518049),
  on 2026-09-12 and on `cdc75ee` — the same commit `main` points at. That run is
  also the first green `coverage` job this project has had on GitHub, so the
  `pcov`-through-`PHP_INI_SCAN_DIR` mechanism is now observed rather than only
  reasoned about, and the `skeleton` job's `lava map --check` step — the one that
  would have caught the stale map this repository shipped for two milestones
  (DECISIONS.md 170) — has run for real. One green run is not a pattern: the
  jobs are re-run on every push, and any push that moves the tag commit has to
  earn its own green.
- **PHP 8.3 and 8.4 are exercised by CI, and the floor is now checkable
  locally too.** Development here is on 8.5.4. The CI matrix is what runs the
  suite on 8.3 and 8.4; locally, `composer check:floor` (step 3) lints every
  tracked file against a real 8.3 through docker. What is still CI-only is the
  *suite* on those versions — a local 8.3 or 8.4 run means mounting the
  repository into `php:8.3-cli` and running PHPUnit there by hand.
- **No release has reached anyone.** The `0.1.0` tag is pushed as of
  2026-09-12, but the six packages are not registered on Packagist, so no
  webhook exists for Packagist to learn about it from — `composer require
  lava/core` still 404s and `lava/app`'s `composer create-project` path is still
  untested against Packagist. The skeleton is verified by copy-and-install
  (step 5) instead. Registering the packages is the step that would make the
  pushed tag mean what "release" usually means; see "The tag" above.
- **None of the six manifests can be published without a split.** Packagist
  reads `composer.json` only at a repository root, and this repository's root is
  the monorepo, so each package needs a split mirror before registration is even
  possible. Five of the six additionally declare a `../core` path repository
  that is monorepo-local and has to be stripped in the split — the *constraint*
  half of that problem is fixed as of 2026-09-12, when they moved from the
  unbound `lava/core: @dev` to `^0.1.0` resolved from a version-pinned path
  repository. `lava/core` is the one package that could ship verbatim. The
  detail, including what is and is not fatal for a consumer, is in "The tag"
  above.
- **The first publishable split cannot come from `0.1.0`.** The manifests at the
  pushed tag still declare `@dev` against unpinned path repositories, and a
  pushed tag is immutable — so whatever Packagist is first pointed at has to be
  a later tag (0.1.1 or beyond), cut from a commit whose manifests have been
  through the split.
