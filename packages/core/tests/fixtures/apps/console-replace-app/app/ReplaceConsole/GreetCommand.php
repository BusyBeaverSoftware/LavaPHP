<?php

declare(strict_types=1);

namespace App\ReplaceConsole;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\Commands\AppCommand;
use Lava\Core\Console\IO;

/** An app command that boots the app and reports what its Greeter says. */
final class GreetCommand extends AppCommand
{
    public function name(): string
    {
        return 'app:greet';
    }

    public function summary(): string
    {
        return 'Say what the registered Greeter says.';
    }

    public function pack(): string
    {
        return 'app';
    }

    public function emptyPayload(Args $args): array
    {
        return ['greeting' => ''];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $greeter = $app->container->get(Greeter::class);
        $io->data('greeting', $greeter instanceof Greeter ? $greeter->greeting : '');
        $io->line($greeter instanceof Greeter ? $greeter->greeting : '');

        return $io->emit($this->name());
    }
}
