<?php declare(strict_types=1);

namespace Emz\Monitorio\Api;

use Emz\Monitorio\Log\LogLevel;
use Emz\Monitorio\Log\LogReader;
use Emz\Monitorio\Log\LogVolumeReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class LogsApiController extends AbstractController
{
    private const DEFAULT_MIN_LEVEL = 'WARNING';
    private const MAX_LIMIT = 1000;

    public function __construct(
        private readonly LogReader $logReader,
        private readonly LogVolumeReader $logVolumeReader,
    ) {
    }

    #[Route(
        path: '/api/_action/emz/monitorio/logs',
        name: 'api.action.emz.monitorio.logs',
        methods: ['GET']
    )]
    public function getLogs(Request $request): JsonResponse
    {
        $since = $this->parseSince($request->query->get('since'));
        $minLevel = $this->parseMinLevel($request->query->get('min_level'));
        $limit = $this->clampLimit((int) $request->query->get('limit', self::MAX_LIMIT));

        $entries = $this->logReader->readSince($since, $minLevel, $limit);

        return new JsonResponse(['data' => $entries]);
    }

    /**
     * Groesse des Log-Verzeichnisses - bewusst ein eigener Endpunkt und nicht
     * Teil von /logs: der Scan dort laeuft bei einem grossen Log in den
     * HTTP-Timeout, die Groesse waere dann ausgerechnet im kritischen Fall
     * nicht abrufbar.
     */
    #[Route(
        path: '/api/_action/emz/monitorio/logs/meta',
        name: 'api.action.emz.monitorio.logs.meta',
        methods: ['GET']
    )]
    public function getLogsMeta(): JsonResponse
    {
        return new JsonResponse(['data' => $this->logVolumeReader->read()]);
    }

    private function parseSince(mixed $raw): \DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return new \DateTimeImmutable('-1 hour');
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Invalid "since" timestamp: %s', $raw));
        }
    }

    private function parseMinLevel(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            return self::DEFAULT_MIN_LEVEL;
        }

        $value = strtoupper($raw);

        if (!LogLevel::exists($value)) {
            throw new \InvalidArgumentException(sprintf('Invalid "min_level": %s', $raw));
        }

        return $value;
    }

    private function clampLimit(int $value): int
    {
        if ($value <= 0) {
            return self::MAX_LIMIT;
        }

        return min($value, self::MAX_LIMIT);
    }
}
