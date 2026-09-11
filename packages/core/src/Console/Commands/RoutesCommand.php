<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava routes` — the route table with each route's injection plan and its
 * gating state. A gated-off route is still listed with `--all`: "why is my
 * route a 404" is answered by seeing the route and its flag, not by its
 * absence from the table.
 */
final class RoutesCommand extends AppCommand
{
    public function name(): string
    {
        return 'routes';
    }

    public function summary(): string
    {
        return 'List routes with their injection plans and gating state.';
    }

    public function flags(): array
    {
        return ['json', 'all', 'env'];
    }

    protected function emptyPayload(Args $args): array
    {
        return ['routes' => []];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $all = $args->bool('all');
        $records = [];
        $rows = [];

        foreach ($app->router->routes() as $route) {
            $state = $route->feature === null || $app->features->on($route->feature) ? 'active' : 'disabled';
            if (!$all && $state === 'disabled') {
                continue;
            }

            $plan = $app->router->plan($route->name);
            $records[] = [
                'name' => $route->name,
                'methods' => $route->methodNames(),
                'path' => $route->path,
                'state' => $state,
                'feature' => $route->feature,
                'middleware' => $route->middleware,
                'handler' => $plan->json()['handler'],
                'injects' => $plan->injects,
            ];
            $rows[] = [
                implode('|', $route->methodNames()),
                $route->path,
                $route->name,
                $state,
                implode(' ', array_map(
                    static fn (array $inject): string => $inject['name'] . ':' . $inject['kind'],
                    $plan->injects,
                )),
            ];
        }

        $io->data('routes', $records);
        $io->text((new Table(['methods', 'path', 'name', 'state', 'injects'], $rows))->render());

        return $io->emit($this->name());
    }
}
