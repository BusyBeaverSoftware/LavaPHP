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

   *Amended 2026-09-11 (M9 post-release).* Two corrections, both forced by the
   flag check. First, the kernel seeds the SAME shape before it dispatches, so
   the two envelopes it emits without running the command at all — `--help`, and
   an invocation refused for an undeclared flag — carry the command's keys; that
   required `emptyPayload` to be `public` (15 one-word widenings, one name for
   one concept, rather than a second forwarding method that could drift).
   Second, "seeding first" was **necessary but not sufficient**: a seed is only
   an improvement if its values satisfy the contract, and three keys across two
   commands did not — `lava.check/2` required `strict`/`quick` that only
   `report()` wrote, and `lava.map/1` called `path`/`fingerprint` strings when
   no invocation with no app has an honest string for them. Seeding an INVALID
   shape is not better than seeding none; it is the same bug with a friendlier
   stack. The generated guard in
   `JsonSchemaTest::testARejectedInvocationObeysTheSchemaItsCommandClaims` is
   what makes that class of bug impossible to ship unnoticed from here on.

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

### Open finding — **decided and implemented 2026-09-11** (M9 post-release)

**Acted on**: you chose the recommended option — an undeclared flag is
`bad_usage`, exit 2, with the command's declared list and `lava <cmd> --help` in
the fix. The two open questions it raised are settled in
[§ M9 post-release](#): the implicit global set is `--json`, `--quiet`,
`--help`, `--env` (the `--env` call included), `--` literals stay positional
because the check reads the PARSED flag map, and pack-defined flags keep working
because the check compares against the command's own `flags()` rather than a
list of core's. The text below is the finding as written; it is kept for the
reasoning, not as outstanding work.

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

No code depended on the permissiveness, so the change cost nothing: the only
tests that moved were ones asserting a payload's keys, and every command's seed
had to be checked against its schema — which is how `lava.check/2` and
`lava.map/1` turned out to promise properties their commands never emitted on
failure paths. See § M9 post-release.

## 2026-09-11 — M5 (lava/db)

The plan's acceptance criteria for M5 are "compiler snapshot tests green
driver-free; with sqlite installed: demo CRUD, `lava db:status` applied/pending,
migrate/rollback round-trip; db pack installs standalone". All four are met and
verified against the real binary (see 20). The M4 open finding above was still
open at the time and is now decided and implemented (see § M9 post-release).

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

## 2026-09-11 — M9 slice 1 (`lava/core` at PHPStan `max`)

The plan's M9 row says "phpstan max on core". It does not say max everywhere, and
that distinction turned out to be the whole slice: `packages/core/src` went from
**30 errors in 10 files** to zero at `max`, while the same run over the packs and
the canonical app still reports **86**. Core is now enforced at `max` by
`composer verify`; everything else stays at level 8. Both facts are recorded
below, because a gate that says "max on core" without saying what that left
behind is a gate that reads stronger than it is.

117. **`phpstan.neon` stays at level 8 for everything; a second file holds core at
    `max`.** The split is about what the two trees ARE, not about how much time I
    had. Core is the code an app author cannot replace: every inference core makes
    about a value it did not construct is a promise the framework keeps on that
    author's behalf, so core is held to the strictest reading of its own types —
    including the strict-array rules `max` adds. A pack is replaceable and an app
    is the user's own; level 8 keeps a real bar under them (wrong argument types,
    missing returns, dead code) without making a pack author clear core's bar to
    ship. `composer verify` now runs `@phpstan` then `@phpstan:core`, so the
    stronger promise is enforced by the gate and not by a sentence in a plan.

118. **The new config is standalone, not `includes: phpstan.neon`.** NEON merges
    array parameters across an `includes`, so a child config carrying only
    `level: max` would silently inherit every path from the parent — the run would
    grow to the whole repository the first time someone added a path there, and it
    would fail for a reason that has nothing to do with the change that caused it.
    `phpstan-core-max.neon` therefore repeats its two parameters and nothing else.
    The file carries the reasoning inline, so the next reader meets the argument
    where the decision is.

119. **What `max` found outside core, recorded rather than implied.** 86 errors:
    32 `cast.string`, 32 `offsetAccess.nonOffsetAccessible`, 13 `argument.type`,
    7 `cast.int`, 1 `foreach.nonIterable`, 1 `return.type`. By file:
    `apps/demo/tests/TasksTest.php` 41, `packages/db/src/Schema/SchemaSnapshot.php`
    27, `apps/demo/app/Tasks/Task.php` 4, `apps/demo/tests/UpstreamTest.php` 3,
    `packages/db/src/Migration/MigrationRunner.php` 3, and singles in
    `apps/demo/app/Services.php` (2), `apps/demo/tests/PagesTest.php` (2),
    `apps/demo/app/Http/TasksController.php`, the demo's upstream fixture router,
    `packages/db/src/Connection.php`, `packages/db/src/Sql/SchemaCompiler.php`.
    46 of the 86 are in the demo's own tests. This is a follow-up, not a
    regression: nothing was at `max` before this slice, so none of it is newly
    broken.

120. **The `Config` accessors narrow in their own bodies.** The old
    `typed()`/`optional()` pair took a `callable $check` and returned `mixed`, so
    the type label and the checker were two separate facts that could drift —
    `typed('string', is_int(...))` type-checked fine and handed an `int` to a
    caller that had declared `string`. Each accessor now reads the raw value from
    a `mixed`-returning helper and does its own `is_string`/`is_int`/`is_bool`/
    `is_array` check one line later, with `wrongType(): never` making the failing
    branch terminal. Eight errors gone, and the shape is one the analyser can
    follow: the declaration is now the enforcement, in the same body, visible to
    whoever reads the accessor. `has()` is `array_key_exists`, unchanged — a key
    set to `null` has been set, and `??` would have hidden the wrong type the
    accessor exists to report.

121. **`get_debug_type`, not `get_class`, in the two `FlagSubjectResolver`
    reports.** `App::handle` and `ValidateWiring` both guard a container value
    that failed the `instanceof`, and both then reported the offender's class.
    `get_class` on a scalar raises a TypeError — from inside the rendering of the
    very problem meant to explain the misconfiguration, which turns a diagnosable
    boot failure into a blank 500. The branch exists precisely because the value
    need not be a `FlagSubjectResolver` and need not be an object; the report now
    says what it actually is. `ValidateWiring`'s copy is the one that runs first
    (boot, before traffic), and `App::handle`'s is the defense-in-depth re-check.

122. **Named shapes replaced `array<string, mixed>` in `AboutCommand` and
    `TestRun`, and the reason is a closure.** The eleven errors in `AboutCommand`
    were not really about `array<string, mixed>`; they were about an inline
    `static fn (array $pack): array` whose declared `array` parameter erased the
    shape the caller knew, leaving four `mixed` cells that had to be cast back to
    text. Same in `TestCommand`. So the fix is two parts: the producers declare
    what they build (`@phpstan-type PhpFacts`/`PackFacts` on `AboutCommand`,
    `TestCase` on `TestRun`, imported by `TestCommand`), and the row mapping moved
    out of the closure into a named method whose `@param` carries the shape. The
    four `(string)` casts in `TestCommand` are gone rather than kept-and-ignored:
    a cast asserts nothing, and the shape now says those values are already
    strings. `@phpstan-type` was already the house idiom (`SchemaSnapshot`,
    `RegexRule`), so this is not a new pattern to learn.

123. **`InvalidConfig::outOfRange()` takes `int|float`, not `mixed`.** All three
    callers pass an `int` (a timeout, a retry count, a backoff), and the docblock
    already described the method as being about numeric ranges — "a negative
    timeout, a retry count below zero, a percentage above 100". `mixed` was
    looser than the method's own description; a value that is the wrong TYPE is
    `badType()`'s subject, not this one's. Tightening it also made the
    interpolation analysable without a cast, which is the tell that the type was
    the problem rather than the message. This is a BC break for a hypothetical
    external caller, at 0.1.0, in the direction of a stricter promise.

124. **`TestApp::restoreEnv()` declares `array<mixed>`, because that is what it
    is.** The parameters were typed `array<string, string>` — a claim about what
    the environment contains — while the method does no type work at all: it
    snapshots `$_ENV` and `$_SERVER` and assigns them back. `$_SERVER` genuinely
    may hold a non-string, and the analyser was right to reject the narrower
    claim. The honest fix is the wider type plus a docblock saying why, not a cast
    or an ignore at the assignment.

125. **The alias target is guarded, not cast.** `resolveAliases()` read
    `(string) $registration->value`. `alias()` takes a string so that cast is
    never lossy today — but if it ever were, `(string)` on an array is a fatal and
    on an `int` yields the id `"5"`, sending the reader after a service nobody
    registered. It now throws the same `\LogicException` the neighbouring
    no-factory guard throws, naming the alias and the actual type. Two guards, one
    idiom, both making an invariant explicit where it is relied on.

126. **The `Allow` header narrows its method names instead of trusting
    `is_array`.** A problem's `context` is `array<string, mixed>`, so
    `is_array($context['allowed'])` proved the container and nothing about the
    contents; `implode` on it was the analyser's complaint, and it was a fair one
    — `(string)` on a nested array would have raised inside the rendering of a
    405. A `methodNames()` helper collects the non-empty strings and drops the
    rest, and an empty result now omits the header entirely rather than sending
    `Allow: ` with nothing in it. Covered where it already was:
    `HttpTest.php:57` asserts `GET, PUT`, and `ServeTest.php:174` and
    `KernelBootTest.php:343` assert it over a real socket and through the kernel.

127. **`PDO::getAvailableDrivers()` is collected, not declared.** The stub returns
    `array<int|string, mixed>` while the contract is a list of driver names, so
    `pdoDrivers()` walks the result and keeps the strings — which also gathered
    the `class_exists(\PDO::class)` guard into one place. The alternative was
    declaring the key `array` and casting at the use site, which is the same
    non-check as decision 126.

128. **Some of these guards are unreachable, and the coverage threshold must not
    be set to force tests for them.** `alias()` cannot produce a non-string
    target; a 405's `allowed` list is built by `MethodNotAllowed` from strings;
    `class_exists(\PDO::class)` is always true on the machines this runs on. Those
    branches exist to make an invariant explicit and to replace a cast that could
    fail silently, and they cost coverage by construction. When the coverage
    threshold lands later in M9 it will be set from what the suite reaches, with
    these named as deliberate exclusions — writing a test that registers a
    non-string alias would be testing a state the public API cannot produce, and
    that is coverage theatre, not coverage. Logged now so the number is argued
    about once, in the open, rather than quietly excluded at threshold time.

Verified by running. `packages/core/src` at `--level=max` reports `[OK] No
errors`, from a measured baseline of 30 errors in 10 files. `composer verify`
with the live database tests enabled is **875 tests, 4270 assertions**, level 8
`[OK] No errors` across all five packs plus `apps/demo/app` and `apps/demo/tests`,
and the new `@phpstan:core` leg `[OK] No errors`. The behaviour touched by the
refactors is not merely analysed: the `Allow` header has three existing
assertions including one over a real socket, and the `Config` accessors are
covered by that pack's own 32 tests / 136 assertions.

