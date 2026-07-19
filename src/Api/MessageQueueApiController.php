<?php declare(strict_types=1);

namespace Emz\Monitorio\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final class MessageQueueApiController extends AbstractController
{
    /**
     * @param iterable<string, object> $receivers alle messenger.receiver-getaggten Transports, indexiert nach Alias
     */
    public function __construct(private readonly iterable $receivers)
    {
    }

    #[Route(
        path: '/api/monitorio/message-queue',
        name: 'api.monitorio.message_queue',
        methods: ['GET']
    )]
    public function getMessageQueueBacklog(): JsonResponse
    {
        $result = [];

        foreach ($this->receivers as $name => $receiver) {
            // Gleiche Quelle und Semantik wie messenger:stats: Transports ohne
            // Count-Support (z. B. scheduler_shopware) werden ausgelassen.
            if (!$receiver instanceof MessageCountAwareInterface) {
                continue;
            }

            try {
                $result[] = ['name' => (string) $name, 'size' => $receiver->getMessageCount()];
            } catch (\Throwable) {
                // Nicht erreichbarer Transport (z. B. AMQP down) soll nur seinen
                // eigenen Eintrag kosten, nicht die gesamte Antwort (kein 500).
            }
        }

        return new JsonResponse($result);
    }
}
