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

## 2026-09-11 — M5 (lava/db)

The plan's acceptance criteria for M5 are "compiler snapshot tests green
driver-free; with sqlite installed: demo CRUD, `lava db:status` applied/pending,
migrate/rollback round-trip; db pack installs standalone". All four are met and
verified against the real binary (see 20). The M4 open finding above is still
open and unacted on.

1. **The pack is split into a driver-free half and a driver-bound half.**
   `Sql/SchemaCompiler` turns a `Table` definition into DDL strings and touches
   no database; `Schema`/`Connection` execute it. So the compiler's tests are
   pure — no PDO, no server, no skip — and they can assert the exact DDL for all
   three dialects, which a live test never could (it can only assert that
   *something* worked). This is what makes "compiler snapshot tests green
   driver-free" a real guarantee rather than a euphemism for "the live tests were
   skipped".

2. **Foreign keys are emitted as table-level `CONSTRAINT … FOREIGN KEY` clauses,
   not inline column `REFERENCES`.** MySQL parses an inline column-level
   `REFERENCES` and then *silently ignores it* — no error, no constraint. A
   schema layer whose FK syntax works on two dialects and quietly does nothing on
   the third is worse than one that lacks the feature, because the failure
   appears months later as orphaned rows. Consequence: `addColumns()` **refuses**
   a referencing column on MySQL (`bad_schema`) rather than emitting DDL that
   does nothing, since `ALTER TABLE ADD CONSTRAINT` on an existing table is a
   different operation the DSL does not offer.

3. **Postgres spells boolean literals `TRUE`/`FALSE`; MySQL and SQLite use
   `1`/`0`.** Found by hand-writing the expected DDL for every type × dialect
   before writing the compiler test, which is the only reason it was found at
   all: `DEFAULT 1` on a `BOOLEAN` column is accepted by Postgres in some
   contexts and not others, and no live SQLite test would ever have exercised it.

4. **`Schema::checkReferences()` catches a reference to a column the target does
   not have — and deliberately not a reference to a table that does not exist.**
   SQLite accepts a dangling FK at DDL time and only fails on the first insert,
   so `foreign key mismatch` surfaced at a point far from its cause. But a
   reference to a table that does not exist *yet* is legitimate — migrations run
   in order and the target may be created by a later one — so the check consults
   the snapshot only for tables that are already there. The asymmetry is the
   point: it catches the mistake that is always a mistake and leaves the case
   that is sometimes correct to the database.

5. **`Connection::lastInsertId()` returns `?string`, not `string`.** `PDO` returns
   `string|false`, and the `false` is a real case (a driver or statement that
   cannot report it). Coercing it to `"0"` would be indistinguishable from a real
   id of zero; `null` says "not known", which is what a caller needs to branch on.

6. **No transaction around a migration.** Wrapping the batch would look tidier
   and would work on SQLite and Postgres — but MySQL commits implicitly on every
   DDL statement, so there the "rollback" would undo only the repository rows
   while the tables stayed. The result would be a migration recorded as unapplied
   but actually applied, and the next run would fail on `table already exists`.
   Instead each migration is recorded the moment it succeeds, so a failure leaves
   everything before it applied *and* recorded, and the run is resumable. The
   `migration_failed` fix text was corrected to say exactly this — it previously
   claimed the batch was rolled back, which was never true.

7. **A migration file returns an instance: `return new class extends Migration
   {…};`.** That removes the two things that make migration discovery fragile
   elsewhere — parsing a class name out of a timestamp, and instantiating a class
   by reflection. Empirically verified before committing to it: re-`require`-ing
   the same file yields distinct instances of the same class entry, so a
   long-lived process that loads the directory twice does not get a redeclare
   fatal and does not share state between instances.

8. **The migration repository is created with the pack's own schema DSL.** So
   `lava db:migrate` is the first real exercise of the DSL: if it works at all,
   the DSL works end to end against a real driver. `has()` then `create()` rather
   than `CREATE TABLE IF NOT EXISTS`, because the DSL has no `if not exists` and
   adding one to the compiler for this single caller would be a feature the
   schema layer does not otherwise offer; losing the race between the two calls
   produces "table already exists", which says what happened.

9. **`db:new` owns the migration's name; the caller supplies only a
   description.** The name *is* the ordering, so `<YYYY_MM_DD_HHMMSS>_<snake>` is
   generated rather than typed — two people on separate branches cannot collide
   on a number they were both told to increment. CamelCase is accepted
   (`CreateUsersTable` and `create_users_table` produce the same file), because
   both are things a person types. Only the `create_<table>_table` shape produces
   a working body; every other description gets a commented template and the
   command says so on the way out, because `add_avatar_to_users` could be read as
   "add a column to users" and guessing which column, of which type, with which
   default is how a generator writes a migration nobody asked for. An existing
   file is refused, never overwritten: the only way to collide is to generate two
   migrations inside the same second, and the file already there may be someone's
   half-written work.

10. **`--batches`, not `--steps`.** The unit is the batch, not the migration, so
    the flag is named for what it counts. `--steps` would invite the reading that
    it counts migrations, which is a different operation this command does not
    offer. A non-numeric value is refused rather than coerced (`--batches=all`
    becoming 0, or 1, or `PHP_INT_MAX`, would silently pick a different amount of
    undo on the one operation where that is hardest to notice).

11. **`db_not_configured` is raised lazily, when a command runs — not at boot.**
    The pack is enabled and the app boots fine without a database; only a command
    that needs one fails. So the pack can be enabled on an app whose database has
    not been created yet, and a missing DSN is reported as "add `DATABASE_DSN`"
    rather than as a boot failure that hides every other diagnostic. The
    `Connection` constructor stores a DSN and nothing talks to a driver until the
    first query.

12. **`PackInfo::configFiles` was declared but nothing loaded it — a genuine core
    gap, found while wiring this pack.** `LoadConfig` loaded only `app` and
    `logging`, so `lava about` would have advertised `config/database.php` while
    `lava config` showed none of its keys. Fixed by extracting the loader into
    `Config\ConfigFile` (shared by both) and adding a `LoadPackConfig` boot step
    between `CheckModules` and `RegisterCoreServices`. `Kernel::STEPS` went from
    12 entries to 13, and `KernelBootTest` asserts the list. The new step
    swallows instantiation and `instanceof` failures on purpose: `WireModules`
    reports those far more precisely, and two reports of one mistake is worse
    than one.

13. **A fixture that named a real pack rotted the moment the pack landed.**
    `missing-pack-app` referenced `\Lava\Db\DbModule` to produce `missing_pack`,
    and creating `DbModule` in this milestone made the class resolve — so the
    problem vanished and two unrelated tests failed with no visible connection to
    the cause. The fixture now names a deliberately fictional pack
    (`lava/search`, which is not one of the five this monorepo builds) and
    `KernelBootTest` asserts that class does not exist, so the fixture fails
    loudly at its own line if it ever does. The lesson is recorded in the fixture
    and in the test, not just here, because the next person to touch it is the one
    who needs it.

14. **`BadUsage::invalidArgument()` — a positional is not a flag.** `db:new`'s
    description was reported as `Invalid value '…' for --description`, but it is a
    positional argument. An agent told that retries with `--description=…` and
    gets a second, different failure. Found by running the real binary and reading
    the output, not by reading the code. The two now report the name the caller
    actually types, and the `context` key differs (`argument` vs `flag`) so a
    consumer can tell which it was.

15. **`db:migrate` and `db:rollback` report partial progress, and the mechanism
    is a callback rather than a re-query.** A run that stopped on its third
    migration has still applied two, and the envelope said `applied: []` next to a
    database with two new tables — the one report an agent must not be given, and
    a direct contradiction of the framework's "the payload's keys are true on
    every exit path" promise. The runner now calls a callback the moment each
    migration is run *and recorded*, and `DbCommand::guarded()` gained a third
    argument for payload keys that are written whether or not the work finished.
    Re-querying the database inside the catch was rejected: the database may be
    exactly what is broken, and a second failure there would replace the
    diagnosis with a worse one. `rollback()`'s `batches` is now derived from what
    actually came off rather than from what was asked for, so it and `rolled_back`
    always agree.

16. **A usage error emitted `"data":[]` — a JSON list where the contract says
    object.** `AppCommand::run()` checked usage *before* seeding the payload, so
    the one envelope most likely to be fed to a schema validator was the one that
    failed it. Found by adding the usage-error invocations to the schema test.
    Fixed by seeding first; two new `JsonSchemaTest` entries pin it. The fix also
    exposed two *schema* inaccuracies — `lava.describe/1` declared `selector` as a
    non-nullable string and `lava.features/1`'s `resolution` branch required
    `flag` to be an object — both of which forbade the nulls a refused invocation
    legitimately carries. The schemas were wrong, not the payloads.

17. **A pack command's contract is `lava.<pack>.<command>/1`, not
    `lava.<pack>:<command>/1`.** `db:status` emits `lava.db.status/1`. The colon
    would make the schema name `docs/schemas/lava.db:status/1.json`, and a colon
    is illegal in a path on Windows — the repository would be uncheckoutable for
    a whole platform. It also keeps the name inside the envelope's own `schema`
    pattern, which admits letters, digits and dots: the previous behaviour
    produced names the envelope's own contract rejected, a contradiction that had
    never been exercised because no pack command existed. The envelope's `command`
    field keeps the colon, because that is the string an agent types. Three
    existing assertions of the old form were updated. **Flagged for you**: this is
    a public naming rule for pack contracts and it is pre-1.0, so it is cheap to
    overrule now and expensive later.

18. **Pack schemas live in the same `docs/schemas/` as core's, and the core
    schema test lists them by name.** The alternative — a per-pack schemas
    directory — would mean the envelope's `$id` prefix resolved to different
    files in different suites, which is worse than the duplication it avoids. But
    the core test runs in an app that enables no packs, so it cannot *enumerate*
    pack commands without depending on a pack's fixtures, which is the coupling
    the packs exist to avoid. It therefore lists the four names explicitly, and
    `packages/db/tests/Schema/DbSchemaTest.php` asserts the same set in the other
    direction — a deleted or stale pack schema fails there. The weak point is the
    list itself: adding a pack means adding its schemas here or the core test
    fails, which is loud rather than silent, but it is a coupling worth naming.

19. **`packages/db/src` was added to `phpstan.neon`'s `paths`.** It was absent
    because the directory had no source in it. Level 8 is clean on the pack, with
    no ignores, no baselines, no `assert()` and no added casts — the five errors
    the first run found were all real (two dead `inTransaction()` guards that
    PHPStan correctly proved unreachable because it treats the method as pure, one
    genuinely unhandled `string|false` return, one imprecise `array` return type
    fixed with `array_values`, and one redundant `array_values` on an already-list).

