<?php declare(strict_types=1);

namespace Emz\Monitorio\Storefront\JsErrorTracking;

use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Theme\AbstractThemePathBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liefert die Daten fuer den Loader des von Monitorio gehosteten Tracking-Snippets.
 * Das Plugin bringt bewusst keinen Tracking-Code mit - es injiziert nur Konfiguration
 * und laedt `SNIPPET_URL`, damit Monitorio das Snippet ohne Plugin-Release aktualisieren kann.
 */
final class JsErrorTrackingConfigProvider
{
    /**
     * Von Monitorio gehostet und versioniert; zeigt derzeit auf die Staging-Instanz.
     * Bewusst ein App-Host ohne www-Umweg: die Apex-Domain monitorio.de antwortet mit 307
     * auf www und wuerde jeden Seitenaufruf einen zusaetzlichen Round-Trip kosten.
     *
     * Aus dieser URL leitet das Snippet auch seinen Ingest-Endpunkt ab
     * (`<base>/t/e/<projectId>`) - Quelle und Ziel haengen an diesem einen Wert.
     *
     * Der Endpunkt muss `Access-Control-Allow-Origin` senden - der Loader laedt das Script
     * mit crossOrigin="anonymous", damit keine Cookies mitgehen.
     */
    public const SNIPPET_URL = 'https://staging-app.monitorio.de/t/v1.js';

    private const CONFIG_ENABLED = 'EmzMonitorio.config.jsErrorTrackingEnabled';
    private const CONFIG_PROJECT_ID = 'EmzMonitorio.config.projectId';
    private const CONFIG_SHOP_TOKEN = 'EmzMonitorio.config.shopToken';
    private const CONFIG_SNIPPET_URL = 'EmzMonitorio.config.snippetUrl';

    /**
     * Inline-Script-sichere JSON-Kodierung: `<`, `>`, `&`, `'` und `"` werden innerhalb der
     * Werte hex-escaped, damit ein Token aus dem Admin kein `</script>` erzeugen kann.
     */
    private const JSON_FLAGS = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT
        | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RequestStack $requestStack,
        private readonly AbstractThemePathBuilder $themePathBuilder,
    ) {
    }

    /**
     * Beide Werte sind fertig kodierte JSON-Literale und gehen im Template unescaped raus.
     *
     * @return array{config: string, snippetUrl: string}|null null, wenn nichts ausgeliefert wird
     */
    public function getLoaderData(): ?array
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return null;
        }

        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null;

        if (!$this->isEnabled($salesChannelId)) {
            return null;
        }

        $projectId = $this->systemConfigService->getInt(self::CONFIG_PROJECT_ID, $salesChannelId);
        $shopToken = trim($this->systemConfigService->getString(self::CONFIG_SHOP_TOKEN, $salesChannelId));

        // Beides ist im Snippet Pflicht: fehlt eines, bricht es still ab (`if (!projectId
        // || !token) return;`). Dann gar nicht erst laden, statt einen wirkungslosen
        // Request pro Seitenaufruf zu erzeugen.
        if ($projectId <= 0 || $shopToken === '') {
            return null;
        }

        $route = $request->attributes->get('_route');

        $config = [
            'projectId' => $projectId,
            'shopToken' => $shopToken,
            'salesChannelId' => $salesChannelId,
            'context' => PageContext::fromRoute(\is_string($route) ? $route : null),
        ];

        $buildId = $this->resolveBuildId($request, $salesChannelId);
        if ($buildId !== null) {
            $config['buildId'] = $buildId;
        }

        return [
            'config' => json_encode($config, self::JSON_FLAGS),
            'snippetUrl' => json_encode($this->resolveSnippetUrl($salesChannelId), self::JSON_FLAGS),
        ];
    }

    /**
     * Kennung des Storefront-Builds, aus dem die ausgelieferten JS-Dateien stammen.
     *
     * Es ist derselbe Hash, der als Verzeichnis in den Asset-URLs steht
     * (`/theme/<buildId>/js/storefront/storefront.js`). Der aktive SeedingThemePathBuilder
     * bezieht einen Seed mit ein, der bei jedem Theme-Build neu gesetzt wird - der Wert
     * wechselt also mit dem Build und identifiziert zugleich das Verzeichnis, auf das die
     * Stack-Frames zeigen. Damit taugt er sowohl als Deploy-Marker als auch als Schluessel
     * fuer spaeter hochgeladene Sourcemaps.
     *
     * Sales-Channels ohne Theme (headless) liefern keine Storefront aus - dort entfaellt
     * das Feld, statt einen erfundenen Wert zu melden.
     */
    private function resolveBuildId(Request $request, ?string $salesChannelId): ?string
    {
        $themeId = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_THEME_ID);

        if ($salesChannelId === null || !\is_string($themeId) || $themeId === '') {
            return null;
        }

        try {
            return $this->themePathBuilder->assemblePath($salesChannelId, $themeId);
        } catch (\Throwable) {
            // Der Loader ist wichtiger als die Build-Zuordnung.
            return null;
        }
    }

    /**
     * Der Standardfall ist die Konstante; das Config-Feld existiert fuer abweichende
     * Monitorio-Instanzen (lokal, Produktion). Das Snippet leitet seinen Ingest-Endpunkt
     * aus genau dieser URL ab, deshalb reicht dieser eine Wert zum Umbiegen.
     */
    private function resolveSnippetUrl(?string $salesChannelId): string
    {
        $configured = trim($this->systemConfigService->getString(self::CONFIG_SNIPPET_URL, $salesChannelId));

        return $configured !== '' ? $configured : self::SNIPPET_URL;
    }

    /**
     * Bewusst nicht ueber getBool(): das castet per (bool), womit der String "false" zu true
     * wird - genau so schreibt `bin/console system:config:set` den Wert. Bei einem Schalter,
     * der ein fremdgehostetes Script in jede Storefront-Seite haengt, muss ein unklarer Wert
     * "aus" bedeuten. Ueber die Admin-Oberflaeche gesetzte echte Booleans verhalten sich gleich.
     *
     * Das Lesen ueber den SystemConfigService setzt ausserdem den Cache-Tag
     * `system.config-<salesChannelId>`, wodurch die gecachte Seite beim Umstellen der
     * Einstellung von selbst aus dem Cache faellt.
     */
    private function isEnabled(?string $salesChannelId): bool
    {
        return filter_var(
            $this->systemConfigService->get(self::CONFIG_ENABLED, $salesChannelId),
            \FILTER_VALIDATE_BOOL
        );
    }
}
