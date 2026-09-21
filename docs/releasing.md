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

A change an app has to act on — a refusal where there was none, a response
that carries less, a map that reads stale — goes out in a minor, never a patch.
A `^0.1.0` constraint stops below `0.2.0`, so an app upgrades by choosing to.
`0.2.0` was the first such minor, `0.3.0` the second, `0.4.0` the third and
`0.5.0` the fourth, `0.6.0` the fifth and `0.7.0` the sixth; the README lists what
upgrading to each asks of an app. A minor stays a minor even when every change
in it fixes a silent wrong result — `0.3.0` was planned as `0.2.1` until the
list of what it asks of an app was written down (DECISIONS.md 279, 280).

## Before the tag

Run these from a clean checkout. The first three are the whole gate; the rest
are the claims the gate cannot make on its own.

A clean checkout needs one install. Steps 4 and 5 make their own, in scratch
copies of the tree, because installing from a fresh copy is exactly what they
check:

```sh
composer install    # the monorepo, for steps 1–3
```

1. **`composer verify`** — the suite, PHPStan at level 8 across every pack's
   `src`, `apps/demo/app`, `apps/demo/tests` and `tools`, and `lavaphp/core` alone
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
4. **`composer check:install`** — every package and app copied out of the
   working tree as a fresh clone has it, and installed for real. Each package
   gets `composer validate --strict` on its manifest *as published* — no
   `repositories` block, a real `lavaphp/core` constraint — and then an install;
   the skeleton, `apps/demo` and `apps/blog` are then mapped and checked with
   `lava map --check` and `lava check --strict`. One command carries the
   decoupling claim (a pack that has grown an undeclared dependency on a sibling
   fails to install on its own), the skeleton's claim (its committed `AGENTS.md`
   is current from a fresh install), and the apps' end-to-end claim. CI's
   `isolated-install` and `skeleton` jobs run the same script. Expect `every
   target installed fresh and passed`.
5. **`composer check:split`** — each package as its own git repository, and an
   app built from those repositories alone with `composer create-project`,
   `composer require` and `lava check --strict`. It is the release rehearsed
   before anything is pushed. Expect `an app built from the mirrors alone
   installs and checks green`.