20. **Verifying "with sqlite installed" needed no privileges and no package
    install.** `apt-get download php8.5-sqlite3` + `dpkg-deb -x` into a temp
    directory yields a working `pdo_sqlite.so`, and `PHP_INI_SCAN_DIR=:<dir>`
    loads it for a process *and every subprocess it spawns* — because `PHP_INI_SCAN_DIR`
    is an environment variable and the CLI test harness forwards the environment
    rather than the command line. So the live tests and the CLI golden tests need
    one variable, and no harness change. The extraction is a local technique, not
    a repository dependency: the tests skip with instructions when no driver is
    available, and the skip message names `PHP_INI_SCAN_DIR` because that is the
    difference between "the parent has it" and "the subprocess has it".

21. **The schema plumbing moved to `Lava\Core\Tests\Support\EnvelopeSchemas`.**
    Core and db now share one prefix resolver rather than two copies. That
    matters more than the duplication: if the two resolved `$id`s differently, a
    `$ref` between a core schema and a pack schema would pass in one suite and
    fail in the other for no reason a reader could see. `JsonSchemaTest` was
    refactored onto it with no behaviour change.

### Open finding, not acted on (needs your call)

**Whether a pack's envelope contracts should be listed by name in the core
schema test** (18). The current compromise keeps the core test pack-agnostic and
the check loud, but it means a new pack edits a core test. The alternatives are
a per-pack schemas directory (rejected: splits the `$id` space) or letting core
reach into pack fixtures (rejected: the coupling the packs exist to avoid). A
third option exists if you would rather: have `lava list` report the schema name
each command claims, so the test can enumerate contracts from *any* app without
knowing which packs exist — which is a small payload addition to `lava.list/1`
and would remove the list entirely. Say the word and it is a twenty-minute change.

### Open finding, not acted on (needs your call)

**The problem-code registry is documentation that nothing checks** (22). The
table in `docs/problem-codes.md` claims "Every code maps 1:1 to one Problem class
and is exercised by a fixture test", and no test reads the file — so a code can
be renamed in a class and the table will go on describing the old name, in the
one document an agent is told to trust. The fix is the same shape as the schema
drift guard: a test that reflects over `LavaProblem` subclasses in both
namespaces, collects `code()`, and asserts the set equals the table's first
column, in both directions.

I have not written it, because doing so *changes what the table is*: it stops
being prose that happens to be accurate and becomes a machine-checked contract,
which means every future problem class must be added to the table in the same
commit or the suite goes red. That is probably what you want given the
agent-first pillar, and it is a fifteen-minute test. But it is a decision about
what the document *is*, so it is yours rather than mine. Say the word and it is
in the next milestone's first commit.

## 2026-09-11 — M6 (lava/validate)

The plan's acceptance criteria for M6 are "demo form route validates; every rule
failure carries field + fix hint; pack installs standalone". The second and third
are met and verified for real: the fixture app's `POST /users` route was driven
through a live `php -S` and curl (five bad fields → a 422 with five problems,
each naming its field, rule, `expects`, and a redacted value where the field
looks secret), and the pack was installed and tested standalone
(`composer install` inside `packages/validate`, its own `vendor/`, **273 tests,
858 assertions, all green**). The first is met in a fixture rather than in
`apps/demo/` — see 30, which is the one deviation from the plan's wording and is
flagged rather than buried. Both open findings from M5 (18, 22) are still open
and unacted on.

23. **The pack's API is static and dependency-free, and `register()` is
    deliberately empty.** A `Validator` is built from a field map that only the
    app knows, so the container cannot hold one: a singleton validator would have
    to be for a specific set of fields, and choosing which fields belong together
    is exactly the decision a handler makes. The module class exists anyway,
    because `app/Modules.php` names the module that owns a feature — without it,
    enabling the pack is a `MissingPack` and `--feature validate` has nothing to
    gate. This is the first pack with no service, no config file and no env var,
    so it is also the first proof that a pack *may* be nothing but an API.

24. **A field's name is the array key in `Validator::of()`, not an argument to
    `Field`.** A field cannot then be declared twice, because PHP array keys make
    the duplicate unrepresentable — there is no check to write and no state to
    get wrong. It also means a `Field` carries no identity, so the same
    `Field::str()->email()` value can back two differently-named fields, and the
    validator's field list and the request body have the same shape.

25. **Fields are optional unless `->required()` says so.** The chain reads as a
    condition on a value that exists — `->max(20)` on an absent nickname must not
    fire, or "optional but short" is inexpressible. Requiring is therefore the
    thing said out loud, which is also the safer default: a forgotten
    `->required()` on a create form produces an empty column, while a forgotten
    `->optional()` on a patch form rejects every request that omits the field.

26. **`min`/`max` are one rule with two modes, and the mode is chosen by the type
    rule at build time.** `min(2)` against the string `'5'` is either "two
    characters" (false) or "the number two" (true), and no inspection of the value
    can tell which the author meant. So `Field::str()->min(2)` builds
    `MinRule::length(2)` and `Field::int()->min(2)` builds `MinRule::numeric(2)`,
    and the `expects` in the problem context says which (`{"bound":2,
    "of":"characters"}`). Three mis-declarations are **refused rather than
    guessed**: a bound on an untyped chain (`untypedBound`), a bound on a boolean
    (`boundOnBoolean`), and a fractional length (`fractionalLength`). Picking a
    meaning would make a field's answer depend on the data — the same rule passing
    or failing for reasons the author never wrote down.

27. **`RuleSet::inspect()` returns a `RuleFailure`, not a bare `RuleViolation`.**
    It is the last point that knows *which* rule stopped the pipeline, and the
    `validation_failed` context has to report `rule` and `expects`. Carrying the
    rule with its own text means no rule has to name itself in its message, and
    the problem is a carrier rather than a second author of the same sentence.

28. **`DeclarationSite` exists because PHP backtrace frames report the CALL site,
    not the definition site.** A mis-declared rule is raised from a constructor or
    a builder method while the app's chain is still on the stack, so the first
    frame outside the pack is the `Field::…->regex(…)` line — the line to edit.
    `predicateFailed()` is the exception: it is raised from `inspect()`, long after
    the chain was built, so a stack walk there lands on the framework's own
    `HandlerInvoker` and blames the wrong file. `CustomRule` therefore captures its
    declaration site when it is constructed and passes it in. `SOURCE_ROOT` is
    `src/`, **not the package root** — the first version used the package root and
    silently skipped a fixture's own controller as "inside the pack", which is how
    a problem came to report `HandlerInvoker.php` even after the capture was
    added. The fix is one path segment and a docblock saying why.

29. **`RegexRule` parses the pattern before anchoring it, and refuses a pattern
    with no delimiters separately.** Two bugs, one shape. (a) Slicing the body as
    "everything between the first and last character" turned `'/abc/i'` into
    `/^abc/$/` and refused a pattern that was fine — and because the rewrite
    happened *before* PCRE saw it, the complaint named a `$` the author never
    typed. The parser now scans backward for the last closer that leaves only
    modifiers after it, and pairs `(`, `[`, `{`, `<` with their own closers.
    (b) `preg_last_error_msg()` reports every PCRE *compile* failure as "Internal
    error"; the real message is only in the emitted warning, so it is captured
    with a scoped `set_error_handler` and restored in a `finally`. A pattern with
    no delimiters is refused by `undelimited()` rather than handed to PCRE, which
    would accept `[` as a delimiter and complain about a modifier instead of
    about the missing delimiter.

30. **The form route lives in the pack's own fixture, not in `apps/demo/` — a
    deviation from the plan's wording, flagged rather than buried.** M6's verify
    line says "demo form route validates", and `apps/demo/` is currently an empty
    directory that M7 explicitly owns ("apps/demo promoted to canonical fixture").
    Building the route there now would mean M7 promoting a directory M6 had
    already half-built, and it would put a pack-specific route in the app that is
    meant to demonstrate the framework. So the route is
    `packages/validate/tests/fixtures/apps/validate-app/`, it is a real form route
    (`POST /users`, exercised over a live `php -S` with both a JSON and a
    form-encoded body), and the pack installs and tests standalone. **If you want
    the demo route in `apps/demo/` instead, say so and it moves in M7** — the
    fixture is written so its controllers could be lifted wholesale.

31. **`LavaProblem::httpStatus()` was added so a pack can declare its own status.**
    `validation_failed` is a 422 and lives in a pack; the alternative is a `match`
    on `code()` somewhere in the HTTP layer, which would make core enumerate the
    codes of packs it has never heard of. The default stays 500, because most
    problems *are* developer faults. The three caller-fault codes override it:
    `route_not_found` 404, `method_not_allowed` 405, `malformed_body` 400,
    `validation_failed` 422. Core's `HttpErrors::forReport()` takes the status
    from the first problem, which is safe because problems that disagree about
    their status agree about being the caller's fault.

32. **`malformed_body` is core, and an EMPTY body is not malformed.** The parsing
    rule is core's (`RequestBody`), and a request that declares a JSON body and is
    not one is refused before routing — the alternative, leaving the parsed body
    null, would present a syntax error as every field being missing, which is a
    confidently actionable answer to a question nobody asked. But an empty body is
    *no body at all*, so it is not a parse error: it reaches validation and gets a
    422 naming every required field. Verified over curl: `-d ''` → 422 with five
    problems; `-d '"just a string"'` → 400 `malformed_body`.

33. **`->email()` and `->uuid()` are instance refinements, not static
    constructors — and this was a real silent-wrong-answer bug, found by a test
    and fixed in the source.** PHP lets a static method be called through an
    instance, so `Field::str()->required()->email()` returned a brand-new field
    and discarded the `required()`: a field declared required that accepted a
    missing value. Demonstrated before fixing (`Field::str()->required()->email()
    ->max(254)` → `[EmailRule, MaxRule]`, `isRequired: false`, empty body
    *valid*). The refinements are now instance methods that replace the type rule
    in place — so `[RequiredRule, EmailRule, MaxRule]`, `isRequired: true`, empty
    body refused — a text refinement on a non-text chain is refused
    (`incompatibleFormat`), and both the unit tests and the fixture's HTTP test pin
    it. Nothing in the repo had called `Field::email()` as an entry point, so the
    change cost two docblocks and one test file.

34. **The cross-field idiom is "pass when the field you depend on is unreadable",
    and the fixture proved it by getting it wrong first.** A rule has two
    outcomes, pass or refuse, and "the field I depend on is not there" is not a
    reason to refuse `ends_at`. The fixture's predicate originally guarded the read
    (`is_string($ends) && is_string($body['starts_at'] ?? null) && …`) and
    returned **false** when `starts_at` was missing — which produced a second
    complaint, `after_starts_at`, about a comparison that never happened, on a
    request whose only mistake was the missing `starts_at`. Caught by the HTTP
    test, fixed in the fixture (`!is_string(...) || …`), and documented as the
    pattern: guarding the read is not enough on its own — the guard has to decide
    what to do about the missing value. There is deliberately no third "not
    applicable" outcome on `Rule`; from the caller's perspective "abstain" and
    "pass" are the same thing.

