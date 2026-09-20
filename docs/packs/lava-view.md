# lavaphp/view

Twig, wired the way the rest of the framework is wired. One service, two
template functions, four problem codes — and every Twig failure leaving the pack
as a `LavaProblem` with a file, a line and an imperative fix.

Three properties are the reason it exists rather than an app calling Twig
directly:

- **The dangerous default is the one you have to type.** Autoescaping is on
  always and is not configurable, so a template reaches the browser escaped
  whether it is called `page.twig` or `page.html.twig`. Twig's own default
  depends on that filename, which is a rule an agent cannot be asked to know and
  whose failure mode is an XSS hole no test would catch.
- **A missing value is an error, not a blank.** `strict_variables` is on, so
  `{{ titel }}` fails the render with the variable's name and the line instead
  of rendering an empty string into a page that looks fine. A blank where a
  value belongs is the single hardest template defect to notice.
- **A template mistake is a framework failure.** A template that does not
  compile, a `url()` called with the model instead of its id, a variable the
  handler forgot to pass: each is one of this pack's four codes with a fix that
  names the file to open.

Codes this pack raises: `template_not_found`, `template_failed`,
`view_dir_missing`, `bad_view_call` — see
[problem-codes.md](../problem-codes.md).

## Install and enable

```sh
composer require lavaphp/view
```

```php
// app/Modules.php
use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\View\ViewModule::class, package: 'lavaphp/view', feature: 'views'),
];
```

`views` is the pack's gate. Do **not** add a `define` entry for it — the pack
defines it, and writing it yourself is a boot problem rather than a harmless
duplicate. Turn the pack off from `set` or from the environment:

```php
// config/features.php
return ['set' => ['views' => \Lava\Core\Features\Flag::off()]];
```

```sh
LAVA_FEATURE_VIEWS=off
```

Turning it off is not free if your own wiring references the pack: a service in
`app/Services.php` that type-hints `ViewRenderer` becomes a dangling reference
and boot reports `service_not_registered` naming the file and the line. That is
the wiring contract doing its job, not a bug.

## Configure

`config/view.php`, optional — every key has a default:

```php
return [
    'path'  => 'views',      // where templates live
    'debug' => false,        // Twig's debug tools (dump()); omit the key for env !== 'prod'
    'cache' => 'var/views',  // compiled templates; '' means compile every render
    'namespaces' => [],      // 'paper' => ['themes/paper', 'views']: @paper/… searches these in order
    'extensions' => [],      // container ids of Twig extensions, installed at boot
];
```

Omit `debug` rather than setting it to `null`: a key that is present with the
wrong type is an `invalid_config` problem, not a fallback to the default. The
same is true of every config key in the framework.

`path` and `cache` are resolved against the app directory when they are
relative, never against the working directory — `lava serve` and a request under
FPM have different working directories, and a path that depends on which one is
running works in development and 404s in production.

**The cache is off in debug, whatever you configured.** A compiled template is
not recompiled when its source changes unless Twig's staleness check runs, and
`debug: false` is exactly what disables that check — so a cached debug
environment is the worst kind of confusing: you edit a template, the page does
not change, and nothing says why.

A `path` that is not a directory fails the **boot** with `view_dir_missing`,
naming the path, the config key and the app directory. Every render would fail
identically, so N request-time errors collapse into one message that says what
to create.

**`namespaces` is how themes fall back.** Each entry is a Twig namespace and the
directories `@name/…` searches, first match first:

```php
'namespaces' => ['paper' => ['themes/paper', 'views']],
```

`render('@paper/layout')` is `themes/paper/layout.twig` when the theme has one
and `views/layout.twig` when it does not, and a page picks its theme without
touching the loader: `{% extends '@' ~ theme ~ '/layout.twig' %}` with `theme` in
the render context. A name is lowercase letters, digits and `_`; one directory
may be given as a string. Every directory is resolved like `path` and checked at
boot, so a missing one is `view_dir_missing` naming `view.namespaces.<name>`, and
`template_not_found` for `@name/…` lists that namespace's directories, or, for a
name this key does not declare, the names it does. `$view->namespaces()` returns
the declared names with their directories, in config order, so a page that picks
its theme can check the name without a second list of themes.

