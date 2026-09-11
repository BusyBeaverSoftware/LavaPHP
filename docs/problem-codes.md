# LavaPHP problem codes

Every `LavaProblem` carries a stable snake_case `code` — the JSON discriminator
an agent can match on. Codes are a public contract from 0.1.0 onward: a code is
never renamed or repurposed; new problems get new codes. Every code maps 1:1 to
a Problem class in `Lava\Core\Problem\` and is exercised by a fixture test.

Problem JSON shape (identical in boot reports, `lava check`, HTTP error pages,
and every `--json` command):

```json
{"code":"missing_pack","problem":"…what failed, one sentence…","fix":"…imperative fix…",
 "context":{…failing input…},"source":{"file":"app/Modules.php","line":4},"severity":"fatal"}
```

`source.file` is an absolute path on the wire — the shape above is elided for
readability; see [conventions.md](conventions.md#the-cli-contract).

## Registry

| Code | Class | Thrown when | Fix pattern |
|---|---|---|---|
| `invalid_config` | `InvalidConfig` | a config file returns a non-array, an unknown key/section appears, or a typed accessor finds a wrong type | name the file, the expected shape |
| `invalid_env_file` | `InvalidEnvFile` | `config/.env` has a malformed line | quote the line and line number |
| `duplicate_service` | `DuplicateService` | a container id is registered twice | show both registration sites |
| `service_not_registered` | `ServiceNotRegistered` | code resolves an id that was never registered | show the exact registration line to add |
| `circular_service` | `CircularService` | a service factory (transitively) resolves itself | print the chain |
| `unknown_feature` | `UnknownFeature` | a flag name was never defined anywhere | suggest the nearest defined name |
| `duplicate_feature` | `DuplicateFeature` | a flag name is defined twice | show both definition sites |
| `unknown_env_branch` | `UnknownEnvBranch` | a per-env flag has no branch for the current env | name the missing branch |
| `invalid_flag_value` | `InvalidFlagValue` | a Flag was constructed or parsed with an invalid value (percentage out of range, unparsable env syntax, nested per-env) | quote the value and the grammar |
| `invalid_feature_name` | `InvalidFeatureName` | a flag name doesn't match `[a-z][a-z0-9_]*` | quote the name and the rule |
| `missing_pack` | `MissingPack` | a module is listed in `app/Modules.php`, its feature resolves on, but the pack's module class doesn't exist | the exact `composer require lava/<pack>` command |
| `module_mismatch` | `ModuleMismatch` | a loaded module's `PackInfo` disagrees with its `app/Modules.php` entry (package or feature) | show both values and the entry's line |
| `invalid_gating` | `InvalidGating` | an audience-targeted flag (rollout/users) gates a boot-lifetime resource (a module) | point at per-request gating (`->when()`) or a deterministic flag |
| `unexpected_failure` | `UnexpectedFailure` | a non-LavaProblem throwable escaped during boot (e.g. a PHP error in a user config file) | wrap the original message + step |
| `bad_route_pattern` | `BadRoutePattern` | a route path is malformed (no leading `/`, untyped `{param}`, unknown/duplicate param type, unclosed placeholder) or URL generation passed a value the route would never match | quote the path and the exact syntax to write |
| `bad_handler` | `BadHandler` | a route handler breaks the handler contract: missing/malformed spec, class not instantiable, constructor takes arguments, method missing/non-public, no or wrong return type, non-injectable parameter | name the handler and the exact signature to write |
| `bad_middleware` | `BadMiddleware` | a middleware class-string doesn't exist or doesn't implement PSR-15 `MiddlewareInterface` | name the class and the PSR-15 signature |
| `duplicate_route_name` | `DuplicateRouteName` | two routes share a name | show both registration sites |
| `route_not_found` | `RouteNotFound` | no route matched the method + path (also the real 404 for a gated route whose flag is off) | `Run: lava routes --json` |
| `method_not_allowed` | `MethodNotAllowed` | the path matched but the method didn't (also: HEAD to a GET-only route — HEAD is never auto-mapped) | list the accepted methods |
| `unknown_route` | `UnknownRoute` | URL generation asked for a route name that isn't registered | suggest the nearest registered name |
| `unknown_command` | `UnknownCommand` | `lava <name>` named a command this app doesn't have | suggest the nearest registered command, else `lava list` |
| `duplicate_command` | `DuplicateCommand` | two commands claim the same name (core, a pack, or `app/Commands.php`) | rename or remove the second; when both claim one pack, override `pack()` |
| `missing_entry_point` | `MissingEntryPoint` | `lava serve` found no `public/index.php` to run — checked before the server is announced | copy the canonical one from the `lava/app` skeleton |
| `missing_test_runner` | `MissingTestRunner` | the app has no `vendor/bin/phpunit` to run its suite with | `Run: composer install --dev`, or set `LAVA_PHPUNIT` |
| `bad_test_report` | `BadTestReport` | the runner ran but wrote no JUnit report, or wrote one that isn't well-formed XML | run the runner directly; its own output rides in `context` |
| `not_an_app` | `NotAnApp` | the directory booted has no `app/`, no `config/`, and no `public/index.php` | name the directory and the layout to create |
| `bad_usage` | `BadUsage` | a command was invoked with a missing or malformed argument (the command exists; the arguments don't) — exit 2 | quote the usage line |
| `missing_env_var` | `MissingEnvVar` | a declared required env var has no value in the process environment or `config/.env` — **severity warn** | set it in `config/.env` or export it |
| `unknown_selector` | `UnknownSelector` | `lava describe <selector>` matched no route, service, flag, env var, or command | suggest the nearest name and list every candidate namespace |

`not_an_app` is the one code that exists because booting the wrong directory
**succeeds**. Every user-authored artifact is optional (see
[conventions.md](conventions.md)), so without this check a random directory
yields an app with no routes and `lava routes` there answers
`status: ok, routes: []` — which reads as "your app has no routes" rather than
"there is no app here".

`missing_env_var` is deliberately Warn, not Fatal: `lava env` is a report, and a
diagnostic that itself exits non-zero is an obstacle. `lava check --strict` is
where an unset required var becomes a build failure.

`bad_usage` is the only code that exits **2** rather than 1, alongside
`unknown_command`. An agent can tell "you typed it wrong" from "it ran and
failed" without parsing the body.

M1/M2/M3 status: every code above has its class; all except `unexpected_failure` are
exercised by fixture/unit tests under `packages/core/tests/` (see
`tests/Unit/KernelBootTest.php` for the fixture-level ones — `bad-routes-app`
collects five route problems in one boot; `module-app` exercises the module
contract end to end).

M4 status: all of M4's codes are live and covered — `unknown_command`
(`tests/Unit/ConsoleTest.php`, `tests/Unit/ConsoleDispatchTest.php`),
`bad_usage` (`tests/Unit/InspectionCommandsTest.php`,
`tests/Unit/ServeCommandTest.php`), `duplicate_command`
(`tests/Unit/RegisterCommandsTest.php`), `missing_env_var` and
`unknown_selector` (`tests/Unit/InspectionCommandsTest.php`),
`missing_entry_point` (`tests/Unit/ServeCommandTest.php`),
`missing_test_runner` and `bad_test_report` (`tests/Unit/TestCommandTest.php`,
`tests/Unit/JunitTest.php`), and `not_an_app`
(`tests/Unit/KernelBootTest.php`). `missing_env_var` is raised by one shared
rule (`Config/EnvAudit`) used by both `lava env` and `lava check`, so the two
commands cannot disagree about whether a variable is set. Still to come with
their milestones: pack-specific codes (M5+). The table is complete when 0.1.0 is
tagged. `lava check` renders problems fix-first (runnable commands first).

`missing_test_runner` and `bad_test_report` are separated on purpose, because
they need different fixes: no runner means the app was never installed, while a
runner that wrote nothing means PHPUnit ran and stopped before testing — a
broken `phpunit.xml`, a bootstrap that fatals. Neither is ever a *framework*
problem, so a red suite contributes no problems at all: `lava test` and
`lava check` let the exit code and `status` go red and leave `problems[]` empty.
The framework has no business pronouncing on code it never read.