35. **`UnreadableField`'s fix names the builder method, not the accessor's word
    for the type.** A handler reads `->string('name')` and declares it
    `Field::str()`; the first version interpolated the accessor's word and told the
    reader to write `Field::string()`, which does not exist. A fix that names a
    method that does not exist is worse than no fix at all, because an agent
    follows it and gets a fatal. There is now a small explicit map and a test that
    regexes every `Field::X()` out of the fix text and asserts
    `method_exists(Field::class, 'X')` — the same invariant is asserted over every
    `InvalidRule` factory's fix.

36. **The value in a problem context is redacted on the FIELD NAME, and the one
    implementation is shared.** A 422 body is logged, echoed into terminals and
    pasted into bug reports, so `password` never appears in one — the decision is
    about the field, not the value, because "does this look secret" is not a
    question a validator can answer. `ValidationFailed::reportable()` is public and
    `UnreadableField` uses it, so a redaction policy that lived in two places
    cannot hide a password in one report and print it in the other. Arrays become
    their JSON text and non-encodable values become their type name, so the context
    is always JSON-safe. Verified live: `password` → `"<redacted>"`, and the
    offending request body never reaches `context` for `malformed_body`.

37. **A fixture app needs the harness's autoloader to run under a real SAPI.** The
    first live `php -S` run returned 500 `bad_handler` for all three routes: the
    fixture has no `composer.json`, so nothing maps `App\` to its `app/` directory.
    That is not a defect — it is why `packages/core/tests/Support/fixture-autoload.php`
    exists and why `lava serve` prepends it — but it is worth recording because the
    in-process `TestApp::boot()` hides it by registering the same autoloader by
    hand. The correct live invocation is documented in
    `docs/packs/lava-validate.md`. `TestApp::bootFixture()`/`fixturePath()` are
    hardcoded to core's fixtures directory, so a pack's fixture must use
    `TestApp::boot()` with an explicit path; the fixture's `App\` class names are
    also distinct from core's, since two fixtures declaring the same class is an
    uncatchable redeclare fatal for the whole process.

38. **The test helper `Inspect::refuses()` takes a documented `$mayQuote` escape.**
    Its assertion is that a refusal's text never quotes the value it refused — the
    property that keeps a rule's message about the shape of what was wanted rather
    than a quotation of what arrived. One legitimate exception: a message that
    prints a bound (`9223372036854775807`) can coincide with the value being
    tested, and the check is sound but not complete (it only fires for strings
    longer than three characters). Rather than weaken the assertion, the opt-out is
    explicit at the one call site that needs it.

39. **The M6 additions raise the stakes on open finding 22 (the unguarded
    problem-code registry).** The table in `docs/problem-codes.md` gained four rows
    (`malformed_body`, `validation_failed`, `invalid_rule`, `unreadable_field`) and
    still nothing reads the file, so four more codes can now drift from their
    classes in silence — in the one document an agent is told to trust. The
    proposed fix (a test reflecting over `LavaProblem` subclasses and asserting the
    code set equals the table's first column, both directions) is unchanged and
    still a fifteen-minute test. It remains unacted on because it changes what the
    table *is*, which is your call rather than mine.

40. **A pack's standalone install is a verification, not an artifact: its
    `vendor/` is ignored and its `composer.lock` is not tracked.** M6's third
    criterion is "pack installs standalone", and the only honest way to check it
    is to actually do it — `cd packages/validate && composer install` creates a
    real `vendor/` with real `nyholm/psr7`. Committing it would put a second,
    independently-versioned copy of the framework's dependencies in the tree;
    committing the lock it generates would pin a *library's* dependencies, which
    is the opposite of what a library wants (its CI should test the range it
    claims to support, and the root `composer.lock` already pins the monorepo's
    dev environment). So `.gitignore` gained `/packages/*/vendor/` and the
    generated lock was deleted — `packages/db` already had neither, and this
    makes the two packs agree. The install is reproducible from the documented
    command in `docs/packs/lava-validate.md`.

41. **The fixture `.env` negation was generalized to every pack, not just core.**
    The rule exists because a fixture app's `.env` is *test data*: ignore it and
    CI boots a different fixture than a local run. It was written as
    `!packages/core/tests/fixtures/**`, which was correct while core was the only
    pack with fixtures and quietly wrong the moment a second one had any — the
    validate fixture has no `.env` today, so nothing was broken, but the next
    pack to add one would have lost it in silence. It is now
    `!packages/*/tests/fixtures/**`, and the behaviour was checked by creating
    the file, watching `git status -uall` list it, and deleting it again.

## 2026-09-11 — M7 slice 1 (`lava map`, AGENTS.md, `lava check` integration)

42. **The map is a list of DECLARATIONS, and the fingerprint covers the facts, not
    the rendered file.** A flag's *value* depends on the environment; a flag's
    *existence* does not. So the document records route paths, service ids, flag
    names, and env var names, and never a resolved state — which is what makes
    `lava map` write identical bytes under `--env=dev` and `--env=prod`. Hashing
    the facts rather than the Markdown is the same idea one level down: a change
    to the renderer (a new column, different wording) must not make every app's
    map stale, because nothing about those apps changed. Only a change to what the
    app declares does, and then the file really is out of date. Both properties
    are pinned by `tests/Unit/ProjectMapTest.php`, including the negative one: two
    boots in different environments must produce the same fingerprint, asserted
    alongside `assertNotSame` on the two `App::$env` values so the test cannot
    pass by booting the same environment twice.

43. **`ProjectMap::relative()` decides the app root FIRST, and this was a real bug
    found by a test, not by reading.** The original rule was "dependency code
    first" — a regex looking for a `vendor/<vendor>/<pkg>/` or `packages/<pkg>/`
    marker anywhere in the path — on the reasoning that an installed app holds
    core under its OWN `vendor/`, so the marker has to win over "inside the app
    root". The flaw is that this repo's fixtures live at
    `packages/core/tests/fixtures/apps/ok-app/`, so the app's own
    `app/Services.php` rendered as `core:tests/fixtures/apps/ok-app/app/Services.php`
    — and then the same app, booted from a temp copy, hashed differently. The
    fingerprint mismatch is how it surfaced. The rule is now: inside the app root
    is the app's own, UNLESS the remainder's *first* segment is `vendor` or
    `packages`, which is composer's layout at an app root and the only place it
    can turn the app's own tree into a dependency's. A deeper directory the app
    happened to name `packages` stays the app's. Two things worth recording from
    fixing it: (a) the earlier docblock's example for "the last marker wins" was
    badly chosen — `vendor/acme/thing/vendor/other/src/X.php` is structurally
    `vendor/other/src/…` at the inner marker, i.e. package `src`, so the code was
    right and my test expectation was wrong; a case that really exercises the rule
    needs a package segment at the inner marker
    (`vendor/acme/thing/vendor/other/pkg/src/X.php` → `pkg:src/X.php`); (b) greedy
    `.*` does give "the last marker that fits", because a longer prefix is a later
    match, which is worth stating precisely rather than as a slogan.

44. **The hash marker is line 1, matched with `\A`, and nothing else.** The
    alternative considered was scanning a window of the first N lines, which would
    make the verdict depend on where a reader put a blank line, and would have to
    reason about whether a hash inside a fenced example counts. `MapDocument`
    matches the first line exactly, so "where is the marker?" has one answer.
    `staleness()` reports a file that exists with no marker as `stale` with the
    placeholder `(no marker)`, not as `missing` — it is there and it is wrong, and
    telling the reader to create a file that is in front of them would be a worse
    lie than the placeholder.

45. **The file list comes from the filesystem and the pack manifests, not from what
    loaded.** Reading it off `Config`'s provenance would be wrong: a pack's config
    file is only loaded when the pack's flag is on, so the list — and therefore
    the fingerprint — would move with the environment, breaking decision 42 on a
    deploy that changed nothing. A file that EXISTS is a fact; a file that was
    read is a resolution.

46. **`lava.check` went to `/2`, `lava.check/1` was DELETED, and per-command
    contract versions moved into `Envelope::VERSIONS`.** Decision 14 pre-committed
    to the bump when M7 added `map` to the `sections` enum, so this is that
    promise kept rather than a judgement call. Deleting `/1` rather than keeping
    it is a judgement call, and the reasoning is that nothing can emit it and
    nothing pinned it — 0.1.0 is not tagged — so a schema file no code can produce
    is a document that lies about what exists, and it would also fail
    `testEveryCoreCommandHasASchema` (which derives the expected names from the
    command registry). The version map exists so the `/N` has exactly one home:
    `Envelope::schema()` is the only place a schema name is built, and
    `JsonSchemaTest` derives its expectations from that same method, so a bump
    cannot be half-applied. The shared vocabulary (`lava-envelope/1`) was NOT
    bumped: its shape did not change, only two prose descriptions, and it is one
    file every command's schema references — bumping it would have been a
    different contract's change wearing this one's clothes.

47. **`stale_map` is one code with three `why` values, and it is Warn.** Three
    codes would force a consumer to branch on a diagnostic detail when the message
    ("your map is not accurate") and the fix (`Run: lava map`) are identical in all
    three cases — the same test that keeps `missing_test_runner` and
    `bad_test_report` apart, applied and reaching the opposite conclusion.
    `why: unwritable` rides on this code rather than getting its own because a
    failed write leaves "the map is not accurate" exactly true, and the registry
    is a public contract that is not worth growing by one for an environment
    condition — a read-only checkout — that no consumer would branch on
    differently. It is Warn, not Fatal, because a documentation lag is not a
    broken app; `--strict` is where it becomes a build failure, the same door
    `missing_env_var` uses.

48. **`lava check` verifies a map you HAVE; `lava map --check` answers whether one
    exists.** The guard is `if ($document->exists())` around the map sweep. An app
    that chose not to ship `AGENTS.md` has no drift to catch, and a warning nobody
    can act on is noise in the one list an agent is supposed to read top-down. The
    two commands therefore legitimately disagree on an app with no map, and both
    are right — the disagreement is the design, not a bug, and it is pinned by
    `testCheckDoesNotDemandADocumentTheAppNeverHad`. The comparison itself lives in
    ONE place (`ProjectMap::staleness()`) precisely so the two doors cannot start
    disagreeing about the freshness verdict.

49. **`lava check`'s ordering rule became severity-major: fatal-runnable, fatal,
    warn-runnable, warn.** The old rule was runnable-only ("fixes that are a
    command need no judgement, so they come first"), and `stale_map` broke it: its
    fix IS a runnable command, but it is a warning, so a stale comment would have
    been hoisted above "your route does not compile". The original intuition was
    right and survives as the *minor* key, inside a severity rather than across
    one. `usort` is stable from PHP 8.0, so discovery order is preserved within
    each of the four groups. Verified live on `env-app`, where a fatal
    (`missing_test_runner`) sorts above two warns and `stale_map` (runnable) ahead
    of `missing_env_var` (not) — so all four groups are reachable, not just the
    two the fixtures happened to produce before.

50. **`lava map --check` exits 1 on a stale map — a real bug in my own new code,
    found by running the binary.** `IO::emit()` fails only on fatals, and
    `stale_map` is a Warn, so a verification command answered "no" with exit 0,
    which is useless to a build. The fix passes `failed: true` explicitly on the
    stale path, with the comment saying why. The same applies to a failed write:
    exit 0 there would tell a caller its `AGENTS.md` was written when it was not.
    The related contract, now stated in the schema: **`found` and `fresh` describe
    the file the command FOUND, read before it acts; `written` reports what it then
    did.** So a first `lava map` reads `found: null, fresh: false, written: true` —
    "nothing current was here; there is now". This bit me as a test failure before
    it bit anyone else, which is the argument for documenting it in the schema an
    agent reads rather than only in the code.

51. **The framework reference's snippets are constants in core, and the test that
    covers them checks TRUTH, not bootability.** If the reference were a
    hand-maintained Markdown page it would be exactly the kind of parallel doc the
    framework bans everywhere else, and it would drift silently because nothing
    would ever read it. Held in `Lava\Core\Map\FrameworkReference`, every PHP
    snippet is parsed with `token_get_all(…, TOKEN_PARSE)`, every class it names is
    checked to exist, and a curated `TAUGHT` list of class/method pairs is asserted
    in BOTH directions — the method must exist AND its name must appear in a
    snippet — so the list cannot rot into a record of an API the reference stopped
    mentioning. The honest limit, recorded in the test's docblock: core's suite must
    run with only core installed (`cd packages/core && composer install`), so the
    existence check is scoped to `Lava\Core\` names, and the `modules` snippet's
    `Lava\Db\DbModule` is checked by the db pack, which owns that class. Also
    unproven by design: that a snippet is a *complete working file* — they are
    minimal on purpose. The working-app proof is the `lava/app` skeleton (slice 2)
    and `lava check` is what verifies it.

52. **`ProjectMapTest` and `MapCommandTest` work in temp directories, and the CLI
    test is the sharpest available proof of portability.** `lava map`'s default
    mode writes `AGENTS.md` into the app directory, so an in-process test against
    `ok-app` would mutate a tracked fixture — the same reason the schema test
    invokes `map --check` and not `map`. Working on a copy also makes the
    portability assertions real rather than notional: the generated file must not
    mention the directory it was generated in, and a machine-specific path leaking
    in shows up as the temp directory's own name. The strongest assertion available
    is in `testTheSameAppHashesTheSameFromAnyDirectory` — the app booted in-process
    from the fixture and the app booted by the real binary from a temp copy produce
    the same fingerprint, which is decisions 42 and 43 stated as one string
    comparison.

Verified this slice by running the real binary, not by reading code: `lava map`
writes a 322-line `AGENTS.md` with a leading marker; `--check` fresh → exit 0;
drift a route path → exit 1, `why: stale`, both hashes in `context`,
`source: {file: AGENTS.md, line: 1}`; regenerate → fresh; absent file →
`lava check` reports nothing while `lava map --check` reports `why: missing` with
exit 1; `lava check` on a stale map → `status: ok`, one `stale_map` warn, exit 0,
and exit 1 under `--strict`. Full suite: **704 tests, 3444 assertions, 26
skipped**. PHPStan level 8: no errors.

### Open finding, not acted on (needs your call)

The M7 addition sharpens finding 22 (the unguarded problem-code registry) again,
for the same reason decision 39 gave: `docs/problem-codes.md` gained a
`stale_map` row and still nothing reads the file, so the document an agent is
told to trust can drift from the classes in silence. The proposed fix is
unchanged and still small — a test reflecting over `LavaProblem` subclasses and
asserting the code set equals the table's first column, in both directions. It
remains unacted on because it changes what the table *is*, which is your call.

## 2026-09-11 — M7 slice 2 (`lava/app` skeleton, pre-generated map, CI enforcement)

53. **Handlers take dependencies as METHOD parameters, and the skeleton's first
    draft got this wrong.** `HandlerInvoker` constructs a handler class with
    `new $class()` and no arguments; services arrive as typed method parameters.
    My skeleton shipped `HelloController(private readonly Greeter $greeter)`,
    which boot refused with `bad_handler: the constructor has required
    parameters` — correctly, and with the fix in the message. This is the one
    place a competent PHP developer's instinct (constructor injection) is wrong
    here, so it is worth recording that the framework was right and the skeleton
    was wrong, not the reverse. The rule was ALREADY documented — `docs/
    conventions.md` §"The handler contract" says "no required parameters —
    dependencies arrive as typed method parameters" — and I had written the
    skeleton without reading it. Nothing in the docs needed changing. Fixed by
    moving `Greeter` to a method parameter, and the wrong story in
    `app/Routes.php`'s comment ("the controller itself comes from the
    container") was corrected in the same pass: it is built with `new`.

54. **`bin/lava`'s autoloader discovery is cwd-first, and that is not a heuristic
    — it is the answer.** `Console::main($argv, ?string $appDir = null)` takes
    the app directory from `getcwd()` and from nowhere else, so `<cwd>` IS the
    app; the two can never legitimately disagree. The four candidates are
    `<cwd>/vendor/autoload.php`, then `$GLOBALS['_composer_autoload_path']` (set
    by composer's own bin proxy — the only candidate that survives
    `vendor/lava/core` being a SYMLINK, because `__DIR__` resolves through the
    link to the package's real home), then the two `__DIR__`-relative forms. The
    old order was `__DIR__`-first, which is how `packages/app` — a path-repo
    install with `vendor/lava/core` symlinked to `../core` — loaded the
    MONOREPO's autoloader while booting the app, and reported `bad_handler: the
    class does not exist` for a class sitting right there in the app's own
    `app/`. Found by running the real acceptance path, not by reading code.

55. **`app/Commands.php` was missing from `ProjectMap::configFiles()`,** so the
    generated map's Files section — "the framework reads a fixed set of paths" —
    omitted a path the framework does read. A small real gap, found while
    reading the skeleton requirements. Added, which is also why the committed map
    lists `app/Commands.php` even though the skeleton ships no such file: every
    path in that table is optional, and the table is a list of what you MAY
    write.

56. **The skeleton ships `config/.env.example`, not `config/.env`,** so the
    generated map's Environment section reads 0 on a fresh install. That is
    accurate rather than a bug: the app as installed reads no environment
    variables. It goes stale the moment you `cp config/.env.example config/.env`
    — which is the first thing the README tells you to do, and a clean
    demonstration of what the staleness hash is for. Committing a real `.env` was
    not an option: `.env` is gitignored repo-wide, and a template whose whole
    point is that the real environment beats the file should not ship one.

57. **The skeleton ships a pre-generated `AGENTS.md` (R2), and the claim was
    verified by diffing two real installs rather than asserted.** A copy of
    `packages/{core,app}` was installed in a temp directory with
    `COMPOSER_MIRROR_PATH_REPOS=1` — which forces composer to COPY the path
    repository instead of symlinking it, the closest available stand-in for a
    packagist install — and `lava map` run there produced a file byte-identical
    to the committed one, from a different app directory and a different vendor
    layout. That is exactly what slice 1's portable-path work enables: the
    document describes the app, not where the app lives. The full M7 acceptance
    chain was then run in that same install: `lava check` green; edit a route →
    `lava check` warns `stale_map` with exit 0, and exit 1 under `--strict`;
    `lava map` → green again.

58. **The app's `composer.lock` is gitignored, deliberately.** The repo's
    convention is that only the root lock is tracked, but there is a stronger
    reason here: a lock generated in this monorepo pins `lava/core` to the
    `../core` PATH repository, and `composer create-project lava/app` has to
    resolve `lava/core` from packagist — so the skeleton must ship no lock at
    all. The rule is in `.gitignore` next to the reason, because the next person
    to run `composer install` in `packages/app` will generate one.

59. **The `../core` path repository stays in the skeleton's `composer.json`, and a
    README records why.** A published `lava/app` must not carry it — verified:
    with `../core` absent, composer hard-fails with "The `url` supplied for the
    path (../core) repository does not exist" rather than falling back to
    packagist. The plan anticipated this at its own line 317 ("create-project
    from path repos is finicky pre-packagist — skeleton tested via copy+install;
    create-project validated once published"), so the in-tree path repo is the
    prescribed arrangement and the resolution belongs to M9. The README says so
    where a reader will find it, rather than leaving it as folklore.

60. **CI's `isolated-install` job was RED, and had been since M0 — a real
    pre-existing bug, found by running the step.** `composer validate --strict`
    fails on every pack: each requires `lava/core: @dev`, composer calls that an
    unbound constraint and warns, and `--strict` promotes warnings to a non-zero
    exit. Reproduced under `bash -e` exactly as Actions runs it — step exit 1,
    for all four packs. Fixed by dropping `--strict`; plain `validate` still
    fails on a real error in the file, which is what the step is for. The
    constraint was NOT changed: `@dev` is what a path repository needs before the
    packs are on packagist, so "fixing" the warning would have broken every
    install. Worth recording that a CI job can be red for three milestones
    without anyone noticing, because nothing local runs the workflow.

61. **A `skeleton` CI job now enforces R2 on every push.** Fresh copy of
    `packages/{core,app}` into a temp directory, `composer install`, then
    `lava map --check` (R2 stated on its own line) and `lava check --strict`. The
    LAYOUT is the point: `vendor/lava/core` is a symlink to the sibling `core`,
    which is precisely the shape that triggered decision 54 — so this job fails
    if that regression ever returns. Both jobs were verified green by running
    them locally as written, not by reading them.

62. **The autoloader fix has a regression test, and the test was proven to
    discriminate.** `testTheAppsOwnAutoloaderWinsOverTheOneBesideTheBinary`
    builds a temp app whose `Site\Handler` is reachable ONLY through the app's own
    `vendor/autoload.php` — the harness's `auto_prepend_file` maps `App\` and
    nothing else, so no fixture machinery can mask the result. I then patched
    `bin/lava` back to the old two-candidate `__DIR__`-relative order and re-ran
    it: the test FAILS (exit 1, not 0). Restored, it passes. A regression test
    that has never been seen to fail is a guess.

63. **A red test suite is not a framework problem — and I nearly "fixed" a
    documented decision.** While probing the skeleton, `lava check` on an app with
    a failing test reported `status: failed` with `problems: []`, which looked
    like a wart: an agent reading only `problems` would conclude nothing was
    wrong. It is deliberate, and `docs/conventions.md` says so in as many words —
    "A red test suite is not a problem... the framework does not pronounce on code
    it never read" — with PHPUnit's own message carried in `data.tests.cases[]`
    (file, line, type, message) as the better fix hint. No change made. Logged
    because the lesson is the one that repeats: read the conventions before
    treating output as a defect.

64. **The readable-path list IS hashed, and that is correct — but it means a
    framework change can invalidate an app's committed map.** Adding
    `app/Commands.php` to `configFiles()` moved `ok-app`'s fingerprint from
    `1820933a07fe656c` to `27c9bf5f2fa35955`, which I checked rather than assumed.
    At first glance that looks like it contradicts decision 42 ("a change to the
    renderer must not make every app's map stale"), but it does not: the Files
    table is part of what the document SAYS, so a document generated before the
    change is genuinely no longer what `lava map` would write — and the hash
    exists to answer exactly that question. If the path list were excluded, an
    old document would report `--check` green while differing from a fresh
    generation by a whole table row, which is the one thing the hash must never
    do. The distinction decision 42 draws is between *formatting* (a column
    order, different wording — not hashed) and *facts* (which paths are read —
    hashed). Pack manifests contribute their `config_files` to the same list, so
    enabling a pack moves the hash too, which is intended: the app really did
    change.

    The consequence to keep in view: the `AGENTS.md` the skeleton ships is
    accurate for the framework version it ships WITH. A published app that
    resolves a newer `lava/core` could therefore start life with a map that is
    stale by one table row. The new `skeleton` CI job (decision 61) is what keeps
    the in-tree claim honest across framework changes, and `lava check` reports
    `stale_map` immediately — a warn, with `Run: lava map` as its fix — so a user
    in that position is told what to do rather than left with a silently wrong
    document. Not worth engineering around further; recorded so the next person
    to change the readable-path set knows it invalidates every committed map and
    that the skeleton's must be regenerated in the same commit.

Verified this slice by running the real binary and the real CI steps, not by
reading code: `lava check` in `packages/app` green (9 sections, 5 tests, 10
assertions); the committed `AGENTS.md` byte-identical across a monorepo checkout
and a copied install; the M7 acceptance chain green in that install; the new
`testTheAppsOwnAutoloaderWinsOverTheOneBesideTheBinary` shown to fail against the
old discovery order and pass against the new; both CI jobs green when run as
written. Full suite: **705 tests, 3468 assertions, 26 skipped**. PHPStan level 8:
no errors.

The two open findings from slice 1 (decisions 18 and 22, and the restatement of
22 at the end of the slice-1 section) remain unacted on and still need your call.
Nothing in this slice changed their shape.

## 2026-09-11 — M7 slice 3 (`apps/demo` promoted to the canonical app)

65. **Middleware must be registered in `app/Services.php`, and listing it in
    `app/Middleware.php` is not enough.** The demo's first boot failed with
    `service_not_registered` for `App\Http\RequestIdMiddleware` — I had declared
    it as global middleware and never registered it. This is decision 53's shape
    again, one layer out: a handler class is built with `new`, but a middleware
    class is *resolved from the container* at request time, so a name that was
    never registered is a boot problem rather than a 500 on the first request
    that hits it. `BuildRouter`/`ValidateWiring` catch it, and the fix in the
    message names the exact `$c->singleton(…)` line to add. Worth recording that
    the framework was right and the app was wrong for the second time in two
    slices, and that the `ok-app` fixture's own comment had said so all along:
    "Middleware is resolved from the container at request time — register it like
    any other service (validated at boot by BuildRouter)."

66. **A real defect in `ProjectMap::configFiles()`, found only because the demo
    finally loaded a pack that declares a config file.** `PackInfo` names a
    config file as a STEM — `configFiles: ['database']` — and `LoadPackConfig`
    reads `config/{$name}.php`; but the Files table rendered the stem verbatim.
    The demo's generated map therefore listed BOTH `config/database` (a path that
    cannot be opened) and the real `config/database.php` the glob had already
    found: two rows, one file, and the reader left to work out which one they
    were allowed to edit. Fixed by appending the extension where the manifest is
    read. The defect survived the entire milestone that built `lava map` because
    **no fixture app loaded a pack declaring a config file** — every fixture
    either had no packs or a pack with an empty manifest. The regression test
    `testAPackConfigFileIsListedOnceUnderItsRealPath` needed a fixture that did,
    so `module-app`'s `DemoPackModule` gained `configFiles: ['database']` and the
    fixture gained the matching `config/database.php`. The test was then proven
    to discriminate, not assumed: reverting `$files[] = 'config/' . $name . '.php'`
    to `$files[] = 'config/' . $name` makes it FAIL ("Failed asserting that an
    array does not contain 'config/database'"), and restoring the fix makes it
    pass. A regression test that has never been seen to fail is a guess.

    The consequence, measured rather than reasoned: the demo's fingerprint moved
    `dc2fb06f28a274bd` → `1aacbbf3666c1401`, and the committed `AGENTS.md` had to
    be regenerated in this commit. `packages/app`'s map did NOT move — verified by
    running `lava map --check` there, which reports `packs: 0`: the skeleton
    declares no packs, so the pack-manifest loop never runs and its facts are
    unchanged. That is decision 64's rule holding exactly as stated — a change to
    what the document SAYS invalidates the maps that say it, and nothing else.

67. **The demo's two app commands share an abstract base, `AppTaskCommand`,
    mirroring the framework's own `DbCommand`.** Both need a typed
    `TaskRepository` out of the container, and `Container::get()` returns `mixed`
    — there is no typed accessor, deliberately. The framework's answer to that is
    `InvalidConfig::wrongService($id, $expected, $got)`, so the demo uses the
    framework's answer rather than an `instanceof` check with its own wording or
    an `assert()`. The base class also sets `pack(): 'app'`, which is what makes
    `lava list` group the app's own commands separately from core's and db's.
    Chosen over a trait and over repeating the four lines in each command,
    because the framework already demonstrates the base-class form and the demo's
    job is to look like the framework.

68. **`app:stats` counts in PHP, and that is the honest answer rather than a
    shortcut.** The query builder has no aggregate verb, and `select('COUNT(*)')`
    is actively broken: `Dialect::quote()` leaves `*` bare but quotes every other
    segment, so it compiles to `"COUNT(*)"`. I first wrote the overdue count as
    `scalar(select('id')->where(…))` — caught by reading `Connection::scalar()`,
    which returns `(array_values($row)[0] ?? null)`, i.e. the FIRST COLUMN OF THE
    FIRST ROW: an id, not a count. Counting four rows in PHP is correct at demo
    scale and needs no new framework surface; `Connection::query()` is the
    documented raw path when a real app outgrows it. The docblock says so where
    the next reader will look, and the same reasoning is in the demo's README —
    the alternative was a canonical example whose numbers were quietly wrong.

69. **The CSV export test parses instead of string-matching, and the first
    version of it failed for the wrong reason.** `fputcsv` encloses any field
    containing a space, so the row is `7,"Ship it",0,2026-12-31,…`. That is
    correct CSV. My assertion (`assertStringContainsString('Ship it,0,…')`) was
    asserting PHP's quoting rules rather than the data, so the TEST was fixed and
    the app was not: `str_getcsv($lines[1], escape: '')` and assert on parsed
    values. Fighting `fputcsv` would have meant hand-rolling CSV in the canonical
    app. The same file spells a boolean as `'1'`/`'0'` rather than casting,
    because `fputcsv` writes `false` as `''` — a distinction the test now states
    out loud ("a boolean has to be spelled out").

70. **The skeleton's `app/Modules.php` docblock was wrong about the gate flag,
    and correcting it produced a second finding.** It claimed a pack entry
    "requires the matching `Feature::define('db', …)` in `config/features.php`
    plus `composer require lava/db`." Both halves are wrong: `CollectFlagDefinitions`
    auto-defines each module's gate as `Flag::on()` from `app/Modules.php`, and
    `absorbDefine` explicitly REJECTS a `define` entry for a pack's flag ("Flag
    'db' is the gate for pack lava/db and is defined by the pack itself"). So the
    documented instruction was not merely unnecessary — following it is a boot
    problem. The correction names the two things a pack really takes (the ref
    line and `composer require`) and points at `'set'` for turning one off.

    Then I verified the correction instead of shipping it on reading, by adding
    `'db' => Flag::off()` to the demo's `set` section and booting. It does NOT
    cleanly disable the pack: `app/Services.php` registers `TaskRepository`, which
    type-hints `Lava\Db\Connection`, so with the pack off the app boots to
    `service_not_registered` naming that file and line. That is the wiring
    contract working as designed — with no auto-wiring there is nothing to fall
    back to, so a dangling reference is a boot problem with the line to fix rather
    than a 500 on request N+1 — but it means "turn the pack off with one config
    line" is only true for an app that does not use it. The docblock now says
    that too. Reverted the demo's `set` section immediately; `lava check --quick`
    green again, `problems: []`.

71. **`pkill -f "lava serve"` killed my own shell.** The pattern matched the
    shell's own command line, so the shell exited 144 along with the server. Fixed
    by reading pids from `ps aux` and issuing `kill -TERM <pids>`, then verifying
    the port was dead with `curl --max-time 2`. Recorded because the reflex is
    wrong in exactly the situation it feels most useful — cleaning up a dev
    server at the end of a run — and the failure looks like the agent being
    killed rather than like the command being wrong.

72. **A `demo` CI job now runs the README's quick start for real.** Fresh copy of
    `apps/demo` beside `packages/{core,db,validate}` (the three path repositories
    its `composer.json` declares), `composer install`, then `lava db:migrate`,
    `lava app:seed`, `lava app:stats --json`, then `lava map --check` and
    `lava check --strict`. The migration step is the one that earns its place: the
    suite runs against `sqlite::memory:`, so nothing else in CI exercises the
    documented on-disk DSN or the `var/` directory. Verified by replicating the
    whole job locally from a fresh copy — install, migrate (1 migration, batch 1),
    seed (4 rows), stats (`{"total":4,"open":4,"done":0,"overdue":0}`),
    `map --check` current, `check --strict` green with 11 tests / 47 assertions,
    every step exit 0. `map --check` passing in a `/tmp` app directory is also a
    live proof of slice 1's portability property: the committed map is accurate
    from a path it was never generated in.

73. **`docs/problem-codes.md` now states that app-owned codes are not in the
    registry.** The registry catalogues the FRAMEWORK's codes, and the demo raises
    `task_not_found`, which belongs to the app. The section explains why the split
    exists (core cannot write "Run: `lava app:stats --json`…" — that sentence is
    only true in one app), that what an app inherits is the shape rather than an
    entry, and that an app adding a row to the table would be a bug. Added because
    a reader who greps the table for a code they saw in a response and finds
    nothing needs to know that absence is by design, not a gap in the docs.

Verified this slice by running the real binary and the real CI steps, not by
reading code: the demo boots, migrates, seeds, serves and passes its suite
(11 tests, 47 assertions); a real-HTTP smoke test confirmed `X-Request-Id` on
every response, both validation problems in one 422 with fix text, a real
`task_not_found` 404, and correctly quoted CSV; the flag gate was proven over
HTTP and over the CLI (`LAVA_FEATURE_TASKS_CSV_EXPORT=off` removes `tasks.export`
from `lava routes`, and `lava features resolve` shows the full three-layer trace);
the new regression test was shown to fail against the old rendering and pass
against the fix; the new CI job was replicated end to end from a fresh copy; and
`packages/app`'s committed map was confirmed still current (`packs: 0`) rather
than assumed unaffected. Full suite: **706 tests, 3756 assertions**. PHPStan
level 8: no errors. `lava check --quick` on the demo runs in 0.02–0.03s against a
budget of under 2s.

The two open findings from slice 1 (decisions 18 and 22) remain unacted on and
still need your call. Nothing in this slice changed their shape.

Environment note: no PDO driver is installed system-wide on this machine, so the
demo's suite and the CI replication above were run with an extracted `sqlite3.so`
+ `pdo_sqlite.so` on `PHP_INI_SCAN_DIR` — no sudo, no system change. The plan's
prerequisite is `sudo apt install php8.5-sqlite3`; CI needs nothing, since
`ubuntu-latest` + `setup-php` ships `pdo_sqlite`.

## 2026-09-11 — M8 slice 1 (`lava/view`: Twig with strict defaults and a real problem surface)

74. **`Router` and `UrlGenerator` are container services, registered by core's
    `BuildRouter`.** `ViewModule` needs the generator for `url()`, and it cannot
    have it any other way: a module's `register()` runs in `WireModules`, which
    is BEFORE `BuildRouter`, so the router does not exist yet. The app cannot
    register them either — `WireAppServices` also runs before `BuildRouter`, and
    the app never sees the `Router` at all. The alternative I rejected was
    having `ViewModule` implement `ProvidesRoutes` with an empty body purely to
    capture the router as an argument: a trick that works and that the
    framework's philosophy forbids, because a reader of `Modules.php` would then
    be reading a lie. So core registers both ids itself, at the end of
    `BuildRouter::run()`, and the order is load-bearing in two directions: after
    `app/Services.php` (so an app that registered these ids gets a
    `duplicate_service` naming both sites rather than silently losing its own)
    and before `ValidateWiring` (so anything depending on them is resolved and
    checked at boot). A pack's factory may therefore depend on `UrlGenerator`
    even though the pack's `register()` ran earlier — the closure is called at
    `ValidateWiring` time, not at register time. `UrlGenerator` is a singleton,
    not a factory: it is stateless, and a second instance would be a second
    thing to keep in step with the router.

75. **Three golden service counts moved 10 → 12, and they stay asserted rather
    than derived.** `CheckCommandTest` (two tests) and `MapCommandTest` (one).
    The numbers are literals on purpose — the test's job is to notice a count
    that changed without anyone deciding it should, which a computed count could
    never do. The comment added next to one of them says so, so the next person
    to see it fail knows it is a decision point rather than a chore.

76. **Twig has no default extension, so the pack normalizes the name.** Found by
    driving the loader directly rather than by reading Twig's docs:
    `new FilesystemLoader($dir)->exists('page')` returns `false` while
    `page.twig` sits right there, because the loader looks for exactly the name
    it is given. Every template reported `template_not_found` before this.
    `ViewRenderer::normalize()` appends `.twig` when it is missing, which is one
    rule rather than two ways to do a thing, and it matches the convention the
    framework already uses for a file a package owns (`PackInfo` names a config
    file as `'database'`; the loader supplies `.php`). The pack owns `.twig` the
    same way.

77. **Three defects in the missing-template path, all found by writing the test
    rather than by reading the code.** (a) `templates()` stripped `.twig` from
    the names it listed while the message's subject line named
    `does/not/exist.twig` — one message spelling the same file two ways, and a
    list whose entries were not the strings `render()` was documented to take.
    (b) `TemplateNotFound::of()` appended `.twig` to a name that already had it,
    so the fix told its reader to create `does/not/exist.twig.twig` — a file the
    loader would still not find. (c) `$listed` (the `Available: …` sentence) was
    computed and then never interpolated, so the message listed nothing while
    the fix said "call render() with one of the names above". All three are
    fixed and each now has an assertion, including a `assertStringNotContainsString('.twig.twig', …)`
    that would have caught (b) on its own.

78. **A `LavaProblem` raised inside a template passes through untouched.**
    Twig wraps anything a template function throws in a `RuntimeError` whose
    message is `An exception has been thrown during the rendering of a template
    ("…")`, which buried `bad_view_call` — code and fix both — inside a generic
    `template_failed`. `ViewRenderer::raised()` walks the previous chain
    (bounded at 8, because a throwable cycle is not something to hang on) and
    rethrows a `LavaProblem` as itself. This is the same rule `lava/db`'s
    `MigrationFailed` documents: a wrapper problem wraps only throwables that
    are not already `LavaProblem`s.

79. **`TemplateFailed` is one code with two factories.** `syntax()` and
    `runtime()` differ in the fix, not the diagnosis: a template that does not
    compile is a typo in template text, while one that threw while rendering is,
    with `strict_variables` on, almost always a variable the handler never
    passed into the context — so the second fix names `render()` in the handler
    and the first names the template. One code because a consumer acts on both
    identically (read the fix, open `source.file:line`, edit). The `source()`
    fallback is the template name AS GIVEN, not `$template . '.twig'`: the
    renderer normalizes before calling, and appending again would put a second
    copy of the naming rule in a problem class — two places to change, and a
    `broken.twig.twig` the day they disagree.

80. **`ViewFunctions::registry()` is asserted as an ordered list of names, not a
    count.** The pack's promise is that the entire template namespace is one
    array in one file that an agent can read. A third function arriving from a
    bundle, an extension, or a stray `addFunction()` is exactly what that test
    exists to notice, and a count would not notice a swap.

81. **The view fixture gained a `config/view.php`, because the fixture's own
    docblock claimed something untrue.** `app/Modules.php` said the fixture
    "exercises both halves of the manifest: `config/view.php` is read by
    `LoadPackConfig` at boot" — and no such file existed, so the claim was
    decoration. Rather than weaken the docblock, the file was added (with the
    default value, deliberately, so it tests the wiring rather than the file)
    and a test now asserts `Config::provenance('view.path') === 'config/view.php'`.
    That assertion is the only thing in the repository proving
    `configFiles: ['view']` is not a dead declaration: without it, the pack could
    name a config file nothing ever reads and every other test in the suite would
    still pass. Discrimination proven — rename the file away and the test fails
    with `'(unset)'`; restore it and it passes.

82. **`bad_view_call` names a type and never a value, and there is a test for
    it.** `describe()` returns `'null'` or `'a ' . get_debug_type($value)`.
    A route param can hold anything an app put in its model, and a problem
    report is an error page, a log line, and a `--json` body that gets pasted
    into an issue — the same non-disclosure rule `lava/validate` applies to a
    submitted field, applied here to a template's context. The test passes a
    secret inside a param value and asserts it appears nowhere in the rendered
    problem JSON.

83. **PHPStan now analyses `packages/view/src` and `packages/http-client/src`.**
    They were missing from `phpstan.neon`'s paths — view and http-client were
    the two packs whose source no static analysis had ever read. Level 8, no
    errors, and no suppressions, no baseline entries, no casts added to silence
    anything: the same rule the rest of the repository is held to. `TwigFunction::getCallable()`
    returns `mixed`, so the tests narrow it with a real `instanceof`-free
    `@var callable` on a value they just checked — which is a test-local
    narrowing, not a suppression in source.

84. **The HTTP test lives in `tests/Http/`, matching `lava/validate`'s layout.**
    The first version sat directly in `tests/` and resolved its fixture with
    `dirname(__DIR__)`, which pointed at `packages/view/fixtures/…` — one
    directory too high, so all twelve tests failed with `not_an_app`. The pack
    convention (`tests/Http/`) is what makes `dirname(__DIR__)` correct, so the
    file moved rather than the path being special-cased. The test helper that
    boots the fixture now puts the boot problems in the failure message: a bare
    `assertInstanceOf` reported "expected App, got BootFailure" twelve times and
    said nothing about why, which is the opposite of what this framework is for.

85. **`/packages/*/composer.lock` is now gitignored.** Installing a pack on its
    own (`cd packages/view && composer install`, which is how the standalone
    claim in its docs is verified) leaves a lock in the package directory, and
    `.gitignore` only named `/packages/app/composer.lock`. The lock is not an
    artifact to commit for exactly the reason already written next to that line:
    it pins `lava/core` to the `../core` path repository, which is a
    monorepo-local arrangement no consumer has. Generalised rather than adding a
    second specific line, so the next pack that gets installed standalone does
    not repeat this.

Verified this slice by running the pack, not by reading it: 70 tests / 238
assertions in the view suite, all green, covering every route of the fixture
over the real dispatch path and every one of the four problem codes; the full
gate is **776 tests, 4009 assertions** with SQLite enabled (26 of them skip
without a driver, which is why the count moves between environments) and
PHPStan level 8 reports no errors. The Twig behaviours are asserted where they
can be observed rather than by reading options back: escaping by rendering
`<script>` and checking for `&lt;script&gt;`, `strict_variables` by rendering an
undefined variable and expecting the failure, the cache by `getCache()` in both
environments. Two claims were proven to discriminate rather than asserted: the
`ProjectMap`-style check on the pack config file (fails without it, passes with
it) and the `.twig.twig` guard (the fix text is asserted to name the file once).

The standalone claim in `docs/packs/lava-view.md` was also run rather than
copied from `lava/validate`'s doc: `cd packages/view && composer install` pulls
`twig/twig` and `lava/core` from the path repository for real, and the pack's own
`phpunit.xml.dist` then reports the same 70 tests / 238 assertions green. That
is the layout a consumer gets, not the monorepo's.

The two open findings from M7 slice 1 (decisions 18 and 22) remain unacted on and
still need your call. Nothing in this slice changed their shape.

## 2026-09-11 — M8 slice 2 (`lava/http-client`: a PSR-18 client with two rules)

86. **The retry boundary is "no response arrived", and a 5xx is deliberately not
    retried.** `sendRequest()` retries a `NetworkExceptionInterface` — connection
    refused, DNS failure, timeout, truncated response — and never a response,
    whatever its status. The alternative (retry 5xx and 429 too) is what most
    clients do and I rejected it for three reasons that compound: PSR-18 already
    promises a response is a result, so retrying one makes `sendRequest()`'s
    behaviour depend on a status the caller may have wanted to see; retrying a
    429 without honouring `Retry-After` is guessing; and retrying any 5xx without
    jitter turns one slow service into a stampede from every client at once. The
    line is also the one an agent can hold in its head — "retries are for when
    nothing came back" — where "retries are for when nothing came back or the
    status looked bad" is two rules that interact. `UnexpectedStatus` is where a
    bad status becomes an error, and it is raised only by the JSON calls, which
    promise a decoded body and cannot return one from an error page.

87. **The idempotent-method rule is enforced by the pack, not by configuration.**
    `retries` is a ceiling, not a promise: it applies to `GET`, `HEAD`, `PUT`,
    `DELETE`, `OPTIONS` and `TRACE` (RFC 9110's idempotent set) and to nothing
    else, whatever it is set to. The pack can see the method, so it makes the
    distinction itself rather than leaving it to every call site to remember —
    and the failure mode it prevents is a duplicate charge or a duplicate email,
    which is not a thing a comment in a docblock prevents. Tested over a real
    socket, not only against a fake: the fixture's `/drop` route promises a
    `Content-Length` it does not deliver, curl reports errno 18, and the server's
    own counter shows two attempts for a GET and one for a POST under the same
    configuration.

88. **The pack is five problem codes, not the four the design settled on.** The
    fifth is `unencodable_json_body`, added when I looked at what `json_encode`
    can actually fail on and found a raw `JsonException` escaping the pack: a
    string with invalid UTF-8 (a latin-1 column straight out of a database), a
    `NAN` float, a resource, a self-referencing structure. Those are real and
    invisible at the call site, and the alternative — letting a non-`LavaProblem`
    out of a pack — is the one thing this framework's error pillar forbids. The
    factory does not accept the payload as a parameter at all, so there is no
    call site that could pass one by accident: a `postJson()` body is where a
    login sends a password, and it is the most credential-bearing value in the
    whole pack.

89. **`TransportFailed` and `BadRequestUrl` implement the PSR-18 exception
    interfaces rather than a `Transport` interface of the pack's own.** An
    earlier intent recorded in the plan was a private `Transport` interface so
    tests could inject a fake. That is what PSR-18 already is — so the pack
    depends on the standard instead of inventing a second one, and gains
    something the private interface would not have given: an app can hand this
    pack any PSR-18 client and the retry rule, the URL guard and the problem
    types all still apply. `NetworkExceptionInterface` requires `getRequest()`,
    which is why `TransportFailed` keeps the request object — and keeping is not
    printing: it is not in `context`, and a test asserts that an `Authorization`
    header never reaches the JSON.

90. **Three codes are 502 and two are 500, and the split is an alerting rule.**
    `transport_failed`, `unexpected_status` and `bad_json_response` all mean *the
    upstream misbehaved* — a 502 the caller can retry, and not this app's bug.
    `bad_request_url` and `unencodable_json_body` mean *our own code built
    something unusable* — a 500 that only a deploy fixes. Collapsing them would
    leave one status unable to distinguish "their service is down" from "our code
    is wrong", which is exactly the distinction an alerting rule needs. This is
    the first pack to override `httpStatus()` at all, and it is the reason the
    method exists on `LavaProblem` rather than a `match` in core's HTTP layer.

91. **The scheme rule refuses everything but `http`/`https`, and the check runs
    before the transport.** curl will fetch `file:///etc/passwd` and speak
    `gopher://`; a URL handed to this pack is the kind of value that arrives from
    outside — a webhook target, a callback, a URL read out of a row — so a client
    that fetches whatever scheme it is given is a file-disclosure and SSRF
    primitive. The check lives in `HttpClient::sendRequest()`, not in
    `CurlTransport`, so it holds for any transport an app injected, including a
    fake in a test. Writing the test found a real defect in the first version: it
    checked the host before the scheme, and `parse_url('file:///etc/passwd')` has
    a scheme and no host — so a `file://` URL was reported as "not an absolute
    URL", indistinguishable from a typo. The scheme is now checked first, and its
    message names the scheme it refused.

