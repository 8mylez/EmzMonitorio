<?php declare(strict_types=1);

namespace Emz\Monitorio\Storefront\Twig;

use Emz\Monitorio\Storefront\JsErrorTracking\JsErrorTrackingConfigProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class JsErrorTrackingExtension extends AbstractExtension
{
    public function __construct(
        private readonly JsErrorTrackingConfigProvider $configProvider,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('emz_monitorio_js_error_tracking', $this->configProvider->getLoaderData(...)),
        ];
    }
}
