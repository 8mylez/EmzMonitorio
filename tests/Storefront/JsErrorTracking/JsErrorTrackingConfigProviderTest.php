<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Storefront\JsErrorTracking;

use Emz\Monitorio\Storefront\JsErrorTracking\JsErrorTrackingConfigProvider;
use Emz\Monitorio\Storefront\JsErrorTracking\PageContext;
use PHPUnit\Framework\TestCase;
use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Theme\AbstractThemePathBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit-Tests gegen den Loader-Contract: getLoaderData() liefert entweder null
 * (nichts ausliefern) oder zwei fertig kodierte, inline-script-sichere JSON-Literale.
 *
 * `projectId` und `shopToken` sind im Snippet Pflicht - fehlt eines, bricht es still ab.
 * Der Provider liefert deshalb in diesem Fall gar nichts aus.
 */
final class JsErrorTrackingConfigProviderTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0189c6b1f1e57e1a9b2d4c3f5a6e7d80';
    private const THEME_ID = '0189c6b2aa117d3e8f4b1c9e2d5a6f70';
    private const BUILD_ID = 'cb1116e70d578ba33c978843afbdd646';
    private const SHOP_TOKEN = 'test-shop-token';

    public function testReturnsNullWhenDisabled(): void
    {
        self::assertNull($this->provider(enabled: false)->getLoaderData());
    }

    public function testReturnsNullWhenProjectIdIsMissing(): void
    {
        self::assertNull($this->provider(projectId: 0)->getLoaderData());
    }

    public function testReturnsNullWhenProjectIdIsNegative(): void
    {
        self::assertNull($this->provider(projectId: -1)->getLoaderData());
    }

    public function testReturnsNullWhenShopTokenIsMissing(): void
    {
        self::assertNull($this->provider(shopToken: '')->getLoaderData());
    }

    public function testReturnsNullWhenShopTokenIsOnlyWhitespace(): void
    {
        self::assertNull($this->provider(shopToken: "  \n ")->getLoaderData());
    }

    public function testReturnsNullWithoutMainRequest(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects(self::never())->method('get');

        $provider = new JsErrorTrackingConfigProvider(
            $systemConfig,
            new RequestStack(),
            $this->createMock(AbstractThemePathBuilder::class)
        );

        self::assertNull($provider->getLoaderData());
    }

    public function testBuildsConfigForEnabledSalesChannel(): void
    {
        $data = $this->provider(route: 'frontend.home.page')->getLoaderData();

        self::assertIsArray($data);
        self::assertSame(
            [
                'projectId' => 1,
                'shopToken' => self::SHOP_TOKEN,
                'salesChannelId' => self::SALES_CHANNEL_ID,
                'context' => PageContext::STOREFRONT,
                'buildId' => self::BUILD_ID,
            ],
            json_decode($data['config'], true)
        );
    }

    public function testProjectIdStaysAnIntegerInTheJson(): void
    {
        // Das Snippet prueft `!projectId`; ein String waere zwar truthy, die Ingest-URL
        // wird aber aus dem Wert gebaut - deshalb als Zahl ausliefern.
        $data = $this->provider(projectId: 42)->getLoaderData();

        self::assertIsArray($data);
        self::assertStringContainsString('"projectId":42', $data['config']);
    }

    public function testShopTokenIsTrimmed(): void
    {
        $data = $this->provider(shopToken: '  tok_live_123  ')->getLoaderData();

        self::assertIsArray($data);
        self::assertSame('tok_live_123', json_decode($data['config'], true)['shopToken']);
    }

    public function testContextFollowsTheCurrentRoute(): void
    {
        $data = $this->provider(route: 'frontend.checkout.confirm.page')->getLoaderData();

        self::assertIsArray($data);
        self::assertSame(PageContext::CHECKOUT, json_decode($data['config'], true)['context']);
    }

    public function testFallsBackToTheHostedSnippetUrl(): void
    {
        $data = $this->provider(baseUrl: '')->getLoaderData();

        self::assertIsArray($data);
        self::assertSame('https://app.monitorio.de/t/v1.js', json_decode($data['snippetUrl'], true));
    }

    public function testConfiguredBaseUrlMovesTheSnippet(): void
    {
        $data = $this->provider(baseUrl: '  http://localhost:8080  ')->getLoaderData();

        self::assertIsArray($data);
        self::assertSame('http://localhost:8080/t/v1.js', json_decode($data['snippetUrl'], true));
    }

    public function testTrailingSlashInTheBaseUrlDoesNotDoubleTheSeparator(): void
    {
        $data = $this->provider(baseUrl: 'http://localhost:8080/')->getLoaderData();

        self::assertIsArray($data);
        self::assertSame('http://localhost:8080/t/v1.js', json_decode($data['snippetUrl'], true));
    }

    public function testExposesTheRawOriginForThePreconnectHint(): void
    {
        // Roh, nicht JSON-kodiert: der Wert geht in ein href-Attribut und wird
        // dort von Twig escaped - ein JSON-Literal haette Anfuehrungszeichen.
        $data = $this->provider(baseUrl: 'http://localhost:8080/')->getLoaderData();

        self::assertIsArray($data);
        self::assertSame('http://localhost:8080', $data['preconnectOrigin']);
    }

    public function testConfigIsReadForTheCurrentSalesChannel(): void
    {
        $seen = [];
        $systemConfig = $this->keyedConfig(seen: $seen);

        $provider = new JsErrorTrackingConfigProvider(
            $systemConfig,
            $this->requestStack('frontend.home.page'),
            $this->createMock(AbstractThemePathBuilder::class)
        );

        self::assertIsArray($provider->getLoaderData());
        foreach ($seen as [$key, $salesChannelId]) {
            self::assertSame(self::SALES_CHANNEL_ID, $salesChannelId, $key . ' wurde ohne Sales-Channel gelesen');
        }
        self::assertContains(['EmzMonitorio.config.jsErrorTrackingEnabled', self::SALES_CHANNEL_ID], $seen);
        self::assertContains(['EmzMonitorio.config.projectId', self::SALES_CHANNEL_ID], $seen);
        self::assertContains(['EmzMonitorio.config.shopToken', self::SALES_CHANNEL_ID], $seen);
    }

    public function testSalesChannelIdIsNullWhenTheRequestCarriesNone(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'frontend.home.page');

        $stack = new RequestStack();
        $stack->push($request);

        $data = (new JsErrorTrackingConfigProvider(
            $this->keyedConfig(),
            $stack,
            $this->createMock(AbstractThemePathBuilder::class)
        ))->getLoaderData();

        self::assertIsArray($data);
        self::assertNull(json_decode($data['config'], true)['salesChannelId']);
    }

    public function testShopTokenCannotBreakOutOfTheInlineScript(): void
    {
        $xss = '</script><img src=x onerror=alert(1)>';

        $data = $this->provider(shopToken: $xss)->getLoaderData();

        self::assertIsArray($data);
        self::assertStringNotContainsString('</script>', $data['config']);
        self::assertStringNotContainsString('<', $data['config']);
        // Der Wert bleibt inhaltlich erhalten, nur die Kodierung ist escaped.
        self::assertSame($xss, json_decode($data['config'], true)['shopToken']);
    }

    public function testConfiguredBaseUrlCannotBreakOutOfTheInlineScript(): void
    {
        $data = $this->provider(baseUrl: '</script><img src=x onerror=alert(1)>')->getLoaderData();

        self::assertIsArray($data);
        self::assertStringNotContainsString('<', $data['snippetUrl']);
    }

    /**
     * `bin/console system:config:set <key> false` legt den String "false" ab. Ein (bool)-Cast
     * wuerde daraus true machen und das Tracking gegen den Willen des Betreibers ausliefern.
     *
     * @dataProvider falsyConfigValues
     */
    public function testAmbiguousConfigValuesCountAsDisabled(mixed $stored): void
    {
        self::assertNull($this->provider(enabled: $stored)->getLoaderData());
    }

    public static function falsyConfigValues(): iterable
    {
        yield 'echter boolean false' => [false];
        yield 'string false aus der cli' => ['false'];
        yield 'string 0' => ['0'];
        yield 'leerer string' => [''];
        yield 'nie gesetzt' => [null];
        yield 'unsinniger wert' => ['vielleicht'];
    }

    /**
     * @dataProvider truthyConfigValues
     */
    public function testExplicitlyEnabledValuesDeliverTheLoader(mixed $stored): void
    {
        self::assertIsArray($this->provider(enabled: $stored)->getLoaderData());
    }

    public static function truthyConfigValues(): iterable
    {
        yield 'echter boolean true' => [true];
        yield 'string true aus der cli' => ['true'];
        yield 'string 1' => ['1'];
    }

    public function testBuildIdMatchesTheThemeAssetPath(): void
    {
        // Derselbe Hash steht als Verzeichnis in /theme/<buildId>/js/... - daran haengt
        // spaeter die Sourcemap-Aufloesung.
        $data = $this->provider()->getLoaderData();

        self::assertIsArray($data);
        self::assertSame(self::BUILD_ID, json_decode($data['config'], true)['buildId']);
    }

    public function testBuildIdIsOmittedWithoutTheme(): void
    {
        // Headless-Sales-Channel: keine Storefront, kein Theme, also auch keine Build-Kennung.
        $data = $this->provider(themeId: null)->getLoaderData();

        self::assertIsArray($data);
        self::assertArrayNotHasKey('buildId', json_decode($data['config'], true));
    }

    public function testLoaderStillWorksWhenThePathBuilderFails(): void
    {
        $pathBuilder = $this->createMock(AbstractThemePathBuilder::class);
        $pathBuilder->method('assemblePath')->willThrowException(new \RuntimeException('kein seed'));

        $provider = new JsErrorTrackingConfigProvider(
            $this->keyedConfig(),
            $this->requestStack('frontend.home.page'),
            $pathBuilder
        );

        $data = $provider->getLoaderData();

        // Die Build-Zuordnung ist nice-to-have, der Loader nicht.
        self::assertIsArray($data);
        self::assertArrayNotHasKey('buildId', json_decode($data['config'], true));
    }

    private function provider(
        mixed $enabled = true,
        int $projectId = 1,
        string $shopToken = self::SHOP_TOKEN,
        string $baseUrl = '',
        string $route = 'frontend.home.page',
        ?string $themeId = self::THEME_ID,
    ): JsErrorTrackingConfigProvider {
        $pathBuilder = $this->createMock(AbstractThemePathBuilder::class);
        $pathBuilder->method('assemblePath')
            ->with(self::SALES_CHANNEL_ID, self::THEME_ID)
            ->willReturn(self::BUILD_ID);

        return new JsErrorTrackingConfigProvider(
            $this->keyedConfig($enabled, $projectId, $shopToken, $baseUrl),
            $this->requestStack($route, $themeId),
            $pathBuilder
        );
    }

    /**
     * Der Provider liest vier Schluessel ueber drei Methoden - der Mock muss also nach
     * Schluessel unterscheiden, nicht pauschal antworten.
     *
     * @param array<int, array{0: string, 1: string|null}> $seen
     */
    private function keyedConfig(
        mixed $enabled = true,
        int $projectId = 1,
        string $shopToken = self::SHOP_TOKEN,
        string $baseUrl = '',
        array &$seen = [],
    ): SystemConfigService {
        $systemConfig = $this->createMock(SystemConfigService::class);

        $systemConfig->method('get')->willReturnCallback(
            function (string $key, ?string $salesChannelId = null) use ($enabled, &$seen) {
                $seen[] = [$key, $salesChannelId];

                return $key === 'EmzMonitorio.config.jsErrorTrackingEnabled' ? $enabled : null;
            }
        );

        $systemConfig->method('getInt')->willReturnCallback(
            function (string $key, ?string $salesChannelId = null) use ($projectId, &$seen) {
                $seen[] = [$key, $salesChannelId];

                return $key === 'EmzMonitorio.config.projectId' ? $projectId : 0;
            }
        );

        $systemConfig->method('getString')->willReturnCallback(
            function (string $key, ?string $salesChannelId = null) use ($shopToken, $baseUrl, &$seen) {
                $seen[] = [$key, $salesChannelId];

                return match ($key) {
                    'EmzMonitorio.config.shopToken' => $shopToken,
                    'EmzMonitorio.config.monitorioBaseUrl' => $baseUrl,
                    default => '',
                };
            }
        );

        return $systemConfig;
    }

    private function requestStack(string $route, ?string $themeId = self::THEME_ID): RequestStack
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, self::SALES_CHANNEL_ID);
        if ($themeId !== null) {
            $request->attributes->set(SalesChannelRequest::ATTRIBUTE_THEME_ID, $themeId);
        }

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
