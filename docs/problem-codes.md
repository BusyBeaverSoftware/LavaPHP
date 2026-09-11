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

M1/M2/M3 status: every code above has its class; all except `unexpected_failure` are
exercised by fixture/unit tests under `packages/core/tests/` (see
`tests/Unit/KernelBootTest.php` for the fixture-level ones — `bad-routes-app`
collects five route problems in one boot; `module-app` exercises the module
contract end to end). `unknown_command` arrives with M4's console kernel
(`tests/Unit/ConsoleTest.php`, `tests/Cli/LavaBinaryTest.php`). Still to come
with their milestones: pack-specific codes (M5+). The table is complete when
0.1.0 is tagged. `lava check` renders problems fix-first (runnable commands
first).