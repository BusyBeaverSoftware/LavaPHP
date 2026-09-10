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
| `unexpected_failure` | `UnexpectedFailure` | a non-LavaProblem throwable escaped during boot (e.g. a PHP error in a user config file) | wrap the original message + step |

Codes land here as the classes are written; the table is complete when 0.1.0
is tagged. `lava check` renders problems fix-first (runnable commands first).