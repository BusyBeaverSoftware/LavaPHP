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