92. **The URL's userinfo and secret-shaped query parameters are masked, and the
    request body and headers are not printed at all.** Two asymmetric mistakes:
    masking a query parameter that turns out to be harmless costs a little
    readability in one error message; failing to mask a real token costs a leaked
    credential in a log, a CI transcript, and an issue someone pasted `--json`
    output into. So the name list is generous — `token`, `api_key`, `key`,
    `secret`, `signature`, and the rest — and the rule leans. The redaction lives
    in `Url::redact()` and every place this pack prints a URL prints it through
    that, so it cannot be bypassed by a new call site. curl's own error text is
    redacted too, because curl echoes the URL back in several of its errors —
    the same reason `lava/db` redacts PDO's message and not just the DSN.

93. **The response body travels in `context`, never in the message.** The
    framework's contract is that the message is one sentence, and a 400-character
    HTML error page pasted into it makes every other line of a report unreadable.
    `context` is a rendered field — both `ProblemCliRenderer` and the JSON
    envelope print it — so nothing is hidden by the split. This was a real
    inconsistency caught while writing the tests: `UnexpectedStatus` had put the
    body in `context` and `BadJsonResponse` had put it in the message. The rule is
    now uniform, and both problems' tests assert that the body is in `context`
    and *not* in the message, so the next one cannot drift.