## 2026-09-11 — M9 slice 2 (a red suite that `lava check` called green)

Found while finishing slice 1, and it is the most serious defect of the run so
far. `lava check --strict` reported the demo's tests section `ok` — exit 0, no
problems — while the demo's suite was **red with two errors**. The trigger was
the environment (no PDO SQLite driver, so the demo's two DB test classes error in
`setUpBeforeClass`), which is exactly why it had gone unnoticed: on a machine
with the driver the suite is green, and no test asserted what happens when a
class cannot set itself up.

The mechanism, measured rather than reasoned about: PHPUnit writes a test class
that throws in `setUpBeforeClass` to `--log-junit` as an **empty `<testsuite>`**
— no `<testcase>`, no `<error>`, and the file's own totals still read zero
failures — while its console prints `ERRORS!` and it exits 2. For the demo the
XML said `tests=6 errors=0 failures=0` and the console said `Tests: 8, Errors: 2`.
So `Junit::parse`, which walks `//testcase` elements, saw a clean run, and every
verdict built from it — `TestRun::ok()`, the tests section, the exit code —
called a red suite green. An agent running `lava check` would have shipped it.

129. **The exit code is the verdict; the report is the detail.** `TestRun` now
    carries the runner's exit status and `ok()` requires it to be 0. The
    alternative — inferring the loss from the XML — is a heuristic with a
    false-positive: a `<testsuite>` with zero tests is also what a legitimately
    empty test class produces, and `--log-junit` gives no way to tell the two
    apart. PHPUnit's exit status is exact, documented, and already captured by
    `PhpUnitRunner` (it was being used only for the no-report case and otherwise
    discarded).

130. **`Junit::parse()`'s `$exitCode` is required, not defaulted.** A default of
    `0` would mean "assume the run was green", which is the precise mistake this
    parameter exists to prevent — a future caller could forget it and silently
    reinstate the bug. Requiring it forced every call site to state the exit code
    it means, which is why `JunitTest`'s eight calls now read `, 2)` for the red
    fixture report and `, 0)` for the green ones.

131. **`incomplete_test_report` is a new code, and it does not contradict "a red
    suite contributes no problems".** That rule (docs/problem-codes.md) is about
    the framework not pronouncing on the app's code, and it still holds: a suite
    whose failures the report describes contributes nothing, which
    `testACountedFailureIsTheAppsFindingAndNotTheFrameworks` pins. This code is a
    finding about the RUN — the framework saying it could not read the answer —
    which is the same category `bad_test_report` and `missing_test_runner`
    already occupy. The sentence in the doc that said neither of those "is ever a
    framework problem" was imprecise enough to mislead, and is rewritten to state
    the boundary: problems are for whether a verdict could be read at all.

132. **`TestRun::problems()` rather than the same rule in both commands.**
    `lava test` and `lava check` read the same `TestRun` and must reach the same
    verdict about it; putting the rule in one place is what makes that structural
    rather than a coincidence to be maintained. `lava check` files the problem
    under `tests` by adding the code to `SECTIONS`, so it lands beside the other
    runner-level problems instead of defaulting to `boot`.

133. **The payload still reports what the report contains.** The `tests` counts
    stay `1 test, 0 errors` for a fixture whose runner exited 2, because that is
    what PHPUnit wrote down; the problem is what says the report is incomplete.
    Rewriting the counts to match the exit code would invent numbers PHPUnit
    never produced and would erase the very discrepancy the code exists to
    report. This also kept the `lava.test/1` / `lava.check/1` payload shape
    unchanged, so the frozen schemas needed no edit.

134. **`setup-error-app` reproduces the mechanism without the environment
    condition.** A fixture whose `setUpBeforeClass` simply throws needs no
    missing driver and no database, so the case is a CI regression test rather
    than something only a misconfigured machine can find. That is the whole
    reason this defect survived a full green gate: the condition that triggers it
    is absent exactly where the gate runs.

135. **`ok()`'s exit-code check is defense-in-depth, and the probe showed it.**
    Reverting only `ok()` to the report-only verdict left both CLI tests passing,
    because the CLI outcome is protected by the problem's Fatal severity
    (`IO::emit` ORs in `hasFatals()`, and the check section ORs in `$fatals !==
    []`). Only the `ok()` contract test failed. Reverting `problems()` instead
    failed all three. So the two halves are pinned by different tests, and
    `ok()` stays honest anyway: "is this run green?" answering yes for an exit-2
    run is simply wrong, whichever caller asks.

136. **Fatal, not Warn.** An unreadable verdict must stop the agent rather than
    be ranked below something cheaper — the same severity as its sibling
    `bad_test_report`. Severity does not change the outcome here (the section is
    `failed` either way, because `ok()` is also false), so this is about
    rendering and ordering, and Fatal is what the situation is.

