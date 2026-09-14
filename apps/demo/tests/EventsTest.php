<?php

declare(strict_types=1);

namespace App\Tests;

use Lava\Core\Boot\App;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestClient;
use Lava\Db\Connection;
use Lava\Db\Migration\MigrationFiles;
use Lava\Db\Migration\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Completing a task dispatches `TaskCompleted`, and `app/Listeners.php` decides
 * what follows: here, one log line. The test swaps the logger for one that
 * records, the same seam a test uses for any listener's dependency.
 */
final class EventsTest extends TestCase
{
    public function testCompletingATaskReachesItsListenerAndNothingElseDoes(): void
    {
        $log = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = is_string($level) ? "{$level}: {$message}" : (string) $message;
            }
        };

        $app = TestApp::boot(dirname(__DIR__), ['DATABASE_DSN' => 'sqlite::memory:'], [LoggerInterface::class => $log]);
        self::assertInstanceOf(App::class, $app);
        $db = $app->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        (new MigrationRunner($db, MigrationFiles::inApp($app->appDir)))->migrate();

        $client = new TestClient($app);
        $created = $client->json('POST', '/tasks', ['title' => 'Ship the events pack']);
        self::assertSame(201, $created->status(), $created->body());
        self::assertSame([], $log->messages, 'Creating a task dispatches nothing.');

        $id = $created->json()['task']['id'];
        self::assertSame(200, $client->post("/tasks/{$id}/complete")->status());
        self::assertSame(['info: task completed'], $log->messages);

        self::assertSame(404, $client->post('/tasks/999999/complete')->status());
        self::assertCount(1, $log->messages, 'A task that is not there completes nothing, and nothing is dispatched.');
    }
}