94. **The module registers three ids and deliberately no `ClientInterface`
    alias.** `ClientOptions`, `CurlTransport`, `HttpClient`. The alias would be
    convenient — type-hint the PSR-18 interface, get the pack's client — but it
    would also *occupy* the standard id, leaving an app that wants its own
    PSR-18 client under that id with a `duplicate_service` at boot and no way
    around it. An app with different needs constructs its own. `ClientOptions` is
    registered even though it is a readonly value object, because it is what the
    pack read from config and `lava services` should show it.

95. **The options are read from config at register time, and captured by the
    factories.** A factory that read config when it ran would make a service's
    behaviour depend on when it was first resolved — the class of thing this
    framework exists to remove — and would move a bad `timeout` from a boot
    problem to whichever request happened to touch the client first. Reading at
    register time means `register()` throws `InvalidConfig` and the boot step
    that calls it (`WireModules`) turns the throw into a boot problem, so
    `'timeout' => -1` is reported with the key and the file before any request.

96. **Range checks got a new core factory, `InvalidConfig::outOfRange()`.** A
    negative timeout is a perfectly good int, so `Config::int()` cannot refuse it
    — and without a range check the value reaches curl, which rejects it on the
    first request with a message naming neither the key nor the file. It went in
    core rather than the pack because the rule is about config values, which is
    core's subject, and the fix ("Fix the value of 'timeout' in
    config/http_client.php") is text core can write without knowing anything
    about HTTP. The next pack with a numeric key gets it for free instead of
    inventing a sixth code.

