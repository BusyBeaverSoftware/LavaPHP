# DECISIONS.md

Autonomous decisions made while implementing the approved plan
(`/home/five/.claude/plans/greedy-painting-twilight.md`), each with rationale.
Nothing here overrides a user decision — flag anything you disagree with and
it gets reverted or reworked.

## 2026-09-10 — pre-vendor verification phase

**Context**: `composer install` is persistently blocked by the Bash safety
classifier being unavailable ("glm-5.3:cloud is temporarily unavailable"), so
`vendor/` does not exist and the plan's normal gates (PHPUnit, `php -S` +
curl) cannot run. `php <script>` passes the classifier, so real
execution-verification is possible without vendor — `scripts/smoke.php`
(empty PSR interface stubs + a source autoloader) is that harness and is
**35/35 green**. It is deleted once vendor exists.

1. **Caller attribution walks past the class's own backtrace frames instead
   of using a fixed frame index** (`Container::callerLocation`,
   `Router::caller`). An empirical PHP 8.5.4 probe showed a closure frame
   reports the *invoker's* file:line (the defining location only survives
   inside the `function` string as `{closure:file:line}`). A fixed index
   broke twice: `frames[2]` named `WireAppServices.php` for wiring closures,
   and `frames[1]` broke for routes registered through the `get()`/`post()`
   wrappers (one extra frame). The walk — first frame whose file is not this
   class's file — is correct for every call shape and names the user's exact
   registration line. Locked in by two smoke checks (duplicate_service sites,
   duplicate route names through a loader closure).

2. **`BadHandler::routeHasNone()` for handler-less routes.** A route without
   `->handler()` was reported as "Route handler 'no.handler' is invalid" —
   mislabeling the *route* as the handler. Now: "Route 'no.handler' (/nope)
   has no handler." Same `bad_handler` code, `route`/`path` context.

3. **`Matched` carries `(route, args)` only; the injection plan is fetched
   from the Router by name at dispatch** (`App::handle` →
   `router->plan($route->name)`). `Router::match()` hard-requiring attached
   plans made the pure router unusable without a full boot (LogicException on
   any plan-less router — URL generation, unit tests). Matching is a pure
   pattern operation; `Router::plan()` keeps its invariant guard, which is
   unreachable post-boot because BuildRouter attaches a plan for every
   compiled route and any failure there is fatal.

4. **`scripts/smoke.php` gained concrete `Nyholm\Psr7` stand-ins**
   (`ServerRequest`, `Response`, `Uri`, `Stream`, `Psr17Factory` — immutable
   `with*`, case-insensitive headers, exactly the surface dispatch touches).
   M2's central deliverable — `App::handle` through middleware to a response —
   was otherwise 0% verified until vendor lands. The stubs exercise the real
   fixtures end-to-end: 200 JSON with typed params, middleware ordering
   global→auth, args+service injection, 404/405 problem bodies with `Allow`,
   Accept negotiation to the HTML diagnostics page, gated route 404 while its
   flag is off. Real nyholm re-confirms via `php -S` + curl once vendor exists.

## 2026-09-10 — vendor landed: first real PHPUnit run + real-SAPI gate

**Context**: the user ran `composer install` through their own `!` shell
(the classifier stayed down), so vendor/ now exists: real nyholm/psr7 1.8.2,
PHPUnit 12.5.35. First-ever PHPUnit run: 97 tests → 3 errors + 2 warnings →
all fixed → green, then extended (ModuleTest, SubjectGatingTest) → 105 tests.
The temporary smoke harness (which reached 46 checks before vendor) is
retired once its unique checks live in phpunit + the gate below.

1. **Two real framework bugs surfaced only under PHPUnit warnings.**
   `Container::describe()` read `$this->traces[$id]?->resolvedClass()` —
   the nullsafe `?->` guards the method call, not the array fetch, so
   describe-before-first-resolve (legal: `lava services` does it) emitted an
   "Undefined array key" warning. Fixed with `($this->traces[$id] ?? null)?`.
   `Router::pattern()`'s regex-validity probe passed deliberately-bad
   fragments to `preg_match` to detect them — but PHP emitted the raw
   compile warning before returning false. The thrown `BadRoutePattern` is
   the report we want; `@preg_match` silences the probe's own diagnostic.

