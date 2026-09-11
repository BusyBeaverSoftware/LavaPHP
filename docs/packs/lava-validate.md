# lava/validate

A typed validation DSL. You declare a field map; the pack runs it against a
payload and gives you back the values that were there and one problem per field
that was not.

Two properties are the reason it exists rather than a `if (!isset(...))` chain:

- **Every failure is actionable.** A `validation_failed` problem carries the
  field, the rule that refused the value, what that rule wanted, what was sent,
  and an imperative fix — in one JSON object, at a 422, so an agent can repair
  the request in one round trip.
- **A mistake in the declaration is a different problem from a bad value.**
  A pattern that does not compile, a bound on a boolean, a predicate that
  throws: those are `invalid_rule` with a 500 and a file and line in your own
  source. They are found once, at boot or at the declaration, not on whichever
  request happens to reach the field first.

Codes this pack raises: `validation_failed`, `invalid_rule`, `unreadable_field`
— see [problem-codes.md](../problem-codes.md).

## Install and enable

```sh
composer require lava/validate
```

```php
// app/Modules.php
use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\Validate\ValidateModule::class, package: 'lava/validate', feature: 'validate'),
];
```

`ValidateModule::register()` is empty, and that is the design rather than an
omission. A `Validator` is built from a field map that only the app knows, so
the container cannot hold one — a singleton validator would have to be for a
specific set of fields, and choosing which fields belong together is exactly the
decision a handler makes. The pack has no service, no config file and no env
var; the module exists so the feature is *declarable* and gateable.

## Declaring fields

```php
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validator;

$validator = Validator::of([
    'email'    => Field::str()->required()->email()->max(254),
    'password' => Field::str()->required()->min(12),
    'age'      => Field::int()->required()->min(18)->max(120),
    'nickname' => Field::str()->max(20),
    'role'     => Field::any()->required()->in(['editor', 'admin']),
    'terms'    => Field::bool()->required(),
]);
```

Four things about that map are deliberate.

**The name is the array key.** A field cannot be declared twice, because PHP
array keys make the duplicate unrepresentable — there is no check to write and
no state to get wrong. A `Field` carries no identity of its own, so the same
`Field::str()->email()` value can back two differently-named fields.

**Fields are optional unless `->required()` says so.** The chain reads as a
condition on a value that exists: `->max(20)` on an absent nickname must not
fire, or "nickname is optional but must be short" would be impossible to
express. Requiring is the thing that has to be said out loud, which is also the
safer default — a forgotten `->required()` on a create form produces an empty
column, while a forgotten `->optional()` on a patch form rejects every request
that omits the field.

**The chain starts with a type, and that is what makes `->min()` decidable.**
`min(2)` against the string `'5'` is either "at least two characters" (false) or
"at least the number two" (true), and no inspection of the value can tell which
the author meant. So the type rule — added first, exactly once — decides:
`Field::str()->min(2)` bounds characters, `Field::int()->min(2)` bounds the
value. A chain with no type rule has nothing to decide from, and `->min()` there
is **refused** rather than guessed, because guessing would make a field's answer
depend on the data.

**`->email()` and `->uuid()` refine the type; they do not stack beside it.**
`Field::str()->required()->email()` keeps the `required()` that came before it
and replaces the text rule with the email rule, so the pipeline holds
`[required, email]` and not `[required, string, email]` — a second rule that can
never fire is dead weight and a lie in the problem context, which names the rule
that actually stopped the value. A text refinement on a chain whose type is not
text (`Field::int()->email()`) is refused: no integer is an email address, and
replacing the type would quietly drop the bounds that were built on it.

The type constructors (`str`, `int`, `float`, `bool`, `any`) are **static**, so
they must be the first call in a chain. PHP lets a static be called through an
instance, and `Field::str()->required()->email()` would then return a brand-new
field and silently discard the `required()` — a field declared required that
accepts a missing value. Making the refinements instance methods is what closes
that hole; the pack's test suite pins it.

## The rules

