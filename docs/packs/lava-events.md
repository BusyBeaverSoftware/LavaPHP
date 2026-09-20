# lavaphp/events

A PSR-14 event dispatcher whose listeners are declared in one file, checked at
boot, and listed where an agent looks: `lava events` and the app's `AGENTS.md`.
One file, three container ids, one command, two problem codes.

Reach for it when one action should reach several independent parts of an app,
or when the code that acts should not know what follows: a completed task that
updates a count, writes an audit line and notifies watchers. When an action has
one known consequence, call that service directly. A direct call is shorter, and
`lava services` already shows it.

Three properties are why it is shaped this way rather than as hooks:

- **One registry, no string names.** Listeners are listed in
  `app/Listeners.php` by event class, and nothing registers a listener anywhere
  else, so the file, `lava events` and the map are the whole story of what runs.
- **A listener that cannot run fails the boot.** Every listener is resolved and
  its `__invoke()` checked against each event it is listed for while boot builds
  the provider, so a typo or a wrong type is a problem with a fix at boot rather
  than a `TypeError` on the one request that fires the event.
- **Dispatch is PSR-14 and synchronous.** Listeners run in order, in the
  request; a stoppable event is asked before each one, and a listener's
  exception is not caught.

Codes this pack raises: `invalid_listeners_file` and `bad_listener`. See
[problem-codes.md](../problem-codes.md).

## Install and enable

```sh
composer require lavaphp/events
```

```php
// app/Modules.php
use Lava\Core\Modules\ModuleRef;

return [
    ModuleRef::of(\Lava\Events\EventsModule::class, package: 'lavaphp/events', feature: 'events'),
];
```

`events` is the pack's gate, defined by the pack; do not `define` it yourself.
Turned off, the dispatcher and the command are absent, and a handler that takes
`EventDispatcher` is a `service_not_registered` boot problem naming its route.

## Declare listeners

```php
// app/Listeners.php
use App\Tasks\LogCompletion;
use App\Tasks\NotifyWatchers;
use App\Tasks\RecountBoard;
use App\Tasks\TaskCompleted;
use App\Tasks\TaskEvent;

return [
    TaskCompleted::class => [LogCompletion::class, NotifyWatchers::class],
    TaskEvent::class => RecountBoard::class,
];
```

- A **key** is an event class, or an interface or parent class its events share.
- A **value** is a container id, a `Phase::first()` or `Phase::last()` of one, or a list of either, in the order they run.
- An event reaches the listeners of **every** key it is an instance of, in file
  order, and a listener named under two such keys runs once. Above, a
  `TaskCompleted` that implements `TaskEvent` reaches `LogCompletion`,
  `NotifyWatchers`, then `RecountBoard`.
- No file means no listeners, which is valid.

A listener listed under an interface must accept the interface: every event
that implements it reaches the listener, not only the one you had in mind.
Boot checks exactly that.

## Write a listener

A listener is an invokable service registered in `app/Services.php`, so its own
dependencies arrive through its factory like any other service's:

```php
// app/Tasks/NotifyWatchers.php
final readonly class NotifyWatchers
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function __invoke(TaskCompleted $event): void
    {
        // …
    }
}

// app/Services.php
$c->singleton(NotifyWatchers::class, static fn (Container $c): NotifyWatchers => new NotifyWatchers($c->get(MailerInterface::class)));
```

`__invoke()`'s first parameter is typed as the event, a parent of it, an
interface it implements, or `object`. Any further parameter must be optional,
because dispatch passes only the event. What it returns is ignored.

A listener may depend on the service that dispatches its event, directly or
through something that takes `EventDispatcher`, such as a renderer whose Twig
extension dispatches: the dispatcher fetches the listeners on its first
dispatch, so it is not part of any listener's construction.

A listener is one instance. Boot builds it when it builds the provider, and the
provider holds it, so every dispatch reaches the same object. Register listeners
with `$c->singleton()` and keep what belongs to one dispatch on the event rather
than on the listener: a listener registered with `$c->factory()` is refused at
boot as `factory_listener`, because the word promises a fresh object per
resolution and a listener is shared — a difference nothing would report at
runtime.

## Dispatch

A handler, or any service, takes `Lava\Events\EventDispatcher`:

```php
public function complete(RouteArgs $args, TaskRepository $tasks, EventDispatcher $events): ResponseInterface
{
    $task = $tasks->complete($args->int('id'));
    $events->dispatch(new TaskCompleted($task->id, $task->title));

    return Responses::json(['completed' => $task->id]);
}
```