97. **PSR-17 capabilities are taken as an intersection type, not as two
    parameters and not as a concrete class.** `CurlTransport` takes
    `ResponseFactoryInterface&StreamFactoryInterface`; `HttpClient` takes
    `RequestFactoryInterface&StreamFactoryInterface`. One parameter that
    documents the real requirement, satisfied by the `Psr17Factory` core already
    uses, and the pack never names `Nyholm\Psr7` in its own client code — the
    module names it once, at the wiring site, where a library choice belongs.
    The pack's `composer.json` now requires `nyholm/psr7`, `psr/http-factory` and
    `psr/http-message` directly rather than leaning on `lava/core`'s transitive
    ones.

98. **The pack has its own `php -S` harness instead of using core's
    `ServedApp`.** `ServedApp` runs `lava serve`, which boots a fixture through
    the CLI — so a pack that depended on it would stop being installable on its
    own, which is the claim every pack here has to keep. `tests/Support/LocalServer.php`
    starts one server per test process on a free port and stops it from a
    shutdown function, so a failed run leaves no orphan. `php -S` is
    single-process, which is why `/slow` sleeps one second and the timeout test
    waits 150ms — the bleed into the next request is bounded and short.

99. **Three PHPStan level 8 errors were fixed at the cause, not suppressed.**
    `curl_setopt_array`'s stub wants a `non-empty-string` for `CURLOPT_URL` and
    `CURLOPT_CUSTOMREQUEST`, and a `non-empty-string` for `CURLOPT_USERAGENT`; so
    the transport now refuses an empty URL or method with `TransportFailed`
    (which is honest — it is a public class a caller may use directly, and curl
    cannot express either), and an empty configured user agent is left to curl
    rather than sent as a blank header, which some servers refuse outright.
    `curl_exec`'s `string|true` narrowed with `is_string()`. `HttpClient::json()`'s
    `$body` gained its `array<string, mixed>` docblock. No `@phpstan-ignore`, no
    baseline, no cast.