| Chain call | Rule | Passes when | `expects` in the problem |
|---|---|---|---|
| `->required()` | `required` | the value is present and not empty | `{"present":true}` |
| `Field::str()` | `string` | the value is a string | `null` |
| `Field::int()` | `integer` | the value is an integer, or an integer-valued numeric string that fits in a PHP int | `null` |
| `Field::float()` | `float` | the value is a finite real number | `null` |
| `Field::bool()` | `boolean` | the value is a bool, or one of `1/0`, `true/false`, `on/off` | `null` |
| `->email()` | `email` | `filter_var(…, FILTER_VALIDATE_EMAIL)` accepts it | `null` |
| `->uuid()` | `uuid` | the canonical hyphenated spelling, braces and `urn:` refused | `null` |
| `->regex($p)` | `regex` | the whole value matches `$p` | the anchored pattern |
| `->in([…])` | `in` | the value's string form is in the list | the allowed list |
| `->min($n)` / `->max($n)` | `min` / `max` | characters (text) or magnitude (numbers), inclusive | `{"bound":n,"of":"characters"\|"value"}` |
| `->custom(…)` | your name | your predicate returns true | whatever you declared |

**Present** means not null, not an empty array, and not a string that is empty
or whitespace-only. `0`, `false` and `'0'` are all present — they are values a
caller meant to send, and treating a legitimate zero as "missing" is the classic
validation bug. `RequiredRule::isPresent()` is the framework's single definition
of the word: the rule set uses it to decide whether to run anything at all, and
the result object uses it to decide whether a field is there. Two definitions
would eventually disagree, and the disagreement would look like a field that
validates and then cannot be read.

**Bounds are inclusive, and lengths are characters, not bytes.** `->max(4)`
accepts `'José'` (four characters, five bytes). A byte bound would accept
`'Jose'` and refuse `'José'`, which makes the rule a rule about the alphabet.

**`->int()` refuses digit strings too large to hold.** `'9223372036854775808'`
is one past `PHP_INT_MAX`; casting it would produce a wrong number, so it is
refused with a message that says so. The same applies to a numeric string that
overflows to `INF` for `->float()`.

**`->regex()` anchors the pattern for you.** `'/\d+/'` means "the whole value is
digits" — unanchored matching is the single most common validation bug, since
`'/\d+/'` would otherwise accept `'abc5'`. Anchoring is idempotent, and a
pattern that will not compile is refused where it is written, quoting PCRE's own
complaint rather than `preg_last_error_msg()`'s "Internal error". A pattern with
no delimiters is refused separately, because anchoring it first would make PCRE
complain about a `$` the author never typed.

## Reading the result

```php
$input = $validator->validate($request->getParsedBody() ?? []);

if ($input->failed()) {
    return HttpErrors::forReport($input->report(), $request);
}

$email = $input->string('email');   // string, or throws UnreadableField
$age   = $input->int('age');        // int 34, from the form string '34'
$terms = $input->bool('terms');     // true, from 'on'
```

Both halves of the result are available at once, which is what lets a 422 list
every bad field while a handler still sees what was right.

**The typed accessors are strict.** `int('age')` returns an int or throws
`UnreadableField` — it never returns `0` as a fallback, because `0` is a value a
handler will happily store. A silently wrong row is the most expensive failure
this framework can produce, so the accessor refuses instead. Each accessor
delegates to the very rule that validated the field, so "accepted by validation"
and "readable by the handler" are one decision made in one place, and the test
suite asserts they never disagree.

**The field map is the allow-list.** `all()` and `value()` see declared fields
only, so `"is_admin": true` in a profile form never reaches the handler. That is
a property of the shape rather than a filter someone has to remember to call.

**A field that failed is not readable.** `has()` is false for it, so a handler
that forgot to check `failed()` gets a null rather than half-valid data.

## Cross-field rules

A rule's predicate receives its own field's value and nothing else —
deliberately, because a rule that could reach into the whole payload would make
a field's answer depend on the order fields happen to be declared in. So the
documented pattern reads the payload in the handler and captures it:

```php
$body = self::body($request);

$input = Validator::of([
    'starts_at' => Field::str()->required()->regex('/\d{4}-\d{2}-\d{2}/'),
    'ends_at'   => Field::str()->required()->regex('/\d{4}-\d{2}-\d{2}/')->custom(
        name: 'after_starts_at',
        check: static fn (mixed $ends): bool => !is_string($body['starts_at'] ?? null)
            || (is_string($ends) && $ends >= $body['starts_at']),
        message: "'{field}' must not be earlier than 'starts_at'.",
        fix: "Send '{field}' as a date on or after the value sent for 'starts_at'.",
        expects: ['not_before' => 'starts_at'],
    ),
])->validate($body);
```

