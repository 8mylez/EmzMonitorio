<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Emz\Monitorio\Api\MessageQueueApiController;
use PHPUnit\Framework\TestCase;

/**
 * Reine Unit-Tests gegen den fixen API-Contract (docs/message_queue_companion_endpoint.md, §3):
 * GET /api/monitorio/message-queue -> 200 mit JSON-Array [{name:string, size:int}].
 *
 * Connection und AbstractSchemaManager sind in DBAL 4.4 nicht final -> per createMock mockbar.
 * Der Controller baut die Antwort per `new JsonResponse` (kein Container/Serializer noetig).
 */
final class MessageQueueApiControllerTest extends TestCase
{
    public function testReturnsEmptyArrayWhenTableIsMissing(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')
            ->with(['messenger_messages'])
            ->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        // Kein Doctrine-Transport -> Query darf gar nicht erst laufen.
        $connection->expects($this->never())->method('fetchAllAssociative');

        $response = (new MessageQueueApiController($connection))->getMessageQueueBacklog();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('[]', $response->getContent());
    }

    public function testReturnsBacklogPerQueueWithIntegerSizes(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        // DBAL liefert COUNT(*) als String -> muss zu int gecastet werden.
        $connection->method('fetchAllAssociative')->willReturn([
            ['name' => 'default', 'size' => '1234'],
            ['name' => 'low_priority', 'size' => '5'],
        ]);

        $response = (new MessageQueueApiController($connection))->getMessageQueueBacklog();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                ['name' => 'default', 'size' => 1234],
                ['name' => 'low_priority', 'size' => 5],
            ],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testReturnsEmptyArrayWhenNoMessagesWaiting(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $response = (new MessageQueueApiController($connection))->getMessageQueueBacklog();

        self::assertSame(200, $response->getStatusCode());
        // Leeres Result -> JSON-Array [], niemals Objekt {}.
        self::assertSame('[]', $response->getContent());
    }
}