Verified this slice by running the pack, not by reading it. 98 tests / 256
assertions in the http-client suite, and the same 98 / 256 when the pack is
installed on its own (`cd packages/http-client && composer install &&
vendor/bin/phpunit`) — that is the consumer's layout, and it is where the
`Lava\Core\Testing\*` helpers matter, since core ships them in `src/` rather
than in `autoload-dev` precisely so packs can use them standalone. The full gate
is **874 tests, 4265 assertions** with SQLite enabled, and PHPStan level 8
reports no errors with `packages/http-client/src` newly in its paths.

Three defects were found by writing the tests rather than by reading the code,
and each got an assertion that would catch it again:

- the header collector reset its buffer on the terminating blank line of a
  response, so every response arrived with **zero headers** — caught by the live
  tests asserting `Content-Type` and `Location`, which a fake transport could
  never have caught;
- the URL guard checked the host before the scheme, so `file:///etc/passwd` was
  reported as "not an absolute URL" (decision 91);
- `UnexpectedStatus` and `BadJsonResponse` disagreed about where the body goes
  (decision 93).

The doc sample in `docs/packs/lava-http-client.md` was generated from the code
and diffed against it rather than written by hand: `json_encode($problem->json())`
for a URL with userinfo and a `?token=` parameter produces exactly the JSON in
the page, redactions included.

The two open findings from M7 slice 1 (decisions 18 and 22) remain unacted on and
still need your call. Nothing in this slice changed their shape.

## 2026-09-11 — M8 slice 3 (`apps/demo`: the HTML surface and the outbound call)

The demo now exercises all four packs. `lava/view` gives it a board at `/` and a
task page at `/tasks/{id}/view`; `lava/http-client` gives it `GET
/upstream/health`, the one route that leaves the process. Both arrived with a
config file, a service, tests, README, and a regenerated map — and writing the CI
step for the outbound route turned up a defect in `lava serve` that had nothing to
do with either pack (decisions 109–113).

100. **The upstream is this app's own `/health`, so the demo runs with no
    network.** `app.upstream` defaults to `http://127.0.0.1:8080` and
    `App\Upstream\Upstream` appends `/health`. Pointing the demo at a real
    third-party API would have made its suite fail offline and flake online —
    the two worst properties a canonical app can have — so the demo exercises
    `lava/http-client` against something genuinely running that it also owns.
    `lava serve` in one terminal is the whole setup, and CI asserts exactly that
    by starting one server and having it fetch itself.

