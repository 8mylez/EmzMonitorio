<?php declare(strict_types=1);

namespace Emz\Monitorio\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class MonitorioApiController extends AbstractController
{
    #[Route(
        path: '/api/monitorio/free-disk-space',
        name: 'api.monitorio.free_disk_space',
        methods: ['GET']
    )]
    public function getFreeDiskSpace(): JsonResponse
    {
        $df = disk_free_space("/");

        $dt = disk_total_space("/");

        $data = [
            'freeDiskSpace' => $df,
            'totalDiskSpace' => $dt
        ];

        return new JsonResponse($data);
    }
}