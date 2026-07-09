<?php declare(strict_types=1);

namespace Emz\Monitorio\Api;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final class MessageQueueApiController extends AbstractController
{
    private const MESSENGER_TABLE = 'messenger_messages';

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route(
        path: '/api/monitorio/message-queue',
        name: 'api.monitorio.message_queue',
        methods: ['GET']
    )]
    public function getMessageQueueBacklog(): JsonResponse
    {
        // Ohne Doctrine-Transport existiert die Messenger-Tabelle nicht -> leeres Backlog, kein 500.
        if (!$this->connection->createSchemaManager()->tablesExist([self::MESSENGER_TABLE])) {
            return new JsonResponse([]);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT queue_name AS name, COUNT(*) AS size
             FROM ' . self::MESSENGER_TABLE . '
             WHERE delivered_at IS NULL
             GROUP BY queue_name'
        );

        return new JsonResponse(array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'size' => (int) $row['size'],
            ],
            $rows
        ));
    }
}
