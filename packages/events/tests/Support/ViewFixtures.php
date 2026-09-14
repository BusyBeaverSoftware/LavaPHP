<?php

declare(strict_types=1);

// Fixtures that need lavaphp/view and Twig, apart from Fixtures.php so that
// file loads without them. Required only by the test that boots both packs.

namespace Lava\Events\Tests\Support;

use Lava\Events\EventDispatcher;
use Lava\View\ViewRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A Twig extension whose function dispatches: `{{ ship('A1') }}`. */
final class ShippingExtension extends AbstractExtension
{
    public function __construct(private readonly EventDispatcher $events)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [new TwigFunction('ship', function (string $order): string {
            $this->events->dispatch(new Shipped($order));

            return "shipped {$order}";
        })];
    }
}

/** A listener that renders, so it needs the renderer the extension is installed in. */
final class RendersShipment
{
    /** @var list<string> */
    public array $rendered = [];

    public function __construct(private readonly ViewRenderer $views)
    {
    }

    public function __invoke(Shipped $event): void
    {
        $this->rendered[] = $this->views->renderToString('note.twig', ['order' => $event->order]);
    }
}
