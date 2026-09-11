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

Run these from a clean checkout. The first two are the whole gate; the rest are
the claims the gate cannot make on its own.

1. **`composer verify`** — the suite, PHPStan at level 8 across every pack's
   `src`, `apps/demo/app`, `apps/demo/tests` and `tools`, and `lava/core` alone
   at level `max`. Expect `[OK] No errors` twice and a green suite.
2. **`composer coverage`** — per-pack line coverage with a floor per pack,
   counted with the `bin/lava` subprocesses included. Expect exit 0 and a
   non-zero "child processes captured" count; a zero there means the instrument
   is blind, not that the code is covered. Needs `pcov` and `pdo_sqlite`.
3. **`lava check --strict` on `apps/demo`** — the canonical app boots, its map is
   current, and its suite is green, with warnings promoted to failures. This is
   the end-to-end proof that the packs still work together in an app that
   actually uses them.
4. **`lava map --check` on `packages/app`** — the skeleton's committed
   `AGENTS.md` is accurate from a fresh install, with no `lava map` run first.
5. **Each pack installs standalone** — `db`, `validate`, `view` and
   `http-client`, each copied out with only `core` beside it, `composer install`
   then `composer validate`. This is the decoupling claim: a pack that has grown
   an undeclared dependency on a sibling fails here and nowhere else.
6. **CI is green on the tag commit.** The five jobs are the authority on PHP
   8.3, 8.4 and 8.5, on a machine that is not this one. This step is the one that
   cannot be run from this checkout: it has **no git remote configured**, so the
   workflow has never run. Push a branch, watch the five jobs go green, and only
   then cut the tag — a tag on a commit CI has not seen is a tag that may have to
   move, and a tag that moves is worse than a late one.

## The tag

```
git tag -a 0.1.0 -m "0.1.0"
```

Push it only when the release is meant to be published — the push is what makes
it a release, and a pushed tag is not reversible in the way a local one is.
Packagist picks it up from the push.

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

- **The CI coverage job has never had a green run on GitHub, because this
  checkout has no remote.** The mechanism is proven locally — `pcov.directory`
  set to the repository, the ini reaching child processes through
  `PHP_INI_SCAN_DIR` — but setup-php's image is not this machine, and the job
  must be observed green once before the gate can be called verified. Same for
  the other four jobs, and for the `skeleton` job's `lava map --check` step,
  which is what would have caught the stale map this repository shipped for two
  milestones (see DECISIONS.md 170).
- **PHP 8.3 and 8.4 are CI-only.** Development here is on 8.5.4. The floor is
  enforced by the CI matrix, not by anything runnable on this machine.
- **No release has been published.** Until a tag is pushed, `lava/app`'s
  `composer create-project` path is untested against Packagist — the skeleton is
  verified by copy-and-install (step 4) instead.