`dispatch()` returns the event it was given, so a listener can set a value on
it for the caller to read. That is the filter pattern: a mutable event carries
the value, each listener may change it, and the caller reads the result.

Listeners run in their position in `app/Listeners.php`. That is the whole order
for a file that says nothing else, and moving a line is how you change it.

When one listener has to run before or after the rest — a quota check first, an
audit trail last — wrap its id:

```php
use Lava\Events\Phase;

return [
    TaskCompleted::class => [
        Phase::first(CheckQuota::class),
        LogCompletion::class,
        NotifyWatchers::class,
        Phase::last(AuditTrail::class),
    ],
    TaskEvent::class => RecountBoard::class,
];
```

- There are three phases — `first`, `default` and `last` — and a bare id is the
  default one, so a file that names none runs exactly as it always did.
- The order is phase rank, then file order within a phase.
- A phase belongs to the listener, not to the entry: an id carries it under
  every key that names it, which is what lets a `last` listener under a class
  run after a default one under an interface. Above, `AuditTrail` runs after
  `RecountBoard`, although `RecountBoard` is written later.
- Giving one id two phases — two entries disagreeing, one entry saying both, or
  a `Phase::first()` beside a bare id — is `listener_order_conflict` at boot.
- Numbers were considered and refused: `10` before `-20` has no wrong value for
  boot to catch, while a phase keeps every mistake in this file a boot problem
  with a fix.

An event implementing
`Psr\EventDispatcher\StoppableEventInterface` reaches no further listener once
`isPropagationStopped()` is true. Stopping is by position, not by phase: a
default-phase listener that stops propagation skips the `last` listeners too, so
work that must happen whatever the listeners do belongs on the caller's side of
`dispatch()` rather than in `last`. A listener that throws stops the dispatch, and
the exception reaches the caller and the app's middleware as it would from a
direct call.

The pack registers `Lava\Events\EventDispatcher`, `Lava\Events\ListenerProvider`
and `Lava\Events\ListenerMap`. It does not register
`Psr\EventDispatcher\EventDispatcherInterface`: that id is left free for an app
that wants a dispatcher of its own, as lavaphp/http-client leaves
`ClientInterface`.

## What boot checks

| Mistake | Problem |
|---|---|
| `app/Listeners.php` does not parse, or raises an `Error` while it is read | `invalid_listeners_file`, at the error's line |
| `app/Listeners.php` returns something other than a map of class names to an id or a non-empty list of ids | `invalid_listeners_file` |
| a key that is not a class or an interface | `bad_listener` |
| a listener id nothing registered | `service_not_registered`, naming `app/Listeners.php` |
| a listener with no `__invoke()` | `bad_listener` |
| an `__invoke()` whose first parameter is missing, untyped, a union, a builtin, or a class the event is not | `bad_listener` |
| an `__invoke()` with a second required parameter | `bad_listener` |
| one listener given two phases (two entries, one entry twice, or a `Phase` beside a bare id) | `listener_order_conflict` |

One boot reports everything the file gets wrong: every key is checked, then every
listener, and the findings arrive together rather than one boot at a time.

Reading a listener's signature uses reflection, at boot and read-only: the third
place the framework does so ([conventions.md](../conventions.md#the-reflection-boundary)).
The listener itself is still built by its own factory.

## Seeing what runs

`lava events` prints each key and the listeners an event of that key runs, in
that order, with the phase in brackets where one was given; `--json` is
`lava.events/2` ([schema](../schemas/lava.events/2.json)):

```json
{"file": "app/Listeners.php", "phases": ["first", "default", "last"], "events": [{"event": "App\\Tasks\\TaskCompleted", "listeners": [{"listener": "App\\Tasks\\LogCompletion", "phase": "default"}, {"listener": "App\\Tasks\\AuditTrail", "phase": "last"}]}]}
```

`lava map` adds an **Events** section to `AGENTS.md` with the same rows, so a
change to `app/Listeners.php` makes a committed map stale, as a new route does.
Each listener also appears in the services table, with the line that registers
it.

## Testing

Assert what a listener did through its dependencies, which `replace:` swaps like
any other service:

```php
$app = TestApp::boot(dirname(__DIR__), replace: [MailerInterface::class => new Mailer($recording)]);
(new TestClient($app))->post('/tasks/7/complete');
self::assertCount(1, $recording->sent);
```

A listener can be replaced too, with an instance of its class. Dispatching an
event from a test without an HTTP request is
`$app->container->get(EventDispatcher::class)->dispatch(new TaskCompleted(…))`.

The pack's own tests boot `tests/fixtures/apps/events-app/`, an app whose
listeners reach an event through its class and through an interface, and run
`lava events` against the schema.
