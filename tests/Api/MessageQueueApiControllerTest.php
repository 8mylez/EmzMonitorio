<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Api;

use Emz\Monitorio\Api\MessageQueueApiController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Reine Unit-Tests gegen den API-Contract:
 * GET /api/monitorio/message-queue -> 200 mit JSON-Array [{name:string, size:int}].
 *
 * Datenquelle ist wie bei messenger:stats die Menge der messenger.receiver-getaggten
 * Transports (indexiert nach Alias); gezaehlt wird via MessageCountAwareInterface.
 */
final class MessageQueueApiControllerTest extends TestCase
{
    public function testReturnsCountPerTransport(): void
    {
        $receivers = [
            'failed' => $this->countAwareReceiver(0),
            'async' => $this->countAwareReceiver(11),
            'low_priority' => $this->countAwareReceiver(1),
        ];

        $response = (new MessageQueueApiController($receivers))->getMessageQueueBacklog();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                ['name' => 'failed', 'size' => 0],
                ['name' => 'async', 'size' => 11],
                ['name' => 'low_priority', 'size' => 1],
            ],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testSkipsTransportsWithoutCountSupport(): void
    {
        // z. B. scheduler_shopware: ReceiverInterface ohne MessageCountAwareInterface
        $receivers = [
            'async' => $this->countAwareReceiver(3),
            'scheduler_shopware' => $this->createMock(ReceiverInterface::class),
        ];

        $response = (new MessageQueueApiController($receivers))->getMessageQueueBacklog();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                ['name' => 'async', 'size' => 3],
            ],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testSkipsTransportsThatFailToCount(): void
    {
        $broken = $this->createMock(MessageCountAwareInterface::class);
        $broken->method('getMessageCount')->willThrowException(new \RuntimeException('transport down'));

        $receivers = [
            'broken_amqp' => $broken,
            'async' => $this->countAwareReceiver(2),
        ];

        $response = (new MessageQueueApiController($receivers))->getMessageQueueBacklog();

        // Ein nicht erreichbarer Transport kostet nur seinen Eintrag, keinen 500.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                ['name' => 'async', 'size' => 2],
            ],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testReturnsEmptyArrayWithoutTransports(): void
    {
        $response = (new MessageQueueApiController([]))->getMessageQueueBacklog();

        self::assertSame(200, $response->getStatusCode());
        // Leeres Result -> JSON-Array [], niemals Objekt {}.
        self::assertSame('[]', $response->getContent());
    }

    private function countAwareReceiver(int $count): MessageCountAwareInterface
    {
        $receiver = $this->createMock(MessageCountAwareInterface::class);
        $receiver->method('getMessageCount')->willReturn($count);

        return $receiver;
    }
}
