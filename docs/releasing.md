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

**Registering needs something to register.** Packagist reads `composer.json`
only at the root of a repository, and this repository's root is the monorepo, so
each package is published from a read-only mirror of its own directory — see
[Publishing](#publishing). The tag is still the release act: the split workflow
pushes it to every mirror, and Packagist learns of it there.

Cross-package constraints are lockstep: `^0.1.0` appears in the five packages
that depend on core, so a minor release bumps them together or the packs
resolve to a core older than the one they were tested against.

## Publishing

Six packages are published, each from a read-only mirror of its directory:

| Directory | Package | Mirror |
|---|---|---|
| `packages/core` | `lava/core` | `BusyBeaverSoftware/lava-core` |
| `packages/db` | `lava/db` | `BusyBeaverSoftware/lava-db` |
| `packages/validate` | `lava/validate` | `BusyBeaverSoftware/lava-validate` |
| `packages/view` | `lava/view` | `BusyBeaverSoftware/lava-view` |
| `packages/http-client` | `lava/http-client` | `BusyBeaverSoftware/lava-http-client` |
| `packages/app` | `lava/app` | `BusyBeaverSoftware/lava-app` |

The mirror names are set once, at the top of `.github/workflows/split.yml`. A
package's name comes from its `composer.json`, never from its mirror.

**Every manifest under `packages/` is publishable as it sits.** None carries a
`repositories` block: in a published package that block is ignored when the
package is a dependency and fatal when it is the root, and `composer
create-project lava/app` makes the skeleton the root (DECISIONS.md 229). So the
split is a pure prefix split that rewrites nothing, and the manifest CI tested
is the one a consumer gets. Inside this repository the packages still resolve
each other from the working tree — the root `composer.json` and each app under
`apps/` declare path repositories — and a package installed on its own is given
them by `tools/install-check.php`, in a scratch copy, never in its own file.

`.github/workflows/split.yml` does the pushing. On a push to `main` it runs
`git subtree split --prefix=packages/<name>` for each package and pushes the
result to that mirror's `main`; on a tag, it pushes the tag. It refuses a split
whose `composer.json` declares `repositories`, never forces a push, and pushes
nothing at all until the `SPLIT_TOKEN` secret exists.

### Before the first publish

These steps create public repositories and publish packages, so a maintainer
does them once, by hand:

1. Create six **empty** public repositories under `BusyBeaverSoftware`, named as
   in the table — no README, license or `.gitignore`, so the first push is not
   refused as unrelated history.
2. Create a fine-grained personal access token with **Contents: read and write**
   on those six repositories only, and add it to this repository as the Actions
   secret `SPLIT_TOKEN`.
3. Push `main`. Check that each mirror now has `composer.json` at its root and
   the history of its own directory.
4. Sign in to packagist.org with GitHub and submit each mirror's URL. Set up
   Packagist's GitHub integration (or its webhook) for each, so pushes and tags
   reach it without a manual update.
5. Tag the release here — `git tag -a 0.1.1 -m "0.1.1"`, then push the tag. The
   workflow pushes it to every mirror, and Packagist publishes it. `0.1.0` is not
   published: its manifests still carry path repositories.

### Rehearsing it locally

- `composer check:install` copies every package and app out of the working tree
  as a fresh clone has it, and installs each for real: `composer validate
  --strict` on the manifest as published, then `lava map --check` and `lava
  check --strict` for the skeleton, the demo and the blog. Name targets to run
  fewer: `composer check:install -- db app`. CI's `isolated-install` and
  `skeleton` jobs run the same script.
- `composer check:split` turns each package into a local git repository — a
  mirror — and has a consumer that knows only those mirrors run `composer
  create-project lava/app`, `composer require` for every pack, and `lava check
  --strict`; every `lava/*` package in its lock must come from a mirror. It
  builds the mirrors from the working tree, so it rehearses manifests before
  they are committed. CI's `publish-rehearsal` job runs it too.

Neither pushes or publishes anything, and neither can show Packagist itself.

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
- **The mirrors do not exist yet.** Every manifest is publishable as it sits and
  the split workflow is in place, but nothing is pushed until the six mirror
  repositories and the `SPLIT_TOKEN` secret exist — the one-time setup under
  [Publishing](#publishing). `composer check:split` rehearses the whole path
  locally, Packagist excepted.
- **The first publishable split cannot come from `0.1.0`.** The manifests at the
  pushed tag still declare `@dev` against unpinned path repositories, and a
  pushed tag is immutable — so whatever Packagist is first pointed at has to be
  a later tag (0.1.1 or beyond), cut from a commit whose manifests carry no
  path repositories.