**`extensions` installs Twig extensions at boot.** Register each in
`app/Services.php` and list its container id:

```php
// app/Services.php
$c->singleton(App\View\AppExtension::class, static fn (): App\View\AppExtension => new App\View\AppExtension());
```

```php
// config/view.php
'extensions' => [App\View\AppExtension::class],
```

The renderer's factory adds them while it builds the Twig environment, which
boot does before anything can render. That matters because Twig refuses a new
filter, function or extension after its first render, so an extension added
later, from a handler or a middleware, works only until something has rendered.
An id that is not registered is `service_not_registered` naming
`config/view.php`, and one whose service is not a
`Twig\Extension\ExtensionInterface` is `invalid_config`, both at boot.

## Rendering

`ViewRenderer` is a registered container id. A handler takes it as a typed
method parameter, like any other service:

```php
use App\Tasks\TaskRepository;
use Lava\Core\Routing\RouteArgs;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;

final class TaskController
{
    // Constructed with no arguments: the repository arrives as a parameter, like
    // the renderer, because it is registered in app/Services.php.
    public function show(RouteArgs $args, TaskRepository $tasks, ViewRenderer $view): ResponseInterface
    {
        return $view->render('tasks/show', ['task' => $tasks->find($args->int('id'))]);
    }

    public function missing(ViewRenderer $view): ResponseInterface
    {
        return $view->renderStatus('tasks/show', 404, ['task' => null]);
    }
}
```

| Call | Returns |
|---|---|
| `render($template, $context = [])` | a 200 `ResponseInterface`, HTML |
| `renderStatus($template, $status, $context = [])` | the same, with your status — the 404 page, the 422 re-render |
| `renderToString($template, $context = [])` | the HTML alone, for an email body or a fragment written to a file |
| `exists($template)` | whether a template is there, so a handler can choose a page or a 404 without catching an exception to find out |
| `templateDir()` | where templates are read from |
| `namespaces()` | each name `view.namespaces` declares, in config order, with the absolute directories `@name/…` searches |
| `environment()` | the Twig `Environment`, for an app that needs to add a filter of its own — before the first render: Twig locks filters, functions, globals and extensions on first use and throws `LogicException` after. Code that may run later guards it: `if (!$twig->hasExtension(AppExtension::class)) { $twig->addExtension(new AppExtension()); }` |

The renderer returns a response rather than a string on purpose. Twig returns
HTML and a handler must return a response, so somewhere the string has to be
wrapped — and doing it here makes the common case one expression
(`return $view->render(…)`) and gives `renderStatus()` an obvious home. An app
that wrapped it itself would reinvent the status, the header and the body on
every call site, slightly differently each time.

## Template names

`render('tasks/show')` — the `.twig` extension is optional and added for you.
Both spellings name the same file; there are not two ways to do it, there is one
rule applied to what you typed.

A subdirectory is part of the name: a file at `views/tasks/show.twig` is
`'tasks/show'`, not `'show'`. When a template is missing, the
`template_not_found` message lists what *is* there — with the extension, in
sorted order, so the names it prints are names you can paste straight back into
`render()`.

## The two functions

The whole template namespace is two functions, both declared in one array in
`Lava\View\ViewFunctions`. Adding a third is a deliberate edit to that file, not
something that happens to an app.

### `url()`

```twig
<a href="{{ url('tasks.show', {id: task.id}) }}">open</a>
```

Turns a route **name** into a path, through the same router the request went
through. Hardcoding `/tasks/7` means renaming the route's path silently breaks
every link and nothing warns — the route still matches, the page still renders,
and the link 404s. Going through the router makes that impossible: an unknown
name throws `unknown_route` with the nearest real name, and a value the route
would never match throws `bad_route_pattern`.