The `!is_string(...) || …` is the idiom, not a defensive flourish. A rule has
two outcomes, pass or refuse, and "the field I depend on is not there" is not a
reason to refuse `ends_at` — `starts_at` has its own `->required()` and its own
problem, and returning false here would add a second complaint, about a
comparison that never happened, to a request with one thing wrong with it.
Guarding the read is not enough on its own: the guard has to decide what to do
about the missing value, and the answer is to let this field pass.

`{field}` in `message` and `fix` is the one substitution `->custom()` performs,
so a renamed field cannot leave a stale name behind. Both sentences are
required, because only the author knows what the predicate tests.

**A predicate that throws is an app fault, not a validation failure.** It
becomes `invalid_rule` with a 500, the original throwable as `previous`, and a
source location pointing at the `->custom(…)` line. Reporting it as a 422 would
be confidently wrong and perfectly actionable — the caller would go and edit a
value that was never the problem. The location is captured when the rule is
*constructed*, because a stack walk at inspect time would land on the framework's
own handler invocation and blame the wrong file.

## The 422 response

`Validated::report()` returns a `ProblemReport` you hand to
`HttpErrors::forReport()`. One problem per failed field, never one problem with
a list inside it:

```json
{"problems":[
  {"code":"validation_failed",
   "problem":"Field 'password' must be at least 12 characters, but 5 were sent.",
   "fix":"Send 'password' with at least 12 characters.",
   "context":{"field":"password","rule":"min",
              "expects":{"bound":12,"of":"characters"},
              "value":"<redacted>","sent":true},
   "source":null,"severity":"fatal"}
]}
```

| Context key | Meaning |
|---|---|
| `field` | which field |
| `rule` | the rule's stable id — `min`, `in`, `required`, your custom name |
| `expects` | what that rule wanted, machine-readable (`null` when it takes no parameter) |
| `value` | what was sent, **redacted when the field name looks like a secret** |
| `sent` | whether the key was in the payload at all |

`sent` is what distinguishes "you did not include the key" from "you sent null".
Both arrive as a null value and they need different fixes — `Include 'email' in
the request body.` versus `Send a value for 'email', or omit the key entirely if
it is optional.` Telling a caller to include a key they already included is a fix
that does not work.

`value` is redacted when the *field name* looks secret (`password`, `api_key`,
`credentials.token`), because a 422 body is logged, echoed into terminals and
pasted into bug reports. The decision is about the field rather than the value:
"does this look secret" is not a question a validator can answer. The value is
never in `message` in the first place — rules write prose about the shape of
what was wanted, not a quotation of what arrived — so redaction is a second line
of defence rather than the only one.

A request whose JSON body is not a JSON object is refused before routing with
`malformed_body` (400). Reporting a syntax error as "every field is missing"
would be a confidently actionable answer to a question nobody asked.

## Testing this pack

| Layer | Location | Needs |
|---|---|---|
| Rules, builder, validator, problems | `packages/validate/tests/` | nothing — pure code |
| HTTP end to end | `packages/validate/tests/Http/` | nothing — boots the fixture app in-process |
| A real server | `php -S` against the fixture | nothing |

```sh
# From the repo root.
vendor/bin/phpunit --testsuite=validate

# The pack on its own, the way a consumer installs it.
cd packages/validate && composer install && vendor/bin/phpunit
```

The HTTP tests boot `tests/fixtures/apps/validate-app/` through
`TestApp::boot()`, which registers the fixture's `App\` autoloader in-process
and restores the environment when boot returns. To drive the same fixture under
a real SAPI, the fixture needs the same autoloader a real app gets from its
composer.json — fixture apps have no composer.json, so the harness prepends one:

```sh
FIX=packages/validate/tests/fixtures/apps/validate-app
LAVA_FIXTURE_APP_DIR=$PWD/$FIX php \
  -d auto_prepend_file=$PWD/packages/core/tests/Support/fixture-autoload.php \
  -S 127.0.0.1:8732 -t $FIX/public $FIX/public/index.php
```

Without that prepend every `App\` class is unfindable and a perfectly good
fixture boots with `bad_handler` for all three routes — which is the reason the
harness exists and the reason `lava serve` prepends it too.