101. **The upstream URL is config, never a request parameter.** A route that
    fetches a caller-supplied URL is an SSRF primitive; the demo does not ship
    one, and `lava/http-client` refuses `file://` and `gopher://` for the same
    reason. The path is fixed at `/health` and the base comes from
    `config/app.php`, so there is no input from the request in the URL at all.

102. **`app.upstream` lives in `config/app.php`, because that is the only
    app-owned config file core loads.** `LoadConfig::FILES` is `['app',
    'logging']`; every other config file is reached through a pack's declared
    `configFiles`. A `config/upstream.php` would have been listed in the map's
    Files table and read by nobody — a trap whose only symptom is a config key
    that is silently always its default. The value sits beside `app.base_url`
    with a comment saying why it is there rather than in a file of its own.

103. **The config file reads `UPSTREAM_URL` itself, through `ProcessEnv::real()`.**
    Not a module, not a service factory: one place, so the precedence rule is
    visible in one file instead of split between a reader and a builder. This is
    the idiom `DbModule::setting()` already uses — real environment wins over the
    config file, and an empty string counts as unset, because `UPSTREAM_URL=` is
    how a deploy template spells "leave this blank" and treating it as a URL
    would produce a `bad_request_url` about a scheme nobody typed. It is also
    what lets the demo's suite point the fetch at a server it starts.

104. **`App\Upstream\Upstream` exists because a handler parameter cannot be a
    scalar.** `HandlerInvoker` accepts `ServerRequestInterface`, `RouteArgs`, or a
    registered container id that is a class — a `string` config value has no way
    in. So the base URL travels as a constructor argument of a registered
    service, the same shape `TaskRepository` receives its `Connection` in. The
    constraint produced a better demo than the alternative would have: the pack's
    client is composed into an app service rather than called from a controller,
    which is what a real app does with a dependency it does not own.

105. **The route is not flag-gated, though the pack is.** `http_client` gates the
    pack at boot, and that is the flag that matters: turn it off and the route
    404s because the module is absent, which is the framework's own mechanism. A
    second flag on the route would be two ways to remove one route, and
    `lava features resolve` would have to be asked twice to learn one thing.

106. **`UPSTREAM_URL` is declared `optional`, with a description.** It has a
    working default, so declaring it `required` would make `lava check --strict`
    fail on a fresh clone of a demo that runs perfectly well without it. Declaring
    it at all is what puts it in `lava env`, in the `env` section of `lava check`,
    and in AGENTS.md — a variable an app reads and does not declare is invisible
    to every one of those.

107. **The HTML surface is a second controller, not content negotiation.** There
    is no content negotiation in this framework: a route answers one media, and a
    caller who wants the other asks for a different route. So
    `TasksPageController` is the page and `TasksController` is the API, and
    neither has to ask what the caller would have preferred. It also keeps
    `/tasks` answering JSON for everyone who asks for it — including the `curl`
    a person copies out of the README — which is the "agents first" pillar
    surviving contact with a browser UI.

108. **The demo duplicates the pack's `php -S` harness instead of sharing it.**
    `tests/Support/UpstreamServer.php` and `tests/fixtures/upstream/router.php`
    mirror `lava/http-client`'s own `LocalServer`. The pack's copy exists so the
    pack can prove its standalone claim; this one exists so the demo can prove
    the pack composes in a real app. Sharing either would make one claim depend
    on the other's `autoload-dev`, and a pack that could not be tested without the
    demo would be a pack that is not really decoupled. The behaviour lives in the
    path prefix (`/ok`, `/broken`, `/drop`, `/flaky-<id>`) because
    `App\Upstream\Upstream` appends `/health` to whatever base it is given — so a
    query string would have landed in the middle of the path.

109. **`lava serve` now stops the server it started.** The `proc_open` command is
    an **array**, not a shell string. PHP runs an array through `execve` directly
    and hands a string to `/bin/sh -c` — and that shell is why a stopped `lava
    serve` used to leave the server running: the signal reached the shell, and
    `php -S` never heard it. The array form also removes `escapeshellarg` and with
    it every quoting rule the shell would otherwise apply to `--host`, whatever a
    caller passes. `stopServerOnSignal()` then terminates the child from a
    SIGINT/SIGTERM handler, guarded by `function_exists` because `lava serve` has
    to work on a PHP built without process control. In a terminal this was always
    invisible — Ctrl-C signals the whole foreground process group — but every
    programmatic stop reaches only `lava serve`: a script's `kill`, an agent
    stopping a server it started in the background, a CI cleanup trap.

110. **The wait polls instead of using `proc_close`, and that is load-bearing.**
    PHP's `waitpid` wrapper retries on `EINTR`, so a signal arriving mid-wait is
    remembered but never dispatched: dispatching needs the VM to reach a safe
    point, and the VM is parked inside a syscall that keeps restarting. With
    `proc_close` the handler above is dead code and the server outlives the
    process exactly as before — which is what the first version of this fix did,
    and what the empirical check caught. `waitForServer()` naps 20ms at a time,
    which puts opcodes back between waits and gives the signal somewhere to run.
    The exit code comes from `proc_get_status` for the same reason: once a status
    poll has reaped the child, `proc_close` reports -1 whatever happened. Ctrl-C
    now exits 130 and SIGTERM 143 — 128 + the signal, which is what a shell
    reports for a process killed by that signal, so Ctrl-C still looks like
    Ctrl-C to whatever is waiting.

111. **`ServedApp::stop()`'s `pkill` is gone, and `ServeShutdownTest` is what
    replaces it.** The framework's own HTTP harness carried
    `pkill -f 'php -S 127.0.0.1:<port>'` after its `proc_terminate`, with a
    comment saying a surviving worker would hold the port and serve stale code to
    the next run — the workaround is the evidence that this hurt in practice. A
    pattern kill with no remaining reason to exist is only a way to kill
    something else by accident, so the sweep is deleted and the guarantee is
    asserted instead: `testStoppingTheCommandStopsTheServerItStarted` starts a
    server, stops it, and fails if anything still accepts a connection on the
    port. It is the one test in the suite that asserts the ABSENCE of a process,
    and so the one the harness could not paper over. Reverting `ServeCommand`
    alone makes it fail — checked, not assumed — and it skips where pcntl is
    absent, because that is exactly where the guarded behaviour is absent too.

112. **The severity was worse than a leaked port: an orphaned server holds the
    test runner's stdout.** The failing-without-the-fix run did not print a
    failure — it hung until the harness timed out at 120s. The orphan had
    inherited fd 4 of PHPUnit's own stdout pipe (a `proc_open` child inherits fds
    it was not given a replacement for), so the write end stayed open after
    PHPUnit exited and `phpunit | tail` never saw EOF. That is the shape of the
    bug in CI: not a job that fails, a job that hangs until the runner's timeout.
    It is also why the CI step's cleanup trap is not sufficient on its own — it
    kills `lava serve`, and before this fix that left the server, the pipe, and
    the job.

113. **`--flag value` is not a spelling this CLI accepts, and the first version of
    the CI serve step used it.** `Args` takes values with `=` only
    (`--port=8080`), deliberately: `--flag value` is ambiguous with a positional
    argument and guessing would make `lava describe --json users.show` swallow the
    selector. So `--port 8080` parsed as a bare flag plus a positional, and the
    port silently stayed at the default — the step passed for the wrong reason and
    would have broken confusingly the moment the default changed. The corrected
    step passes no port at all, because the claim under test is the DEFAULT one:
    pinning the port would make it pass while the default was broken. Recorded
    because the failure mode is silent, and I hit it while writing the thing that
    was supposed to catch silent failures.

114. **The CI `demo` job now copies five packs and proves the serve claim over a
    socket.** The copy list is `packages/core packages/db packages/validate
    packages/view packages/http-client` — it and `apps/demo/composer.json` move
    together, and the job fails on a path repository that is not on disk. The new
    step starts `lava serve` on its default port and asserts the two claims no
    in-process test can reach: that `/` is HTML with `url()`-built links, and that
    `/upstream/health` fetches the app's own `/health`, which fails if
    `app.upstream`'s default and `lava serve`'s default ever drift apart. The
    `test` job now asks setup-php for `extensions: pcntl`, because otherwise the
    guarantee is real but untested — `ServeShutdownTest` would skip on every
    matrix leg, and a skip is honest and invisible at the same time.

115. **PHPStan now analyses `apps/demo` at level 8, and it found a dead property.**
    `phpstan.neon` covered `packages/*/src` and nothing else, so the canonical app
    — the code the docs tell people to copy — was the one tree no static analysis
    touched: neither its `app/` nor its tests. Adding both paths at the same level
    found exactly one error, and it was real: `TasksTest::$app` was assigned in
    `setUpBeforeClass` and never read. Removed, not silenced. The app passes level
    8 otherwise, which is worth knowing on its own — it means the examples a
    reader copies are type-clean under the framework's own bar, not merely
    runnable.

116. **The demo's `UpstreamServer` removes the files it makes.** `stop()` now
    unlinks its `tempnam` log — the same rule `ServedApp` already kept for its own
    — and the `/flaky-<id>` counter files the router wrote. Eight files per run
    were accumulating in `/tmp`, permanently: the log is a `tempnam` and the
    counters are keyed by a random id, so nothing ever reuses one. Noticed by
    looking at `/tmp` during the session-hygiene sweep, which is the only way this
    class of leak shows up — nothing fails, and nothing that passes tells you.

Verified by running, not by reading. The demo suite is 24 tests / 99 assertions;
`lava check --strict` reports all nine sections `ok` and `lava map --check`
confirms the regenerated map (10 routes / 17 services / 5 features / 4 packs /
4 env vars, hash `d6b2a2d7e8377ce8`); the root gate is **875 tests, 4270
assertions** with the live database tests enabled, and PHPStan level 8 reports no
errors across every pack's `src` and now the demo's `app/` and `tests/` too. Over
real HTTP through `lava serve`: the board renders, the task page
renders, a hostile title is escaped to `&lt;script&gt;`, `/tasks/export` is 200
`text/csv` with the flag on and a 404 with the nav link gone when
`LAVA_FEATURE_TASKS_CSV_EXPORT=off`, a missing task answers `task_not_found` in
the framework's envelope, and the self-fetch returns `{"status":"ok"}` in 10ms.
The CI serve step was rehearsed end to end and leaves zero orphans on port 8080.
The serve fix was checked in both directions: `SIGTERM` → exit 143 and `SIGINT` →
exit 130, each with no survivors and the port released; and with `ServeCommand`
stashed back to its previous version, `ServeShutdownTest` fails and the orphan
survives on the test's own port.

The two open findings from M7 slice 1 (decisions 18 and 22) remain unacted on and
still need your call. Nothing in this slice changed their shape.