Verified by running, in both directions, through the real binary on the demo —
the exact scenario that exposed the bug. Without the PDO driver: `lava check
--strict` now exits **1**, the tests section reads `failed`, and the problem is
`incomplete_test_report` with `PHPUnit exited 2 but its JUnit report lists no
failures and no errors (6 tests, 0 non-passing cases)`. With the driver:
`lava check --strict` exits **0** with all nine sections `ok` and `Tests: 24,
Assertions: 99`. The regression tests were proved to fail against the old
behaviour (decision 135's two probes). `composer verify` with the live database
tests enabled is **881 tests, 4346 assertions** (from 875 / 4270 — six new tests,
76 new assertions), level 8 and core-at-`max` both `[OK] No errors`, and the two
new `JsonSchemaTest` cases confirm the envelope still obeys its own frozen
schema in this failure shape.

Both changes in this section and the one above touch `TestRun.php` and
`TestCommand.php`, so they are committed together rather than split: separating
them would have meant committing a version of those two files that is in neither
state.

## 2026-09-11 — M9 slice 3 (coverage, and the two numbers M9 owes)

137. **`composer coverage` is its own script, and deliberately not part of
    `verify`.** It needs a coverage driver (pcov) and `pdo_sqlite`, and a gate
    that cannot run on a fresh checkout is a gate that gets skipped — so
    `verify` stays runnable anywhere and coverage runs where the driver is. It
    is a script rather than `phpunit --coverage-text` because a report nobody is
    held to is a number nobody acts on, and because the number that report
    prints is wrong (138).

138. **The measurement was the defect, and that is why the child capture
    exists.** The framework's end-to-end tests prove a command by RUNNING it:
    the harness spawns `bin/lava` and reads its stdout, its stderr and its exit
    code. Every line that only runs inside that child is invisible to the
    parent's coverage report. Measured parent-only, `packages/db` reads
    **55.52%**, and the four `db:*` commands — the most thoroughly tested
    surface in the repository — read as the least covered code in it.
    Rejected: accepting a 55% floor with a big "subprocess-driven" exclusion
    list, because that names the symptom as if it were a reason and would have
    hidden any REAL db gap inside the same bucket. Chose: capture the children,
    then set the floor from what the suite actually reaches.

139. **The capture lives in the harness's own prepend file, guarded by an
    environment variable.** `packages/core/tests/Support/fixture-autoload.php`
    is what every `bin/lava` child is prepended with; when `LAVA_COVERAGE_DIR`
    names a directory it starts pcov and registers a shutdown writer for what
    ran. No production code learns that coverage exists, `composer verify`
    behaves exactly as it did before, and a child that is not part of a coverage
    run pays nothing.

140. **The variable is forwarded, not whitelisted.** `LavaCli::isLavaVar()`
    strips `LAVA_ENV`, `LAVA_FEATURE_*` and the harness's own two — it exists so
    that a CI environment cannot steer a fixture's FLAGS. `LAVA_COVERAGE_DIR` is
    a measurement, and the harness forwards it by not stripping it. Said out
    loud at the capture site, so the next reader does not "fix" the omission.

141. **One file per child, named by pid AND a unique id.** Children run
    concurrently in some tests, and a run spawns enough short-lived processes
    that the operating system recycles pids inside it.

142. **pcov's `collect()` filter argument is not usable here.** On pcov 1.0.12
    `\pcov\collect(\pcov\inclusive, [$dir])` returned 0 files in every form
    tried — the directory with and without a trailing slash, the exact file
    path, two directories, and `\pcov\exclusive` — while unfiltered `collect()`
    returned the files. So the child collects unfiltered and filters in PHP.
    Recorded because it cost time once and would cost it again.

143. **`pcov.directory` must be set, and must cover the repository.** A child
    runs with its working directory set to a fixture app in `/tmp`, so pcov's
    own auto-detection tracks the wrong tree and the children contribute
    nothing. The gate refuses to start without it and prints the ini line that
    fixes it; the alternative is a number that is quietly short.

144. **The merge only counts lines the parent's report calls executable.** pcov
    also counts lines clover does not — a brace, a `case`, a closing tag. That
    is real execution, but it is outside the measured universe, so it cannot
    move a percentage. Counting it would inflate coverage with lines no report
    would ever have counted as missed.

145. **Floors sit ~3 points below measurement, and the measured values are
    written into the constant.** A floor that sits ON the measurement goes red
    the next time someone moves a branch; a floor nobody can satisfy gets
    deleted rather than fixed. Measured 2026-09-11: core 87.91%, db 81.14%,
    http-client 96.69%, validate 98.29%, view 97.73%, all packs 88.05%.

146. **`db`'s floor is above the parent-only ceiling on purpose.** Parent-only
    db is 55.68%; the floor is 78.00%. If the child capture ever goes quiet,
    this gate fails loudly instead of reporting a smaller number as if it were
    the whole truth. The floor is the tripwire for the instrument as well as for
    the code — and a `childHits === 0` check fails first, naming the three ways
    the capture can go quiet.

147. **Two shipped APIs had zero execution, and that is a different thing from a
    branch tail.** `LineLogger::log()` — the container's default for
    `Psr\Log\LoggerInterface` — had never run in a test; the only mention of the
    class in the suite asserted that a container ALIAS points at it.
    `SchemaSnapshot::equals()` and `json()` had no caller anywhere in the
    repository, though the class docblock offers `equals()` as the way to answer
    "did the migration do what it said". Both now have tests
    (`LineLoggerTest`, `SchemaSnapshotTest`): hardening, for a milestone, means
    closing shipped promises that have nothing behind them rather than moving a
    percentage.

148. **The deliberate exclusions, named, as decision 128 promised.**
    (a) `SchemaSnapshot::fromMysql`/`fromPgsql` — MySQL and PostgreSQL
    catalogue introspection; the gate has no server for either, and a test that
    cannot run is not coverage. (b) The malformed-artifact diagnostics in
    `CollectFlagDefinitions` — `config/features.php` with an unknown section or
    a `define` that is not a list, `app/Modules.php` entries that are not
    `ModuleRef`s. These are reachable by a user, and each is a fixture app's
    worth of work; the shapes with fixtures already exist (the `bad-*` apps) and
    these are the rarer shapes of the same failures. (c) The guards from
    decision 128: `alias()`'s non-string target, a 405's `allowed` list,
    `class_exists(\PDO::class)`. Named here so the number is argued about once,
    in the open, rather than quietly excluded at threshold time.

149. **`tools/` joined the phpstan paths.** The gate reads two formats, merges
    them, and reports a number people act on: a type error in it does not crash,
    it reports a plausible wrong percentage with confidence. It cost two real
    fixes rather than suppressions — `$argv` may not exist at all
    (`register_argc_argv`), so arguments are read from `$_SERVER['argv']` and
    narrowed to strings; and `SimpleXMLElement::xpath()` returns `array|null`,
    which the loop handles rather than assumes away.

150. **Proved the gate fails, in both directions.** Probe A — `db`'s floor
    raised to 99 — exits 1 with `packages/db is at 81.14%, below its floor of
    99.00%`, and composer propagates the code. Probe B — the capture's filter
    changed so children wrote empty files, with nothing else touched — exits 1
    with `no child process contributed a line the parent had not already
    covered`, and the report above it read `db 55.68%` and `core 85.09%` against
    core's 85.00% floor. Probe B is the argument for the instrument check
    existing: without it, core would have passed at 85.09% while the report was
    three points short of the truth.

151. **R4 measured: `lava check --quick` on the demo is 0.03 s** — three runs,
    against a budget of under 2 s.

152. **Eager boot measured: 17 services constructed per request in 0.39 ms mean,
    0.37 ms median, 0.46 ms p95.** `Kernel::boot` on `apps/demo`, 200
    iterations, no driver loaded. Boot is eager by construction —
    `ValidateWiring` resolves every registered id, which is what turns a broken
    factory into a boot problem instead of a 500 on request N+1 — so the
    measurement is what makes "accepted v1" a claim rather than a hope: 0.4 ms
    against a request that costs tens of milliseconds. Measured with pcov loaded
    the same loop reads 0.58 ms, and that difference is the instrument's cost,
    not the framework's. The escape hatch for a deployment where even 0.4 ms
    matters is a compiled artifact (a pre-built container and map); it is
    documented and not built, because nothing in this measurement justifies
    building it.

153. **Coverage measurement caveat.** No coverage driver and no `pdo_sqlite` are
    installed system-wide on this machine, so pcov was built from source into
    `/tmp` and every number above was produced with
    `PHP_INI_SCAN_DIR=":/tmp/lava-php-conf"`. CI uses
    `shivammathur/setup-php` with `coverage: pcov` and gets `pdo_sqlite` from
    the image. **The CI job that runs this gate was written but could not be run
    here**: the local proof covers the mechanism (a scan-dir ini, an absolute
    `pcov.directory`, a leading-colon `PHP_INI_SCAN_DIR`), not GitHub's image.
    That step needs one green run before it can be called verified.

### Open finding, not acted on (needs your call)

`SchemaSnapshot::equals()` is documented as "order-insensitive on every level
because both sides sort", but the implementation is
`$this->tables === $other->tables`, and `===` on arrays requires the same key
ORDER. For a snapshot from `of()` the claim holds — both sides come from
`ORDER BY` — so nothing is broken today. A caller building one by hand, however,
gets order-sensitive equality, and there is no caller to break if you would
rather it were canonicalised (a recursive sort). Not changed unattended: it is
API semantics, and the obvious shortcut has a trap of its own — `==` on arrays
ignores key order but compares loosely, and `null == false` is true while a
column's `default` is `string|null`.

Also still open from earlier: decision 18 (whether a pack's envelope contracts
should be listed by name in the core schema test) and decision 22 (whether the
problem-code registry should become a machine-checked contract).

Verified by running. `composer verify` with the live database tests enabled is
**897 tests, 4385 assertions** (from 881 / 4346 — the two new test classes, 16
tests and 39 assertions), level 8 and core-at-`max` both `[OK] No errors`.
`composer coverage` exits **0** with every pack at or above its floor:
core 3048/3467 = 87.91%, db 985/1214 = 81.14%, http-client 263/272 = 96.69%,
validate 461/469 = 98.29%, view 172/176 = 97.73%, all packs 4929/5598 = 88.05%,
with **153 child processes captured contributing 407 lines the parent could not
see**. Both failure probes were run and reverted (150). `lava check --quick` on
the demo: 0.03 s, three runs. `Kernel::boot` on the demo: 0.39 ms mean over 200
iterations with 17 services constructed.



## 2026-09-11 — M9 slice 4 (the R3 gap, and the instrument's second blind spot)

The audit that ended slice 3 left one confirmed hole and one suspicion. The hole
was `db_connection_failed`, exercised by nothing. The suspicion was that a
coverage number is only as good as its instrument — which slice 3 had already
proved once. Both turned out to be real, and the second one was the bigger of
the two.

154. **The R3 audit was 53/53 complete, and only one of its two hits was real.**
    `docs/problem-codes.md` matches the source exactly: 53 codes declared, 53
    registered, zero mismatches in either direction, zero class-name
    mismatches. The audit's own "no literal in any test tree" list named
    `unknown_route` and `db_connection_failed`; checking by CLASS rather than by
    string showed `unknown_route` **is** exercised
    (`packages/core/tests/Unit/RoutingTest.php:295` catches `UnknownRoute` for
    the typo'd name `users.shwo`), so that hit was a limitation of my grep, not
    of the docs. `db_connection_failed` returned nothing from every test tree —
    a real gap. The lesson recorded: a code audit has to search for the class
    name as well as the code string, because a test may assert on the type
    without ever writing the snake_case literal.

155. **`DbConnectionFailed::redact()` leaked a URL-shaped credential.** Found by
    PROBING the function rather than reading it — one of the two things I did in
    slice 3 that paid off. `mysql://user:hunter2@db/app` came back untouched,
    because the only pattern was `/(password|passwd|pwd)\s*=\s*[^;\s]*/i` and a
    URL has no `password=` key to match. That is the `DATABASE_URL=…` shape a
    `.env` carried over from another framework tends to have, and it is exactly
    the shape PDO cannot open at all — so the one report that carries it is a
    report nobody has ever seen succeed. Fixed with a second pattern that masks
    the userinfo up to the `@` (`#(://[^:/@\s]*:)[^@\s]*(?=@)#`), leaving host
    and database legible. Mutation-checked: neutering the second pattern fails
    four of the twelve cases, and the pattern was restored byte-identically.

156. **A docblock claim was checked and found false, so it was rewritten rather
    than shipped.** The class docblock said "some drivers echo the connection
    string back inside their error text", and the draft I was writing went
    further — that `could not find driver` quotes the DSN verbatim. Running
    `new \PDO(...)` against both shapes showed neither installed driver quotes
    anything: pdo_sqlite says `unable to open database file` and an unusable
    scheme says `could not find driver`. So the leak path is not the driver's
    message at all — it is `context.dsn`, a field this problem populates itself.
    Both docblocks now say that: the DSN half is a response to a real leak, the
    message half is a POSTURE ("we cannot know what a given driver will print,
    and the cost of being wrong is a password in a build log") and is labelled
    as one. The synthetic test case that covers the message field says so in its
    own docblock, so nobody later reads it as a reproduction of a real driver.

157. **The end-to-end redaction test asserts on the WHOLE envelope.** The unit
    test covers the two shapes the redaction knows; only a real invocation can
    show that nothing downstream puts the unredacted DSN back. So
    `testAConnectionFailureNeverPrintsTheCredentialItWasGiven` asserts
    `assertStringNotContainsString('hunter2', $result->stdout)` and the same on
    stderr, not `context.dsn` alone — because the field that leaks next time is
    the field that does not exist yet.

158. **The second instrument artifact: a fixture built in a data provider is
    invisible.** PHPUnit's pcov driver calls `pcov\start()` when a test begins
    and `pcov\clear()` when it ends (`PcovDriver::stop()` collects then clears),
    so anything executed during test ENUMERATION is never inside a measured
    window. Measured by capturing the parent cumulatively — running the suite
    with no `--coverage-clover` at all, so no driver ever clears, and collecting
    at shutdown: **10 lines across three files** read as uncovered while
    genuinely executing. The visible symptom was `packages/db/src/Query/Join.php`
    at 0.0% while `CompilerTest` asserted the exact INNER and LEFT JOIN SQL.

159. **Not fixed in the instrument — fixed in the tests.** The honest repair was
    a second suite run (a cumulative parent capture merged like the children
    already are), and it was rejected: it doubles the gate's runtime to recover
    ten lines. The alternative was to notice what the artifact was pointing at,
    and it was pointing at something real: `CompilerTest` builds its `Condition`,
    `Join` and `OrderBy` values DIRECTLY and never goes through `QueryBuilder`.
    So the compiler was thoroughly tested and the app-facing API that feeds it
    was not. The fix is `QueryBuilderChainTest` (17 compiled cases plus three
    behaviour tests) and `QueryBuilderLiveTest` (9 cases run against real
    SQLite), whose fixtures are closures the test calls — which keeps the
    construction inside the measured window as a side effect of testing the
    right thing. Recorded in `tools/coverage-check.php` so a future reader who
    sees a 0.0% suspects a provider-built fixture before suspecting the code.

160. **What the builder gap actually was.** 31/61 executable lines in
    `QueryBuilder`, with no execution at all for: `table()`, the entire `or*`
    family (`orWhere`, `orWhereNull`, `orWhereNotNull`, `orWhereIn`,
    `orWhereNotIn`, `orWhereBetween`, `orWhereRaw`), `whereNull`, `whereNotNull`,
    `whereIn`, `whereNotIn`, `whereBetween`, `innerJoin`, `leftJoin`, and the
    SUCCESS path of `limit` and `offset` — the refusal tests only ever reached
    the throw. Now 61/61. This is the app-facing surface: it is what the demo and
    every user app call.

161. **The live test is not decoration.** Four of its cases exist because
    compiling a string cannot settle them: `LIMIT -1 OFFSET n` is SQLite's
    spelling and a syntax error in MySQL; `LEFT JOIN` against a row with no
    match is the only way to find the users with no posts; `NOT IN` with a NULL
    in the list matches NOTHING (three-valued logic), which is now pinned as
    behaviour rather than left as folklore; and the OR/AND precedence case below.

162. **The OR/AND precedence surprise is documented, not fixed.**
    `where('role','admin')->orWhereIn('plan',['pro'])->whereNotNull('verified')`
    compiles to `role = 'admin' OR (plan IN ('pro') AND verified IS NOT NULL)`,
    because SQL's AND binds tighter. A caller reading that chain as a sentence
    almost always means `(role = 'admin' OR plan IN ('pro')) AND verified IS NOT
    NULL`, and gets the smaller-or-larger set accordingly. The builder has no
    grouping, so the intended reading is not expressible — the live test asserts
    BOTH result sets and asserts they differ, and spells the intended one with
    `whereRaw`. Adding a grouping API is a product decision and is left as an
    open finding below rather than made here.

163. **`Compiled::json()` had no caller, and is now tested rather than deleted.**
    No command in the repository emits a `Compiled`. It is a convention rather
    than dead code — every value object in this pack knows its own JSON shape,
    and `SchemaSnapshot::json()` is in the same position and was tested for the
    same reason in slice 3. Deleting it is an API decision; testing it fixes the
    contract now, so that when a `db:explain` or a `--verbose` path prints one,
    the shape is already pinned instead of invented at the call site.

164. **`InvalidMigrationFile` went from 15.8% to covered as a contract.** Five
    factories, one CLI test touching one of them. `InvalidMigrationFileTest`
    now pins all five (message, fix, context keys, source-or-null, and that the
    unexpected return is reported as a TYPE and never a value), and
    `DbCommandsTest` gains a reachability test for the half the old test missed:
    a correctly-named file that returns the wrong thing, through the real
    binary. `alreadyExists` and `unwritable` are contract-tested but NOT
    reachability-tested, and that is stated rather than papered over:
    `alreadyExists` needs two `db:new` runs inside one wall-clock second (a
    flake, not a test) and `unwritable` needs a directory the test process
    cannot write, which as root it always can.

165. **The db floor was raised from 78.00 to 85.00.** The floors' own docblock
    states the rule — about three points below measurement — and this slice took
    db from 81.14% to 88.64%, which left the floor 10.6 points low. A floor that
    far below its pack is a floor nobody reads, which is the failure mode the
    rule exists to prevent. Raising it also WIDENS the instrument tripwire:
    parent-only db is 55%, so the gap between a working instrument and a broken
    one grew from 23 points to 30. This is a policy change to the gate and is
    flagged as reversible — the reasoning is in `tools/coverage-check.php` next
    to the constant.

### Open finding — **decided and implemented 2026-09-11** (M9 post-release)

**Acted on**: you chose the `whereGroup(Closure)` / `orWhereGroup(Closure)` pair.
The implementation is in `Lava\Db\Query\HasConditions` (the shared where-family),
`ConditionGroup` (what the closure is handed), `Condition::group()` (the nested
value) and `Compiler::terms()` (parentheses); the reasoning, including the one
wrinkle it accepted, is in § M9 post-release below. The text below is the finding
as written; it is kept for the reasoning, not as outstanding work.

**`QueryBuilder` has no condition grouping, so `(A OR B) AND C` is not
expressible.** Decision 162 documents the behaviour and the live test asserts
it, but the API gap is the finding: a chain with an `or*` method in the middle
reads like a grouped boolean expression and compiles as an ungrouped one, and
the only way to get grouping is `whereRaw` — which means the safe, binding-based
API cannot express a query shape that is entirely ordinary. The options are a
`whereGroup(Closure)` / `orWhereGroup(Closure)` pair (nested `Condition` lists,
which the compiler would have to render with parentheses — a change to
`Compiler::where()` and to the `Condition` shape), or documenting `whereRaw` as
the answer. Not chosen here because it changes both the query API and the
compiled SQL of anything that adopts it, and it is a product decision about how
much query builder this framework wants.

Also still open from earlier: decision 18 (whether a pack's envelope contracts
should be listed by name in the core schema test — **decided: read them off
`lava list` in the packed-app fixture instead of listing them here**),
decision 22 (whether the problem-code registry should become a machine-checked
contract — **decided: machine-check it; see § M9 post-release**), and
`SchemaSnapshot::equals()`'s order-sensitivity from slice 3 (**decided: correct
the docblock; the method's order-sensitivity is intended**). The three were
settled in the M9 post-release pass; the sections they are described in are the
record.

### Verified by running

`composer verify` with the live database tests enabled is **958 tests, 4598
assertions** (from 897 / 4385 — 61 tests and 213 assertions added here), level 8
and core-at-`max` both `[OK] No errors`. `composer coverage` exits **0** with
every pack at or above its floor: core 3048/3467 = 87.91%, db 1077/1215 =
**88.64%** (from 81.14%, and 55% measured parent-only), http-client 263/272 =
96.69%, validate 461/469 = 98.29%, view 172/176 = 97.73%, all packs 5021/5599 =
89.68%, with 155 child processes captured contributing 408 lines the parent
could not see. The four files this slice touched read: `QueryBuilder` 61/61,
`Query/Condition` 22/22, `Query/Join` 1/1, `Problem/DbConnectionFailed` 13/13,
`Problem/InvalidMigrationFile` from 15.8% to covered.

The redaction fix was mutation-checked (decision 155). The provider artifact was
measured, not inferred: a cumulative parent capture of the same suite named the
exact 10 lines (decision 158). The `NOT IN` NULL trap, the LEFT JOIN difference
and the OR/AND precedence surprise are all asserted against real SQLite
(decisions 161-162). The db floor change was confirmed to still exit 0
(decision 165).

## 2026-09-11 — M9 slice 5 (conventions.md, the release checklist, and a stale skeleton map)

166. **`conventions.md` was audited against the tree, not read for tone.** A script
    (`/tmp/conv-audit.php`, throwaway) extracted every backticked path, `Class::member`
    pair and schema name from the file and checked each against the filesystem. It
    reported two hard defects and six pairs to confirm by hand. The pairs were all
    real — `Feature::define` (`Features/Feature.php:26`), `Kernel::CORE_SERVICES`
    (`Boot/Kernel.php:51`), `FlagSubjectResolver` (`Features/FlagSubjectResolver.php`),
    `HandlerInvoker::plan` (`Routing/HandlerInvoker.php:39`), `Method::Head`
    (`Routing/Method.php:11`), `UrlGenerator::url` (`Routing/UrlGenerator.php:24`) —
    so the audit's value was the two hits, not a clean bill of health. An audit that
    can only ever say "looks fine" is not an audit.

167. **The `/1` example in the CLI contract was pointing at a file that does not
    exist.** Line 180 read `file: \`lava.check/1\` means \`docs/schemas/lava.check/1.json\``
    — but decision 46 deliberately DELETED `lava.check/1.json`, and `lava.check` is at
    `/2`. A document whose example names a missing file is worse than one with no
    example: the reader goes looking, finds nothing, and stops trusting the rule. Fixed
    by making the example `lava.routes/1` (which exists), stating the `/N` rule so the
    example's numeral cannot go stale again, and adding one paragraph that says the
    numeral is per command and `docs/schemas/` is the list — naming `lava.check` at `/2`
    and why. The explanation was aligned to decision 46's actual reasoning (nothing
    could emit `/1`, nothing had pinned it, the first release is untagged) rather than
    to a rationale invented for the paragraph; the first draft of the fix did the
    latter, and it was rewritten.

168. **`tests/Unit/FrameworkReferenceTest.php` was a wrong path** — the real one is
    `packages/core/tests/Unit/FrameworkReferenceTest.php`. The repo is a monorepo, so a
    path without a package prefix reads as "at the root" and there is no such directory.
    Fixed. This is the failure mode of the whole file: every path in it is either
    app-relative (correct as written — `app/Routes.php` means the app's, not the repo's)
    or repo-relative (needs the package prefix), and the two are indistinguishable
    unless you go look.

169. **A stale measurement in prose is a defect, and there were two.** `README.md` and
    `.github/workflows/ci.yml` both still explained the coverage instrument with the
    pre-chain-tests db number ("81%"). Both now say 55% — the parent-only figure, which
    is the one the sentence is actually about and does not move when the suite grows. A
    number in prose that tracks the code is a number that will be wrong; the fix is to
    cite the one that is a property of the instrument.

170. **The skeleton's committed `AGENTS.md` was STALE, and that is R2 broken in the
    committed tree.** `lava map --check` in `packages/app` failed: hash `69c87bdd…`
    recorded, `56720f28…` expected. The cause is provable from history rather than
    guessed: commit `377b736` (M8 slice 1, lava/view) registered `Router` and
    `UrlGenerator` as container ids in `Boot/Steps/BuildRouter.php`, which changes the
    services list — and therefore the fingerprint — of EVERY app. `8085486` (M8 slice 3)
    regenerated the demo's map because it touched the demo, so the demo stayed fresh;
    the skeleton's map was last written in `e9274b1` (M7 slice 2) and nothing
    regenerated it. It survived M8 and M9 because `composer verify` cannot see it — no
    test asserts the skeleton's AGENTS.md — and the CI job that does (`skeleton`, step
    `lava map --check`) has never had a green run on GitHub. Fixed by regenerating:
    the diff is the two service rows plus the hash, nothing else.

171. **The regeneration was verified path-independent, which is the property that
    makes the fix trustworthy.** A map that reads "current" only at `packages/app` would
    be a fingerprint that depends on where the checkout lives, contradicting the
    "no absolute paths" rule the document itself states. The regenerated file was copied
    to `/tmp/skel-check/app` with `vendor/lava/core` repointed at the real core, and
    `lava map --check` there answers "current" with the same hash. Same bytes, different
    absolute path — so the fix is a fact about the app, not about this machine.

172. **R2's promise has a precondition the doc did not state, and the release checklist
    now does.** The skeleton "ships a pre-generated AGENTS.md accurate from the moment of
    `composer install`" — accurate against the core it was generated from. Any core
    change that adds a container id invalidates every app's map, so the maps must be
    regenerated as the LAST step before a tag, not at some earlier convenient point.
    `docs/releasing.md` step 4 is that step.

173. **The release checklist is `docs/releasing.md`, and it states what is NOT
    verified.** A checklist that implies everything was checked is worse than one that
    lists the gaps, so it names three: the CI coverage job has never run green on
    GitHub (the mechanism is proven locally, setup-php's image is not), PHP 8.3/8.4 are
    CI-only (development is on 8.5.4), and no release has been published so
    `create-project` from Packagist is untested. It also fixes the 0.x promise
    precisely — stable: the pillars, the artifact paths, the problem codes, the
    envelope schemas; not stable: class/method signatures inside a pack.

174. **No `CHANGELOG.md`, and the omission is deliberate.** The record is this file
    plus `git log`; a hand-maintained changelog beside them is a third account of the
    same events, and it drifts first and is believed second. If consumers want one it
    should be generated from the commit log at tag time. Recorded in `docs/releasing.md`
    so it reads as a decision rather than an oversight, and flagged here as reversible.

175. **`composer.json` carries no `version` field and no command prints a framework
    version.** Packagist derives the version from the tag, so a field would be a second
    answer that goes stale the first time a tag is cut from a branch. The first draft of
    `releasing.md` claimed `lava about` reports the framework version; it does not — it
    reports the RUNTIME's facts (PHP, extensions, PDO drivers, which packs the app can
    see). Corrected before committing, because a checklist that sends a reader to a
    field that is not there is the same defect as decision 167.

176. **`lava check --quick` on the demo is 0.03–0.05s against R4's <2s budget** —
    measured three times, not estimated. R4 is met with two orders of magnitude to
    spare, which is worth recording because it means the budget is not a constraint on
    what `check` may add; it is a constraint that has been paid for already.

### Verified by running

`composer verify` after the doc edits: **958 tests, 4598 assertions**, level 8 and
core-at-`max` both `[OK] No errors`. `lava check --strict` on `apps/demo`: every
section ok, 24 tests / 99 assertions, exit 0. `lava check --quick` on the demo:
exit 0, timed 0.05/0.03/0.03s. `lava map --check` on `apps/demo`: current, exit 0.
`lava map --check` on `packages/app`: FAILED before the fix (stale), current after,
and current again from `/tmp/skel-check/app` — a different absolute path. Only the
root `composer.lock` is tracked; every pack's and app's lock exists on disk and is
ignored, as documented. `composer validate` passes on all four packs and the root.

177. **This checkout has no git remote, which explains the CI gap structurally and
    makes the tag a local one.** `git remote -v` is empty and `main` is the only
    branch, so the five CI jobs have never run and cannot until a remote exists.
    Two consequences recorded rather than worked around: `docs/releasing.md` step 6
    now says the CI check is the step that CANNOT be run from here, and tells the
    reader to push a branch, watch it go green, and only then tag — because a tag
    that has to move is worse than a late one. The 0.1.0 tag created at the end of
    this milestone is therefore LOCAL and unpushed; pushing it is the publication
    act and is the user's call, gated on that first green run.

178. **The pack's HTTP harness leaked its temp files; the demo's copy of the SAME
    harness had already been fixed.** Decision 116 (M8 slice 3) fixed exactly this
    in `apps/demo/tests/Support/UpstreamServer.php` — `stop()` unlinks the
    `tempnam` log and the `/flaky-<id>` counter files, with the ids recorded at
    hand-out time so the harness owns their lifetime. The pack's
    `tests/Support/LocalServer.php` is the deliberate duplicate of that file
    (decision 108: two copies, so each can prove its own claim) and it was never
    given the fix. 179 files had accumulated in `/tmp` — 110 drop counters, 39
    server logs, 30 not-an-app dirs — and the leak was live: a suite run added
    about nine. Nothing failed, and nothing that passed said anything, which is
    the whole reason this class of leak survives. Fixed by mirroring the demo's
    shape rather than inventing a second one: `COUNTER_PREFIX` as a public const,
    ids recorded in `flakyId()`, and `stop()` removing the log plus every counter
    carrying a recorded id. The counter NAME is not known at hand-out time (an id
    is handed out before a test picks a counter), so the cleanup globs by id; the
    id is hex, so it cannot inject a wildcard. Verified by running: the pack suite
    now leaves **0** files, was ~9.

179. **`stop()` deleting the log exposed a second bug in the same method, in both
    copies.** Both `LocalServer::shared()` and `UpstreamServer::shared()` read the
    server's log INTO the "did not come up" exception — after calling `stop()`.
    Once `stop()` unlinks the log, that read returns an empty string and a
    warning, so the one diagnostic that explains a failed start would have gone
    silent exactly when it was needed. Fixed by reading the log before stopping,
    in both. This is a change to a rarely-taken path, and it is worth stating
    plainly that it was found by reading the method I was editing rather than by
    a failing test: nothing covers "the fixture server never came up", because
    making it happen is not a thing a test can arrange.

180. **The pack's `/flaky` fixture route modelled a rule the pack deliberately does
    not have.** It failed `fail` times with a 500 and then succeeded — i.e. it
    modelled retrying a 5xx. `HttpClient`'s docblock states the retry boundary is
    "no response arrived", and that retrying a 5xx without honouring `Retry-After`
    and without jitter is a request the caller's rate limiter pays for twice. So
    the route was both unexercised (no test requested it) and wrong in the
    direction that matters: a future test written against it would have pinned
    the opposite of the pack's rule. Rewritten to the demo's semantics —
    truncate on the first attempt, answer the second — which is the boundary the
    pack retries on, and then actually exercised: decision 181.

181. **`testARetryThatSucceedsReturnsTheGoodResponse` closes a real gap.** The
    pack's retry rule was covered from both ends already — attempt COUNTS via
    `FakeTransport` in-process, and two live tests asserting the count when every
    attempt fails — but nothing asserted the positive path over a socket: that a
    retry which succeeds returns the good response to the caller. The new test
    asserts the status, the body AND the server-side counter, so the second
    attempt is the server's fact and not the client's opinion. Also added:
    `testTheFixtureRouterKeepsCountersWhereTheHarnessLooksForThem`, which asserts
    the router file contains `LocalServer::COUNTER_PREFIX`. `LocalServer`'s
    docblock had claimed a test kept the two duplicated prefixes honest; it did
    not, so the claim was false. It is true now.

182. **The 0.1.0 tag was re-cut, and that is only legitimate because it is local.**
    The tag created earlier in this milestone pointed at `f4ede08`; these harness
    fixes landed after it. A tag that has not been pushed and that nothing has
    resolved against may be moved — and my own checklist says a tag CI has seen
    may not be, which is the distinction that matters. The tag now points at the
    final commit of M9, and it is still unpushed.

### Verified by running

`composer verify`: **960 tests, 4603 assertions** (from 958 / 4598 — the two new
tests), level 8 and core-at-`max` both `[OK] No errors`. `composer coverage` exits
**0**, every pack at or above its floor, unchanged (the changes are tests and
fixtures, not `src`). The demo suite: 24 tests / 99 assertions, 0 files left in
`/tmp`. The http-client suite: 100 tests / 261 assertions, **0 files left** — it
was about nine per run. The 179 accumulated artifacts were removed.

## 2026-09-11 — M9 post-release: the five decisions, and what acting on them found

The findings left open through M4–M9 were put to you with options; five were
answered and are implemented here. Decisions 1–4 are the flag rule and the
three smaller corrections; decision 5 is the query group; decision 6 (the
remote and the branch push) is last and is the only one that leaves the machine.
Everything in this section was verified against the real binary, and the numbers
are at the end.

183. **The flag check lives in the KERNEL, not in each command.** `Args` parses
    any `--flag` it is handed and nothing compared that against the command's
    `flags()`, so `lava routes --strct` printed the route table and exited 0. The
    check is in `Console::run()` for the same reason the `LavaProblem` catch is:
    it is the single dispatch point, so a pack command written tomorrow is
    covered without its author knowing to write the check, and a check each
    command must remember is one the next command forgets. Doing it per command
    would also have to be re-litigated in a review of every future pack.

184. **A flag is universal exactly when the KERNEL reads it, and that rule
    decides the set.** `Command::UNIVERSAL_FLAGS = ['json','quiet','help','env']`.
    The membership test is mechanical rather than a matter of taste: `json` and
    `quiet` build the writer (`IO::standard`), `help` is answered by the
    dispatcher before the command is consulted, and `env` selects the boot — so
    none of the four needs a command's cooperation, and requiring every command
    to declare them would be describing the kernel's work as the command's.
    `--env` was the interesting one you flagged; it is universal on this rule
    even though commands also declare it, and the `env` in the fix list below is
    the command's own declaration, not this const.

185. **`--json` stays declared by commands even though it is universal.** The
    `flags` column in `lava list` describes what a command makes of its
    arguments, and a reader scanning that table should not have to know
    `UNIVERSAL_FLAGS` to learn that `--json` works. The const is the enforcement
    rule; `flags()` is the description. Two questions, so two lists — and the
    union is what the check uses, which is why the pair cannot disagree.

186. **`emptyPayload()` became `public`, and the kernel seeds it too.** Two
    envelopes are emitted WITHOUT the command running — `--help`, and an
    invocation refused for an undeclared flag — and both claim `lava.<cmd>/N`,
    whose schema requires its `data` keys on every exit path. So the kernel
    seeds the command's declared shape before it dispatches. That needed the
    method public (15 one-word widenings, one per command), and a public method
    was chosen over a second forwarding method because two names for one concept
    is how a shape starts to drift. `emptyPayload(Args)` deliberately stays a
    function of the INVOCATION alone — not of the app — which is the rule that
    later forced the `map` contract change (see 191).

187. **`--help` is answered BEFORE the flag check, so a typo cannot hide the
    answer to the question it was asking.** `lava routes --strct --help` prints
    the usage and exits 0. Failing the help request would hide the flag list
    behind the mistake that needed it, and `--help` is exactly what the refusal
    tells the caller to type next; a refusal-to-refusal loop would be the
    framework's own fix hint failing to work.

188. **The fix names the command's own flags and the universal four are left
    out.** `Run: lava routes --help (it accepts --all, --env, --json)` — not a
    padded list including `--quiet` and `--help`. Two reasons: those two can
    never reach this error, so listing them would suggest the typo might be one
    of them; and the list is the answer to "what did I mean to type", so it
    should contain candidates for the mistake rather than a complete inventory
    of the parser's abilities. `accepted` in `context` is sorted, so two runs of
    the same mistake produce byte-identical output — a diffable payload.

189. **A pack's flag is a flag in this process, and the check still refuses it
    on the wrong command.** `packages/db/tests/Cli/DbUsageTest.php` pins the two
    edges a blanket rule gets wrong: `db:rollback --batches=1` must still be
    accepted, and `db:status --batches=1` must be refused even though a sibling
    in the same pack declares `batches`. `lava db:status --batches` is `lava env
    --strict` in pack form — the mistake a helpful-sounding flag invites — and
    it is why the check compares against the command's `flags()` rather than a
    process-wide union of everything any command declares.

190. **The guard that proves it is GENERATED from `lava list`, not a list of
    cases.** `JsonSchemaTest::testARejectedInvocationObeysTheSchemaItsCommandClaims`
    boots `packed-app` (the smallest app that enables lava/db), reads the command
    set off the payload, refuses each command a flag it does not declare, and
    validates the envelope that comes back against the schema THAT command
    claims. A command added to core or a pack tomorrow is covered without anyone
    remembering to add it — and it found two real bugs within minutes of
    existing, which is the argument for the generated form in one sentence.

191. **That guard immediately found `lava.check/2` violating its own contract.**
    `strict` and `quick` are required properties of `lava.check/2` and were
    written only in `report()` — so any envelope emitted before it (a section
    that throws, a refused flag, `--help`) claimed the schema while missing two
    of its keys. Both are functions of how the invocation was TYPED, not of
    anything inspected, so the fix is to seed them: knowable before the boot,
    which is exactly the test the seed must pass.

192. **And then `lava.map/1`, whose seed could NOT be made honest — so the
    contract changed instead: `lava.map/2`.** `path` and `fingerprint` were
    typed `string`, but `fingerprint` is a hash of the app's DECLARATIONS, so no
    invocation that never booted has an honest value for it — a failed boot, a
    refused flag, and `--help` all emit it. `/2` widens both to
    `["string","null"]`, with null meaning "the app was never read". The idiom is
    already in that schema (`found` is nullable), so this is not a new concept.
    Consequences accepted: **bumping is right even though no emitted value
    changes**, because the numeral is how a consumer LEARNS it must handle a
    value it was told could not occur — a consumer that pinned `/1` and receives
    a null `path` on a failed boot is precisely the one that needs to be told.
    `/1` was deleted rather than kept beside `/2`, exactly as `lava.check/1` was:
    nothing could emit it, so it would be a schema file no code produces, which
    is a document that lies about what exists. `Envelope::VERSIONS` gained the
    entry, and `MapCommandTest` now asserts `/2` — one home for the version means
    a half-applied bump cannot happen, which the test then proves.

193. **The alternative for `map` was rejected on cost, and the rejection is
    recorded.** Threading the app directory into `emptyPayload` would have made
    `path` a real string (it is knowable pre-boot), but `fingerprint` still could
    not be, so the contract would still have needed `/2` — a signature change
    across 20 command classes plus the kernel, buying one nicer key on a payload
    whose `problems` are the thing to read. Keeping `emptyPayload(Args)` a pure
    function of the invocation is one rule; the alternative was two rules and a
    wider API.

194. **A richer failed-boot payload for `map` is noted and NOT done.** On a
    failed boot `found` and `path` are both knowable without a boot (they come
    off the file), so `map` could report "your AGENTS.md claims hash X and I
    could not compute what the app hashes to" instead of nulls. That is a
    behaviour improvement, not a contract repair, and it is not in the decision
    this pass was implementing. Recorded here so it is a choice rather than an
    oversight.

195. **`whereGroup` needed the closure to be handed a type that cannot lie.**
    The obvious implementation — hand the closure a `QueryBuilder` — would accept
    `->limit(5)` inside a group and drop it, which is the silent-ignore failure
    this framework bans everywhere else. So the closure gets a `ConditionGroup`,
    a class whose ONLY methods are the condition ones. To avoid a second copy of
    twenty method bodies (and the drift that comes with it: `IN ()` refused,
    `= NULL` refused, one bound argument each), those bodies moved into a trait,
    `HasConditions`, used by both. Sharing the BODIES rather than the TYPE is the
    distinction that matters: a group is not a builder and a builder is not a
    group, which is what a trait expresses and what an inheritance chain would
    have had to lie about. `ConditionGroupTest` asserts all three parts — the
    shared vocabulary, the absent terminal vocabulary, and that the two are not
    `is_a` each other — so the design cannot quietly regress into "hand it the
    builder, it's easier".

196. **A group is a `Condition`, and the compiler is still the only renderer.**
    The builder has no dialect, so it cannot quote columns and therefore cannot
    pre-render a group; `Condition::group()` stores the nested list and
    `Compiler` renders it. That keeps the pack's one real invariant intact — the
    compiler trusts what it is handed and never re-checks — and it means a
    group's columns are quoted, its bindings ordered, and its `whereRaw`
    fragments bound by the same code as the rest of the clause. The cost is
    stated in `Condition`'s docblock rather than hidden: a group's
    `expression`/`operator`/`bindings` hold `''`/`Raw`/`[]` because the
    properties are non-nullable, NOT because they mean anything, and
    `isGroup()` is the only sanctioned way to ask which kind a Condition is.

197. **`AND`/`OR` placement now has exactly one home.** `Compiler::where()` and
    the group renderer both call a new `terms()`, so the prefix loop — including
    the first-term-has-no-prefix rule — is written once. A group that rendered
    its own body would have been a second implementation of the one thing most
    likely to differ, and `terms()` is what makes nesting free rather than a
    special case.

198. **An empty group is `bad_query`, not `()`.** `whereGroup(fn ($q) => null)`
    compiles to `()` — a syntax error on every dialect — so `Condition::group()`
    refuses it with the fix naming the closure to write. This is also what makes
    `isGroup()` total (a group is never the empty list), which is why the
    discriminant can be the nested list at all.

199. **The live test that documented the limitation now documents the fix, and
    proves the group equals the raw SQL it replaced.** The old test asserted the
    ungrouped chain selects a surprising set and said the intended reading "is
    not expressible". Both halves survive: the chain still means what precedence
    says, and the intended reading is now written with `whereGroup` and RUN
    against the same database as the hand-written fragment — so "the group is the
    same query" is an executed assertion, not a comment. The one thing a raw
    fragment could get wrong while looking right is placeholder order, which is
    why the equality is asserted by running both.

200. **`ServeCommand`'s defaults became constants and its URL one method.**
    `DEFAULT_HOST`/`DEFAULT_PORT`/`DEFAULT_WORKERS`/`DOC_ROOT`/`ENTRY_POINT`
    replace literals that appeared in the seeding, the resolution, two prose
    lines and the `proc_open` path — so a `serve` envelope that could not boot
    cannot describe a different server than the one it would have started, and
    `url()` builds the URL in one place. Found while making `emptyPayload`
    honest, which is the general lesson: the seed is where a payload's
    duplications become visible.

201. **`lava.list` stayed at `/1`, and the reason is the frozen-`/N` rule
    read precisely.** The `schema` column it gained in decision 2 is ADDITIVE —
    every payload valid under `/1` is valid under the new one and the schema file
    was edited to match, which is what an additive change is allowed to do.
    `lava.check` and `lava.map` were bumped because a consumer could MISHANDLE
    the new value (a widened enum, a widened type); nothing here can. Both moves
    follow the same rule, and the rule is what decided them rather than
    symmetry.

202. **Decision 18's answer was implemented as a FIXTURE, not a list.** The core
    schema test no longer names the pack contracts: it reads `commands[].schema`
    off `lava list` in `packed-app`, so the colon-to-dot rule and the
    per-command version have exactly one home (`Envelope::schema()`). A pack
    adding a command now edits a fixture app's manifest — the same edit an app
    makes to USE the pack — instead of a core test, which is the difference
    between a use of the registry and a copy of it.

203. **Decision 22's answer is a machine-checked registry, and it was already
    live.** `lava list` reports each command's `schema`, and
    `JsonSchemaTest`/`DbSchemaTest` check the union in both directions: a schema
    file for a command nothing registers, and a command whose file was never
    written, both fail. The registry is therefore `docs/schemas/` plus
    `Envelope::schema()`, and it cannot drift silently.

204. **Decision 4 was a docblock correction, and the correction is stated as
    such.** `SchemaSnapshot::equals()` is order-sensitive, that is intended (a
    column order difference is a schema difference), and the docblock said
    otherwise. The fix was the docblock, not the method: changing the method
    would have hidden a real drift signal to make a sentence true.

205. **The release is a BRANCH, and the tag stays local.** You authorised
    creating a remote and pushing a branch so the five CI jobs run for the first
    time. The `0.1.0` tag does NOT go with it: the tag is what makes a release
    real, and the whole point of the run is to learn whether it is deserved. See
    the entry that records what was pushed and what CI said.

### What is still not verified

- **The five CI jobs have never run.** Everything above was verified locally, on
  PHP 8.5.4 with the sqlite/pcov extensions loaded from `/tmp`. PHP 8.3 and 8.4
  exist only in CI, so the `^8.3` floor is asserted by the constraint and by
  nothing else yet.
- **The coverage job is verified locally and not on GitHub.** `composer coverage`
  exits 0 here with pcov; whether the runner's PHP has the driver is a question
  only CI can answer, and it is the one job most likely to differ.
- **`lava serve` cannot be verified to completion in this harness.** Its success
  path blocks by design; the payload shape is checked on the usage-error path and
  the server itself is exercised by the router tests, but a real browser-facing
  run is not something this pass did.

### Verified by running

`composer verify`: **992 tests, 5382 assertions** (from 960 / 4603 — 32 tests and
779 assertions added), level 8 and core-at-`max` both `[OK] No errors`.
`composer coverage` exits **0** with every pack at or above its floor: core
3110/3511 = 88.58%, db 1097/1234 = **88.90%** (up from 88.64% — the group API's
new `src` lines are covered), http-client 263/272 = 96.69%, validate 461/469 =
98.29%, view 172/176 = 97.73%, all packs 5103/5662 = 90.13%.

The flag rule and the `map` contract were both run through the real binary, not
read: `lava map --strct --json` emits `lava.map/2` with
`{"path":null,"fingerprint":null,…}` and a `bad_usage` problem naming the flag,
the accepted list and the fix, exit **2**; `lava check --strct --json` on an app
that cannot boot emits `lava.check/2` with `strict`/`quick` seeded and the same
one-problem report; universal and short flags are accepted everywhere;
`--help` wins over a typo; `--` literals stay positional; and on the demo,
`lava map --check` reports `lava.map/2` with `fresh: true` and exits 0, which is
also the proof that the version bump reached the binary rather than only the
class.

## 2026-09-11 — the first CI run: three parse errors, a PHP 8.3 `php -S` difference, and a new gate

206. **CI ran, and `test (8.3)` failed on a real violation.** Six of seven jobs
    went green on the first push of `m9-post-release` (`demo`, `test (8.5)`,
    `test (8.4)`, `isolated-install`, `skeleton`, `coverage`); `test (8.3)`
    reported `syntax error, unexpected token "->"` at
    `packages/db/tests/Query/QueryBuilderTest.php:140`. This is the first
    information the repository has ever had about the `^8.3` floor it declares,
    and the information is that the floor was being violated. Entry 205 said
    "see the entry that records what was pushed and what CI said" — this is it.

207. **The violation was PHP 8.4 syntax: parentheses-free `new` in member
    access.** `new QueryBuilder('users')->where(…)` is legal from 8.4 and a
    PARSE error on 8.3, which matters because a parse error kills the whole
    file, and with it the run. Fixed to `(new QueryBuilder('users'))->…` in both
    places it appeared. The class of bug is only reachable by a parse at the
    floored version: the host is 8.5, PHPStan is happy, and the suite is green
    locally, all while the file cannot compile on a version the manifest claims
    to support.

208. **PHPUnit stops at the FIRST file it cannot compile, which is why the
    local sweep mattered.** A repo-wide `php -l` under a real 8.3 found a
    *third* occurrence CI had not reached:
    `packages/validate/tests/Rules/CustomRuleTest.php:66`, same construct. CI
    would have found it too — on the next push, after the first was fixed. One
    local pass reported all of them; three CI cycles would have been needed
    otherwise. That asymmetry is the whole argument for the gate in 210.

209. **php-parser CANNOT check the floor, and this was measured rather than
    assumed.** The obvious implementation — `nikic/php-parser` is already in
    `vendor/`, so parse every file with `PhpVersion::fromString('8.3')` — does
    not work, and fails *silently*, which is the worst way for a gate to fail.
    Three findings, all on v5.8.0:
    `ParserFactory::createForVersion()`'s own docblock says the parser "will
    generally accept code for the newest supported version" and only the LEXER
    is version-aware; a probe confirmed `new Foo()->bar()` and property hooks
    both parse cleanly at target 8.3 while `= =` still throws, so it catches
    token-level nonsense and not newer grammar; and the two spellings produce
    **byte-identical ASTs** (`Expr_MethodCall(var: Expr_New(…))` either way),
    so no AST visitor can tell them apart afterwards either. The plan was
    abandoned at this point rather than shipped as a check that passes
    everything. It is recorded here so it is not re-attempted.

210. **The systemic fix is `composer check:floor` — a real floored interpreter,
    borrowed from docker.** `tools/php-floor-check.php` lints every tracked and
    untracked-but-not-ignored PHP file with the oldest version `composer.json`
    claims to support, and prints **every** file that does not parse, not the
    first. Three decisions inside it:
    - **The floor is READ from `require.php`, never restated.** A second copy of
      "8.3" in the tool is a copy that can drift, and the drifting one is the
      one nobody reads. The constraint shape is asserted rather than
      pattern-matched loosely, so a future `>=7.4 || ^8.3` fails loudly instead
      of quietly linting the repository against 7.4.
    - **Not part of `composer verify`**, for the same reason `coverage` is not:
      it needs a PHP of the floored version. `verify` stays runnable anywhere.
      When docker is absent on a newer host it FAILS with the fix rather than
      passing quietly — a floor check that skips itself is indistinguishable
      from a floor that holds.
    - **One container for the whole list**, not one per file: a container start
      dominates `php -l`, and a gate slow enough to be skipped is a gate that
      gets skipped. It uses the host interpreter directly when the host IS the
      floor, which is the only case needing no container at all.

    It was proven by injection, not by reading: two files carrying an 8.4-ism
    each (the paren-free `new`, and a property hook) were added, the gate
    reported BOTH with their real interpreter messages and exited 1, and it
    went green again when they were deleted. A gate that has never failed is a
    gate you do not know works.

211. **A second, unrelated 8.3 incompatibility: `php -S` ignores
    `auto_prepend_file` on 8.3.** With the syntax fixed, `test (8.3)`'s
    equivalent — the full suite in a `php:8.3-cli` container — still failed, on
    **20 of 22 `ServeTest` cases**, every one a 500 where a 200 or 404 was
    expected. The isolation that made this actionable:
    - the same container with `php:8.5-cli` passed **all 992**, so it was not
      the container, the mounts, or the environment: it was 8.3;
    - the 500 body named the cause precisely — `App\Http\TimingMiddleware`,
      `App\Greeter` and the controllers "do not exist", i.e. the fixture's
      `App\` autoloader was absent;
    - a direct probe settled it: a prepend file that appends to a log inside
      `php -S` workers ran **on 8.5 and never ran on 8.3**.

    So `ServeCommand::prependArguments()`, which forwards the parent's
    `auto_prepend_file` to the `php -S` child, is a **no-op on 8.3**. Only
    `serve` is affected, which is why nothing else failed: every other harness
    path spawns a plain `php bin/lava`, and there the prepend does run on 8.3.

212. **The fix is in the fixtures, not the framework, and that placement is the
    point.** A real app's `public/index.php` requires its own composer
    autoloader, which maps `App\` — serve works for real apps on 8.3 and always
    did. The prepend exists only because fixture apps have no `composer.json`.
    So the four fixture entry points (`ok-app`, `module-app`, `subject-app`,
    `bad-routes-app`) now `require` the shared
    `packages/core/tests/Support/fixture-autoload.php` themselves. That file is
    idempotent (`$GLOBALS['lava_fixture_autoloader']`), so where the prepend did
    run it costs one function call, and the mapping rule keeps its single home
    instead of gaining four copies. **No production code changed** — the
    framework's HTTP path never depended on the prepend.

213. **`prependArguments()` was kept, not removed.** It is still the mechanism
    by which a SERVED app gets pcov coverage capture (the prepend is where
    `pcov\start()` lives for children), and the `coverage` job runs on 8.5 where
    it works. Removing it would trade a documented 8.3 no-op for an
    undiagnosed coverage hole. The no-op is now recorded in the fixture
    comments and here rather than left to be rediscovered.

214. **Docs were updated where the work made them false.** `docs/releasing.md`
    step 6 still claimed the repository "has **no git remote configured**, so
    the workflow has never run" — untrue since entry 205. It now describes the
    jobs as the authority on the versions, adds `composer check:floor` as step 3
    (renumbering the rest), and states the asymmetry CI cannot fix: PHPUnit
    stops at the first uncompilable file, so a commit with N 8.4-isms costs N
    pushes. `README.md` gained the `check:floor` line and the reason it and
    `coverage` sit outside `verify`.

215. **CI is green on all seven jobs, `test (8.3)` included.** Run
    `34653678933`, on `1ad17a8`: `demo`, `skeleton`, `isolated-install`,
    `test (8.5)`, `test (8.4)`, `test (8.3)` and `coverage` — all `success`,
    zero failing jobs. The 8.3 job is the one that matters here: it ran the full
    suite on the version the manifest claims, on a machine that is not this one,
    and it passed. The floor holds.

    That closes the verification gap entry 6 of `docs/releasing.md` described
    and entry 205 left open. It does **not** authorise the tag: decision 6 was
    "push a BRANCH only", and the tag was explicitly withheld from that
    authorisation. Pushing a release tag is on the "stop and ask" list, so
    `0.1.0` is still local and stays local until the user says otherwise.

### What is still not verified

- **The container was a proxy, and the proxy was right.** `php:8.3-cli` is not
  GitHub's 8.3, so the local runs could not have proved the CI result — but they
  predicted it exactly, including the parse error verbatim. The `php -S`
  behaviour is a property of PHP rather than of an image, which is why it
  transferred. Nothing here is now pending on CI.
- **`php:8.3-cli` must be pulled once.** The floor gate needs network on a cold
  machine. It says so rather than failing obscurely, but a machine with no
  docker cannot run it — which is why it is not in `verify`, and why the 8.3
  matrix job remains the authority.
- **`git push origin main` will be non-fast-forward.** The org repository's
  `main` and `dev` hold an unrelated 2024 prototype (`decec6a`, `bb5492b`) and
  have never been touched by this work. Landing on `main` therefore means either
  a force-push or a rename, and both are irreversible — a release-time decision
  for the user, not a thing to pre-empt.


### Verified by running

The full suite in containers, one per floored version, with `DB_TEST_DSN` set:
**992 tests, 0 failures on php 8.3, 8.4 and 8.5** (1 skip in each: the
`pdo_sqlite`-gated CLI case, which those images lack). Before the fixture fix
the 8.3 run had 20 failures, all `ServeTest`, all 500s; the 8.5 run of the same
container was green throughout, which is the control that made the diagnosis
possible.

`composer verify` on the host: **992 tests, 5382 assertions, OK**, then
`[OK] No errors` from PHPStan level 8 and again from `phpstan:core` at `max`.
The host run reaches one test and 5 assertions more than the containers because
it has `pdo_sqlite` and pcov on `PHP_INI_SCAN_DIR`.

`composer check:floor`: **"448 file(s) parse on PHP 8.3"**. Proven by injection
— two probe files each carrying an 8.4-only construct were reported together
with their real messages, exit 1, and the gate returned to green when they were
removed. The 8.3 diagnosis itself was confirmed against a real interpreter
before anything was changed: `php -l` on 8.3 reproduces CI's
`syntax error, unexpected token "->"` verbatim on the old spelling and accepts
the new one.

The `php -S` finding was measured directly rather than inferred: a prepend that
writes a line in the worker logged `ran pid=7 env='/probe/path'` under
`php -S … PHP_CLI_SERVER_WORKERS=2` on 8.5, and produced no file at all on 8.3,
while `variables_order` (`EGPCS`) and `getenv()`'s contents were identical on
both — which is what ruled out the environment and left the SAPI behaviour.

And CI: **`gh run view 34653678933` reports all seven jobs `success`** on
`1ad17a8` — `demo`, `skeleton`, `isolated-install`, `test (8.5)`,
`test (8.4)`, `test (8.3)`, `coverage`. The docs commit that recorded it
(`ca351c6`, run `34653771104`) is green on all seven as well. The first green
runs of this workflow, and the first time the `^8.3` floor has been checked by
anything other than the manifest that declares it.

**Serving a REAL app on 8.3 was then run, not reasoned** — the last claim in
this section that had been inference. `apps/demo` (which has its own installed
`vendor/`, so it gets `App\` from its own composer autoloader the way any real
app does) was migrated and served under `php:8.3-cli`:

| Request | Result |
|---|---|
| `lava db:migrate --json` | `lava.db.migrate/1`, 1 migration applied, batch 1 |
| the serve envelope | `lava.serve/1`, `"booted": true` |
| `GET /tasks` | **200** `{"tasks":[]}` — controller, middleware and the database all resolved |
| `GET /upstream/health` | **502** `transport_failed` — nothing listening on :8080, which is the correct diagnosis rather than a symptom |
| `GET /nope` | **404** `route_not_found`, with its `lava routes --json` fix hint |
| `"does not exist"` in the server log | **0** |

That last row is the one that matters: it is the exact string the fixture
failures were made of, and its absence is what shows the 8.3 breakage was a
property of the FIXTURE HARNESS and not of the framework. The asymmetry entry
212 asserted — real apps fine, fixtures broken — is now measured on both sides.
It also closes the objection that the fixtures' fix might have been papering
over a real 8.3 defect in `lava serve`: it was not, because on 8.3 `lava serve`
serves a real app correctly with no fixture scaffolding at all.

The repository was left untouched by that run (the database went to `/tmp`, and
`git status` is clean afterwards), which is the check `docs/releasing.md` asks
for after anything that exercises the demo.

## 2026-09-12 — the remote named after this project now contains this project

216. **`origin` held an unrelated 2024 prototype, and the name it occupied was
    the name this project needed.** `BusyBeaverSoftware/LavaPHP` was a private
    org repo whose `main` was `decec6a` ("working on the router and dispatcher
    for simple api framework", 2024-05-01) and whose `dev` was `bb5492b`
    ("first code commit :)") — a three-commit prototype with **no common
    ancestor** with this work. So the repository bearing this project's name
    contained none of it, while the branch that *is* this project lived on a
    branch of a repo whose default branch pointed at something else entirely.
    Replaced by user decision, in the order "rename first, delete last":
    rename, create, push, verify, and only then delete.

217. **"Rename first, delete last" rather than delete-then-create.** The
    ordering is not caution theatre; it is the difference between a plan that
    can fail safely and one that cannot. Renaming frees the name while leaving
    the prototype intact, so every later step — creating the new repo, pushing
    `main`, watching CI — can fail and be retried with nothing lost, and the
    delete happens last, when the thing that replaces it is already proven.
    Delete-first would have destroyed the only remote copy of the prototype
    before knowing whether the replacement could be pushed at all.

218. **GitHub leaves a redirect after a rename, so `origin` was re-pointed
    BEFORE the new repo existed.** `git remote rename origin archive-2024` plus
    an explicit URL update means that for the whole window between the rename
    and the new remote, there was no remote that could accept a push — a push
    to the old URL would have followed GitHub's rename redirect into
    `LavaPHP-archive-2024` and landed silently in the archive. The remote is
    now gone entirely; `origin` is the only one left.

219. **`main` was fast-forwarded to include the four floor fixes.** Local `main`
    was `ffd751a`, four behind `m9-post-release`; `git merge --ff-only` moved it
    to `cdc75ee` with no rewrite. The reason is the audience: `main` is what a
    visitor clones from a public repo, and `ffd751a` is the commit whose 8.3 job
    failed (entries 206–208). A default branch that does not compile on a PHP
    version the manifest claims to support is the worst possible first
    impression, and the fix already existed four commits up.

220. **The 2024 prototype was preserved locally before the archive was
    deleted.** `2024-prototype-main` → `decec6a` and `2024-prototype-dev` →
    `bb5492b`. The archive's third branch, `m9-post-release` → `cdc75ee`, was
    this project's own work and needed no preservation — it is now the new
    repo's `main`. Deletion came only after all four conditions held: the new
    repo existed, `main` was pushed and confirmed at `cdc75ee` by `ls-remote`
    (not merely by a local push that reported success), all seven CI jobs were
    green on it, and the archive was confirmed to hold 0 issues, 0 pull
    requests, 0 releases and 0 tags. Those preserved copies are local-only, and
    that is an acceptable place for them to live: the prototype is three commits
    on `main` and one on `dev`, it is unrelated to this project's history, and
    the user's decision was to delete it.

221. **`0.1.0` was re-cut onto `cdc75ee`, and is still not pushed.** The tag had
    been cut at `ffd751a` *before* the floor violations were known — which made
    it a tag on the very commit whose 8.3 job went red, contradicting
    `docs/releasing.md` step 7 ("CI is green on the tag commit"). Once run
    `34696518049` returned seven green jobs on `cdc75ee`, the tag was moved:
    **was `ffd751a`, now `cdc75ee`**. That SHA is recorded here rather than left
    to `git reflog` because a moved tag is normally a thing to distrust, and the
    reader is owed the reason this one is safe: it is local-only
    (`git ls-remote --tags origin` → 0 refs), so no consumer has ever resolved
    it, and moving it rewrites no published history. Pushing it remains a
    stop-and-ask action and was not done.

222. **The first CI run under the repository's own name is green on all seven
    jobs, `coverage` included.** Run
    `34696518049` on `cdc75ee`, 2026-09-12. The `coverage` job passing is the
    first green coverage run this project has had on GitHub, which converts the
    `pcov`-through-`PHP_INI_SCAN_DIR` mechanism from something reasoned about
    locally into something observed (entry 215 recorded the same result on a
    branch of the old repo; this is the first on the new one). It is one run,
    not a pattern — the jobs re-run on every push.

223. **Docs were corrected where this work made them false.**
    `docs/releasing.md`'s "Known gaps at 0.1.0" opened with the CI coverage job
    having "never had a green run on GitHub, because this checkout has no
    remote" and closed with "PHP 8.3 and 8.4 are CI-only" — the first is now
    false and the second is now half false, since `composer check:floor` lints
    the floor locally through docker even though the *suite* on 8.3/8.4 remains
    CI-only. Both were rewritten to the observed state. Separately, "The five
    jobs are the authority" was wrong — there are seven — and a comment in
    `packages/db/tests/Query/QueryBuilderTest.php` pointed the reader at
    `tools/php-version-check.php`, a filename that never existed, while claiming
    the floor is "parsed" locally: the exact mechanism entries 209–210 measured
    as impossible, since a version-targeted php-parser accepts 8.4 grammar
    silently. Corrected to `composer check:floor` /
    `tools/php-floor-check.php`, and "parsed" to "linted".

### Verified by running

| Claim | How it was checked | Result |
|---|---|---|
| The rename took | `gh repo view …-archive-2024 --json name,visibility` | `LavaPHP-archive-2024`, still `PRIVATE`, `main` intact |
| The push landed on the NEW repo, not the archive | `gh api repos/BusyBeaverSoftware/LavaPHP/commits/main` | `cdc75ee` on the new public repo |
| The archive was not touched by the push | `gh api repos/…-archive-2024/commits/main` | still `decec6a`, the 2024 prototype |
| `origin` holds exactly what was intended | `git ls-remote --heads origin` | one ref, `refs/heads/main` = `cdc75ee` |
| `main` fast-forwarded, not rewritten | `git merge --ff-only`, then SHA comparison | `main` == `m9-post-release` == `cdc75ee` |
| CI green on the new repo | `gh run watch … --exit-status` | exit 0; `jobs: 7, green: 7` |
| `test (8.3)` specifically, the job that failed before | `gh run view --json jobs` | `success` |
| Archive held nothing but its branches | `gh api` for issues/pulls/releases/tags | 0, 0, 0, 0 |
| Every archive branch is accounted for | `git ls-remote archive-2024` before deletion | `main`/`dev` preserved as tags; `m9-post-release` is our own `cdc75ee` |
| The archive is gone | `gh repo view …-archive-2024` | `Could not resolve to a Repository` |
| The tag is not published | `git ls-remote --tags origin \\| wc -l` | `0` |

### What is still not verified

- **The `0.1.0` tag is not pushed, by design.** Pushing it is a stop-and-ask
  action; until then `lava/app`'s `composer create-project` path is untested
  against Packagist, as `docs/releasing.md` says.
- **The 2024 prototype now exists only as two local tags on this machine.** The
  archive that held it has been deleted, by the user's decision. If those tags
  are ever wanted elsewhere, `git push <remote> 2024-prototype-main` from this
  checkout is the whole recovery procedure — but it is worth knowing that the
  remote copy is gone before the laptop is.
- **One green CI run is not a pattern.** It is the authority for `cdc75ee` and
  nothing else; any commit that moves the tag has to earn its own green.

## 2026-09-12 — the first tag push, and what it does and does not publish

224. **"Packagist picks it up from the push" was measured false, and the docs
    said it.** `docs/releasing.md` closed its release instructions with that
    sentence for the whole of M9. It is the kind of claim that is true in the
    general case and false here, which is the dangerous combination: Packagist
    learns about a tag from a **webhook on a package that is already
    registered**, so the dependency runs the other way — registration first, tag
    second. Measured directly on 2026-09-12,
    `packagist.org/packages/lava/{core,db,validate,view,http-client,app}.json`
    all return `404`: none of the six packages is registered, so no webhook
    exists, so pushing `0.1.0` reaches GitHub and stops there. `composer require
    lava/core` keeps failing for everyone. The useful half of that measurement
    is that the **names are unclaimed** — nobody has squatted `lava/core`, so
    registering is available whenever the user wants it. The sentence was
    replaced with the prerequisite and the measurement, and
    `packages/app/README.md` — public now, and telling readers to run
    `composer create-project lava/app my-app`, which 404s — was given the caveat
    its own line 72 already carried further down.

225. **`0.1.0` was moved to the release commit and pushed.** The tag sat on
    `cdc75ee`, which is now one commit behind `main`: the record of the repo
    swap (entries 216–223) and the corrections in 224 live in the commit after
    it. A release should carry the account of what it is, so the tag moves onto
    the commit that holds that account rather than staying on the convenient
    one. This is a second move of a tag that has already moved once (entry 221),
    and the same justification applies with the same force: it has never been
    pushed, so no consumer has resolved it, and nothing published is being
    rewritten. It is pushed in this commit, which is what changes that fact.

    The push costs a **full duplicate run of all seven jobs**, deliberately
    accepted rather than worked around. The workflow triggers on any push with
    no `tags:` filter, and no job branches on `github.ref`, so CI cannot tell a
    tag push from a branch push — and that is the right design here: it means a
    green result is a statement about *the code at that commit*, not about how
    the ref was named. The cost is one redundant run on a commit already
    verified green; the alternative would be a `tags:`-filtered workflow that
    skips its own checks at the moment they matter most.

226. **The verification of 225 is recorded in the commit after it, and the tag
    is not moved again.** A commit cannot contain the outcome of the action it
    takes — the run on the tag does not exist until the tag is pushed, and the
    tag's SHA is not knowable until the commit is written. So the record splits,
    by necessity rather than by taste, exactly as it did for entry 215 (the
    green CI run recorded in `ca351c6`, the commit *after* the one that earned
    it). The alternative — moving the tag onto the commit that verifies it —
    would recurse forever, each move needing a verification that needs a move.
    The tag therefore stays where 225 put it, and later commits on `main` pass
    it by. That is what a release commit is: a fixed point, not a tip.

227. **The tag push was verified, and it published to nobody.** Every
    prediction in 224 and 225 was checked against the live services rather than
    inferred from the configuration:

    | Claim | How it was checked | Result |
    |---|---|---|
    | The tag reached the remote | `git ls-remote --tags origin` | `refs/tags/0.1.0` = `60b92b7` (annotated tag object) |
    | It points at the release commit | the peeled ref | `refs/tags/0.1.0^{}` = `ad8d5b1` |
    | The push really does cost a duplicate run | `gh run list` after the push | **two** runs on `ad8d5b1`: `34697665025` on ref `0.1.0` and `34697664011` on ref `main` |
    | Both are green | `gh run view --json jobs` | 7 jobs, 7 green, each |
    | No GitHub Release appeared | `gh release list` | `0` |
    | Nothing reached Packagist | `packagist.org/packages/{lava/core,lava/app}.json` | **`404`**, unchanged by the push |

    The last two rows are the ones worth having measured. A pushed tag is a
    GitHub-local act: it creates a ref, and nothing else follows from it.
    Packagist's `404` after the push is the empirical form of 224's argument —
    registration is what publishes, and a tag is only the thing that gets
    published *once something is listening*. This is the honest answer to "is it
    released?", and it is no: `0.1.0` exists, and `composer require lava/core`
    still fails.

228. **The packages are not in publishable shape, and registering them would
    not fix that.** Found by reading the manifests after entry 224 replaced "the
    push publishes" with "registration publishes" — the replacement was still
    too optimistic, because registration is necessary and not sufficient. Five
    of the six manifests are in monorepo development shape:

    | Manifest | `repositories` | requires `lava/core` |
    |---|---|---|
    | `lava/core` | *(none)* | *(nothing `lava/*`)* |
    | `lava/app` | `{"lava/core": {"type": "path", "url": "../core"}}` | `@dev` |
    | `lava/db` | same | `@dev` |
    | `lava/validate` | same | `@dev` |
    | `lava/view` | same | `@dev` |
    | `lava/http-client` | same | `@dev` |

    Both halves break a consumer independently. A path repository pointing at
    `../core` is correct inside this checkout and resolves to nothing in someone
    else's project, and Composer fails the install outright rather than falling
    back to Packagist — the failure mode `packages/app/README.md` already
    describes for the skeleton. And `@dev` is an unbound constraint, which is
    what a path repository needs during development and what a published
    package must not declare; CI's own `isolated-install` job comments on it
    (`ci.yml:83`). `lava/core` is the one clean package: no path repository, no
    `lava/*` requirement, only real Packagist dependencies. So `lava/core` could
    ship today and the other five could not.

    **The resolution is a packaging decision and it is deliberately not made
    here.** Two shapes are available — publish per-package splits with the path
    repositories stripped and `@dev` replaced by a version constraint, or decide
    that only `lava/core` ships in the 0.1.x line — and they lead to different
    repositories, different CI, and different consumer stories. Choosing one is
    a product decision, so the finding is recorded and the choice is left to the
    user; `docs/releasing.md` now states the blocker in "The tag" and in "Known
    gaps" so the next person to attempt a release meets it before Packagist
    rather than after.