Params are a map of name → string or integer, because a route param is always a
string once it is in a path. Anything else is `bad_view_call`:

```twig
{{ url('tasks.show', {id: task}) }}      {# the model, not its id #}
{{ url('tasks.show', {id: task.id}) }}   {# on a task that was never loaded: null #}
```

The fix for the second one is to guard the link, not to change the field:

```twig
{% if task.id is not null %}<a href="{{ url('tasks.show', {id: task.id}) }}">open</a>{% endif %}
```

### `feature()`

```twig
{% if feature('beta_dashboard') %}<a href="/beta">Beta</a>{% endif %}
```

Answers whether a flag is on, from the same resolver a route's `->when()` uses —
so a gated button and a gated route cannot disagree, because there is only one
answer. That holds for audience flags too (`Flag::users`, `Flag::rollout`):
during a request, `feature()` reads the resolver `App::handle()` bound to that
request's subject. A typo'd name is `unknown_feature` with the nearest real
name, not a silently hidden button.

## What a failure looks like

```json
{"code":"bad_view_call",
 "problem":"url('tasks.show', …) was given null for param 'id', which cannot be part of a URL.",
 "fix":"Pass a string or an integer — {{ url('tasks.show', {id: item.id}) }}. If the value can be null, guard the link: {% if item.id is not null %}…{% endif %}.",
 "context":{"route":"tasks.show","param":"id","given":"null"},
 "source":null,"severity":"fatal"}
```

`template_failed` is one code with two factories, because the fix differs even
though the diagnosis does not: a template that **does not compile** is a typo in
the template text, while one that **threw while rendering** is, with
`strict_variables` on, almost always a variable the handler never passed into the
context. The fix for the first names the template; the fix for the second names
`render()` in the handler. Both carry the `file:line` Twig already computed.

A `LavaProblem` raised inside a template — `bad_view_call`, from `url()` or
`feature()` — passes through **untouched**. Twig wraps anything a template
function throws in its own `RuntimeError`, and unwrapping it is what keeps the
specific code and the specific fix instead of burying them inside Twig's
sentence. This is the same rule `lavaphp/db`'s `migration_failed` follows: a
wrapper problem wraps only throwables that are not already `LavaProblem`s.

Nothing this pack reports ever contains a value from your context. `bad_view_call`
names the *type* of what was passed, because a problem report is an error page,
a log line and a `--json` body that gets pasted into an issue — and a route
param can hold anything an app put in its model.

## Testing this pack

| Layer | Location | Needs |
|---|---|---|
| Twig config, functions, module, renderer | `packages/view/tests/Unit/` | nothing — pure code |
| Problem shapes and codes | `packages/view/tests/Problem/` | nothing |
| HTTP end to end | `packages/view/tests/Http/` | nothing — boots the fixture app in-process |
| A real server | `php -S` against the fixture | nothing |

```sh
# From the repo root.
vendor/bin/phpunit --testsuite=view

# The pack on its own, the way a consumer installs it.
cd packages/view && composer install && vendor/bin/phpunit
```

The HTTP tests boot `tests/fixtures/apps/view-app/` through `TestApp::boot()`,
which registers the fixture's `App\` autoloader in-process and restores the
environment when boot returns. The fixture has one route per way a template can
be wrong, so each failure is reachable with a plain GET — a spec you have to
build a request body for is a spec nobody reads.

To drive the same fixture under a real SAPI, the fixture needs the same
autoloader a real app gets from its composer.json. Fixture apps have no
composer.json, so the harness prepends one:

```sh
FIX=packages/view/tests/fixtures/apps/view-app
LAVA_FIXTURE_APP_DIR=$PWD/$FIX php \
  -d auto_prepend_file=$PWD/packages/core/tests/Support/fixture-autoload.php \
  -S 127.0.0.1:8733 -t $FIX/public $FIX/public/index.php
```

Without that prepend every `App\` class is unfindable and a perfectly good
fixture boots with `bad_handler` for every route — which is the reason the
harness exists and the reason `lava serve` prepends it too.