2. **The gate (`scripts/gate.php`) exists because TestClient can't prove the
   SAPI path.** RequestFactory::fromGlobals, the Emitter writing real
   headers, and config/.env loading in a fresh process only happen under a
   real `php -S`. First run failed 25/29: fixture apps have no composer.json,
   so a bare `php -S` child has no `App\` autoloader (real apps get it from
   composer.json, PHPUnit gets it from TestApp). Harness fix:
   `scripts/fixture-autoload.php` via `-d auto_prepend_file` +
   `LAVA_FIXTURE_APP_DIR` — the sanctioned "fixture apps get it from the
   test harness" mechanism from conventions.md.

3. **Gate port hygiene: `proc_terminate` on a `PHP_CLI_SERVER_WORKERS=2`
   master leaves orphan workers holding the port and serving STALE code.**
   Caught by evidence (ps showed the run-1 trio still alive at run-2, and
   run-2's responses were run-1's boot failures). The gate now kills
   by port (`pkill -f 'php -S <host>:<port>'`) before every start and at
   the end, and waits for the port to actually free.

4. **FlagSubjectResolver (M3 slice 2): the container id is the interface's
   own FQCN.** Both the app's `app/Services.php` and the core's
   `App::handle` look it up as `FlagSubjectResolver::class` — fits the
   class-string id rule, no new magic id. `App::handle` binds
   `Features::forSubject()` BEFORE matching so gated routes stay real 404s.
   A service registered under the id that doesn't implement the interface
   renders an `invalid_config` problem at request time (defense in depth);
   the boot-time eager check lands with WiringValidator (slice 3), which
   resolves every registration anyway. Anonymous (resolver → null) resolves
   audience flags OFF — the documented FlagSubject policy, now exercised by
   the subject-app fixture + SubjectGatingTest (users targeting AND rollout,
   plus the no-resolver fail-closed state via a stripped container).

5. **Compact JSON on the wire.** The real-SAPI gate compared raw wire bytes
   for the first time and exposed `Responses::json` as `JSON_PRETTY_PRINT`-
   formatted — invisible to every PHPUnit assertion (they all decode),
   visible to any curl. Agents parse JSON; humans get the HTML diagnostics
   page; pretty-printing belongs to M4's CLI text renderer, not HTTP bodies.
   The plan's own `--json` examples are single-line.

## 2026-09-10 — M3 slice 3: ValidateWiring (the wiring proof)

**Context**: the last boot step, and the reason `Kernel::STEPS` is now 10
entries. The promise from slice 2 — a `FlagSubjectResolver` registered under
the wrong class fails at boot, not on request one — is fulfilled here, and
it generalizes to *every* registration: after `WireAppServices` and
`BuildRouter` have run, the step resolves each container id once. Verified:
105 PHPUnit tests / 408 assertions green, real-SAPI gate 36/36.

1. **Eager resolve-all sweep, one `LavaProblem` per id, never fail-fast.**
   The step iterates `$container->ids()` and calls `get()` on each, adding
   every failure to the problem set instead of throwing on the first. A
   broken factory therefore surfaces as a boot report listing *all* broken
   registrations — an agent fixes the whole file in one round trip rather
   than rediscovering the next breakage per boot. This is the same
   one-round-trip philosophy as `LavaProblem`'s file:line + fix hint.

2. **Resolution runs on the real container, so `lava services` gets real
   traces for free.** The sweep is not static analysis: calling `get()`
   records the container's own dep/dependent traces, which is exactly what
   `lava services` (M4) will report. Constructors do no I/O by convention —
   that convention is what makes an eager resolve-all sweep safe enough to
   also power `lava check`. A constructor that *does* I/O is a wiring bug the
   sweep is right to catch at boot.

3. **Attribution names the user's file, not the framework's.** The missing
   id is reported against the *factory's own wiring line*
   (`app/Services.php:11` in the fixture) because the container tracks the
   registration whose factory asked for the id — so the fix hint points into
   `app/Services.php`, never at `Container.php`. Locked in by the new
   `testBrokenWiringAppFailsAtBootWithEveryBrokenRegistration`.

4. **A plain `\Throwable` from a constructor is still a structured problem,
   never a white screen.** Caught as `UnexpectedFailure::of(self::class,
   $throwable)` → `unexpected_failure`, carrying the step
   (`ValidateWiring::class`), the exception class, and the user's file:line
   (`app/Wiring/Boom.php:12`). The report degrades gracefully: an unexpected
   failure is a first-class problem with the same shape as a typed one.

5. **The `FlagSubjectResolver` re-check swallows a re-throw on purpose.** The
   step resolves the resolver id and asserts `instanceof FlagSubjectResolver`;
   a non-conforming registration becomes `invalid_config` naming the wrong
   class. If *that* `get()` throws, the `catch (\Throwable)` swallows it —
   the sweep above already reported that registration, so re-reporting would
   double-count one breakage as two problems. `App::handle` keeps its own
   check as defense in depth for apps that bypass the boot chain.

6. **`container === null` short-circuits (standard step idiom).** A fatal
   upstream (e.g. `BuildFeatures` on a bad flag) stops the chain and leaves
   the container unset; every later step guards on null rather than
   re-reporting the upstream failure. This is why `bad-flags-app` still
   yields its single expected code list and the sweep can't shift it.

## 2026-09-10 — M4 slice 1 (console kernel) + the phpstan sweep

**Context**: M4 begins. Slice 1 is the CLI walking skeleton — argv → command →
dual-written output → exit code — with `bin/lava` and `lava list`. While
building it, `composer verify` was found to be **red** (34 phpstan level-8
errors) — a pre-existing condition from M1–M3 that the plan defers to M9
("phpstan max on core"). The sweep below happened now, not in M9, for one
reason: two of those "type" errors were real bugs, and a red gate makes it
impossible to tell a new breakage from the old noise.

1. **`CollectFlagDefinitions::absorbDefine()` — `$packFeatures` was never in
   scope.** `absorb()` accepted the `feature => package` map and dropped it
   when calling `absorbDefine()`, so the guard `isset($packFeatures[...])`
   was always false: the "an app cannot redefine a pack's own gate flag"
   check was dead code. Threaded the map through; locked in by the new
   `redefined-pack-flag-app` fixture + KernelBootTest case.

2. **`Config::typed()` — `$file` was out of scope on the wrong-type path.**
   `[$file, $name] = splitKey()` ran only inside the "key missing" branch, so
   a wrong-typed key *without* provenance rendered its fix as
   `config/.php`. Split the key before the branch (`optional()` already did
   this correctly). Locked in by
   `testWrongTypeWithoutProvenanceStillNamesTheConfigFile`.

3. **The rest were genuinely dead checks or missing docblocks** — not
   silences. `getenv()` with no arguments always returns an array (verified
   empirically), so the `is_array(getenv())` guards in `TestApp`/`BuildFeatures`
   were unreachable and removed; `getFileName()`/`getStartLine()` return
   `string|false`/`int|false`, so `?:` replaces `??`; `Closure|null` and the
   `array|string` handler shapes got real types. No `@phpstan-ignore`, no
   baseline, no casts — `composer verify` is green on the merits.

4. **`Command` is an abstract class, not the interface the plan listed.**
   `flags()`/`pack()`/`arguments()`/`usage()` are identical across all 13
   commands; an interface would have forced four lines of boilerplate into
   each. The contract is unchanged — `name()`, `summary()`, `run()` are still
   abstract.

5. **`Envelope` is its own class** (the plan folded the envelope into
   `Console`). Keeping the versioned shape (`lava.<cmd>/1`) and its compact
   encoding in one testable place beat inlining it in the kernel; `IO` owns
   accumulation and emission, `Envelope` owns the wire shape.

6. **Value flags are spelled `--flag=value`, never `--flag value`.** The
   space-separated form is ambiguous with positionals (`lava describe --json
   users.show` would swallow the selector). One unambiguous spelling is a
   contract an agent can rely on. `-q`/`-h`/`-j` short forms map to
   `quiet`/`help`/`json`.

7. **Unknown commands exit 2, not 1.** A bad invocation is distinguishable
   from a command that ran and failed — an agent can tell "you typed it
   wrong" from "it's broken" without parsing. The envelope still carries the
   `unknown_command` problem with a nearest-name fix.

## 2026-09-10 — M4 slice 2 (the inspection commands)

**Context**: `about`, `routes`, `services`, `features` (+ `features resolve`),
`config`, `env`, `describe`, `list`. The design question this slice turned on
was *who owns the facts*: a command that re-derives what boot already decided
can disagree with the app it is describing, and then the diagnostic lies.

1. **`CheckAppDir` is the new first boot step, and `not_an_app` its code.**
   Found while exercising the real binary from `/tmp`: a directory with no
   `app/`, no `config/`, and no `public/index.php` booted **clean**, so
   `lava routes` there answered `status: ok, routes: []`. That is not a
   cosmetic gap — it reads as "your app has no routes" when the truth is
   "there is no app here", which sends an agent debugging a 404 into the wrong
   problem entirely. Every user-authored artifact in conventions.md is
   optional, so *nothing else* in the pipeline can notice. The marker set is
   deliberately generous — ANY ONE of the three suffices — because a
   config-only app (`bad-flags-app`) and a zero-config app that is just
   `public/index.php` are both legitimate; requiring a specific file would
   fail real apps and requiring all three would fail most of them. Fatal
   severity, and the test asserts the report holds *exactly* one problem:
   nothing downstream of "there is no app" should invent findings.

2. **`App` carries what boot DECIDED, not what commands can recompute.**
   Four trailing fields: `globalMiddleware`, `moduleRefs`, `packs`, `dotEnv`,
   `envFromFile`. `lava about` reads `moduleRefs` for gate state and `packs`
   for manifests rather than re-reading `app/Modules.php`; `lava env` reads
   `dotEnv`. All defaulted, so `SubjectGatingTest`'s direct `new App(...)`
   still compiles and the change is additive.

3. **`envFromFile` exists because `.env` promotion is destructive.** Boot
   promotes `config/.env` values into the real process environment
   (`LoadDotEnv`) and never takes them back. Afterwards, `getenv('APP_REGION')`
   is indistinguishable from a shell export, so `lava env` reported
   `source: env` for values that came from the file — a lie in the one column
   the command exists to provide. The fix records boot's own promotion
   *decision* (`envFromFile` = names the real environment did NOT already
   define) instead of guessing post-hoc with a value-comparison heuristic,
   which would have been wrong for any var whose shell value happened to equal
   the file's. `DescribeCommand` applies the same rule.

4. **`--env=<name>` reports source `flag` for `LAVA_ENV`.** The override is
   applied for the boot only and then restored, so the process environment
   holds no trace of it afterwards and `source: unset` would sit next to
   `value: prod`. The flag *is* the source.

5. **Usage errors exit 2, checked BEFORE the boot** (`AppCommand::usageProblem`,
   matching `unknown_command`'s contract). A malformed invocation is not an
   app problem, so it must not depend on the app booting — `lava describe`
   with no selector reports `bad_usage` even where boot would have failed, and
   the fix line quotes the usage.

6. **Every command seeds its payload shape before the boot** (`emptyPayload`).
   A command that threw mid-`inspect` used to emit `data: {}`, breaking the
   promise that `lava.<cmd>/1` has a stable shape. Seeding first means every
   exit path — usage, boot failure, unexpected throw — carries the command's
   keys; `inspect()` overwrites them in place so insertion order is preserved.

7. **`Console::run()` wraps dispatch, so a `LavaProblem` thrown *by a command*
   is a report, never a stack trace.** Regression: `lava features resolve
   <typo>` escaped as an uncaught `UnknownFeature` fatal. This is the single
   dispatch point, so every command — including future pack commands —
   inherits the guarantee; a non-LavaProblem becomes
   `UnexpectedFailure::inCommand()` (which names the command, not a boot step,
   so the report never mislabels where the failure happened).

8. **`AboutCommand` is a plain `Command`, not an `AppCommand`.** "Why won't
   this app start" is answered better by `about` than by anything else, and an
   `AppCommand` would have nothing to say on `BootFailure`. It prints the PHP
   facts first, then `app: null` / `packs: []` — stable keys, so a `--json`
   consumer can tell "no packs" from "could not boot". `gateState()` returns
   `'unknown'` when a `ModuleRef` names an undefined feature rather than
   throwing the very exception it is meant to help diagnose.

9. **`describe` resolves route → service → flag → env → command, and a miss is
   never silent.** A name can legitimately be two of those at once, so
   precedence is documented rather than errored. The `unknown_selector`
   problem carries every candidate name by namespace *and* a nearest-match
   hint whose search spans all five namespaces — resolution order must not
   narrow the suggestion (`describe rutes` points at `lava routes`, a command,
   even though commands resolve last).

10. **`App::envVars()` is the single source of truth for env entries**, and
    `Secrets` is only a fallback. `lava env` and `lava describe` each grew a
    private `declared()`/`names()` helper, which both duplicated the union
    (app declarations + pack declarations + unclaimed `.env` keys) and made
    phpstan infer an always-non-null offset type, producing six
    `nullsafe.neverNull` errors. Rather than suppress them, the union moved to
    `App::envVars(): list<array{name, var, by}>` — one implementation, both
    commands iterate entries and branch on `$var !== null`. The secret rule
    followed the same shape: **a declaration always wins**, and the
    name-based `Secrets::looksSecret()` heuristic applies only where nothing
    declared the value (config keys, unclaimed `.env` entries). Word-boundary
    matching means `api_key` is a secret but `monkey` and `base_url` are not.

11. **`MissingEnvVar` is Warn, not Fatal.** `lava env` is a report; a
    diagnostic that itself exits non-zero is an obstacle. `lava check
    --strict` (slice 3) is where an unset required var becomes a build
    failure.

## 2026-09-10/11 — M4 slice 3 (check, test, serve, schemas, CLI golden tests)

Slice 3 completes M4: `lava check`, `lava test`, `lava serve`, the eleven
`docs/schemas/*/1.json` contracts, the CLI golden tests, and the deletion of
the temporary real-SAPI gate.

1. **A command name the core set doesn't know triggers a boot, lazily**
   (`Console::main`). `lava demo:ping` reported `unknown_command` while
   `lava list` showed it: dispatch ran against `CommandRegistry::core()`, and
   pack/app commands only exist once `app/Modules.php` and `app/Commands.php`
   have run. Eager booting in `main()` is not available — `bin/lava` cannot know
   `--env` before `Args::parse` — so the miss is the only moment a boot is
   justified: core commands keep reading no app files at all. The cost is
   documented in the code: a pack command that is itself an `AppCommand` boots
   twice. A failed boot's report is MERGED into the `unknown_command` envelope
   (the unknown name stays `problems[0]`, exit stays 2), because "that command is
   unreachable while your app is broken" and "you typed it wrong" need different
   fixes.

2. **`DuplicateCommand` distinguishes "same pack twice" from "two packs".** The
   old message read "Command 'routes' is already provided by core; core provides
   it too" when the incoming command never overrode `pack()` — a message that
   reads as a bug in the framework. Both branches now name the packs, and the
   same-pack fix points at the actual cause: override `pack()`. The fixture
   (`dup-command-app`) got the missing override too, so the two-pack branch is
   what it exercises.

3. **`lava serve` propagates the parent's `auto_prepend_file` to the server
   child as `-d`** (`ServeCommand::prependArgument`). Without it the `php -S`
   child could not load `App\` classes in the test harness and every request
   rendered a diagnostics page — `lava serve` served a *different app* than
   `lava check` had just validated. Both processes are the same CLI SAPI reading
   the same php.ini, so a `-d` override is the one thing that does not survive
   the fork, and `auto_prepend_file` is the only override that changes which
   code runs. A `PHP_INI_SCAN_DIR` trick would have been wider than the problem.

4. **`lava serve`'s payload is seeded before any check can fail, and it carries
   the EFFECTIVE values.** Two failure exits (a bad flag, no entry point) never
   reach the code that computes them, and a `--json` consumer must not branch on
   a shape that is only sometimes there. The schema test then caught the
   consequence: seeding the *raw* port meant `data.port` went to the wire as
   `99999`, violating `lava.serve/1`'s `maximum: 65535`. The payload is a port
   number, so an unusable value now falls back to the default and the raw input
   travels in `problems[0].context.value` — where this framework puts failing
   inputs. Validation and defaulting are one method (`ServeCommand::number`) so
   they cannot disagree.

5. **`EnvAudit` is the single rule for "a declared required variable has no
   value", shared by `lava env` and `lava check`.** `lava check`'s `env` section
   was previously unreachable — `missing_env_var` was raised only by
   `EnvCommand` — which contradicted `docs/problem-codes.md`. Two commands
   independently deciding whether a variable is set is the same class of bug as
   two implementations of any other rule, so the rule moved to one place.
   `lava check --no-tests` still runs the env sweep (and the all-features
   sweep); `--quick` skips both, with the tests.

6. **A red test suite is data, not a problem.** `lava test` and `lava check`
   leave `problems[]` empty for a failing suite and go red through `status` and
   the exit code. `missing_test_runner` and `bad_test_report` are real problems
   because they are about the *run* rather than about the code: no runner means
   the app was never installed; a runner that wrote nothing means PHPUnit
   stopped before testing, and its own output is the only diagnosis there is, so
   it rides in `context.output` (bounded to 2000 chars).

7. **The test harness lends `LAVA_PHPUNIT` only to a fixture that declares a
   suite** (`LavaCli::environment`, `CommandTestCase::lendRunner`). A real app
   gets its runner from its own `vendor/`, and the harness stands in for exactly
   that — so `ok-app` (which has `phpunit.xml.dist`) runs a real suite, while
   `module-app` keeps the honest `missing_test_runner`. Lending it to every
   fixture would have made that path unreachable and turned a setup problem into
   PHPUnit's confusing "nothing to run". `LAVA_PHPUNIT` is the documented escape
   hatch (the `DB_TEST_DSN` idiom), not a test-only backdoor.

8. **The schemas are validated by running commands, not by listing them**
   (`tests/Schema/JsonSchemaTest.php`). Each invocation's own `schema` field
   names the file to validate against, so adding a payload key without touching
   its schema fails the build — the schemas set `additionalProperties: false`.
   Failed runs are validated too: `data` keys are promised on every exit path.
   Two implementation notes worth keeping: the `https://lavaphp.dev/schemas/`
   prefix is registered to `docs/schemas/` so opis never reaches the network,
   and envelopes are decoded with `json_decode`'s DEFAULT mode because opis
   refuses a PHP associative array as a JSON object
   (`Helper::getJsonType` returns null for it) — validating the array form would
   fail every command for a reason unrelated to the payload. A `$id`-vs-path
   test exists because the prefix resolver maps URLs to paths and would happily
   load a file under a name its own `$id` denies.

9. **`scripts/gate.php` and `scripts/fixture-autoload.php` are deleted.** The
   gate's 36 real-SAPI checks were run one last time (36/36 green) and then
   ported to `tests/Cli/ServeTest.php`, which starts four servers (ok-app,
   module-app with the pack on and off, subject-app) and drives them over HTTP;
   the `fixture-autoload.php` it needed already lives at
   `packages/core/tests/Support/fixture-autoload.php` in a maintained form. A
   shell script outside PHPUnit could not fail CI, could not share the harness,
   and had to be remembered.

10. **`lava check`'s `config` section carries `invalid_config` for a
    wrong-shaped app artifact too** (e.g. `app/Commands.php` returning
    non-callable), not only for `config/*.php`. The code→section map is static
    and `invalid_config` is one code covering both; splitting the code or
    guessing the section from the file path would be worse than a section label
    that is occasionally broader than the reader expects. Recorded as a known
    compromise rather than a design goal.

11. **Orphan flags (defined and set but never queried) are NOT yet a `lava check`
    warning**, though the plan (line 142) lists one. Deferred, not dropped:
    "never queried" cannot be observed from a boot, because a flag may be read
    by app code the framework never sees, and a gate naming an undefined flag is
    already a boot failure — so the check as specified would fire mostly on
    false positives. It becomes implementable when feature resolutions are
    recorded during a request (`Features::resolve` is the single choke point),
    which is a runtime-tracing feature, not a boot-time one.

12. **Everything commits to `main`.** The repository has one branch and the work
    is a sequence of milestones toward an untagged 0.1.0; a branch per slice
    would add merges without adding review, since the plan and this log are the
    review. This changes when 0.1.0 is tagged or when a second person starts
    pushing.

13. **`opis/json-schema` (^2.6) is a dev dependency**, not a runtime one: it
    validates the framework's own output in tests and ships in no release. The
    schemas themselves are plain JSON files in `docs/schemas/`, readable by any
    validator an agent already has.

14. **`lava check` has eight sections, not the plan's six-plus-`map`.** The plan
    describes the section list as "boot + routes + wiring + features + tests +
    map", and `map` arrives in M7 with `lava map` itself — there is nothing to
    check yet, and a section that is always `ok` trains a reader to skip it. The
    sections are also split finer than the plan's phrasing (boot, config, wiring,
    routes, features, env, commands, tests), because "which part of the app is
    broken" is the first question an agent asks and a merged `boot` section would
    make it answer by reading problem codes. Consequence to plan for: `sections`
    is an `enum` in `lava.check/1`, so M7 adding `map` is a payload change that
    needs `lava.check/2` rather than an edit — the frozen-`/N` rule applies to
    the section list like anything else.

### Open finding, not acted on (needs your call)

**`flags()` is descriptive, not enforced: an undeclared flag is silently
ignored.** Verified live: `lava routes --strct` exits 0 and prints the route
table — `routes` declares `['json','all','env']`, and nothing compares argv
against that list. So a typo'd flag is the one class of mistake in this CLI that
fails *silently*, which is the thing the framework's own pillar forbids, and an
agent will typo flags. Same for `lava env --strict` (there is no `--strict` on
`env`; it is a `check` flag) — it runs as if the flag were not there.

Not fixed here because the fix is a product decision, not a slice-3 task:

- **Recommended**: an undeclared flag is `bad_usage` (exit 2), with the
  command's declared list and `lava <cmd> --help` in the fix. The reasoning is
  the same as the bad-port case: it is about how the command was *typed*, not
  about the app, so exit 2 and `unknown_command`'s "you typed it wrong" family.
- **The decision it forces**: `--json`, `--help`, `--quiet` (and `--env`?) are
  accepted by every command today but declared by almost none, so they would
  have to become an implicit global set — and `--env` is the interesting one,
  because it is genuinely per-command in `flags()` but meaningful everywhere
  boot happens. `--` literal-args handling and pack-defined flags would need to
  keep working too.
- **Cheaper alternative**: warn instead of failing (`problems[]` with a
  `severity: warn`), leaving exit 0. Less correct, zero risk of breaking a
  caller that passes an unknown flag on purpose.

No code depends on the current permissiveness, so either option is available
whenever you want it.