6. **CI is green on the tag commit.** The nine jobs are the authority on PHP
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
git tag -a 0.7.0 -m "0.7.0"
```

Push it only when the release is meant to be published — the push is what makes
it a release, and a pushed tag is not reversible in the way a local one is.

**The push does not, by itself, publish anything to Packagist.** Packagist
learns about a tag from a webhook on a package that is *already registered*, so
the order runs the other way from what "the push makes it a release" suggests:
register the package, put the webhook in place, and only then is a tag push
visible to the world. The six packages published before 0.4.0 have had that
webhook since 2026-09-13 (DECISIONS.md 254), and `lavaphp/events` since it was
registered on 2026-09-14, when Packagist created its hook (DECISIONS.md 295). `0.1.1` predates it, and reached Packagist only because a
maintainer pressed **Update** on each package's page (DECISIONS.md 253).

Two consequences worth expecting. The push triggers a **full duplicate run of
all nine CI jobs** on the tagged commit, because the workflow triggers on any
push with no `tags:` filter and no job branches on `github.ref`; it is
redundant but harmless. And the tagged commit is whatever the tag points at:
move the tag to the commit whose record should ship, not to a convenient one.

**Registering needs something to register.** Packagist reads `composer.json`
only at the root of a repository, and this repository's root is the monorepo, so
each package is published from a read-only mirror of its own directory — see
[Publishing](#publishing). The tag is still the release act: the split workflow
pushes it to every mirror, and Packagist learns of it there.

Cross-package constraints are lockstep: `^0.7.0` appears in the six packages
that depend on core, so a minor release bumps them together or the packs
resolve to a core older than the one they were tested against.

## Publishing

Seven packages are published, each from a read-only mirror of its directory:

| Directory | Package | Mirror |
|---|---|---|
| `packages/core` | `lavaphp/core` | `BusyBeaverSoftware/lava-core` |
| `packages/db` | `lavaphp/db` | `BusyBeaverSoftware/lava-db` |
| `packages/validate` | `lavaphp/validate` | `BusyBeaverSoftware/lava-validate` |
| `packages/view` | `lavaphp/view` | `BusyBeaverSoftware/lava-view` |
| `packages/http-client` | `lavaphp/http-client` | `BusyBeaverSoftware/lava-http-client` |
| `packages/events` | `lavaphp/events` | `BusyBeaverSoftware/lava-events` |
| `packages/app` | `lavaphp/app` | `BusyBeaverSoftware/lava-app` |

The mirror names are set once, at the top of `.github/workflows/split.yml`. A
package's name comes from its `composer.json`, never from its mirror.

`lavaphp/events` joined in 0.4.0. Its mirror, its write deploy key and the
`SPLIT_KEY_EVENTS` secret were created on 2026-09-14 (DECISIONS.md 294), and it
was registered on Packagist the same day (DECISIONS.md 295), whose GitHub
integration added the webhook on submission. A package added later needs the
same four steps of [Setting up the mirrors](#setting-up-the-mirrors) before a
tag that includes it: a tag reaches a mirror Packagist does not know about, and
nobody can install it.

**Every manifest under `packages/` is publishable as it sits.** None carries a
`repositories` block: in a published package that block is ignored when the
package is a dependency and fatal when it is the root, and `composer
create-project lavaphp/app` makes the skeleton the root (DECISIONS.md 229). So the
split is a pure prefix split that rewrites nothing, and the manifest CI tested
is the one a consumer gets. Inside this repository the packages still resolve
each other from the working tree — the root `composer.json` and each app under
`apps/` declare path repositories — and a package installed on its own is given
them by `tools/install-check.php`, in a scratch copy, never in its own file.

`.github/workflows/split.yml` does the pushing. On a push to `main` it runs
`git subtree split --prefix=packages/<name>` for each package and pushes the
result to that mirror's `main`; on a tag, it pushes the tag. It refuses a split
whose `composer.json` declares `repositories`, never forces a push, and skips a
mirror whose deploy key is not configured.

### Setting up the mirrors

Done once, on 2026-09-12, and kept as the procedure for recreating a mirror.
These steps create public repositories and publish packages, so a maintainer
does them by hand:

1. Create seven **empty** public repositories under `BusyBeaverSoftware`, named
   as in the table — no README, license or `.gitignore`, so the first push is
   not refused as unrelated history.
2. For each mirror, generate an SSH key pair. Add the public half to the mirror
   as a deploy key **with write access**, and the private half to this
   repository as the Actions secret `SPLIT_KEY_<NAME>`: `SPLIT_KEY_CORE`,
   `SPLIT_KEY_DB`, `SPLIT_KEY_VALIDATE`, `SPLIT_KEY_VIEW`,
   `SPLIT_KEY_HTTP_CLIENT`, `SPLIT_KEY_EVENTS`, `SPLIT_KEY_APP`. One key per
   mirror, because GitHub will not attach one deploy key to two repositories,
   and because a leaked key should publish one package rather than seven.
3. Push `main`. Check that each mirror now has `composer.json` at its root and
   the history of its own directory.
4. Sign in to packagist.org and submit each mirror's URL. Then make updates
   automatic, one of two ways: log in via GitHub and grant the Packagist
   application access to the `BusyBeaverSoftware` organization, then trigger an
   account sync; or add a webhook to each mirror with the payload URL
   `https://packagist.org/api/github?username=<packagist-username>`, content type
   `application/json`, the Packagist API token as the secret, and only the `push`
   event. Until one of those is done, every push and tag needs **Update** pressed
   on each package's page. Choose one, not both: a working sync treats any hook
   whose URL starts `https://packagist.org/api/github` as its own and rewrites it.

   The integration is what set the hooks up here, and it took two syncs. The
   Packagist account was already connected to GitHub, but the Packagist
   application had not been granted the organization, and a sync in that state
   reported *6 hooks already setup and left unchanged* while GitHub listed none:
   Packagist counts a hook it failed to create as unchanged. After the grant, at
   `github.com/settings/connections/applications/a059f127e1c09c04aa5a`, a second
   sync from `packagist.org/trigger-github-sync/` created all six. Check the
   result with `gh api repos/BusyBeaverSoftware/lava-<name>/hooks`, not with the
   sync's count.
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
  create-project lavaphp/app`, `composer require` for every pack, and `lava check
  --strict`; every `lavaphp/*` package in its lock must come from a mirror. It
  builds the mirrors from the working tree, so it rehearses manifests before
  they are committed. CI's `publish-rehearsal` job runs it too.

Neither pushes or publishes anything, and neither can show Packagist itself.

## What is deliberately not part of a release

- **No `composer.lock` is committed** for any pack or app, and the omission is
  load-bearing rather than housekeeping: a lock generated in this monorepo pins
  `lavaphp/core` to a `../core` path repository, which exists only here. Shipping
  one would hand a consumer a lock that cannot resolve. The root lock IS
  committed — it is a project, and its dev tooling is worth pinning.
- **No `CHANGELOG.md`.** The record is `DECISIONS.md` (every judgement call with
  its rationale and the date) plus `git log`, and a changelog maintained by hand
  beside those is a third account of the same events that drifts first and is
  believed second. If consumers ask for one, it should be generated from the
  commit log at tag time rather than written alongside it.

## Known gaps at 0.6.0

Stated here rather than discovered later, because a release checklist that
implies everything was verified is worse than one that lists what was not.

- **`0.6.0` is a security release.** Five reviewers audited the framework, each
  finding reproduced twice; the reports are the maintainer's, not in this
  repository. Three advisories are published:
  [GHSA-3gvq-mjhr-h8vc](https://github.com/BusyBeaverSoftware/LavaPHP/security/advisories/GHSA-3gvq-mjhr-h8vc)
  (high — authorization bypass and path traversal, `lavaphp/core` 0.5.0 only),
  [GHSA-pp36-3jgx-c95g](https://github.com/BusyBeaverSoftware/LavaPHP/security/advisories/GHSA-pp36-3jgx-c95g)
  (high — credential disclosure, SSRF through non-HTTP protocols and an
  unbounded response, `lavaphp/http-client` before 0.6.0) and
  [GHSA-wqqr-f9fj-9j2j](https://github.com/BusyBeaverSoftware/LavaPHP/security/advisories/GHSA-wqqr-f9fj-9j2j)
  (medium — information disclosure, `lavaphp/core` before 0.6.0). **No CVE has
  been requested for any of them**, so `composer audit` cannot see them: a
  repository advisory reaches the GitHub Advisory Database only through review,
  which a CVE request starts. The same is true of `0.4.1`'s
  GHSA-x76q-3p93-qcc2.
- **Packagist updates itself, for all seven packages.** The tag was pushed at
  01:40:04 UTC and a fresh `composer show -a` from an empty directory saw every
  package by 01:41:53 — six within a minute, `lavaphp/core` fifty seconds later,
  the usual shape. Check a release this way, from a fresh Composer home **in an
  empty directory**, never with `curl` and never from this repository's root.
- **CI was green on the tag commit, three times**, and the split ran green on
  `main` and on the tag.
- **`0.6.0` installs from Packagist on PHP 8.5, 8.4 and 8.3**, with `lava check
  --strict` passing in each. On 8.5 a scratch app also proved the fix itself:
  `GET /docs/%2e%2e%2fsecret.txt` answers `400 bad_request_path` while an
  ordinary path still routes.
- **The upgrade was rehearsed on a real app.** Lava Notes moved from `0.5.0` and
  needed exactly one change — a test that asserted `404` for a traversal attempt
  asserts `400` now — then passed its 230 tests (one skipped for GD).
- **What `0.6.0` asks of an app** is in the README's "Upgrading from 0.5 to 0.6":
  six refusals where there were none. That is why it is a minor and not a patch.
- **SSRF is still not defended** by `lavaphp/http-client`: private and
  link-local addresses are ordinary `http` URLs and the pack has no allow-list or
  hook. The documentation now says so plainly; an app passing a user-supplied URL
  must check it itself.
- **PHP 8.3 and 8.4 are exercised by CI, and the floor is checkable locally.**
  Development here is on 8.5.4. The CI matrix runs the suite on 8.3 and 8.4;
  locally, `composer check:floor` (step 3) lints every tracked file against a
  real 8.3 through docker. The *suite* on those versions is still CI-only.
- **`0.1.0` is not on Packagist, and never will be.** Its manifests carry path
  repositories and a pushed tag does not move, so the first installable version
  is `0.1.1`.
- **The mirrors are read-only.** Issues and pull requests belong in
  `BusyBeaverSoftware/LavaPHP`. A commit pushed to a mirror directly makes the
  next split fail, because the workflow never forces a push.
